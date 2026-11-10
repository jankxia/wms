<?php
namespace app\controller;

use app\model\User;
use app\service\CsrfService;
use app\service\MenuService;
use think\Controller;
use think\exception\HttpResponseException;
use think\Session;

class Base extends Controller
{
    protected $menuList;

    protected function _initialize()
    {
        if (!Session::get('uid', 'think')) {
            return $this->redirect('Login/index');
        }

        // CSRF：所有写操作都要求页面下发的 token
        if (config('csrf_on') && $this->request->isPost() && !CsrfService::check()) {
            throw new HttpResponseException(json([
                'error' => 403,
                'msg'   => '页面已过期，请刷新页面后重试',
            ]));
        }

        $user_info = User::get(['id' => Session::get('uid', 'think')]);
        if (!$user_info) {
            Session::clear('think');
            return $this->redirect('Login/index');
        }
        if ((int) $user_info->status !== 0) {
            Session::clear('think');
            $this->error('该账号已被停用，请联系管理员');
        }

        $service    = new MenuService();
        $role       = $user_info->findRole;
        $this->assign('my_info', $user_info);
        $this->menuList = $service->getMenuShow($user_info);
        $this->assign('_menuList', $this->menuList);
        $this->assign('csrf_token', CsrfService::token());

        $super_admin = config('super_admin');
        if (isset($super_admin) && in_array($user_info->id, $super_admin)) {
            return true;
        }

        if (!$service->checkPermission($role ? $role->ids : '', $this->request->controller(), $this->request->action())) {
            if ($this->request->isAjax() || $this->request->isPost()) {
                throw new HttpResponseException(json(['error' => 101, 'msg' => '没有操作权限！']));
            }
            $this->error('没有操作权限！');
        }
    }
}
