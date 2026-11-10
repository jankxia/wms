# syntax=docker/dockerfile:1
# ---------------------------------------------------------------------------
# WMS 仓库管理系统 —— 生产镜像（PHP 8.2 + Apache）
# 构建：docker build -t wms:latest .
# 运行：见 docker-compose.yml 与 docs/部署到飞牛NAS.md
# ---------------------------------------------------------------------------
FROM php:8.2-apache

ENV TZ=Asia/Shanghai \
    APP_DEBUG=false

# 系统依赖 + PHP 扩展（pdo_mysql 是必需品；gd / zip / mbstring 供 Excel 导入导出）
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev libonig-dev \
        default-mysql-client unzip tzdata; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli gd zip mbstring bcmath opcache; \
    a2enmod rewrite headers expires; \
    ln -snf /usr/share/zoneinfo/$TZ /etc/localtime; \
    echo $TZ > /etc/timezone; \
    rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-wms.ini
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf

COPY . /var/www/html/

RUN set -eux; \
    mkdir -p /var/www/html/runtime /var/www/html/public/uploads; \
    chmod +x /var/www/html/docker/entrypoint.sh; \
    chown -R www-data:www-data /var/www/html/runtime /var/www/html/public/uploads

WORKDIR /var/www/html
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD php -r '$s = @fsockopen("127.0.0.1", 80, $e, $m, 3); exit($s ? 0 : 1);'

ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
CMD ["apache2-foreground"]
