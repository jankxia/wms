<?php
namespace app\service;

use app\model\User;
use think\Session;

class LoginService
{
    /**
     * 登录验证
     * 兼容历史 md5 密码，登录成功后自动升级为 password_hash（需 password 字段长度 >= 255）
     */
    public function login()
    {
        if (!request()->isPost()) {
            return ['error' => 100, 'msg' => '非法请求'];
        }

        $param    = request()->param();
        $username = trim(isset($param['username']) ? $param['username'] : '');
        $password = (string) (isset($param['password']) ? $param['password'] : '');

        if ($username === '' || $password === '') {
            return ['error' => 100, 'msg' => '用户名和密码不能为空'];
        }

        // 简单的登录失败次数限制，5 次失败锁定 10 分钟
        $fail = Session::get('login_fail', 'think');
        if (is_array($fail) && isset($fail['count'], $fail['time'])
            && $fail['count'] >= 5 && time() - $fail['time'] < 600) {
            return ['error' => 100, 'msg' => '密码错误次数过多，请 10 分钟后再试'];
        }

        $user = User::get(['username' => $username]);
        if (is_null($user)) {
            $this->_fail();
            return ['error' => 100, 'msg' => '用户名或者密码错误'];
        }

        // 已废弃的用户不允许登录
        if ((int) $user->status !== 0) {
            return ['error' => 100, 'msg' => '该账号已被停用，请联系管理员'];
        }

        $stored = (string) $user->password;
        $ok     = false;
        $legacy = false;

        if (strlen($stored) === 32 && ctype_xdigit($stored)) {
            // 历史 md5 密码
            $ok     = hash_equals($stored, md5($password));
            $legacy = true;
        } else {
            $ok = password_verify($password, $stored);
        }

        if (!$ok) {
            $this->_fail();
            return ['error' => 100, 'msg' => '用户名或者密码错误'];
        }

        // 历史 md5 密码登录成功后升级为 password_hash
        if ($legacy && strlen($stored) < 255) {
            try {
                $user->password = password_hash($password, PASSWORD_DEFAULT);
                $user->save();
            } catch (\Exception $e) {
                // 字段长度不足等情况忽略，不影响登录
            }
        }

        Session::delete('login_fail', 'think');
        Session::set('uid', $user->id, 'think');
        Session::set('company', $user->company, 'think');
        Session::set('username', $user->username, 'think');

        return ['error' => 0, 'msg' => '登录成功'];
    }

    private function _fail()
    {
        $fail = Session::get('login_fail', 'think');
        $count = (is_array($fail) && isset($fail['count'])) ? (int) $fail['count'] : 0;
        Session::set('login_fail', ['count' => $count + 1, 'time' => time()], 'think');
    }

    /**
     * 注销
     */
    public function quit()
    {
        Session::delete('uid', 'think');
        Session::delete('company', 'think');
        Session::delete('username', 'think');
        Session::delete('user_menu');
        Session::delete('role_permission');
        Session::clear('think');
    }
}
