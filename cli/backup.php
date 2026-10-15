<?php
declare(strict_types=1);

/**
 * 命令行备份——挂在 cron 上就不用惦记了。
 *
 *   docker compose exec recipe-hub php cli/backup.php
 *   docker compose exec recipe-hub php cli/backup.php --keep=14
 *   docker compose exec recipe-hub php cli/backup.php --db-only
 *
 * 默认打的是「整站包」：database.sqlite（VACUUM INTO 的一致性快照）
 * + uploads/ 里的全部图片 + manifest.json，一个 zip 装走整个站。
 * 换服务器时把这个 zip 拿到后台「备份」页传回去点恢复，菜谱和图片一起回来。
 *
 * 用 SQLite 的 VACUUM INTO 抓快照：写库的同时跑也安全，不会拷到
 * 一个「表头写了一半」的残废文件。产物落在 data/backups/，跟数据库、
 * 图片同一个卷，整个卷拷走就是全套。
 */

require dirname(__DIR__) . '/bootstrap.php';

use RH\Config;
use RH\Db;
use RH\Images;
use RH\Logs;
use RH\SitePack;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "这个脚本只能在命令行跑。\n");
    exit(1);
}

$keep   = 30;
$dbOnly = false;

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--keep=(\d+)$/', $arg, $m)) {
        $keep = max(1, (int) $m[1]);
    } elseif ($arg === '--db-only') {
        $dbOnly = true;
    } else {
        fwrite(STDERR, "不认识的参数：{$arg}\n用法：php cli/backup.php [--keep=30] [--db-only]\n");
        exit(1);
    }
}

Db::boot();

$dir = Config::dataDir() . '/backups';

if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, "备份目录建不出来：{$dir}\n");
    exit(1);
}

// 先抓库快照（跟正式库同分区），打完包再删掉中间文件
$snap = Config::dataDir() . '/.pack-' . bin2hex(random_bytes(4)) . '.sqlite';

try {
    Db::pdo()->exec('VACUUM INTO ' . Db::pdo()->quote($snap));
} catch (\Throwable $e) {
    fwrite(STDERR, "备份失败：" . $e->getMessage() . "\n");
    exit(1);
}

$ext  = $dbOnly ? 'sqlite' : 'zip';
$file = 'recipe-' . date('Ymd-His') . '.' . $ext;
$path = $dir . '/' . $file;

if (is_file($path)) {
    $file = 'recipe-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.' . $ext;
    $path = $dir . '/' . $file;
}

try {
    if ($dbOnly) {
        // 只要库：老格式，恢复时图片得服务器上本来就有。跑得快，适合密集 cron。
        if (!@rename($snap, $path) && !@copy($snap, $path)) {
            throw new \RuntimeException('写不进 ' . $path);
        }

        $stat = array('db' => (int) (@filesize($path) ?: 0), 'images' => 0, 'bytes' => 0);
    } else {
        $stat = SitePack::create($snap, $path);
    }
} catch (\Throwable $e) {
    @unlink($snap);
    @unlink($path);
    fwrite(STDERR, "打包失败：" . $e->getMessage() . "\n");
    exit(1);
}

@unlink($snap);

$size = (int) (@filesize($path) ?: 0);

Logs::add(
    'backup_create',
    $file,
    '命令行备份 ' . Images::humanSize($size)
    . ($dbOnly ? '（只要数据库）' : '（含图片 ' . $stat['images'] . ' 张，' . Images::humanSize($stat['bytes']) . '）')
);

echo "备份完成：{$path}\n";
printf("包大小：%.1f KB\n", $size / 1024);

if ($dbOnly) {
    echo "这是只含数据库的包：恢复时图片要服务器上本来就有。想要能整体搬家的包，去掉 --db-only。\n";
} else {
    printf("内容：数据库 %.1f KB + 图片 %d 张（%.1f MB）\n", $stat['db'] / 1024, $stat['images'], $stat['bytes'] / 1048576);
    echo "换服务器：部署好程序 → 后台「备份」页把包传上去 → 点恢复。\n";
}

// ---------------------------------------------------------------- 清理旧备份
$files = array_merge(
    glob($dir . '/recipe-*.zip') ?: array(),
    glob($dir . '/recipe-*.sqlite') ?: array()
);

rsort($files);

$removed = 0;

foreach (array_slice($files, $keep) as $old) {
    if (@unlink($old)) {
        $removed++;
    }
}

if ($removed > 0) {
    echo "顺带清掉 {$removed} 个旧备份（保留最近 {$keep} 份）。\n";
}
