<?php
/**
 * 数据库配置
 *
 * 支持通过环境变量覆盖（Docker / 飞牛 NAS 部署时由 compose 注入）：
 *   DB_HOST  数据库主机，默认 localhost
 *   DB_PORT  数据库端口，默认 3306
 *   DB_NAME  数据库名，默认 wms
 *   DB_USER  用户名，默认 wms
 *   DB_PASS  密码，默认 wms123456
 */

if (!function_exists('wms_env')) {
    function wms_env($key, $default = '')
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }
        return $value;
    }
}

return [
    'type'            => 'mysql',
    'hostname'        => wms_env('DB_HOST', 'localhost'),
    'database'        => wms_env('DB_NAME', 'wms'),
    'username'        => wms_env('DB_USER', 'wms'),
    'password'        => wms_env('DB_PASS', 'wms123456'),
    'hostport'        => wms_env('DB_PORT', '3306'),
    'dsn'             => '',
    'params'          => [],
    'charset'         => 'utf8mb4',
    'prefix'          => 'w_',
    'debug'           => false,
    'deploy'          => 0,
    'rw_separate'     => false,
    'master_num'      => 1,
    'slave_no'        => '',
    'fields_strict'   => true,
    'resultset_type'  => 'array',
    'auto_timestamp'  => false,
    'datetime_format' => 'Y-m-d H:i:s',
    'sql_explain'     => false,
    'break_reconnect' => true,
];
