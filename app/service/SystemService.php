<?php
namespace app\service;

class SystemService
{
    /**
     * 获取系统信息
     * @return array
     */
    public function getSystemInfo()
    {
        return [
            'os' => PHP_OS,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'server_time' => date('Y-m-d H:i:s'),
            'timezone' => date_default_timezone_get(),
            'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown',
            'script_name' => $_SERVER['SCRIPT_NAME'] ?? 'Unknown',
            'php_ini' => get_cfg_var('cfg_file_path') ?: 'Unknown',
            'system_time' => date('Y-m-d H:i:s'),
            'locale' => setlocale(LC_ALL, '0') ?: 'Unknown',
        ];
    }

    /**
     * 获取 PHP 信息
     * @return array
     */
    public function getPhpInfo()
    {
        return [
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'memory_limit' => ini_get('memory_limit'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'max_execution_time' => ini_get('max_execution_time') . '秒',
            'max_input_time' => ini_get('max_input_time') . '秒',
            'zend_version' => zend_version() ?: '-',
            'extensions' => $this->getLoadedExtensions(),
        ];
    }

    /**
     * 获取已加载的扩展
     * @return array
     */
    private function getLoadedExtensions()
    {
        $extensions = get_loaded_extensions();
        $important = [
            'Core', 'date', 'libxml', 'openssl', 'pcre', 'zlib', 'filter',
            'hash', 'json', 'mbstring', 'mysql', 'mysqli', 'pdo', 'pdo_mysql',
            'session', 'SPL', 'standard', 'tokenizer', 'xml', 'curl', 'gd'
        ];
        
        $result = [];
        foreach ($important as $ext) {
            $result[$ext] = in_array($ext, $extensions);
        }
        
        return $result;
    }

    /**
     * 获取数据库信息
     * @return array
     */
    public function getDatabaseInfo()
    {
        try {
            $db = \think\Db::connect();
            
            // 获取 MySQL 版本
            $versionResult = $db->query('SELECT VERSION() as ver, @@version_comment as comment');
            $version = $versionResult[0]['ver'] ?? $versionResult[0][0] ?? '-';
            $versionComment = $versionResult[0]['comment'] ?? $versionResult[0][1] ?? '';
            
            // 获取字符集信息
            $charsetResult = $db->query("SHOW VARIABLES LIKE '%database'");
            $charsets = [];
            foreach ($charsetResult as $row) {
                $key = $row[0] ?? $row['Variable_name'] ?? '';
                $value = $row[1] ?? $row['Value'] ?? '';
                $charsets[$key] = $value;
            }
            
            // 获取存储引擎
            $engineResult = $db->query("SHOW VARIABLES LIKE 'default_storage_engine'");
            $engine = $engineResult[0][1] ?? $engineResult[0]['Value'] ?? '-';
            
            // 获取支持的引擎
            $enginesResult = $db->query("SHOW ENGINES");
            $supportedEngines = [];
            foreach ($enginesResult as $row) {
                $engineName = $row[0] ?? $row['Engine'] ?? '';
                $support = $row[1] ?? $row['Support'] ?? '';
                if ($support === 'DEFAULT' || $support === 'YES') {
                    $supportedEngines[] = $engineName . ($support === 'DEFAULT' ? ' (默认)' : '');
                }
            }
            
            // 获取最大连接数
            $maxConnections = $db->query("SHOW VARIABLES LIKE 'max_connections'");
            $maxConnections = $maxConnections[0][1] ?? $maxConnections[0]['Value'] ?? '-';
            
            // 获取当前连接数
            $threadsConnected = $db->query("SHOW STATUS LIKE 'Threads_connected'");
            $threadsConnected = $threadsConnected[0][1] ?? $threadsConnected[0]['Value'] ?? '-';
            
            // 获取数据库大小
            $database = config('database.database');
            $sizeResult = $db->query("SELECT 
                ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb,
                ROUND(SUM(data_length) / 1024 / 1024, 2) as data_mb,
                ROUND(SUM(index_length) / 1024 / 1024, 2) as index_mb
                FROM information_schema.tables 
                WHERE table_schema = '{$database}'");
            
            $dbSize = $sizeResult[0]['size_mb'] ?? $sizeResult[0][0] ?? '0';
            $dataSize = $sizeResult[0]['data_mb'] ?? $sizeResult[0][1] ?? '0';
            $indexSize = $sizeResult[0]['index_mb'] ?? $sizeResult[0][2] ?? '0';
            
            // 获取表数量
            $tableCount = $db->query("SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = '{$database}'");
            $tableCount = $tableCount[0]['count'] ?? $tableCount[0][0] ?? '0';
            
            return [
                'status' => '正常',
                'version' => $version . ($versionComment ? ' (' . $versionComment . ')' : ''),
                'database' => $database,
                'hostname' => config('database.hostname'),
                'hostport' => config('database.hostport'),
                'charset' => $charsets['character_set_database'] ?? '-',
                'collation' => $charsets['collation_database'] ?? '-',
                'engine' => $engine,
                'supported_engines' => implode(', ', $supportedEngines),
                'max_connections' => $maxConnections,
                'current_connections' => $threadsConnected,
                'database_size' => $dbSize . ' MB',
                'data_size' => $dataSize . ' MB',
                'index_size' => $indexSize . ' MB',
                'table_count' => $tableCount,
            ];
        } catch (\Exception $e) {
            return [
                'status' => '异常',
                'message' => $e->getMessage(),
                'version' => '-',
                'database' => config('database.database'),
                'hostname' => config('database.hostname'),
                'hostport' => config('database.hostport'),
                'charset' => '-',
                'collation' => '-',
                'engine' => '-',
                'supported_engines' => '-',
                'max_connections' => '-',
                'current_connections' => '-',
                'database_size' => '-',
                'data_size' => '-',
                'index_size' => '-',
                'table_count' => '-',
            ];
        }
    }

    /**
     * 获取应用信息
     * @return array
     */
    public function getAppInfo()
    {
        return [
            'app_name' => '仓库管理系统',
            'version' => 'beta',
            'thinkphp_version' => THINK_VERSION,
            'app_path' => APP_PATH,
            'runtime_path' => RUNTIME_PATH,
        ];
    }
}
