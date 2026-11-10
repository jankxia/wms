<?php
namespace app\service;

use app\model\Order;
use app\model\OrderList;
use app\model\Product;
use think\Db;

class OutstorageService
{
    protected $stock;

    public function __construct()
    {
        $this->stock = new StockService();
    }

    public function page()
    {
        $data  = request()->get();
        $where = ['state' => 2];

        empty($data['type'])   || $where['type']   = $data['type'];
        empty($data['author']) || $where['author'] = ['like', '%' . $data['author'] . '%'];
        empty($data['sn'])     || $where['sn']     = ['like', '%' . $data['sn'] . '%'];
        empty($data['start'])  || $where['add_time'][] = ['>=', strtotime($data['start'] . ' 00:00:00')];
        empty($data['end'])    || $where['add_time'][] = ['<=', strtotime($data['end'] . ' 23:59:59')];

        return Order::where($where)->order('id desc')->paginate(15);
    }

    /**
     * 新增出库单：单据 + 明细 + 库存扣减（先校验库存）+ 流水，全程一个事务
     */
    public function save()
    {
        if (!request()->isPost()) {
            return ['error' => 100, 'msg' => '非法请求'];
        }

        $param = request()->param();
        $error = $this->_validate($param);
        if (!is_null($error)) {
            return ['error' => 100, 'msg' => $error];
        }

        try {
            $pairs = $this->stock->normalizePairs(
                isset($param['product']) ? $param['product'] : [],
                isset($param['num']) ? $param['num'] : []
            );
        } catch (\Exception $e) {
            return ['error' => 100, 'msg' => $e->getMessage()];
        }

        Db::startTrans();
        try {
            $order              = new Order();
            $order->sn          = $this->_sn(isset($param['sn']) ? $param['sn'] : '');
            $order->type        = isset($param['type']) ? $param['type'] : '';
            $order->desc        = isset($param['desc']) ? $param['desc'] : '';
            $order->author      = isset($param['author']) ? $param['author'] : '';
            $order->supplier    = isset($param['supplier']) ? $param['supplier'] : '';
            $order->car_no      = isset($param['car_no']) ? $param['car_no'] : '';
            $order->ban_no      = isset($param['ban_no']) ? $param['ban_no'] : '';
            $order->detailed_no = isset($param['detailed_no']) ? $param['detailed_no'] : '';
            $order->state       = 2;
            $order->add_time    = time();
            $order->save();

            // 出库前先校验库存，不足直接抛异常回滚
            $this->stock->applyPairs($pairs, $order, -1, StockService::TYPE_OUT, '出库单创建');

            // 明细落库
            foreach ($pairs as $pair) {
                OrderList::create([
                    'good_id'  => $pair['good_id'],
                    'order_id' => $order->id,
                    'num'      => $pair['num'],
                ]);
            }

            Db::commit();
            return ['error' => 0, 'msg' => '保存成功', 'id' => $order->id];
        } catch (\Exception $e) {
            Db::rollback();
            return ['error' => 100, 'msg' => $e->getMessage()];
        }
    }

    private function _sn($sn)
    {
        $sn = trim((string) $sn);
        if ($sn === '') {
            $sn = 'CK' . date('YmdHis') . mt_rand(100, 999);
        }
        $exists = Order::where('sn', $sn)->count();
        if ($exists) {
            $sn = $sn . '-' . mt_rand(100, 999);
        }
        return $sn;
    }

    public function getAllList($id)
    {
        return OrderList::all(['order_id' => $id]);
    }

    public function totals($list)
    {
        $totalNum   = 0;
        $totalMoney = 0;
        foreach ($list as $item) {
            $totalNum += (int) $item->num;
            $price     = ($item->findGood && $item->findGood->price) ? (float) $item->findGood->price : 0;
            $totalMoney += $item->num * $price;
        }
        return [$totalNum, $totalMoney];
    }

    public function edit($id)
    {
        return Order::get($id);
    }

    /**
     * 删除出库单：库存加回 + 删明细 + 删单据，全程一个事务
     */
    public function delete($id)
    {
        $order = Order::get($id);
        if (!$order) {
            return ['error' => 100, 'msg' => '单据不存在或已删除'];
        }

        Db::startTrans();
        try {
            // 出库单删除要把这批货还回库存
            $this->stock->rollbackOrder($order, 1, StockService::TYPE_OUT_CANCEL);

            OrderList::where('order_id', $order->id)->delete();
            Db::name('order')->where('id', $order->id)->delete();

            Db::commit();
            return ['error' => 0, 'msg' => '删除成功'];
        } catch (\Exception $e) {
            Db::rollback();
            return ['error' => 100, 'msg' => '删除失败：' . $e->getMessage()];
        }
    }

    private function _validate($data)
    {
        $validate = validate('OrderValidate');
        if (!$validate->check($data)) {
            return $validate->getError();
        }
        return null;
    }
}
