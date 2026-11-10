<?php
/**
 * ============================================================================
 * WMS 系统安装程序
 * ============================================================================
 * 
 * 功能说明：
 * 1. 自动创建数据库并导入初始数据
 * 2. 自动配置数据库连接信息
 * 3. 自动配置系统路径
 * 
 * 使用方式：
 * 在浏览器中访问此文件，填写数据库连接信息后点击安装
 * 
 * 安全提示：
 * - 安装完成后请立即删除此文件
 * - 或将其重命名为非公开访问的名称
 * ============================================================================
 */

// 安全开关：默认关闭网页安装向导。
// 容器部署与 bin/migrate.php 已经覆盖了建库/导入/升级流程，这里只是保留原作者的手动安装入口。
// 确实需要用网页向导时，通过环境变量 WMS_ALLOW_INSTALL=1 打开（装完请立刻关闭）。
if (getenv('WMS_ALLOW_INSTALL') !== '1') {
    http_response_code(404);
    exit('Not Found');
}

// 定义静态资源路径常量
// 根据当前文件位置计算相对路径
$staticPath = './static';

// 防止重复安装的简单检查（可选）
if (file_exists('../app/install.lock')) {
    exit('<center style="margin-top:150px;"><h1>系统已安装</h1><p>如需重新安装，请先删除 app/install.lock 文件</p></center>');
}

