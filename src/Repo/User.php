<?php
declare(strict_types=1);

namespace RH\Repo;

use RH\Auth;
use RH\Db;
use RH\Logs;
use RH\Http;

/**
 * 用户管理（管理后台用）。登录注册在 RH\Auth 里。
 */
final class User
{
    public static function list(): array
    {
        $rows = Db::all(
            'SELECT id, username, display_name, avatar, role, can_write, unit_pref, is_active, created_at, last_login_at
               FROM users ORDER BY id ASC'
        );

        $sessionCounts = array();

        foreach (Db::all('SELECT user_id, COUNT(*) c FROM sessions GROUP BY user_id') as $r) {
            $sessionCounts[(int) $r['user_id']] = (int) $r['c'];
        }

        foreach ($rows as &$r) {
            $r['id']       = (int) $r['id'];
            $r['can_write'] = (int) $r['can_write'];
            $r['is_active'] = (int) $r['is_active'];
            $r['created_at'] = (int) $r['created_at'];
            $r['last_login_at'] = (int) $r['last_login_at'];
            $r['recipe_count'] = (int) Db::value(
                'SELECT COUNT(*) FROM recipes WHERE owner_id = ? AND deleted_at = 0',
                array($r['id'])
            );
            $r['sessions']  = $sessionCounts[$r['id']] ?? 0;
        }

        unset($r);

        return $rows;
    }

    public static function create(string $username, string $password, array $opt = array()): int
    {
        Auth::validateCredentials($username, $password);

        if (Db::one('SELECT id FROM users WHERE username = ?', array($username))) {
            Http::fail('这个用户名已经有人用了', 409, 'username');
        }

        $role = (string) ($opt['role'] ?? 'member');

        if (!in_array($role, array('admin', 'member'), true)) {
            $role = 'member';
        }

        $id = Db::insert('users', array(
            'username'      => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'display_name'  => (string) ($opt['display_name'] ?? $username),
            'role'          => $role,
            'can_write'     => isset($opt['can_write']) ? (int) (bool) $opt['can_write'] : 1,
            'unit_pref'     => (string) ($opt['unit_pref'] ?? '克'),
            'is_active'     => 1,
            'created_at'    => time(),
            'last_login_at' => 0,
        ));

        Logs::add('user_create', $username, '角色 ' . $role, null, Auth::name());

        return $id;
    }

    public static function update(int $id, array $patch): void
    {
        $target = Db::find('users', $id);

        if (!$target) {
            Http::fail('用户不存在', 404);
        }

        $row = array();

        if (array_key_exists('display_name', $patch)) {
            $row['display_name'] = mb_substr(trim((string) $patch['display_name']), 0, 32);
        }

        if (array_key_exists('role', $patch)) {
            $role = (string) $patch['role'];

            if (!in_array($role, array('admin', 'member'), true)) {
                Http::fail('角色只能是 admin 或 member', 400, 'role');
            }

            // 不能把自己降级，否则可能没人管得动这个实例
            if ($id === Auth::id() && $role !== 'admin') {
                Http::fail('不能取消自己的管理员身份', 400, 'role');
            }

            if ((string) $target['role'] === 'admin' && $role !== 'admin' && self::adminCount() <= 1) {
                Http::fail('至少要留一个管理员', 400, 'role');
            }

            $row['role'] = $role;
        }

        if (array_key_exists('can_write', $patch)) {
            $row['can_write'] = (int) (bool) $patch['can_write'];
        }

        if (array_key_exists('is_active', $patch)) {
            $active = (int) (bool) $patch['is_active'];

            if ($id === Auth::id() && $active === 0) {
                Http::fail('不能停用自己', 400, 'is_active');
            }

            if ((string) $target['role'] === 'admin' && $active === 0 && self::adminCount() <= 1) {
                Http::fail('至少要留一个启用状态的管理员', 400, 'is_active');
            }

            $row['is_active'] = $active;

            // 停用就顺手把人踢下线
            if ($active === 0) {
                Auth::sessionKillUser($id);
            }
        }

        if (array_key_exists('unit_pref', $patch)) {
            $row['unit_pref'] = (string) $patch['unit_pref'];
        }

        if (array_key_exists('avatar', $patch)) {
            $row['avatar'] = (string) $patch['avatar'];
        }

        if (!$row) {
            return;
        }

        Db::updateRow('users', $id, $row);
        Logs::add('user_update', (string) $target['username'], implode(', ', array_keys($row)), null, Auth::name());
    }

    /** 管理员重置别人的密码 */
    public static function resetPassword(int $id, string $new): void
    {
        if (strlen($new) < 6) {
            Http::fail('新密码至少 6 位', 400, 'password');
        }

        $target = Db::find('users', $id);

        if (!$target) {
            Http::fail('用户不存在', 404);
        }

        Db::updateRow('users', $id, array('password_hash' => password_hash($new, PASSWORD_DEFAULT)));
        Auth::sessionKillUser($id);

        Logs::add('user_reset_pw', (string) $target['username'], '已踢下线', null, Auth::name());
    }

    public static function remove(int $id): void
    {
        $target = Db::find('users', $id);

        if (!$target) {
            Http::fail('用户不存在', 404);
        }

        if ($id === Auth::id()) {
            Http::fail('不能删除自己', 400);
        }

        if ((string) $target['role'] === 'admin' && self::adminCount() <= 1) {
            Http::fail('至少要留一个管理员', 400);
        }

        // 菜谱保留，只把归属置空——家庭的菜谱本不该因为删个人就没了
        Db::exec('UPDATE recipes SET owner_id = 0 WHERE owner_id = ?', array($id));
        Db::exec('DELETE FROM favorites WHERE user_id = ?', array($id));
        Db::delete('users', $id);

        Logs::add('user_delete', (string) $target['username'], '菜谱已保留', null, Auth::name());
    }

    public static function adminCount(): int
    {
        return (int) Db::value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1");
    }

    /** 个人偏好：单位 + 显示名 */
    public static function updateProfile(int $id, array $patch): void
    {
        $row = array();

        if (array_key_exists('display_name', $patch)) {
            $row['display_name'] = mb_substr(trim((string) $patch['display_name']), 0, 32);
        }

        if (array_key_exists('unit_pref', $patch)) {
            $unit = (string) $patch['unit_pref'];

            if (!\RH\Units::has($unit)) {
                Http::fail('这个单位不在支持列表里', 400, 'unit_pref');
            }

            $row['unit_pref'] = $unit;
        }

        if (array_key_exists('avatar', $patch)) {
            $row['avatar'] = (string) $patch['avatar'];
        }

        if ($row) {
            Db::updateRow('users', $id, $row);
        }
    }
}
