<?php
namespace app\service;

use app\model\Order;
use app\model\OrderList;
use app\model\Product;
use app\model\StockLog;
use think\Db;

/**
 * 库存核心服务：出入库落库、库存回滚、流水台账、预警与看板统计
 *
 * 约定：所有改变库存的方法都必须在事务内调用，异常向上抛出，由调用方回滚。
 */
class StockService
{
    /** 变动类型 */
    const TYPE_IN          = 1;   // 入库
    const TYPE_OUT         = 2;   // 出库
    const TYPE_IN_CANCEL   = 3;   // 入库单删除冲销
    const TYPE_OUT_CANCEL  = 4;   // 出库单删除冲销

    public static function typeMap()
    {
        return [
            self::TYPE_IN         => '入库',
            self::TYPE_OUT        => '出库',
            self::TYPE_IN_CANCEL  => '入库单冲销',
            self::TYPE_OUT_CANCEL => '出库单冲销',
        ];
    }

    /**
     * 把表单里的 product[] / num[] 归一化成明细数组
     *
     * @return array [ ['good_id'=>int, 'num'=>int], ... ]
     * @throws \Exception 明细为空或数量非法时抛出
     */
    public function normalizePairs($products, $nums)
    {
        $pairs  = [];
        $merged = [];

        if (empty($products) || !is_array($products)) {
            throw new \Exception('请至少添加一行库存明细');
        }

        foreach ($products as $k => $goodId) {
            $goodId = (int) $goodId;
            if ($goodId <= 0) {
                throw new \Exception('第 ' . ($k + 1) . ' 行未选择产品');
            }
            $raw = isset($nums[$k]) ? trim((string) $nums[$k]) : '';
            if ($raw === '' || !is_numeric($raw)) {
                throw new \Exception('第 ' . ($k + 1) . ' 行数量必须填写数字');
            }
            $num = (int) $raw;
            if ($num <= 0) {
                throw new \Exception('第 ' . ($k + 1) . ' 行数量必须大于 0');
            }
            $merged[$goodId] = isset($merged[$goodId]) ? $merged[$goodId] + $num : $num;
        }

        foreach ($merged as $goodId => $num) {
            $pairs[] = ['good_id' => $goodId, 'num' => $num];
        }

        return $pairs;
    }

    /**
     * 执行一次库存变动并写流水
     *
     * @param array  $pairs     normalizePairs 的结果
     * @param Order  $order     关联单据
     * @param int    $direction 1 增加库存 / -1 减少库存
     * @param int    $type      流水类型
     * @param string $remark    备注
     * @throws \Exception
     */
    public function applyPairs(array $pairs, Order $order, $direction, $type, $remark = '', $scene = '出库')
    {
        foreach ($pairs as $pair) {
            $product = Product::get($pair['good_id']);
            if (!$product) {
                throw new \Exception('产品(id=' . $pair['good_id'] . ')不存在，请刷新后重试');
            }
            if ($product->status != 0) {
                throw new \Exception('产品【' . $product->name . '】已废弃，不能做出入库');
            }

            $before = (int) $product->num;
            $change = $direction * (int) $pair['num'];

            if ($direction < 0 && $before < $pair['num']) {
                throw new \Exception('产品【' . $product->name . '】库存不足：现有 ' . $before . '，本次需' . $scene . ' ' . $pair['num'] . ($scene === '出库' ? '' : '（请先处理该产品相关的出库单据）'));
            }

            $after = $before + $change;

            $ok = Db::name('product')->where('id', $product->id)->update(['num' => $after]);
            if ($ok === false) {
                throw new \Exception('产品【' . $product->name . '】库存更新失败');
            }

            $this->record([
                'type'        => $type,
                'product_id'  => $product->id,
                'product_sn'  => $product->sn,
                'product_name'=> $product->name,
                'order_id'    => $order->id,
                'order_sn'    => $order->sn,
                'num'         => (int) $pair['num'],
                'before_num'  => $before,
                'after_num'   => $after,
                'operator'    => (string) $order->author,
                'remark'      => $remark,
            ]);
        }
    }

