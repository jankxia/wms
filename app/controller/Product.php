<?php
namespace app\controller;

use app\service\ProductService;

class Product extends Base
{
    protected $service;

    public function __construct(ProductService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    public function index()
    {
        $this->assign(['list' => $this->service->page()]);
        return view('index');
    }

    public function lists()
    {
        return $this->index();
    }

    public function create()
    {
        $this->assign($this->service->_init());
        return view();
    }

    public function save()
    {
        return json($this->service->save());
    }

    public function edit($id)
    {
        $this->assign($this->service->_init());
        $this->assign(['info' => $this->service->edit($id)]);
        return view();
    }

    public function update()
    {
        return json($this->service->update());
    }

    public function delete($id)
    {
        return json($this->service->delete($id));
    }
}
