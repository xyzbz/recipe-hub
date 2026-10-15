<?php
declare(strict_types=1);

namespace RH\Repo;

use RH\Db;

/**
 * 站点级设置（key-value）。用户偏好不走这里，用户偏好挂在 users 表上。
 */
final class Settings
{
    private static array $cache  = array();
    private static bool  $loaded = false;

    public static function all(): array
    {
        if (self::$loaded) {
            return self::$cache;
        }

        self::$loaded = true;
        self::$cache  = array();

        try {
            foreach (Db::all('SELECT k, v FROM settings') as $row) {
                self::$cache[(string) $row['k']] = (string) $row['v'];
            }
        } catch (\Throwable $e) {
            self::$cache = array();
        }

        return self::$cache;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $all = self::all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key, null);

        if ($v === null) {
            return $default;
        }

        return $v === '1';
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key, null);

        return $v === null || !is_numeric($v) ? $default : (int) $v;
    }

    public static function set(string $key, string $value): void
    {
        Db::exec(
            'INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v',
            array($key, $value)
        );

        self::$cache[$key] = $value;
        self::$loaded        = true;
    }

    public static function setMany(array $kv): void
    {
        foreach ($kv as $k => $v) {
            self::set((string) $k, (string) $v);
        }
    }

    /** 设置变更后强制重新读，测试用 */
    public static function refresh(): void
    {
        self::$loaded = false;
        self::all();
    }

    /** 暴露给前端的站点信息 */
    public static function siteInfo(): array
    {
        return array(
            'siteName'      => (string) self::get('site_name', '家常菜谱'),
            'siteDesc'      => (string) self::get('site_desc', ''),
            'allowRegister' => self::bool('allow_register', false),
        );
    }
}
