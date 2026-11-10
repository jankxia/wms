<?php
/**
 * 数据库升级脚本（幂等，可重复执行）
 *
 * 用法：php bin/migrate.php
 * 环境变量：DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASS，另有可选的
 *          WMS_ADMIN_USER / WMS_ADMIN_PASSWORD（用于重置超级管理员账号密码）
 *
 * 1.6.x 相对原版的变化：
 *   - w_product 增加 min_num 库存预警下限
 *   - 新增 w_stock_log 库存流水表
 *   - w_user.password 加长到 varchar(255)，支持 password_hash
 *   - 新增“库存预警 / 库存台账”菜单并授权给仓库管理角色
 */

if (PHP_SAPI !== 'cli') {
    die("只能在命令行执行\n");
}

function env_or($key, $default)
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

$host = env_or('DB_HOST', 'localhost');
$port = env_or('DB_PORT', '3306');
$name = env_or('DB_NAME', 'wms');
$user = env_or('DB_USER', 'wms');
$pass = env_or('DB_PASS', 'wms123456');

$dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "[migrate] 数据库连接失败: " . $e->getMessage() . "\n");
    exit(1);
}

echo "[migrate] 已连接 {$host}:{$port}/{$name}\n";

function table_exists(PDO $pdo, $table)
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function column_exists(PDO $pdo, $table, $column)
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function column_length(PDO $pdo, $table, $column)
{
    $stmt = $pdo->prepare('SELECT character_maximum_length FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn();
}

if (!table_exists($pdo, 'w_product')) {
    fwrite(STDERR, "[migrate] 未找到 w_product 表，请先导入 wms.sql\n");
    exit(1);
}

// 1. 库存预警下限
if (!column_exists($pdo, 'w_product', 'min_num')) {
    $pdo->exec('ALTER TABLE w_product ADD COLUMN min_num INT UNSIGNED NOT NULL DEFAULT 0 COMMENT "库存预警下限，0 表示不预警" AFTER num');
    echo "[migrate] w_product.min_num 已添加\n";
} else {
    echo "[migrate] w_product.min_num 已存在，跳过\n";
}

// 2. 库存流水表
if (!table_exists($pdo, 'w_stock_log')) {
    $pdo->exec('CREATE TABLE w_stock_log (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        type TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT "1入库 2出库 3入库冲销 4出库冲销",
        product_id INT UNSIGNED NOT NULL DEFAULT 0,
        product_sn VARCHAR(40) NOT NULL DEFAULT "",
        product_name VARCHAR(40) NOT NULL DEFAULT "",
        order_id INT UNSIGNED NOT NULL DEFAULT 0,
        order_sn VARCHAR(40) NOT NULL DEFAULT "",
        num INT NOT NULL DEFAULT 0,
        before_num INT NOT NULL DEFAULT 0,
        after_num INT NOT NULL DEFAULT 0,
        operator VARCHAR(32) NOT NULL DEFAULT "",
        remark VARCHAR(255) NOT NULL DEFAULT "",
        add_time INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY idx_product (product_id),
        KEY idx_order (order_id),
        KEY idx_add_time (add_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT="库存流水台账"');
    echo "[migrate] w_stock_log 已创建\n";
} else {
    echo "[migrate] w_stock_log 已存在，跳过\n";
}

// 3. 密码字段加长，兼容 password_hash（60 字节）
if (column_length($pdo, 'w_user', 'password') < 255) {
    $pdo->exec('ALTER TABLE w_user MODIFY COLUMN password VARCHAR(255) NOT NULL DEFAULT ""');
    echo "[migrate] w_user.password 已扩展到 varchar(255)\n";
} else {
    echo "[migrate] w_user.password 长度已足够，跳过\n";
}

// 4. 新菜单：库存台账 / 库存预警
$menus = [
    ['id' => 30, 'pid' => 4, 'name' => '库存台账', 'controller' => 'Stock', 'action' => 'index'],
    ['id' => 31, 'pid' => 4, 'name' => '库存预警', 'controller' => 'Stock', 'action' => 'warning'],
];

foreach ($menus as $menu) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM w_menu WHERE id = ?');
    $stmt->execute([$menu['id']]);
    if ((int) $stmt->fetchColumn() > 0) {
        echo "[migrate] 菜单 {$menu['name']}(id={$menu['id']}) 已存在，跳过\n";
        continue;
    }
    $ins = $pdo->prepare('INSERT INTO w_menu (id, pid, name, ico, level, controller, action, status, add_time) VALUES (?,?,?,NULL,1,?,?,0,?)');
    $ins->execute([$menu['id'], $menu['pid'], $menu['name'], $menu['controller'], $menu['action'], time()]);
    echo "[migrate] 菜单 {$menu['name']} 已添加\n";
}

// 5. 给已包含“仓库作业”的角色补授权
$roles = $pdo->query('SELECT id, ids FROM w_role')->fetchAll();
foreach ($roles as $role) {
    $ids = array_filter(array_map('trim', explode(',', (string) $role['ids'])));
    if (!in_array('4', $ids, true)) {
        continue;
    }
    $changed = false;
    foreach (['30', '31'] as $menuId) {
        if (!in_array($menuId, $ids, true)) {
            $ids[]   = $menuId;
            $changed = true;
        }
    }
    if ($changed) {
        $upd = $pdo->prepare('UPDATE w_role SET ids = ? WHERE id = ?');
        $upd->execute([implode(',', $ids), $role['id']]);
        echo "[migrate] 角色 id={$role['id']} 已补充库存台账/预警权限\n";
    }
}

// 6. 可选：重置管理员密码（首次部署用 WMS_ADMIN_PASSWORD 指定）
$adminUser = env_or('WMS_ADMIN_USER', 'admin');
$adminPass = getenv('WMS_ADMIN_PASSWORD');
if ($adminPass !== false && $adminPass !== '') {
    $stmt = $pdo->prepare('SELECT id FROM w_user WHERE username = ?');
    $stmt->execute([$adminUser]);
    $uid = $stmt->fetchColumn();
    $hash = password_hash($adminPass, PASSWORD_DEFAULT);
    if ($uid) {
        $upd = $pdo->prepare('UPDATE w_user SET password = ?, status = 0 WHERE id = ?');
        $upd->execute([$hash, $uid]);
        echo "[migrate] 管理员 {$adminUser} 密码已重置\n";
    } else {
        $ins = $pdo->prepare('INSERT INTO w_user (username, password, truename, role, company, status, add_time) VALUES (?,?,?,1,1,0,?)');
        $ins->execute([$adminUser, $hash, '管理员', time()]);
        echo "[migrate] 管理员 {$adminUser} 已创建\n";
    }
}

echo "[migrate] 完成\n";
