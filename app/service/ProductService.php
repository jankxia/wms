<?php
namespace app\service;

use app\model\Category;
use app\model\Customer;
use app\model\Location;
use app\model\OrderList;
use app\model\Product;
use app\model\Productup;
use app\model\Storage;
use app\model\Supplier;
use app\model\Unit;
use think\Db;

class ProductService
{
    public function page()
    {
        $data  = request()->get();
        $where = [];

        (empty($data['status']) || $data['status'] === '') || $where['status'] = (int) $data['status'];
        empty($data['name']) || $where['name'] = ['like', '%' . $data['name'] . '%'];
        empty($data['sn'])   || $where['sn']   = ['like', '%' . $data['sn'] . '%'];
        empty($data['category']) || $where['category'] = $data['category'];
        empty($data['storage'])  || $where['storage']  = $data['storage'];

        if (!empty($data['low'])) {
            $where['min_num'] = ['>', 0];
        }

        return Product::where($where)->order('id desc')->paginate(15);
    }

    public function _init()
    {
        return [
            'unit'     => Unit::all(['status' => 0]),
            'category' => Category::all(['status' => 0]),
            'customer' => Customer::all(['status' => 0]),
            'supplier' => Supplier::all(['status' => 0]),
            'storage'  => Storage::all(['status' => 0]),
            'location' => Location::all(['status' => 0]),
        ];
    }

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

        if (Product::get(['name' => $param['name']])) {
            return ['error' => 100, 'msg' => '产品名称已经存在'];
        }

        Db::startTrans();
        try {
            $product            = new Product();
            $product->sn        = $this->_fill($param, 'sn');
            $product->nbsn      = $this->_fill($param, 'nbsn');
            $product->cjsn      = $this->_fill($param, 'cjsn');
            $product->name      = $this->_fill($param, 'name');
            $product->category  = $this->_fill($param, 'category');
            $product->storage   = $this->_fill($param, 'storage');
            $product->location  = $this->_fill($param, 'location');
            $product->unit      = $this->_fill($param, 'unit');
            $product->supplier  = $this->_fill($param, 'supplier');
            $product->customer  = $this->_fill($param, 'customer');
            $product->spec      = $this->_fill($param, 'spec');
            $product->price     = $this->_price($param);
            $product->desc      = $this->_fill($param, 'desc');
            $product->num       = 0;
            $product->min_num   = $this->_int($param, 'min_num');
            $product->add_time  = time();
            $product->save();

            $productup            = new Productup();
            $productup->gid       = $product->id;
            $productup->storage   = $product->storage;
            $productup->location  = $product->location;
            $productup->desc      = $product->desc;
            $productup->add_time  = time();
            $productup->save();

            Db::commit();
            return ['error' => 0, 'msg' => '保存成功'];
        } catch (\Exception $e) {
            Db::rollback();
            return ['error' => 100, 'msg' => '保存失败：' . $e->getMessage()];
        }
    }

    public function edit($id)
    {
        return Product::get($id);
    }

    public function update()
    {
        if (!request()->isPost()) {
            return ['error' => 100, 'msg' => '非法请求'];
        }
        $param = request()->param();
        $error = $this->_validate($param);
        if (!is_null($error)) {
            return ['error' => 100, 'msg' => $error];
        }

        $product = Product::get($param['id']);
        if (!$product) {
            return ['error' => 100, 'msg' => '产品不存在'];
        }

        $product->sn       = $this->_fill($param, 'sn');
        $product->nbsn     = $this->_fill($param, 'nbsn');
        $product->cjsn     = $this->_fill($param, 'cjsn');
        $product->name     = $this->_fill($param, 'name');
        $product->category = $this->_fill($param, 'category');
        $product->storage  = $this->_fill($param, 'storage');
        $product->location = $this->_fill($param, 'location');
        $product->unit     = $this->_fill($param, 'unit');
        $product->supplier = $this->_fill($param, 'supplier');
        $product->customer = $this->_fill($param, 'customer');
        $product->spec     = $this->_fill($param, 'spec');
        $product->price    = $this->_price($param);
        $product->desc     = $this->_fill($param, 'desc');
        $product->min_num  = $this->_int($param, 'min_num');
        if (isset($param['status'])) {
            $product->status = (int) $param['status'];
        }

        if ($product->save()) {
            return ['error' => 0, 'msg' => '修改成功'];
        }
        return ['error' => 100, 'msg' => '修改失败'];
    }

    /**
     * 删除产品：有库存或已有出入库记录时拒绝删除，避免历史单据取不到产品
     */
    public function delete($id)
    {
        $product = Product::get($id);
        if (!$product) {
            return ['error' => 100, 'msg' => '产品不存在'];
        }
        if ((int) $product->num > 0) {
            return ['error' => 100, 'msg' => '该产品还有 ' . $product->num . ' 库存，请先出库清零，或将状态改为“废弃”'];
        }
        if (OrderList::where('good_id', $id)->count() > 0) {
            return ['error' => 100, 'msg' => '该产品已有出入库单据记录，不能删除；如需停用请把状态改为“废弃”'];
        }

        Db::startTrans();
        try {
            Productup::where('gid', $id)->delete();
            Db::name('product')->where('id', $id)->delete();
            Db::commit();
            return ['error' => 0, 'msg' => '删除成功'];
        } catch (\Exception $e) {
            Db::rollback();
            return ['error' => 100, 'msg' => '删除失败：' . $e->getMessage()];
        }
    }

    private function _fill($param, $key)
    {
        return isset($param[$key]) ? trim((string) $param[$key]) : '';
    }

    private function _price($param)
    {
        $price = isset($param['price']) ? trim((string) $param['price']) : '';
        if ($price === '' || !is_numeric($price) || $price < 0) {
            return 0;
        }
        return round((float) $price, 2);
    }

    private function _int($param, $key)
    {
        $value = isset($param[$key]) ? trim((string) $param[$key]) : '';
        if ($value === '' || !is_numeric($value) || $value < 0) {
            return 0;
        }
        return (int) $value;
    }

    private function _validate($data)
    {
        $validate = validate('ProductValidate');
        if (!$validate->check($data)) {
            return $validate->getError();
        }
        return null;
    }
}
