# WMS 仓库管理系统（去条码 / Docker 化 / 修复版）

基于 [chenbool/wms](https://github.com/chenbool/wms) 二次开发：ThinkPHP 5.0.24 + MySQL/MariaDB，前端 Bootstrap + jQuery + Avalon。

这个版本的定位很明确：**给不需要扫码枪、想直接在自己 NAS 上跑起来的中小仓库用**。

## 主要变化

| 方向 | 内容 |
|---|---|
| 去扫码 | 删除 `extend/Barcode.php`、`Instorage::barcode()`、`Outstorage::barcode()`，寄件页与打印模板里的条码图改显示单号；系统不再依赖扫码枪 |
| 业务正确性 | 出入库全事务落库；出库前校验库存；删单据按明细冲销库存并记流水；单号留空自动生成 |
| 新增能力 | 运营看板、库存台账、库存预警（每个产品可设预警下限） |
| 安全 | 密码从 md5 升级到 `password_hash`（老密码登录时自动升级）、写操作 CSRF 校验、关闭调试与 trace、HttpOnly Cookie、登录失败 5 次锁 10 分钟、禁止删除超级管理员 |
| 兼容性 | PHPExcel 全面适配 PHP 8（220+ 处花括号下标）、控制器直接返回数组不再 500、MariaDB 兼容 |
| 部署 | 自带 `Dockerfile` + `docker-compose.yml`，首次启动自动建库/导基线/跑迁移；飞牛 NAS 一键安装，详见 [docs/部署到飞牛NAS.md](docs/部署到飞牛NAS.md) |

详细清单见 [docs/改造说明.md](docs/改造说明.md)。

## 快速开始（Docker）

```bash
docker compose up -d --build
# 打开 http://服务器地址:8082
```

飞牛 NAS 图形界面步骤：见 [docs/部署到飞牛NAS.md](docs/部署到飞牛NAS.md)。
不用 Docker 的手动部署：见 [install.md](install.md)。

默认账号（演示数据）：`bool / admin`（超级管理员）、`admin / admin`（仓库管理角色）。**登录后第一件事改密码。**

## 目录结构

```
app/            控制器、服务层、模型、视图（单模块）
  controller/   Base（鉴权 + CSRF）、Index（看板）、Product、Instorage、Outstorage、Stock、User、Role ...
  service/      StockService（库存核心/流水/预警/看板）、Instorage/Outstorage/Product/Login/Csrf ...
  view/         index(看板)、stock(台账/预警)、instorage/outstorage(单据与打印模板) ...
bin/migrate.php 幂等数据库升级脚本（容器启动时自动执行）
docker/         容器用的 php.ini、Apache vhost、entrypoint.sh
docs/           部署与改造说明
extend/Classes  PHPExcel（已适配 PHP 8）
public/         Web 根目录（index.php、static、uploads）
thinkphp/       框架（5.0.24，仅有 Response 一处小补丁）
wms.sql         基线结构与演示数据
```

## 环境要求

- PHP ≥ 8.0（推荐 8.2/8.3），扩展：`pdo_mysql`、`mysqli`、`mbstring`、`gd`、`zip`
- MySQL 5.7+ / MariaDB 10.3+
- 站点根目录指向 `public/`，Apache 需 `mod_rewrite` + `AllowOverride All`

## 数据库升级

```bash
DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME=wms DB_USER=root DB_PASS=*** php bin/migrate.php
```

脚本幂等：补 `w_product.min_num`、建 `w_stock_log`、扩 `w_user.password`、新增「库存台账/库存预警」菜单并授权、可选重置管理员密码。

## 开发约定

见 [开发说明.md](开发说明.md)。新增写操作请走 `Base` 的 CSRF 校验，改库存一律通过 `app\service\StockService`，避免出现账实不符。
