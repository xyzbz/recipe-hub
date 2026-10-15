<?php
/**
 * 前端控制器。
 *
 * /uploads/*  → 直接从 data/uploads 流出去（data 目录不在 docroot 里，不会裸奔）
 * /api/*      → JSON 接口
 * 其余        → 单页应用外壳
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use RH\Auth;
use RH\Config;
use RH\Db;
use RH\Http;
use RH\Images;
use RH\Repo\Settings;
use RH\Router;

$path  = Http::path();
$isApi = ($path === '/api' || strncmp($path, '/api/', 5) === 0);

// ---------------------------------------------------------------- 上传的图片
if (strncmp($path, '/uploads/', 9) === 0) {
    $rel = substr($path, 9);
    $abs = Images::absPath($rel);

    // 兜个底：自己动手往 data/uploads/ 里丢图的人不少（宝塔文件管理器、
    // rsync 一份散图过来）。他们通常把文件平铺在 uploads 根下，而库里记的
    // 是 2026/10/xxx.webp。按原路径找不到时，翻一下根目录的同名文件——
    // 目录结构对不上，也不至于让你看见一片裂图。
    if ($abs === '' || !is_file($abs)) {
        $flat = Config::uploadsDir() . '/' . basename(str_replace('\\', '/', $rel));

        if (is_file($flat)) {
            $abs = $flat;
        }
    }

    Http::streamFile($abs, Images::mimeOf($rel));
}

// ---------------------------------------------------------------- 建库 / 迁移
try {
    Db::boot();
} catch (\Throwable $e) {
    error_log('[recipe-hub] boot failed: ' . $e->getMessage());

    if ($isApi) {
        Http::json(array('ok' => false, 'error' => '初始化失败：' . $e->getMessage()), 500);
    }

    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>初始化失败</title>'
        . '<div style="font:14px/1.7 system-ui,sans-serif;max-width:640px;margin:12vh auto;padding:0 24px">'
        . '<h1 style="font-size:20px">数据库初始化失败</h1>'
        . '<p>多半是 data 目录不可写，或者容器没挂数据卷。</p>'
        . '<pre style="background:#f5f5f4;padding:14px;border-radius:8px;overflow:auto;font-size:12px">'
        . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
        . '</pre></div>';
    exit;
}

// ---------------------------------------------------------------- 健康检查
if ($path === '/healthz' || $path === '/api/health') {
    Http::json(array('ok' => true, 'version' => RH_VERSION, 'time' => date('c')));
}

// ---------------------------------------------------------------- 接口
if ($isApi) {
    $method = Http::method();

    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    Auth::checkCsrf();

    try {
        /** @var Router $router */
        $router = require RH_SRC . '/Routes.php';
        $router->dispatch($method, $path);
    } catch (\Throwable $e) {
        error_log('[recipe-hub] api error ' . $path . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        Http::fail('服务器出错了：' . $e->getMessage(), 500);
    }
}

// ---------------------------------------------------------------- 单页应用
$siteName = (string) Settings::get('site_name', '家常菜谱');
$siteDesc = (string) Settings::get('site_desc', '');

$cssV = @filemtime(__DIR__ . '/assets/app.css') ?: RH_VERSION;
$jsV  = @filemtime(__DIR__ . '/assets/js/app.js') ?: RH_VERSION;

$e = static function ($s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
};
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#faf7f2">
<meta name="color-scheme" content="light">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= $e($siteName) ?>">
<meta name="description" content="<?= $e($siteDesc !== '' ? $siteDesc : $siteName) ?>">
<title><?= $e($siteName) ?></title>
<link rel="stylesheet" href="/assets/app.css?v=<?= $e($cssV) ?>">
<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
</head>
<body>
<div id="app" class="app">
    <div class="boot-screen">
        <div class="boot-mark">🍳</div>
        <div class="boot-text">正在打开菜谱本…</div>
    </div>
</div>

<script>
window.RH_BOOT = {
    csrf: <?= json_encode(Auth::csrf()) ?>,
    siteName: <?= json_encode($siteName, JSON_UNESCAPED_UNICODE) ?>,
    siteDesc: <?= json_encode($siteDesc, JSON_UNESCAPED_UNICODE) ?>,
    version: <?= json_encode(RH_VERSION) ?>
};
</script>
<script type="module" src="/assets/js/app.js?v=<?= $e($jsV) ?>"></script>
</body>
</html>
