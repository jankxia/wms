# 部署到飞牛 NAS（fnOS）

本仓库自带整套 Docker 化配置，飞牛 NAS 上推荐用「容器管理 → 项目（Compose）」一键起两个容器：`wms-app`（PHP 8.2 + Apache）和 `wms-db`（MariaDB 10.11）。首次启动会自动建库、导入基线表、执行升级脚本，不需要手动装环境。

---

## 一、准备

- 飞牛 NAS 已开启 Docker（应用中心安装「容器管理」/ Docker 服务）。
- 记住自己的数据卷路径，例如 `/vol1/1000/docker`。
- 默认端口 `80812`，如与现有服务冲突，改 compose 里的 `"80812:80"` 左边数字即可。

## 二、方式 A：NAS 本地构建（推荐，不依赖外网镜像仓库）

1. 把本项目整个目录上传到 NAS，例如 `/vol1/1000/docker/wms`。
   - 用「文件管理」上传 zip 后解压，或 SSH 上传。
   - 目录里要能看到 `Dockerfile`、`docker-compose.yml`、`wms.sql`、`app/`。
2. 打开「容器管理 → 项目 → 新建项目」：
   - 项目名称：`wms`
   - 项目路径：`/vol1/1000/docker/wms`
   - Compose 文件：选择该目录下的 `docker-compose.yml`
   - 勾选「构建镜像」，保存并启动。
3. 也可以用 SSH，等价于：

```bash
cd /vol1/1000/docker/wms
docker compose up -d --build
docker compose logs -f app      # 看到「基线数据导入完成」「[migrate] 完成」即正常
```

4. 浏览器打开 `http://NAS地址:80812`。

> NAS 是 ARM 架构（少数型号）也没问题，Dockerfile 基于官方 `php:8.2-apache`，多架构通用；只是本地构建时间会长一些。

## 三、方式 B：拉取已构建好的镜像

如果这个仓库已经跑过 GitHub Actions 构建，镜像地址是：

```
ghcr.io/<你的GitHub用户名>/wms:latest
```

在「容器管理」里导入下面这份精简 compose（不用再构建）：

```yaml
services:
  db:
    image: mariadb:10.11
    container_name: wms-db
    restart: unless-stopped
    environment:
      MARIADB_ROOT_PASSWORD: wms_root_pwd
      MARIADB_DATABASE: wms
      MARIADB_USER: wms
      MARIADB_PASSWORD: wms123456
      TZ: Asia/Shanghai
    command: ["--character-set-server=utf8mb4", "--collation-server=utf8mb4_general_ci", "--default-time-zone=+08:00"]
    volumes:
      - wms_db_data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 10s
      timeout: 5s
      retries: 12
      start_period: 30s

  app:
    image: ghcr.io/<你的GitHub用户名>/wms:latest
    container_name: wms-app
    restart: unless-stopped
    depends_on:
      db:
        condition: service_healthy
    environment:
      DB_HOST: db
      DB_PORT: "3306"
      DB_NAME: wms
      DB_USER: wms
      DB_PASS: wms123456
      APP_DEBUG: "false"
      TZ: Asia/Shanghai
    ports:
      - "80812:80"
    volumes:
      - wms_runtime:/var/www/html/runtime
      - wms_uploads:/var/www/html/public/uploads

volumes:
  wms_db_data:
  wms_runtime:
  wms_uploads:
```

如果 GitHub 包权限是私有，先登录一次：

```bash
echo <你的GitHub_TOKEN> | docker login ghcr.io -u <你的GitHub用户名> --password-stdin
```

## 四、方式 C：导入镜像 tar 包

CI 每次构建会产出一个 `wms-image-amd64.tar.gz`（GitHub Actions → Artifacts）。下载后：

```bash
gunzip -c wms-image-amd64.tar.gz | docker load     # 得到 wms:latest
```

然后在「容器管理」里用上面方式 B 的 compose，把 `image:` 换成 `wms:latest` 并去掉 `build:` 即可。

## 五、首次登录与初始化

- 系统自带演示数据，默认超级管理员：用户名 `bool`，密码 `admin`（`admin` 账号同密码，权限为仓库管理角色）。
- **第一件事就是改密码**：登录后右上角头像 → 编辑资料 → 修改密码；或在 compose 中给 `app` 加上 `WMS_ADMIN_PASSWORD: 你的新密码` 并重启一次，启动脚本会把该账号密码重置（`WMS_ADMIN_USER` 默认 `bool`，注意与默认账号一致）。
- 演示数据里带着示例产品、供应商、出入库单据，正式使用前可在系统里删掉，或者把示例单据先删干净再录自己的产品。

登录后你会看到「运营看板」：库存总量、库存金额、今日出入库、预警数、最近单据、低库存 TOP 榜。

## 六、数据、备份与升级

数据全部落在两个命名卷上：

| 卷 | 内容 |
|---|---|
| `wms_db_data` | 全部业务数据（MariaDB） |
| `wms_runtime` | 框架缓存/日志 |
| `wms_uploads` | 上传文件 |

备份（导出 SQL）：

```bash
docker exec wms-db sh -c 'MYSQL_PWD=$MARIADB_PASSWORD mariadb-dump -u$MARIADB_USER $MARIADB_DATABASE' > wms-backup-$(date +%F).sql
```

恢复：

```bash
cat wms-backup-2026-11-10.sql | docker exec -i wms-db sh -c 'MYSQL_PWD=$MARIADB_PASSWORD mariadb -u$MARIADB_USER $MARIADB_DATABASE'
```

升级代码后重建并重启（数据卷不受影响，启动时会自动跑 `bin/migrate.php`）：

```bash
cd /vol1/1000/docker/wms
git pull           # 或重新上传代码
docker compose up -d --build
```

## 七、常见问题

| 现象 | 原因与处理 |
|---|---|
| 打开是 500 /「页面错误！请稍后再试～」 | 先看 `docker compose logs app`；多数是数据库没连上（`DB_HOST` 应填 `db`，不是 `127.0.0.1`） |
| 日志里 `could not find driver` | 镜像里缺 `pdo_mysql`。本仓库 Dockerfile 已装，若自己改过基础镜像需补 `docker-php-ext-install pdo_mysql` |
| 端口被占用 | 改 `docker-compose.yml` 里 `"80812:80"` 的左边 |
| 上传 Excel 报错 | 调大 `docker/php.ini` 的 `upload_max_filesize` / `post_max_size` 后重建 |
| 时间显示差 8 小时 | compose 已设 `TZ=Asia/Shanghai` 与 `--default-time-zone=+08:00`，改过数据库镜像要同步改 |
| 拉取镜像很慢 | 在「容器管理 → 设置」里配镜像加速，或直接用方式 A 本地构建 |
| 忘记密码 | compose 里设 `WMS_ADMIN_PASSWORD` 重启一次即可重置 |

## 八、安全建议

- 不要把 `80812` 直接暴露到公网；需要外网访问时走 NAS 自带的反向代理并启用 HTTPS。
- 生产环境保持 `APP_DEBUG: "false"`（默认），否则异常页会输出调用栈与配置。
- 定期导出备份 SQL；升级前先备份。
