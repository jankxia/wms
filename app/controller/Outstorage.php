<?php
namespace app\controller;

use app\model\Order;
use app\model\Product;
use app\model\Supplier;
use app\service\OutstorageService;

class Outstorage extends Base
{
    protected $service;

    public function __construct(OutstorageService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    public function index()
    {
        $this->assign(['list' => $this->service->page()]);
        return view();
    }

    public function create()
    {
        $this->assign([
            'product'  => Product::all(['status' => 0]),
            'supplier' => Supplier::all(['status' => 0]),
        ]);
        return view();
    }

    public function show($id)
    {
        $list = $this->service->getAllList($id);
        list($totalNum, $totalMoney) = $this->service->totals($list);

        $this->assign([
            'info'        => Order::get(['id' => $id]),
            'list'        => $list,
            'total_num'   => $totalNum,
            'total_money' => $totalMoney,
        ]);
        return view();
    }

    public function save()
    {
        return json($this->service->save());
    }

    public function delete($id)
    {
        return json($this->service->delete($id));
    }

    public function prints($id)
    {
        $list = $this->service->getAllList($id);
        list($totalNum, $totalMoney) = $this->service->totals($list);

        $this->assign([
            'info'        => Order::get($id),
            'list'        => $list,
            'total_num'   => $totalNum,
            'total_money' => $totalMoney,
        ]);
        return view();
    }
}
