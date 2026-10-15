<?php
declare(strict_types=1);

namespace RH;

/**
 * 配置。
 *
 * 优先级：环境变量 > data/config.php > 内置默认值。
 * Docker 部署基本只靠环境变量，不需要挂配置文件。
 */
final class Config
{
    private static array $data   = array();
    private static bool  $loaded = false;

    private const DEFAULTS = array(
        'siteName'      => '家常菜谱',
        'siteDesc'      => '自己家的中餐菜谱本',
        'allowRegister' => 0,
        'uploadMaxMb'   => 8,
        'imageMaxWidth' => 1600,
        'thumbWidth'    => 480,
        'imageQuality'  => 82,
        // 整站恢复时允许上传的备份文件大小。
        // 整站包 = 数据库 + 全部图片，几百 MB 很正常，所以给得比单张图宽得多。
        'restoreMaxMb'  => 512,
        'timezone'      => 'Asia/Shanghai',
        'sessionDays'   => 30,
        'pageSize'      => 24,
        'loginMaxFail'  => 8,
        'loginLockMin'  => 15,
        'shareSpace'    => 1,
        // 菜谱里写了食材库里没有的名字时，自动登记一条。
        // 关掉的话，新食材不会进库，采购清单里就没法按分类归组、也不会参与别名合并。
        'autoIngredient' => 1,
        // 站点对外地址。推送消息里的菜谱链接和封面图都靠它拼绝对 URL，
        // cli/notify.php 跑在命令行里没有 HTTP_HOST，只能从配置读。
        'siteUrl'       => '',
    );

    /** 环境变量 => 配置键 */
    private const ENV_MAP = array(
        'RH_SITE_NAME'      => 'siteName',
        'RH_SITE_DESC'      => 'siteDesc',
        'RH_ALLOW_REGISTER' => 'allowRegister',
        'RH_UPLOAD_MAX_MB'  => 'uploadMaxMb',
        'RH_RESTORE_MAX_MB' => 'restoreMaxMb',
        'RH_IMAGE_MAX_WIDTH' => 'imageMaxWidth',
        'RH_IMAGE_QUALITY'  => 'imageQuality',
        'RH_TIMEZONE'       => 'timezone',
        'RH_SESSION_DAYS'   => 'sessionDays',
        'RH_AUTO_INGREDIENT' => 'autoIngredient',
        'RH_SITE_URL'       => 'siteUrl',
    );

    public static function boot(): void
    {
        if (self::$loaded) {
            return;
        }

        self::$loaded = true;
        self::$data   = self::DEFAULTS;

        $file = RH_ROOT . '/data/config.php';

        if (is_file($file)) {
            $local = require $file;

            if (is_array($local)) {
                self::$data = array_merge(self::$data, $local);
            }
        }

        foreach (self::ENV_MAP as $envKey => $cfgKey) {
            $val = getenv($envKey);

            if ($val === false || $val === '') {
                continue;
            }

            self::$data[$cfgKey] = self::cast(self::$data[$cfgKey], $val);
        }
    }

    private static function cast($current, string $val)
    {
        if (is_int($current)) {
            return (int) $val;
        }

        if (is_bool($current)) {
            return in_array(strtolower($val), array('1', 'true', 'yes', 'on'), true);
        }

        return $val;
    }

    public static function get(string $key, $default = null)
    {
        self::boot();

        if (array_key_exists($key, self::$data)) {
            return self::$data[$key];
        }

        return $default;
    }

    public static function set(string $key, $value): void
    {
        self::boot();
        self::$data[$key] = $value;
    }

    public static function all(): array
    {
        self::boot();

        return self::$data;
    }

    /** 数据目录：数据库 + 上传图片都在这里，Docker 下挂成一个卷 */
    public static function dataDir(): string
    {
        self::boot();

        $dir = (string) getenv('RH_DATA_DIR');

        if ($dir === '') {
            $dir = RH_ROOT . '/data';
        }

        return rtrim($dir, '/\\');
    }

    public static function dbPath(): string
    {
        return self::dataDir() . '/recipe.sqlite';
    }

    public static function uploadsDir(): string
    {
        return self::dataDir() . '/uploads';
    }

    public static function setDataDir(string $dir): void
    {
        putenv('RH_DATA_DIR=' . $dir);
    }
}
