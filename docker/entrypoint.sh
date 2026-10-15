#!/bin/sh
#
# 家常菜谱 · 容器入口
#
# 干三件事，然后交班给 apache2-foreground：
#   1. 把数据目录准备好（挂卷进来时通常是 root 所有，需要 chown）
#   2. 可选：把 www-data 的 UID/GID 对齐到宿主机（PUID / PGID）
#   3. 按 RH_UPLOAD_MAX_MB / RH_RESTORE_MAX_MB 生成 PHP 上传上限

set -e

DATA_DIR="${RH_DATA_DIR:-/var/www/html/data}"
UPLOAD_MB="${RH_UPLOAD_MAX_MB:-8}"

case "$UPLOAD_MB" in
    ''|*[!0-9]*) UPLOAD_MB=8 ;;
esac

# ---------------------------------------------------------------- 1. 数据目录
mkdir -p "$DATA_DIR/uploads" "$DATA_DIR/backups"

# ---------------------------------------------------------------- 2. 用户对齐
if [ -n "$PUID" ] || [ -n "$PGID" ]; then
    PUID="${PUID:-33}"
    PGID="${PGID:-33}"

    if [ "$PUID" != "33" ] || [ "$PGID" != "33" ]; then
        groupmod -o -g "$PGID" www-data 2>/dev/null || true
        usermod  -o -u "$PUID" -g "$PGID" www-data 2>/dev/null || true
    fi
fi

chown -R www-data:www-data "$DATA_DIR" 2>/dev/null || \
    echo "[recipe-hub] 警告：$DATA_DIR 改属主失败，检查挂载权限" >&2

chmod 775 "$DATA_DIR" 2>/dev/null || true

# 顺便清一下可能残留的旧 pid
rm -f /var/run/apache2/apache2.pid 2>/dev/null || true

# ---------------------------------------------------------------- 3. 上传上限
# 图片本身最大 UPLOAD_MB，但 multipart 编码 + 表单字段得留富余。
#
# 另外恢复整站备份要传一个大 zip（数据库 + 全部图片，几百 MB 很正常），
# 单张图那点上限挡不住它——所以跟 RH_RESTORE_MAX_MB 取大值：
# 站内恢复能收多大的包，PHP 的请求体上限就得放到多大。
RESTORE_MB="${RH_RESTORE_MAX_MB:-512}"

case "$RESTORE_MB" in
    ''|*[!0-9]*) RESTORE_MB=512 ;;
esac

LIMIT_MB="$UPLOAD_MB"

if [ "$RESTORE_MB" -gt "$LIMIT_MB" ]; then
    LIMIT_MB="$RESTORE_MB"
fi

printf 'upload_max_filesize = %sM\npost_max_size = %sM\n' \
    "$LIMIT_MB" "$((LIMIT_MB + 8))" \
    > /usr/local/etc/php/conf.d/zz-recipe-hub-runtime.ini

if [ "$(id -u)" = "0" ]; then
    echo "[recipe-hub] 数据目录：$DATA_DIR（单图上限 ${UPLOAD_MB}MB，恢复包上限 ${LIMIT_MB}MB）"
else
    echo "[recipe-hub] 以非 root 身份启动，跳过权限设置；数据目录：$DATA_DIR"
fi

exec "$@"
