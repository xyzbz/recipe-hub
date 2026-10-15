# syntax=docker/dockerfile:1
#
# 家常菜谱 · 单容器镜像
#
#   docker build -t recipe-hub:1.1.3 .
#   docker compose up -d
#
# 一个容器装全部：Apache + PHP-FPM 模式的 mod_php + SQLite。
# 数据库文件和上传的图片都落在 /var/www/html/data，挂一个卷就能整体搬走。

FROM php:8.3-apache

LABEL org.opencontainers.image.title="家常菜谱 Recipe Hub" \
      org.opencontainers.image.description="自托管的中餐菜谱管理系统：菜谱 / 食材库 / 家庭库存 / 膳食计划 / 采购清单" \
      org.opencontainers.image.version="1.1.3"

# 数据目录。挂卷就挂这个路径，里面的东西是「全部家当」。
#
# 刻意【不】设置 APACHE_DOCUMENT_ROOT：当前 php:8.3-apache 的 apache2-foreground
# 并不消费这个变量（设了没人读），但官方历史上那段
#   sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
# 一旦被加回来，我们 vhost 里已经写好的 /var/www/html/public 会被二次替换成
# /var/www/html/public/public，站点直接 404。DocumentRoot 只由
# docker/apache.conf 声明，一处事实来源，不依赖这个变量。
ENV RH_DATA_DIR=/var/www/html/data \
    TZ=Asia/Shanghai

# ------------------------------------------------------------------ 依赖
#
# 官方 php:8.3-apache 的 configure 自带：
#   --enable-mbstring（内置，勿重复编译，会报 already loaded）
#   --with-pdo-sqlite=/usr / --with-sqlite3=/usr
# 加上 fileinfo / session / openssl / zlib，都不用管。
# 真正要额外编译的只有两个：
#   gd   —— 图片缩放 / 转 WebP / 生成缩略图（要 png + jpeg + webp + freetype）
#   exif —— 手机竖拍照片的方向纠正
#
# 注意：这里刻意不做 apt-get autoremove。gd.so 是运行时动态链接
# libpng16 / libjpeg62-turbo / libwebp7 / libfreetype6 的，dpkg 不知道这层关系，
# autoremove 会把它们当「没人要的依赖」删掉，镜像直接挂。
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg62-turbo-dev \
        libwebp-dev \
        libfreetype6-dev \
        tzdata; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" gd exif; \
    rm -rf /var/lib/apt/lists/*; \
    ln -snf /usr/share/zoneinfo/$TZ /etc/localtime; \
    echo $TZ > /etc/timezone

# 内置扩展显式断言：依赖的扩展缺一个，构建当场失败——
# 好过装好之后运行期才在图片上传那里炸。上面 RUN 刚编译完，这里一起验。
RUN set -eux; \
    php -m | grep -x gd; \
    php -m | grep -x exif; \
    php -m | grep -x mbstring; \
    php -m | grep -x pdo_sqlite; \
    php -m | grep -x sqlite3; \
    php -m | grep -x fileinfo; \
    php -m | grep -x session

# rewrite（路由、SPA 兜底）、headers（安全响应头）、expires/deflate（静态资源）
RUN a2enmod rewrite headers expires deflate

# ------------------------------------------------------------------ 应用
COPY docker/php.ini        /usr/local/etc/php/conf.d/zz-recipe-hub.ini
COPY docker/apache.conf    /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh  /usr/local/bin/recipe-hub-entrypoint
RUN chmod +x /usr/local/bin/recipe-hub-entrypoint

# 只拷需要的：源码、前端、种子数据。测试脚本和本地数据库不进镜像。
COPY bootstrap.php  /var/www/html/bootstrap.php
COPY router.php     /var/www/html/router.php
COPY src/           /var/www/html/src/
COPY public/        /var/www/html/public/
COPY seed/          /var/www/html/seed/
COPY cli/           /var/www/html/cli/

# data 目录先建好并交给 www-data；运行时挂卷会盖掉它，但没挂卷时也能直接跑
RUN mkdir -p /var/www/html/data/uploads /var/www/html/data/backups \
    && chown -R www-data:www-data /var/www/html/data

VOLUME ["/var/www/html/data"]
EXPOSE 80

ENTRYPOINT ["/usr/local/bin/recipe-hub-entrypoint"]
CMD ["apache2-foreground"]
