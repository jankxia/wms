<?php
namespace app\model;

use think\Model;

class Product extends Model
{
    protected $pk = 'id';

    /** 名称缓存，避免列表页重复查库 */
    private static $nameCache = [];

    public function findGoodup()
    {
        // return $this->hasOne('\app\model\Productup','id','gid');
        return $this->belongsTo('\app\model\Productup', 'id', 'gid');
    }

    /**
     * 数据兼容：早期数据里 category/storage/location/unit 可能存的是主键，这里统一转成名称展示
     */
    private static function resolveName($table, $value)
    {
        if (null === $value || '' === $value || !is_numeric($value)) {
            return (string) $value;
        }
        $key = $table . ':' . $value;
        if (isset(self::$nameCache[$key])) {
            return self::$nameCache[$key];
        }
        try {
            $name = \think\Db::name($table)->where('id', (int) $value)->value('name');
        } catch (\Exception $e) {
            $name = null;
        }
        $name = $name ?: (string) $value;
        self::$nameCache[$key] = $name;
        return $name;
    }

    public function getCategoryTextAttr($value, $data)
    {
        return self::resolveName('category', isset($data['category']) ? $data['category'] : '');
    }

    public function getStorageTextAttr($value, $data)
    {
        return self::resolveName('storage', isset($data['storage']) ? $data['storage'] : '');
    }

    public function getLocationTextAttr($value, $data)
    {
        return self::resolveName('location', isset($data['location']) ? $data['location'] : '');
    }

    public function getUnitTextAttr($value, $data)
    {
        return self::resolveName('unit', isset($data['unit']) ? $data['unit'] : '');
    }

    public function getStatusTextAttr($value, $data)
    {
        $map = [0 => '正常', 1 => '废弃'];
        return isset($map[$data['status']]) ? $map[$data['status']] : '未知';
    }

    /**
     * 库存金额（数量 * 单价）
     */
    public function getAmountAttr($value, $data)
    {
        $price = isset($data['price']) ? (float) $data['price'] : 0;
        $num = isset($data['num']) ? (float) $data['num'] : 0;
        return round($num * $price, 2);
    }

    /**
     * 是否低于预警下限
     */
    public function getIsLowAttr($value, $data)
    {
        $min = isset($data['min_num']) ? (int) $data['min_num'] : 0;
        $num = isset($data['num']) ? (int) $data['num'] : 0;
        return ($min > 0 && $num < $min) ? 1 : 0;
    }
}
