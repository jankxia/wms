<?php
namespace app\controller;

use app\service\StockService;
use app\service\SystemService;

class Index extends Base
{
    public function index()
    {
        $stock = new StockService();
        $this->assign('board', $stock->dashboard());
        return $this->fetch();
    }

    /**
     * 获取系统信息接口（首页底部“系统信息”折叠区使用）
     */
    public function getSystemInfo()
    {
        $service = new SystemService();

        $data = [
            'system'   => $service->getSystemInfo(),
            'php'      => $service->getPhpInfo(),
            'database' => $service->getDatabaseInfo(),
            'app'      => $service->getAppInfo(),
        ];

        return json(['code' => 0, 'data' => $data, 'msg' => 'success']);
    }
}
