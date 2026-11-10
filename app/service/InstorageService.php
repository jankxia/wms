<?php
namespace app\service;

use app\model\Order;
use app\model\OrderList;
use app\model\Product;
use think\Db;

class InstorageService
{
    protected $stock;

    public function __construct()
    {
        $this->stock = new StockService();
    }

    public function page()
    {
        $data  = request()->get();
        $where = ['state' => 1];

        empty($data['type'])   || $where['type']   = $data['type'];
        empty($data['author']) || $where['author'] = ['like', '%' . $data['author'] . '%'];
        empty($data['sn'])     || $where['sn']     = ['like', '%' . $data['sn'] . '%'];
        empty($data['start'])  || $where['add_time'][] = ['>=', strtotime($data['start'] . ' 00:00:00')];
        empty($data['end'])    || $where['add_time'][] = ['<=', strtotime($data['end'] . ' 23:59:59')];

        return Order::where($where)->order('id desc')->paginate(15);
    }

    /**
     * 新增入库单：单据 + 明细 + 库存增加 + 流水，全程一个事务
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
            $order->state       = 1;
            $order->add_time    = time();
            $order->save();

            $this->stock->applyPairs($pairs, $order, 1, StockService::TYPE_IN, '入库单创建');

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

    /**
     * 单号缺失时自动生成，且保证唯一
     */
    private function _sn($sn)
    {
        $sn = trim((string) $sn);
        if ($sn === '') {
            $sn = 'RK' . date('YmdHis') . mt_rand(100, 999);
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

    /**
     * 删除入库单：扣回库存 + 删明细 + 删单据，全程一个事务
     */
    public function delete($id)
    {
        $order = Order::get($id);
        if (!$order) {
            return ['error' => 100, 'msg' => '单据不存在或已删除'];
        }

        Db::startTrans();
        try {
            // 入库单删除意味着这批货要从库存里扣回去，若已被出库则会因库存不足而抛异常
            $this->stock->rollbackOrder($order, -1, StockService::TYPE_IN_CANCEL);

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
