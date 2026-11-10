<?php
namespace app\model;

use think\Model;

class StockLog extends Model
{
    protected $pk = 'id';

    public function getTypeTextAttr($value, $data)
    {
        $map = \app\service\StockService::typeMap();
        return isset($map[$data['type']]) ? $map[$data['type']] : '未知';
    }

    /**
     * 带符号的变动量，页面显示 +30 / -12
     */
    public function getChangeAttr($value, $data)
    {
        $num  = isset($data['num']) ? (int) $data['num'] : 0;
        $type = isset($data['type']) ? (int) $data['type'] : 0;
        if ($type == \app\service\StockService::TYPE_IN || $type == \app\service\StockService::TYPE_OUT_CANCEL) {
            return '+' . $num;
        }
        return '-' . $num;
    }

    public function getAddTimeTextAttr($value, $data)
    {
        return isset($data['add_time']) ? date('Y-m-d H:i:s', $data['add_time']) : '';
    }
}
