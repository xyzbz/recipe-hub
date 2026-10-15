<?php
declare(strict_types=1);

/**
 * 忘记密码时的后门——只能在服务器上跑，不需要登录。
 *
 *   docker compose exec recipe-hub php cli/reset-password.php 小宋 新密码123
 *   docker compose exec recipe-hub php cli/reset-password.php --list
 *
 * 改完会把这个账号在其它设备上的会话全部踢掉。
 */

require dirname(__DIR__) . '/bootstrap.php';

use RH\Db;
use RH\Logs;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "这个脚本只能在命令行跑。\n");
    exit(1);
}

Db::boot();

$args = array_slice($argv, 1);

if (!$args || $args[0] === '--list' || $args[0] === '-l') {
    $rows = Db::all('SELECT id, username, display_name, role, can_write, is_active FROM users ORDER BY id');

    if (!$rows) {
        echo "还没有任何账号。打开站点走一遍安装引导就行。\n";
        exit(0);
    }

    printf("%-4s %-20s %-16s %-8s %-6s %s\n", 'ID', '用户名', '显示名', '角色', '可写', '状态');

    foreach ($rows as $r) {
        printf(
            "%-4d %-20s %-16s %-8s %-6s %s\n",
            (int) $r['id'],
            (string) $r['username'],
            (string) $r['display_name'],
            (string) $r['role'],
            (int) $r['can_write'] === 1 ? '是' : '否',
            (int) $r['is_active'] === 1 ? '正常' : '已停用'
        );
    }

    echo "\n用法：php cli/reset-password.php <用户名> <新密码>\n";
    exit(0);
}

if (count($args) < 2) {
    fwrite(STDERR, "用法：php cli/reset-password.php <用户名> <新密码>\n");
    fwrite(STDERR, "      php cli/reset-password.php --list\n");
    exit(1);
}

[$username, $password] = $args;
$username = trim($username);

if (strlen($password) < 6) {
    fwrite(STDERR, "密码至少 6 位。\n");
    exit(1);
}

$user = Db::one('SELECT * FROM users WHERE username = ?', array($username));

if (!$user) {
    fwrite(STDERR, "没有叫「{$username}」的账号。用 --list 看看都有谁。\n");
    exit(1);
}

Db::updateRow('users', (int) $user['id'], array(
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'is_active'     => 1,
));

// 旧会话全部作废，防止「密码改了但别人还挂着」
$killed = Db::exec('DELETE FROM sessions WHERE user_id = ?', array((int) $user['id']));

Logs::add('password_reset', $username, '命令行重置', (int) $user['id'], $username);

echo "已重置「{$username}」的密码，并踢掉 {$killed} 个登录会话。\n";

if ((int) $user['is_active'] !== 1) {
    echo "顺带把这个账号重新启用了（原来是停用状态）。\n";
}
