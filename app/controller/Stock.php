<?php
namespace app\controller;

use app\service\StockService;

class Stock extends Base
{
    protected $service;

    public function __construct(StockService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    /**
     * 库存流水台账
     */
    public function index()
    {
        $this->assign([
            'list'  => $this->service->page(),
            'types' => StockService::typeMap(),
        ]);
        return view('index');
    }

    /**
     * 库存预警
     */
    public function warning()
    {
        $this->assign([
            'list' => $this->service->warningList(true),
        ]);
        return view('warning');
    }
}
