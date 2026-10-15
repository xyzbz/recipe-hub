<?php
declare(strict_types=1);

/**
 * 命令行定时推送——挂在 cron 上就不用惦记了。
 *
 *   php cli/notify.php                     # 按当前时间判断该发哪一餐（cron 每分钟跑）
 *   php cli/notify.php --dry-run           # 只看看今天会发什么，不真发
 *   php cli/notify.php --slot=dinner       # 立刻推今天的晚餐（仍走去重）
 *   php cli/notify.php --slot=dinner --force   # 不管推没推过，强制再发一次
 *   php cli/notify.php --slot=dinner --date=2026-10-12
 *
 * 宝塔：计划任务 → 任务类型 Shell 脚本 → 执行周期「每 1 分钟」→ 脚本内容填
 *   cd /www/wwwroot/你的站点 && php cli/notify.php >> data/notify.log 2>&1
 *
 * Docker：
 *   docker compose exec recipe-hub php cli/notify.php
 *
 * 去重靠 notify_log：同一个「日期+餐次」在一个渠道上只许有一条成功记录。
 * 所以每分钟跑一次也不会把同一顿饭推 60 遍；想重发就加 --force。
 */

require dirname(__DIR__) . '/bootstrap.php';

use RH\Config;
use RH\Db;
use RH\Repo\Notify;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "这个脚本只能在命令行跑。\n");
    exit(1);
}

$dry   = false;
$force = false;
$slot  = '';
$date  = '';

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run' || $arg === '-n') {
        $dry = true;
    } elseif (preg_match('/^--slot=(\w+)$/', $arg, $m)) {
        $slot = strtolower($m[1]);
    } elseif (preg_match('/^--date=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
        $date = $m[1];
    } elseif ($arg === '--force') {
        $force = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "用法: php cli/notify.php [--dry-run] [--slot=breakfast|lunch|dinner|snack] [--date=YYYY-MM-DD] [--force]\n";
        exit(0);
    }
}

Db::boot();

if (!\RH\Repo\Settings::bool('notify_enabled', true)) {
    echo "推送总开关是关的，跳过。\n";
    exit(0);
}

$date     = $date !== '' ? $date : date('Y-m-d');
$nowHi    = date('H:i');

if ($slot !== '') {
    if (!isset(Notify::SLOT_LABEL[$slot])) {
        fwrite(STDERR, "不认识的餐次：{$slot}（可选 breakfast / lunch / dinner / snack）\n");
        exit(2);
    }

    $slots = array($slot);
} else {
    $slots = Notify::dueSlots($nowHi);
}

if ($slots === array()) {
    // cron 每分钟都会进这里，安静退出，别把日志文件刷爆
    exit(0);
}

$total = array('sent' => 0, 'failed' => 0, 'skipped' => 0, 'empty' => 0);

foreach ($slots as $s) {
    $label = Notify::SLOT_LABEL[$s] ?? $s;

    $res = Notify::pushSlot(
        $date,
        $s,
        $dry,
        $force ? 'force|' . date('Y-m-d H:i:s') : ''
    );

    if (!empty($res['empty'])) {
        $total['empty']++;
        echo "[{$nowHi}] {$label}：{$date} 没有安排，跳过\n";
        continue;
    }

    $total['sent']    += $res['sent'];
    $total['failed']  += $res['failed'];
    $total['skipped'] += $res['skipped'];

    echo "[{$nowHi}] {$label}：";

    if ($dry) {
        echo "预演，会发给 " . count($res['details']) . " 个渠道（没真发）\n";
        continue;
    }

    echo "成功 {$res['sent']}、失败 {$res['failed']}、已推过 {$res['skipped']}\n";

    foreach ($res['details'] as $d) {
        if ($d['status'] === 'fail' && $d['error'] !== '') {
            echo "    ✗ {$d['channel']}：{$d['error']}\n";
        }
    }
}

exit($total['failed'] > 0 ? 1 : 0);
