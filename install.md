# WMS 系统安装指南

## 推荐：Docker 一键部署（飞牛 NAS 等）

仓库自带 `Dockerfile` + `docker-compose.yml`，容器内是 PHP 8.2 + Apache，配套 MariaDB 10.11，首次启动自动建库、导入基线表并执行升级脚本。

```bash
docker compose up -d --build
# 浏览器打开 http://服务器地址:80812
```

飞牛 NAS 的图形界面步骤、备份恢复、升级与常见问题见 **[docs/部署到飞牛NAS.md](docs/部署到飞牛NAS.md)**。

## 手动部署（不用 Docker）

### 环境要求

| 项目 | 要求 |
|------|------|
| PHP 版本 | >= 8.0（推荐 8.2 / 8.3） |
| 扩展 | pdo_mysql、mysqli、mbstring、gd、zip、json、openssl |
| 数据库 | MySQL 5.7+ / MariaDB 10.3+ |
| Web 服务器 | Apache（需 `mod_rewrite` 与 `AcceptPathInfo On`）/ Nginx |

### 步骤

1. 建库（字符集必须是 utf8mb4）：

   ```sql
   CREATE DATABASE wms DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
   ```

2. 导入基线结构与演示数据：

   ```bash
   mysql -u root -p wms < wms.sql
   ```

3. 执行升级脚本（幂等，可反复执行；只补 `min_num`、`w_stock_log`、密码字段长度和新菜单）：

   ```bash
   DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME=wms DB_USER=root DB_PASS=你的密码 php bin/migrate.php
   ```

4. 配置数据库：`app/database.php` 读取环境变量，默认值可直接改文件里的 `getenv(...) ?: '默认值'`，也可以通过 Web 服务器（Apache `SetEnv`、Nginx `fastcgi_param`、php-fpm `env[...]`）注入：

   | 环境变量 | 默认 |
   |---|---|
   | DB_HOST | localhost |
   | DB_PORT | 3306 |
   | DB_NAME | wms |
   | DB_USER | wms |
   | DB_PASS | wms123456 |

5. 站点根目录指向 `public/`，并把 `public/.htaccess` 生效（Apache 需 `AllowOverride All`）。

6. 目录权限：`runtime/`、`public/uploads/` 需要可写。

7. 打开 `http://你的域名/login/index`。

### 可选：重置管理员密码

```bash
DB_HOST=... DB_NAME=wms DB_USER=... DB_PASS=... \
WMS_ADMIN_USER=bool WMS_ADMIN_PASSWORD=新密码 php bin/migrate.php
```

## 默认登录账号（来自演示数据）

| 用户名 | 密码 | 说明 |
|---|---|---|
| bool | admin | 超级管理员（id=1，可见全部菜单） |
| admin | admin | 仓库管理角色（只能操作仓库作业相关菜单） |

**首次登录后请立刻在「编辑资料」里修改密码。** 密码以 `password_hash` 存储；旧的 md5 密码会在首次登录成功时自动升级为 `password_hash`。

## 关于 public/install.php

`public/install.php` 是原作者的网页安装向导，会直接改写配置文件，且无鉴权，属于风险入口。仓库内已有 `public/install.lock` 使其无法执行；本版本又加了环境变量开关：

```bash
# 确实需要网页安装向导时（装完立即关闭）
WMS_ALLOW_INSTALL=1
```

日常部署请直接用上面的 Docker 方式或手动步骤，不要开启它。

## 常见问题

| 现象 | 处理 |
|---|---|
| `could not find driver` | 没装/没加载 `pdo_mysql`，检查 `php -m` 与 php.ini |
| 「页面错误！请稍后再试～」 | 看 `runtime/log/年月/日.log`，里面记了真实异常 |
| 登录后提示「没有操作权限」 | 该账号角色的菜单权限不含目标菜单，用超级管理员在「角色管理」里勾选 |
| 页面样式错乱 | 站点根目录没指向 `public/`，或 `static/` 未被正确访问 |
| 时间差 8 小时 | `php.ini` 设 `date.timezone = Asia/Shanghai` |
