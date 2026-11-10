#!/usr/bin/env bash
# 容器启动脚本：等待数据库 → 空库时导入基线 → 执行幂等迁移 → 交给 Apache
set -euo pipefail

DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-wms}"
DB_USER="${DB_USER:-wms}"
DB_PASS="${DB_PASS:-wms123456}"

MYSQL_BIN="$(command -v mysql || command -v mariadb || true)"
if [ -z "${MYSQL_BIN}" ]; then
    echo "[entrypoint] 未找到 mysql 客户端，无法初始化数据库" >&2
    exit 1
fi

mysql_exec() {
    MYSQL_PWD="${DB_PASS}" "${MYSQL_BIN}" \
        --protocol=tcp -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USER}" "$@"
}

echo "[entrypoint] 等待数据库 ${DB_HOST}:${DB_PORT} ..."
ready=0
for i in $(seq 1 60); do
    if mysql_exec -e "SELECT 1" >/dev/null 2>&1; then
        ready=1
        break
    fi
    sleep 2
done
if [ "${ready}" != "1" ]; then
    echo "[entrypoint] 数据库连接超时，请检查 DB_HOST / DB_USER / DB_PASS" >&2
    exit 1
fi
echo "[entrypoint] 数据库已就绪"

# 空库则导入基线结构与演示数据
table_count="$(mysql_exec -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}'" 2>/dev/null | tr -d '\r')"
if [ "${table_count}" = "0" ]; then
    echo "[entrypoint] 检测到空库，导入 wms.sql ..."
    MYSQL_PWD="${DB_PASS}" "${MYSQL_BIN}" --protocol=tcp -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USER}" "${DB_NAME}" < /var/www/html/wms.sql
    echo "[entrypoint] 基线数据导入完成"
else
    echo "[entrypoint] 数据库已有 ${table_count} 张表，跳过基线导入"
fi

echo "[entrypoint] 执行数据库升级脚本 ..."
php /var/www/html/bin/migrate.php

chown -R www-data:www-data /var/www/html/runtime /var/www/html/public/uploads 2>/dev/null || true

exec "$@"