    /**
     * 写一条库存流水
     */
    public function record(array $data)
    {
        $data['add_time'] = isset($data['add_time']) ? $data['add_time'] : time();
        StockLog::create($data);
    }

    /**
     * 单据删除时的库存冲销：入库单删除要扣回库存，出库单删除要把库存加回去
     *
     * @param Order $order
     * @param int   $direction -1 扣回 / 1 加回
     * @param int   $type
     * @throws \Exception
     */
    public function rollbackOrder(Order $order, $direction, $type)
    {
        $lists = OrderList::all(['order_id' => $order->id]);
        $pairs = [];
        foreach ($lists as $item) {
            $pairs[] = ['good_id' => (int) $item->good_id, 'num' => (int) $item->num];
        }
        if (empty($pairs)) {
            return;
        }
        $this->applyPairs($pairs, $order, $direction, $type, '单据删除，库存冲销', $direction < 0 ? '冲销入库' : '冲销出库');
    }

    /**
     * 库存流水台账分页
     */
    public function page()
    {
        $data  = request()->get();
        $where = [];

        if (!empty($data['product'])) {
            $where['product_name'] = ['like', '%' . $data['product'] . '%'];
        }
        if (!empty($data['sn'])) {
            $where['order_sn'] = ['like', '%' . $data['sn'] . '%'];
        }
        if (!empty($data['type'])) {
            $where['type'] = (int) $data['type'];
        }
        if (!empty($data['start'])) {
            $where['add_time'] = ['>=', strtotime($data['start'] . ' 00:00:00')];
        }
        if (!empty($data['end'])) {
            $end = strtotime($data['end'] . ' 23:59:59');
            if (isset($where['add_time'])) {
                $where['add_time'] = [$where['add_time'], ['<=', $end], 'and'];
            } else {
                $where['add_time'] = ['<=', $end];
            }
        }

        return StockLog::where($where)->order('id desc')->paginate(15);
    }

    /**
     * 低于预警下限的产品
     */
    public function warningList($onlyLow = true)
    {
        $query = Product::where('status', 0);
        if ($onlyLow) {
            $query->where('min_num', '>', 0)->where('num < min_num');
        }
        return $query->order('num asc')->paginate(15);
    }

    /**
     * 看板统计数据
     */
    public function dashboard()
    {
        $todayStart = strtotime(date('Y-m-d 00:00:00'));

        $productCount = (int) Db::name('product')->where('status', 0)->count();
        $totalNum     = (int) Db::name('product')->where('status', 0)->sum('num');
        $amountRow    = Db::name('product')->field('SUM(num*price) AS amount')->where('status', 0)->find();
        $totalAmount  = isset($amountRow['amount']) ? (float) $amountRow['amount'] : 0;

        $todayIn  = (int) Db::name('order')->where('state', 1)->where('add_time', '>=', $todayStart)->count();
        $todayOut = (int) Db::name('order')->where('state', 2)->where('add_time', '>=', $todayStart)->count();

        $lowCount = (int) Db::name('product')->where('status', 0)->where('min_num', '>', 0)->where('num < min_num')->count();

        $weekStart = strtotime(date('Y-m-d 00:00:00', strtotime('-6 days')));
        $weekIn    = (int) Db::name('order')->where('state', 1)->where('add_time', '>=', $weekStart)->count();
        $weekOut   = (int) Db::name('order')->where('state', 2)->where('add_time', '>=', $weekStart)->count();

        $recent = Order::where('state', 'in', [1, 2])->order('id desc')->limit(8)->select();

        $lowList = Product::where('status', 0)->where('min_num', '>', 0)->where('num < min_num')
            ->order('num asc')->limit(8)->select();

        return [
            'product_count' => $productCount,
            'total_num'     => $totalNum,
            'total_amount'  => $totalAmount,
            'today_in'      => $todayIn,
            'today_out'     => $todayOut,
            'low_count'     => $lowCount,
            'week_in'       => $weekIn,
            'week_out'      => $weekOut,
            'recent'        => $recent,
            'low_list'      => $lowList,
            'author'        => session('username') ?: '',
        ];
    }
}