// ==================== 处理安装表单提交 ====================
if (isset($_POST['host']) && isset($_POST['username']) && isset($_POST['password']) && isset($_POST['db'])) {
    
    // 接收并清理表单数据
    $dbHost     = trim($_POST['host']);     // 数据库主机地址
    $dbUsername = trim($_POST['username']); // 数据库用户名
    $dbPassword = $_POST['password'];       // 数据库密码
    $dbName     = trim($_POST['db']);       // 数据库名称
    
    // -------------------- 1. 连接数据库 --------------------
    $mysqli = new mysqli($dbHost, $dbUsername, $dbPassword);
    
    // 检查数据库连接是否成功
    if ($mysqli->connect_errno) {
        exit('<center style="margin-top:150px;"><h1>连接数据库失败</h1><p>错误信息：' . $mysqli->connect_error . '</p><a href="javascript:history.back()">返回</a></center>');
    }
    
    // -------------------- 2. 创建数据库 --------------------
    // 使用 utf8mb4 字符集创建数据库，支持完整的 Unicode 字符（包括 emoji）
    $createDbSql = "CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
    if (!$mysqli->query($createDbSql)) {
        exit('<center style="margin-top:150px;"><h1>创建数据库失败</h1><p>错误信息：' . $mysqli->error . '</p><a href="javascript:history.back()">返回</a></center>');
    }
    
    // 切换到新创建的数据库
    $mysqli->select_db($dbName);
    
    // -------------------- 3. 导入 SQL 文件 --------------------
    // 读取 SQL 文件内容（包含数据库表结构和初始数据）
    // SQL 文件位于上级目录
    $sqlFilePath = '../wms.sql';
    if (!file_exists($sqlFilePath)) {
        exit('<center style="margin-top:150px;"><h1>SQL 文件不存在</h1><p>请确保 wms.sql 文件存在于项目根目录</p></center>');
    }
    
    $sqlContent = file_get_contents($sqlFilePath);
    // 按分号分割 SQL 语句
    $sqlStatements = explode(';', $sqlContent);
    
    // 逐条执行 SQL 语句
    foreach ($sqlStatements as $sql) {
        $sql = trim($sql);
        if (!empty($sql)) {
            if (!$mysqli->query($sql)) {
                // SQL 执行失败时显示错误信息
                echo '<p style="color:red;">SQL 执行失败: ' . $mysqli->error . '</p>';
            }
        }
    }
    
    // 关闭数据库连接
    $mysqli->close();
    
    // -------------------- 4. 配置系统路径 --------------------
    // 读取配置文件模板
    $configPath = '../app/config.php';
    if (file_exists($configPath)) {
        $configContent = file_get_contents($configPath);
        
        // 获取当前脚本所在的目录路径
        $dirRoot = dirname($_SERVER['SCRIPT_NAME']);
        
        // 如果项目位于根目录，需要调整路径配置
        if ($dirRoot === '\\' || $dirRoot === '/') {
            // 移除配置中的 dirname($_SERVER['SCRIPT_NAME']). 部分
            $configContent = str_replace("dirname(\$_SERVER['SCRIPT_NAME']).", "", $configContent);
            file_put_contents($configPath, $configContent);
        }
    }
    
    // -------------------- 5. 配置数据库连接 --------------------
    // 读取现有的数据库配置
    $dbConfigPath = '../app/database.php';
    $dbConfig = file_exists($dbConfigPath) ? include $dbConfigPath : [];
    
    // 更新数据库连接信息
    $dbConfig['hostname'] = $dbHost;
    $dbConfig['database'] = $dbName;
    $dbConfig['username'] = $dbUsername;
    $dbConfig['password'] = $dbPassword;
    
    // 将配置写入文件（使用 var_export 生成合法的 PHP 数组代码）
    $configCode = "<?php\n/**\n * 数据库配置文件\n * 由安装程序自动生成\n */\nreturn " . var_export($dbConfig, true) . ";\n";
    file_put_contents($dbConfigPath, $configCode);
    
    // -------------------- 6. 创建安装锁定文件 --------------------
    // 标记系统已安装，防止重复执行安装程序
    file_put_contents('../app/install.lock', date('Y-m-d H:i:s'));
    
    // -------------------- 7. 安装完成提示 --------------------
    // 使用与系统一致的样式显示成功信息
    echo '<!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>安装成功 - WMS 系统</title>
        <link rel="stylesheet" href="' . $staticPath . '/css/fonts/linecons/css/linecons.css">
        <link rel="stylesheet" href="' . $staticPath . '/css/fonts/fontawesome/css/font-awesome.min.css">
        <link rel="stylesheet" href="' . $staticPath . '/css/bootstrap.css">
        <link rel="stylesheet" href="' . $staticPath . '/css/xenon-core.css">
        <link rel="stylesheet" href="' . $staticPath . '/css/xenon-forms.css">
        <link rel="stylesheet" href="' . $staticPath . '/css/xenon-components.css">
        <link rel="stylesheet" href="' . $staticPath . '/css/xenon-skins.css">
        <link rel="stylesheet" href="' . $staticPath . '/css/custom.css">
        <style>
            body {
                background: #f0f0f0;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .success-container {
                text-align: center;
                padding: 50px;
            }
            .success-icon {
                font-size: 80px;
                color: #5cb85c;
                margin-bottom: 30px;
            }
            .success-title {
                color: #333;
                font-size: 32px;
                margin-bottom: 20px;
            }
            .success-text {
                color: #666;
                font-size: 16px;
                margin-bottom: 30px;
            }
            .btn-success-custom {
                background: #5cb85c;
                border-color: #4cae4c;
                padding: 12px 40px;
                font-size: 16px;
            }
            .btn-success-custom:hover {
                background: #449d44;
                border-color: #398439;
            }
            .warning-text {
                color: #d9534f;
                margin-top: 30px;
                font-size: 14px;
            }
        </style>
    </head>
    <body>
        <div class="success-container">
            <div class="success-icon">
                <i class="fa fa-check-circle"></i>
            </div>
            <h1 class="success-title">安装成功！</h1>
            <p class="success-text">WMS 系统已成功安装并配置完成</p>
            <a href="./index.php" class="btn btn-success btn-success-custom">
                <i class="fa fa-arrow-right"></i> 立即访问系统
            </a>
            <p class="warning-text">
                <i class="fa fa-exclamation-triangle"></i> 
                重要提示：请立即删除 install.php 文件以保障安全！
            </p>
        </div>
    </body>
    </html>';
    exit;
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="WMS 系统安装程序" />
    <title>系统安装 - WMS</title>

    <!-- 引入项目现有的 Xenon 主题样式 -->
    <link rel="stylesheet" href="<?php echo $staticPath; ?>/css/fonts/linecons/css/linecons.css">
    <link rel="stylesheet" href="<?php echo $staticPath; ?>/css/fonts/fontawesome/css/font-awesome.min.css">
    <link rel="stylesheet" href="<?php echo $staticPath; ?>/css/bootstrap.css">
    <link rel="stylesheet" href="<?php echo $staticPath; ?>/css/xenon-core.css">
    <link rel="stylesheet" href="<?php echo $staticPath; ?>/css/xenon-forms.css">
    <link rel="stylesheet" href="<?php echo $staticPath; ?>/css/xenon-components.css">
    <link rel="stylesheet" href="<?php echo $staticPath; ?>/css/xenon-skins.css">
    <link rel="stylesheet" href="<?php echo $staticPath; ?>/css/custom.css">

    <!-- HTML5 shim and Respond.js IE8 support -->
    <!--[if lt IE 9]>
        <script src="https://oss.maxcdn.com/html5shiv/3.7.2/html5shiv.min.js"></script>
        <script src="https://oss.maxcdn.com/respond/1.4.2/respond.min.js"></script>
    <![endif]-->

    <style>
        /* 安装页面自定义样式 - 紧凑布局，无滚动条 */
        html, body.page-body {
            background: #2c2e2f;
            height: 100%;
            overflow: hidden;
            margin: 0;
            padding: 0;
        }
        
        .install-container {
            max-width: 420px;
            margin: 0 auto;
            padding-top: 5vh;
        }
        
        .install-panel {
            background: #fff;
            border-radius: 5px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        
        .install-header {
            background: #2c2e2f;
            padding: 12px 20px;
            border-bottom: 2px solid #4b7bff;
            display: flex;
            align-items: center;
        }
        
        .install-header .logo {
            display: flex;
            align-items: center;
            text-decoration: none;
            margin-right: 12px;
        }
        
        .install-header .logo img {
            height: 32px;
        }
        
        .install-header .header-content {
            flex: 1;
        }
        
        .install-header h2 {
            color: #fff;
            margin: 0;
            font-size: 16px;
            font-weight: 400;
            line-height: 1.3;
        }
        
        .install-header p {
            color: #999;
            margin: 2px 0 0;
            font-size: 11px;
            line-height: 1.3;
        }
        
        .install-body {
            padding: 15px 20px;
        }
        
        .form-group {
            margin-bottom: 10px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 3px;
            color: #555;
            font-weight: 500;
            font-size: 12px;
        }
        
        .form-group label i {
            width: 14px;
            text-align: center;
        }
        
        .form-group label .required {
            color: #d9534f;
            margin-left: 2px;
        }
        
        .form-control {
            height: 34px;
            border: 1px solid #ddd;
            border-radius: 3px;
            padding: 6px 10px;
            padding-left: 32px;
            font-size: 13px;
            transition: border-color 0.3s, box-shadow 0.3s;
        }
        
        .form-control:focus {
            border-color: #4b7bff;
            box-shadow: 0 0 0 2px rgba(75, 123, 255, 0.1);
        }
        
        .input-icon {
            position: relative;
        }
        
        .input-icon i {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            font-size: 13px;
            z-index: 10;
        }
        
        .install-btn {
            background: #4b7bff;
            border: none;
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 500;
            width: 100%;
            border-radius: 3px;
            transition: background 0.3s;
            height: 38px;
            line-height: 18px;
        }
        
        .install-btn:hover {
            background: #3a6ae6;
        }
        
        .install-btn i {
            margin-right: 6px;
        }
        
        .install-footer {
            background: #f9f9f9;
            padding: 10px 20px;
            border-top: 1px solid #eee;
            text-align: center;
        }
        
        .install-footer p {
            margin: 0;
            color: #888;
            font-size: 11px;
            line-height: 1.5;
        }
        
        .install-footer .warning {
            color: #d9534f;
            margin-top: 3px;
        }
        
        /* 步骤指示器 */
        .steps-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 12px;
        }
        
        .step {
            display: flex;
            align-items: center;
            color: #999;
            font-size: 12px;
        }
        
        .step-number {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #eee;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 6px;
            font-weight: bold;
            font-size: 11px;
        }
        
        .step.active {
            color: #4b7bff;
        }
        
        .step.active .step-number {
            background: #4b7bff;
            color: #fff;
        }
        
        .step-arrow {
            margin: 0 10px;
            color: #ccc;
            font-size: 12px;
        }
    </style>
</head>
<body class="page-body">

    <div class="install-container">
        <!-- 步骤指示器 -->
        <div class="steps-indicator">
            <div class="step active">
                <div class="step-number">1</div>
                <span>配置数据库</span>
            </div>
            <div class="step-arrow"><i class="fa fa-angle-right"></i></div>
            <div class="step">
                <div class="step-number">2</div>
                <span>完成安装</span>
            </div>
        </div>

        <div class="install-panel">
            <!-- 头部 -->
            <div class="install-header">
                <a href="#" class="logo">
                    <img src="<?php echo $staticPath; ?>/images/logo@2x.png" alt="WMS" />
                </a>
                <div class="header-content">
                    <h2>系统安装</h2>
                    <p>请填写数据库连接信息以完成安装</p>
                </div>
            </div>

            <!-- 表单主体 -->
            <div class="install-body">
                <form method="post" role="form" id="install-form">
                    <!-- 数据库主机 -->
                    <div class="form-group">
                        <label for="host">
                            <i class="fa fa-server"></i> 数据库主机
                            <span class="required">*</span>
                        </label>
                        <div class="input-icon">
                            <i class="fa fa-database"></i>
                            <input type="text" class="form-control" id="host" name="host" 
                                   value="localhost" placeholder="如：localhost 或 127.0.0.1" required>
                        </div>
                    </div>

                    <!-- 数据库账号 -->
                    <div class="form-group">
                        <label for="username">
                            <i class="fa fa-user"></i> 数据库账号
                            <span class="required">*</span>
                        </label>
                        <div class="input-icon">
                            <i class="fa fa-user-circle"></i>
                            <input type="text" class="form-control" id="username" name="username" 
                                   value="root" placeholder="数据库用户名" required>
                        </div>
                    </div>

                    <!-- 数据库密码 -->
                    <div class="form-group">
                        <label for="password">
                            <i class="fa fa-lock"></i> 数据库密码
                        </label>
                        <div class="input-icon">
                            <i class="fa fa-key"></i>
                            <input type="password" class="form-control" id="password" name="password" 
                                   placeholder="数据库密码（可为空）">
                        </div>
                    </div>

                    <!-- 数据库名称 -->
                    <div class="form-group">
                        <label for="db">
                            <i class="fa fa-folder"></i> 数据库名称
                            <span class="required">*</span>
                        </label>
                        <div class="input-icon">
                            <i class="fa fa-hdd-o"></i>
                            <input type="text" class="form-control" id="db" name="db" 
                                   value="wms_db" placeholder="要创建的数据库名称" required>
                        </div>
                    </div>

                    <!-- 提交按钮 -->
                    <div class="form-group" style="margin-bottom: 0; margin-top: 12px;">
                        <button type="submit" class="btn btn-primary install-btn">
                            <i class="fa fa-cog"></i> 开始安装
                        </button>
                    </div>
                </form>
            </div>

            <!-- 底部提示 -->
            <div class="install-footer">
                <p><i class="fa fa-info-circle"></i> 请确保 MySQL 服务已启动，且当前用户具有创建数据库的权限</p>
                <p class="warning"><i class="fa fa-exclamation-triangle"></i> 安装完成后请删除此文件以保障安全</p>
            </div>
        </div>
    </div>

    <!-- 引入项目现有的 JS 文件 -->
    <script src="<?php echo $staticPath; ?>/js/jquery-1.11.1.min.js"></script>
    <script src="<?php echo $staticPath; ?>/js/bootstrap.min.js"></script>
    <script src="<?php echo $staticPath; ?>/js/jquery-validate/jquery.validate.min.js"></script>
    <script src="<?php echo $staticPath; ?>/js/toastr/toastr.min.js"></script>

    <script>
        jQuery(document).ready(function($) {
            // 初始化表单验证
            $("#install-form").validate({
                rules: {
                    host: {
                        required: true
                    },
                    username: {
                        required: true
                    },
                    db: {
                        required: true
                    }
                },
                messages: {
                    host: {
                        required: "请输入数据库主机地址"
                    },
                    username: {
                        required: "请输入数据库账号"
                    },
                    db: {
                        required: "请输入数据库名称"
                    }
                },
                errorElement: 'span',
                errorClass: 'help-block',
                highlight: function(element) {
                    $(element).closest('.form-group').addClass('has-error');
                },
                success: function(label, element) {
                    $(element).closest('.form-group').removeClass('has-error');
                },
                errorPlacement: function(error, element) {
                    error.insertAfter(element.closest('.input-icon'));
                }
            });

            // 提交时显示加载状态
            $("#install-form").on('submit', function() {
                if ($(this).valid()) {
                    var $btn = $(".install-btn");
                    $btn.html('<i class="fa fa-spinner fa-spin"></i> 安装中，请稍候...');
                    $btn.prop('disabled', true);
                }
            });
        });
    </script>

</body>
</html>
