<?php
/**
 * 本地开发用的路由脚本 —— 只有 `php -S` 内置服务器需要它。
 *
 * 生产环境（Docker / Apache）走 docker/apache.conf 里的 rewrite，用不到这个文件。
 *
 *   php -S 127.0.0.1:8000 -t public router.php
 *
 * 内置服务器一旦指定了 router script，所有请求都会先进这里；
 * 所以对真实存在的静态文件要返回 false，交还给服务器自己处理。
 */

declare(strict_types=1);

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$file = __DIR__ . '/public' . $path;

// 静态资源（assets/…、favicon…）直接放行
if ($path !== '/' && is_file($file) && strncmp($path, '/uploads/', 9) !== 0) {
    return false;
}

require __DIR__ . '/public/index.php';
