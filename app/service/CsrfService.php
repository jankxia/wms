<?php
namespace app\service;

use think\Session;

/**
 * 极简 CSRF 防护：页面下发 token，写操作请求必须带回来
 * 前端统一放在请求头 X-CSRF-TOKEN 里（jQuery ajaxSetup），普通表单放在 _token 字段
 */
class CsrfService
{
    const SESSION_KEY = 'csrf_token';

    public static function token()
    {
        $token = Session::get(self::SESSION_KEY, 'think');
        if (empty($token)) {
            $token = bin2hex(random_bytes(16));
            Session::set(self::SESSION_KEY, $token, 'think');
        }
        return $token;
    }

    /**
     * 校验 token；$given 为空时从请求头 / 参数里取
     */
    public static function check($given = null)
    {
        if ($given === null) {
            $request = request();
            $given   = $request->header('x-csrf-token');
            if (empty($given)) {
                $given = $request->param('_token');
            }
        }
        $token = self::token();
        if (empty($given) || empty($token) || !is_string($given)) {
            return false;
        }
        return hash_equals($token, $given);
    }
}
