<?php
namespace app\controller;

use app\service\LoginService;
use think\Controller;
use think\Session;

class Login extends Controller
{
    protected $service;

    public function __construct(LoginService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    public function index()
    {
        // 已登录直接进首页
        if (Session::get('uid', 'think')) {
            return $this->redirect('Index/index');
        }
        $this->assign('csrf_token', \app\service\CsrfService::token());
        return view();
    }

    public function login()
    {
        if (!\app\service\CsrfService::check(request()->param('_token'))) {
            return json(['error' => 100, 'msg' => '页面已过期，请刷新页面后重试']);
        }
        return json($this->service->login());
    }

    public function quit()
    {
        $this->service->quit();
        return $this->redirect('Login/index');
    }
}
