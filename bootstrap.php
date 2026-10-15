<?php
/**
 * 家常菜谱 · 应用引导
 *
 * 所有入口（public/index.php、cli/*.php、docker 入口脚本）都从这里进。
 * 只负责：常量、自动加载、时区、错误显示开关。不启动数据库——那个按需来。
 */

declare(strict_types=1);

define('RH_ROOT', __DIR__);
define('RH_SRC', RH_ROOT . '/src');
define('RH_SEED', RH_ROOT . '/seed');
define('RH_VERSION', '1.1.3');

// 生产环境别把报错吐给浏览器，日志打到 data/php-error.log
$rhDebug = (string) getenv('RH_DEBUG') === '1';
error_reporting(E_ALL);
ini_set('display_errors', $rhDebug ? '1' : '0');
ini_set('log_errors', '1');

if (PHP_SAPI !== 'cli') {
    // 容器里 php 日志目录可能不可写，交给 Docker 的 stderr
    $rhLogDir = RH_ROOT . '/data';

    if (!is_dir($rhLogDir)) {
        @mkdir($rhLogDir, 0775, true);
    }

    if (is_dir($rhLogDir) && is_writable($rhLogDir)) {
        ini_set('error_log', $rhLogDir . '/php-error.log');
    }
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'RH\\';

    if (strncmp($class, $prefix, 3) !== 0) {
        return;
    }

    $rel  = str_replace('\\', '/', substr($class, 3));
    $file = RH_SRC . '/' . $rel . '.php';

    if (is_file($file)) {
        require $file;
    }
});

date_default_timezone_set(RH\Config::get('timezone', 'Asia/Shanghai'));
