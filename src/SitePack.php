<?php
declare(strict_types=1);

namespace RH;

use RH\Repo\Settings;

/**
 * 整站备份包（zip）的打包与识别。
 *
 * 一个包三样东西：
 *   manifest.json      自述（程序版本、时间、各表行数），解包的人先看这个
 *   database.sqlite    VACUUM INTO 出来的一致性快照
 *   uploads/**         全部图片，原样搬进来
 *
 * 后台「立即备份」和命令行的 cli/backup.php 都走这里——两条路各写一遍，
 * 迟早会分叉（一边带图一边不带），备份这种命根子上的东西不能分叉。
 */
final class SitePack
{
    /** 自述格式标记，恢复时靠它认亲 */
    public const FORMAT = 'recipe-hub/site/v1';

    public const DB       = 'database.sqlite';
    public const MANIFEST = 'manifest.json';
    public const UPLOADS  = 'uploads/';

    /**
     * 打一份整站包。
     *
     * @param string $dbPath  已经快照好的 SQLite 文件（调用方负责 VACUUM INTO 出来）
     * @param string $zipPath 产物路径
     *
     * @return array{db:int,images:int,bytes:int,file:int} 库大小 / 图片张数 / 图片体积 / 包体积
     */
    public static function create(string $dbPath, string $zipPath): array
    {
        $files = self::uploadFiles();
        $bytes = 0;

        foreach ($files as $abs) {
            $bytes += (int) (@filesize($abs) ?: 0);
        }

        $zip = Zip::create($zipPath);

        // manifest 摆在最前面：体检程序和解包的人先看这个就知道手里是什么
        $manifest = array(
            'app'        => 'recipe-hub',
            'format'     => self::FORMAT,
            'version'    => defined('RH_VERSION') ? RH_VERSION : '',
            'schema'     => Db::version(),
            'created_at' => date('c'),
            'created_ts' => time(),
            'php'        => PHP_VERSION,
            'site_name'  => Settings::get('site_name', '家常菜谱'),
            'counts'     => self::dbCounts(),
            'images'     => array('files' => count($files), 'bytes' => $bytes),
        );

        $zip->addString(
            self::MANIFEST,
            (string) json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            true
        );

        $zip->addFile($dbPath, self::DB, true);

        // 图片原样搬：WebP 已经压过一遍了，再 deflate 白烧 CPU 换不来体积
        foreach ($files as $rel => $abs) {
            $zip->addFile($abs, self::UPLOADS . $rel, false);
        }

        $zip->close();

        return array(
            'db'     => (int) (@filesize($dbPath) ?: 0),
            'images' => count($files),
            'bytes'  => $bytes,
            'file'   => (int) (@filesize($zipPath) ?: 0),
        );
    }

    /**
     * 上传目录里的所有文件。
     *
     * @return array<string,string> 相对路径 => 绝对路径（相对路径已按字典序排好，包内容稳定）
     */
    public static function uploadFiles(): array
    {
        $dir = Config::uploadsDir();
        $out = array();

        if (!is_dir($dir)) {
            return $out;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $f) {
            if (!$f->isFile()) {
                continue;
            }

            $abs = str_replace('\\', '/', $f->getPathname());
            $rel = ltrim(substr($abs, strlen(str_replace('\\', '/', $dir))), '/');

            if ($rel !== '') {
                $out[$rel] = $f->getPathname();
            }
        }

        ksort($out);

        return $out;
    }

    /** 各表行数：写进 manifest，恢复前一眼看出这份「家底」有多少 */
    public static function dbCounts(): array
    {
        $counts = array();

        foreach (array('users', 'recipes', 'ingredients', 'tags', 'pantry_items', 'meal_plans', 'shopping_lists') as $t) {
            $counts[$t] = (int) Db::value('SELECT COUNT(*) FROM ' . $t);
        }

        return $counts;
    }

    /**
     * 这是不是一个 zip？看内容不看扩展名——
     * 用户从微信/网盘存下来的文件，扩展名经常被改得乱七八糟。
     */
    public static function isZip(string $path): bool
    {
        $head = (string) @file_get_contents($path, false, null, 0, 4);

        return strncmp($head, "PK\x03\x04", 4) === 0 || strncmp($head, "PK\x05\x06", 4) === 0;
    }

    /**
     * 定位包内的根目录前缀。
     *
     * 正常是 ''（manifest.json 就在根上）。但现实里经常遇到整个包被套进一层
     * 文件夹——Windows 右键「压缩」会自建一层同名目录，网盘转存有时也这样。
     * 这时候条目的名字是 `recipe-20261013-120000/manifest.json`。
     * 套一层认不出就等于「你的备份打不开」，太亏了，所以这里做兼容。
     *
     * @param array<string,array<string,mixed>> $entries Zip::entries() 的结果
     *
     * @return string|null '' 表示在根上，'xxx/' 表示套了一层；说不清时 null
     */
    public static function rootPrefix(array $entries): ?string
    {
        if (isset($entries[self::MANIFEST])) {
            return '';
        }

        $found = null;

        foreach (array_keys($entries) as $name) {
            $name = ltrim(str_replace('\\', '/', (string) $name), '/');

            if (substr($name, -strlen('/' . self::MANIFEST)) !== '/' . self::MANIFEST) {
                continue;
            }

            $prefix = substr($name, 0, -strlen(self::MANIFEST));

            // 两个不同目录下都有 manifest.json，分不清哪个是正主，不猜
            if ($found !== null && $found !== $prefix) {
                return null;
            }

            $found = $prefix;
        }

        return $found;
    }

    /**
     * 一个「长得不像本站整站包」的 zip 到底是什么，给用户一句能照着做的话。
     * 认得出就指路，认不出就说清楚拒收的理由。
     *
     * @param array<string,array<string,mixed>> $entries
     */
    public static function foreignHint(array $entries): string
    {
        $names = array();

        foreach (array_keys($entries) as $name) {
            $names[] = strtolower((string) $name);
        }

        // 菜谱导入包（cli/import-mealie.php 和「导出/导入」都是一份 JSON + 图片）
        foreach ($names as $n) {
            if (strpos($n, 'recipe-hub-import.json') !== false) {
                return '这是一个「菜谱导入包」，不是整站备份。要导入菜谱请到菜谱页点「导入」；'
                    . '整站备份请用后台「立即备份」生成的 recipe-*.zip。';
            }
        }

        // 里面确实有个 SQLite，只是没带自述——多半是别处打包的
        foreach ($names as $n) {
            if (substr($n, -7) === '.sqlite') {
                return '这个压缩包里是一个 SQLite 库，但没有本站的自述文件（manifest.json），'
                    . '不是本程序生成的整站备份。如果你只有数据库文件，直接传那个 .sqlite 就行。';
            }
        }

        return '这个包里没有本站的自述文件（manifest.json），不是本程序生成的整站备份。'
            . '本站备份是后台「立即备份」生成的 recipe-*.zip（整站包）或老的 recipe-*.sqlite。';
    }
}
