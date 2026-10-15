<?php
declare(strict_types=1);

namespace RH;

/**
 * 操作日志。
 * 记登录、登录失败、注册、上传、删改这些需要事后回溯的动作。
 */
final class Logs
{
    public static function add(
        string $action,
        string $target = '',
        string $detail = '',
        ?int $userId = null,
        string $username = ''
    ): void {
        try {
            if ($userId === null) {
                $userId = Auth::id();
            }

            if ($username === '') {
                $username = Auth::name();
            }

            Db::insert('logs', array(
                'user_id'    => (int) $userId,
                'username'   => mb_substr($username, 0, 64),
                'action'     => mb_substr($action, 0, 32),
                'target'     => mb_substr($target, 0, 128),
                'detail'     => mb_substr($detail, 0, 500),
                'ip'         => Http::ip(),
                'ua'         => Http::ua(),
                'created_at' => time(),
            ));
        } catch (\Throwable $e) {
            // 日志失败不能影响主流程
        }
    }

    public static function recent(int $limit = 100, string $action = ''): array
    {
        $limit = max(1, min(500, $limit));

        if ($action !== '') {
            return Db::all(
                'SELECT * FROM logs WHERE action = ? ORDER BY id DESC LIMIT ' . $limit,
                array($action)
            );
        }

        return Db::all('SELECT * FROM logs ORDER BY id DESC LIMIT ' . $limit);
    }

    /** 最近 N 分钟内某个账号（或某个 IP）的登录失败次数 */
    public static function countFails(string $username, string $ip, int $minutes): int
    {
        $since = time() - max(1, $minutes) * 60;

        return (int) Db::value(
            'SELECT COUNT(*) FROM logs
              WHERE action = ? AND created_at > ? AND (target = ? OR ip = ?)',
            array('login_fail', $since, $username, $ip)
        );
    }

    /** 只保留最近 N 天 */
    public static function prune(int $days = 90): int
    {
        return Db::exec('DELETE FROM logs WHERE created_at < ?', array(time() - max(1, $days) * 86400));
    }

    public static function clear(): int
    {
        return Db::exec('DELETE FROM logs WHERE action <> ?', array('login_fail'));
    }

    /** 统计概览，给管理后台用 */
    public static function stats(): array
    {
        return array(
            'total'      => (int) Db::value('SELECT COUNT(*) FROM logs'),
            'login_today' => (int) Db::value(
                'SELECT COUNT(*) FROM logs WHERE action = ? AND created_at > ?',
                array('login', strtotime('today'))
            ),
            'fails_7d'   => (int) Db::value(
                'SELECT COUNT(*) FROM logs WHERE action = ? AND created_at > ?',
                array('login_fail', time() - 7 * 86400)
            ),
            'uploads'    => (int) Db::value('SELECT COUNT(*) FROM logs WHERE action = ?', array('upload')),
        );
    }
}
