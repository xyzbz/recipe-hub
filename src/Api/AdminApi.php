<?php
declare(strict_types=1);

namespace RH\Api;

use RH\Auth;
use RH\Config;
use RH\Db;
use RH\Http;
use RH\Images;
use RH\Logs;
use RH\Repo\Ingredient;
use RH\Repo\Pantry;
use RH\Repo\Recipe;
use RH\Repo\Settings;
use RH\Repo\Shopping;
use RH\Repo\User;
use RH\Schema;
use RH\SitePack;
use RH\Zip;

final class AdminApi
{
    public static function dashboard(): void
    {
        Auth::requireAdmin();

        $img = Images::usage();

        Http::ok(array(
            'stats'   => array_merge(Recipe::stats(), array(
                'users'          => Auth::userCount(),
                'pantry_expired' => Pantry::stats()['expired'],
                'pantry_soon'    => Pantry::stats()['soon'],
                'shopping_open'  => count(Shopping::lists(true)),
            )),
            'storage' => array(
                'db'      => self::dbSize(),
                'uploads' => $img,
            ),
            'logs'    => Logs::stats(),
            'system'  => array(
                'php'      => PHP_VERSION,
                'sqlite'   => Db::value('SELECT sqlite_version()'),
                'version'  => RH_VERSION,
                'schema'   => Db::version(),
                'data_dir' => Config::dataDir(),
                'tz'       => date_default_timezone_get(),
            ),
        ));
    }

    // ------------------------------------------------------------------ 用户

    public static function users(): void
    {
        Auth::requireAdmin();
        Http::ok(User::list());
    }

    public static function userCreate(): void
    {
        Auth::requireAdmin();

        $id = User::create(
            Http::str('username'),
            (string) Http::input('password', ''),
            array(
                'display_name' => Http::str('display_name'),
                'role'         => Http::str('role', 'member'),
                'can_write'    => Http::bool('can_write', true),
            )
        );

        Http::ok(array('id' => $id));
    }

    public static function userUpdate(array $p): void
    {
        Auth::requireAdmin();
        User::update((int) ($p['id'] ?? 0), Http::body());

        Http::ok(array('updated' => true));
    }

    public static function userPassword(array $p): void
    {
        Auth::requireAdmin();

        User::resetPassword((int) ($p['id'] ?? 0), (string) Http::input('password', ''));

        Http::ok(array('reset' => true));
    }

    public static function userRemove(array $p): void
    {
        Auth::requireAdmin();
        User::remove((int) ($p['id'] ?? 0));

        Http::ok(array('removed' => true));
    }

    // ------------------------------------------------------------------ 设置

    public static function settings(): void
    {
        Auth::requireAdmin();

        Http::ok(array(
            'settings' => array(
                'site_name'      => Settings::get('site_name', '家常菜谱'),
                'site_desc'      => Settings::get('site_desc', ''),
                'allow_register' => Settings::bool('allow_register', false) ? '1' : '0',
                'public_browse'  => Settings::bool('public_browse', false) ? '1' : '0',
                'site_url'       => \RH\Repo\Notify::siteUrl(),
                // 富文本总开关：关掉就全渠道退回纯文本（不带图不带链接）
                'notify_rich'    => Settings::get('notify_rich', '1'),
            ),
            'readonly' => array(
                'uploadMaxMb' => (int) Config::get('uploadMaxMb', 8),
                'dataDir'     => Config::dataDir(),
                'imageMaxWidth' => (int) Config::get('imageMaxWidth', 1600),
                'imageQuality'  => (int) Config::get('imageQuality', 82),
            ),
        ));
    }

    public static function settingsSave(): void
    {
        Auth::requireAdmin();

        $map = array();

        if (Http::input('site_name') !== null) {
            $name = Http::str('site_name');
            $map['site_name'] = $name !== '' ? mb_substr($name, 0, 40) : '家常菜谱';
        }

        if (Http::input('site_desc') !== null) {
            $map['site_desc'] = mb_substr(Http::str('site_desc'), 0, 120);
        }

        if (Http::input('allow_register') !== null) {
            $map['allow_register'] = Http::bool('allow_register') ? '1' : '0';
        }

        if (Http::input('public_browse') !== null) {
            $map['public_browse'] = Http::bool('public_browse') ? '1' : '0';
        }

        if (Http::input('notify_rich') !== null) {
            $map['notify_rich'] = Http::bool('notify_rich') ? '1' : '0';
        }

        if (Http::input('site_url') !== null) {
            $map['site_url'] = \RH\Repo\Notify::normalizeSiteUrl(Http::str('site_url'));
        }

        if ($map) {
            Settings::setMany($map);
            Logs::add('settings_save', implode(', ', array_keys($map)));
        }

        Http::ok(array('settings' => $map));
    }

    // ------------------------------------------------------------------ 日志

    public static function logs(): void
    {
        Auth::requireAdmin();

        Http::ok(array(
            'items' => Logs::recent(Http::int('limit', 100), Http::str('action')),
            'stats' => Logs::stats(),
        ));
    }

    public static function logsClear(): void
    {
        Auth::requireAdmin();
        Http::ok(array('removed' => Logs::clear()));
    }

    // ------------------------------------------------------------------ 备份
    //
    // 备份 = 一个 zip，里面三样东西：
    //     manifest.json      自述（程序版本、时间、各表有多少东西），恢复前先看这个
    //     database.sqlite    VACUUM INTO 出来的一致性快照
    //     uploads/**         全部图片，原样搬进来
    //
    // 以前只备数据库，图片得自己 rsync——换机器时最容易漏的就是这一步（然后菜谱全成了空图）。
    // 现在一个文件装走整个站：新服务器上把程序跑起来、把这个包传回去点恢复，菜谱和图片一起回来。
    //
    // 站内不再有第二套「我的数据」导出：备份就一个入口，做的是完整的事。

    public static function backupList(): void
    {
        Auth::requireAdmin();
        Http::ok(array('items' => self::backupFiles(), 'dir' => self::backupDir()));
    }

    /**
     * 打一份整站包：数据库快照 + 全部图片。
     *
     * 先 VACUUM INTO 到数据目录（跟正式库同分区），再往 zip 里灌，打完删掉中间文件。
     * VACUUM INTO 的好处是抓图/写入的同时跑也安全，不会拷到「表头写了一半」的残废文件。
     */
    public static function backupCreate(): void
    {
        Auth::requireAdmin();

        // 几百 MB 图片灌进 zip 要一会儿，别被 max_execution_time 掐在半路
        @set_time_limit(0);

        $dir = self::backupDir();

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            Http::fail('备份目录创建失败：' . $dir, 500);
        }

        $snap = Config::dataDir() . '/.pack-' . bin2hex(random_bytes(4)) . '.sqlite';

        try {
            Db::pdo()->exec('VACUUM INTO ' . Db::pdo()->quote($snap));
        } catch (\Throwable $e) {
            Http::fail('备份失败：' . $e->getMessage(), 500);
        }

        $stamp = date('Ymd-His');
        $file  = 'recipe-' . $stamp . '.zip';
        $path  = $dir . '/' . $file;

        if (is_file($path)) {
            $file = 'recipe-' . $stamp . '-' . bin2hex(random_bytes(2)) . '.zip';
            $path = $dir . '/' . $file;
        }

        try {
            $stat = SitePack::create($snap, $path);
        } catch (\Throwable $e) {
            @unlink($snap);
            @unlink($path);
            Http::fail('打包失败：' . $e->getMessage(), 500);
        }

        @unlink($snap);

        self::backupPrune();

        $size = (int) (@filesize($path) ?: 0);

        Logs::add(
            'backup_create',
            $file,
            Images::humanSize($size) . '（数据库 ' . Images::humanSize($stat['db']) . ' + 图片 '
            . $stat['images'] . ' 张 ' . Images::humanSize($stat['bytes']) . '）'
        );

        Http::ok(array(
            'file'   => $file,
            'size'   => $size,
            'human'  => Images::humanSize($size),
            'images' => $stat['images'],
            'items'  => self::backupFiles(),
        ));
    }

    public static function backupDownload(): void
    {
        Auth::requireAdmin();

        $file = self::safeBackupName(Http::str('file'));
        $path = self::backupDir() . '/' . $file;

        if ($file === '' || !is_file($path)) {
            Http::fail('备份文件不存在', 404);
        }

        Logs::add('backup_download', $file);

        header('Content-Disposition: attachment; filename="' . $file . '"');
        Http::streamFile($path, preg_match('/\.zip$/i', $file) === 1 ? 'application/zip' : 'application/octet-stream', 0);
    }

    public static function backupRemove(): void
    {
        Auth::requireAdmin();

        $file = self::safeBackupName(Http::str('file'));
        $path = self::backupDir() . '/' . $file;

        if ($file === '' || !is_file($path)) {
            Http::fail('备份文件不存在', 404);
        }

        @unlink($path);
        Logs::add('backup_delete', $file);

        Http::ok(array('items' => self::backupFiles()));
    }

    // ------------------------------------------------------------------ 恢复
    //
    // 以前要恢复得敲命令行：停容器、改名、删 WAL、重启。家用实例一年用不到两次，
    // 但真需要的时候通常是「数据搞坏了」的紧急时刻，越简单越好。
    //
    // 现在分两步走：
    //   inspect —— 上传/指定文件，先看清楚里面有什么（多少菜谱、哪个版本、图片全不全）
    //   restore —— 拿 token 真正执行：铺图 → 给现状拍照 → 断开连接 → 换文件 → 重开 → 保活会话
    //
    // 三道保险：① 动手前一定先做快照  ② 换完先 probe 能不能打开  ③ 打不开就原样退回。
    //
    // 「先铺图、后换库」的顺序是刻意的：铺图是纯增量（只覆盖同名、不删任何东西），
    // 不存在把现状弄坏的路径；而换库有回滚保护。两件事按风险从低到高排，失败面最小。

    /** 第一步：把备份文件传上来做个「体检」，返回第二步要用的 token */
    public static function backupInspect(): void
    {
        Auth::requireAdmin();

        @set_time_limit(0);

        $token = '';
        $label = '';
        $src   = '';

        if (Http::str('file') !== '') {
            $file = self::safeBackupName(Http::str('file'));

            if ($file === '' || !is_file(self::backupDir() . '/' . $file)) {
                Http::fail('备份文件不存在', 404);
            }

            $src   = self::backupDir() . '/' . $file;
            $label = $file;
        } else {
            // 上传的先落到数据目录：staging 必须跟正式库同一个分区，
            // 后面的 rename 才是原子操作，而不是「复制到一半断电」。
            list($src, $token, $label) = self::receiveRestoreUpload();
            self::stagingGc();
        }

        $info = self::inspectBackupFile($src);

        Http::ok(array_merge(
            array('token' => $token, 'file' => $token === '' ? basename($src) : '', 'name' => $label),
            $info
        ));
    }

    /** 第二步：真的把整个站换掉（库 + 图片） */
    public static function backupRestore(): void
    {
        Auth::requireAdmin();

        @set_time_limit(0);

        // 恢复之后 Db 连的就是新库了，当前用户信息先抓在手里：
        // 一是日志要署真名，二是要把自己重新塞回 sessions，免得刚恢复完就被踢下线。
        $me       = Auth::user() ?: array();
        $sid      = (string) ($_COOKIE[Auth::COOKIE] ?? '');
        $username = (string) ($me['username'] ?? '');
        $meId     = (int) ($me['id'] ?? 0);

        $token = Http::str('token');
        $file  = self::safeBackupName(Http::str('file'));
        $label = mb_substr(basename(Http::str('name')), 0, 120);

        if ($token !== '') {
            $src = self::stagingPath($token);

            if ($src === '' || !is_file($src)) {
                Http::fail('刚才那份文件已经失效了，请重新选择备份', 400, 'token');
            }

            if ($label === '') {
                $label = '上传的备份';
            }
        } elseif ($file !== '') {
            $src = self::backupDir() . '/' . $file;

            if (!is_file($src)) {
                Http::fail('备份文件不存在', 404);
            }

            $label = $file;
        } else {
            Http::fail('请先选择要恢复的备份文件', 400, 'file');
        }

        // token 只是握手凭证，第二遍照样体检
        $info = self::inspectBackupFile($src);

        // 整站包先解到 staging，库和图片分开摆
        $stage  = null;
        $dbSrc  = $src;
        $isPack = SitePack::isZip($src);

        // 包可能被套了一层文件夹，体检时已经算出来在哪
        $prefix = (string) ($info['pack_prefix'] ?? '');

        if ($isPack) {
            $stage = Config::dataDir() . '/.restore-' . ($token !== '' ? $token : bin2hex(random_bytes(8)));
            self::rmDir($stage);

            if (!@mkdir($stage, 0775, true) && !is_dir($stage)) {
                Http::fail('解包目录建不出来：' . $stage, 500);
            }

            try {
                $zip = Zip::open($src);
                $zip->extractAll($stage);
                $zip->closeRead();
            } catch (\Throwable $e) {
                self::rmDir($stage);
                Http::fail('解包失败：' . $e->getMessage() . '。当前数据没动。', 500);
            }

            $dbSrc = $stage . '/' . $prefix . SitePack::DB;

            if (!is_file($dbSrc)) {
                self::rmDir($stage);
                Http::fail('这个包里没有 database.sqlite，装不了。', 400, 'file');
            }
        }

        // 快照 + 解包内容 + 新库要同时放得下，别到一半才发现没地方了
        $need = ((int) (@filesize($src) ?: 0)) * 2 + 32 * 1048576;
        $free = (float) (@disk_free_space(Config::dataDir()) ?: 0);

        if ($free > 0 && $free < $need) {
            self::rmDir($stage);

            Http::fail(
                '磁盘空间不够：这一步需要 ' . Images::humanSize($need) . '，数据目录现在只剩 '
                . Images::humanSize((int) $free) . '。当前数据没有动。',
                500
            );
        }

        // 图片先铺：只覆盖同名、不删任何东西（同名必然同内容——文件名是随机 16 位十六进制）
        $placed = array('copied' => 0, 'skipped' => 0, 'failed' => 0);

        if ($stage !== null) {
            $placed = self::placeUploads($stage . '/' . $prefix . rtrim(SitePack::UPLOADS, '/'));
        }

        $out = self::swapDatabase($dbSrc, $info, $label, $sid, $username, $meId);

        $out['images_placed']  = $placed['copied'];
        $out['images_skipped'] = $placed['skipped'];
        $out['packed_images']  = (int) ($info['packed_images'] ?? 0);
        $out['missing_images'] = (int) ($info['missing_images'] ?? 0);
        $out['kept_login']     = !$out['relogin'];

        if ($stage !== null) {
            self::rmDir($stage);
        }

        if ($token !== '' && is_file($src)) {
            @unlink($src);
        }

        Http::ok($out);
    }

    // ------------------------------------------------------------------ 维护

    /** 清日志 / 重算食材引用 / 压缩数据库 */
    public static function maintenance(): void
    {
        Auth::requireAdmin();

        $task = Http::str('task');
        $out  = array();

        switch ($task) {
            case 'prune_logs':
                $out['removed'] = Logs::prune(Http::int('days', 90));
                break;

            case 'refresh_ingredients':
                Ingredient::refreshUsage();
                $out['refreshed'] = true;
                break;

            case 'rebuild_search':
                // 回收站里的菜谱搜不到，没必要给它们重建索引
                $ids = array_column(Db::all('SELECT id FROM recipes WHERE deleted_at = 0'), 'id');

                foreach ($ids as $id) {
                    Recipe::refreshSearchText((int) $id);
                }

                $out['rebuilt'] = count($ids);
                break;

            case 'vacuum':
                Db::pdo()->exec('VACUUM');
                $out['vacuumed'] = true;
                break;

            case 'seed_demo':
                $u = Auth::user();
                $out['created'] = Schema::seedDemo($u ? (int) $u['id'] : 0);
                break;

            default:
                Http::fail('不认识这个维护任务', 400, 'task');
        }

        Logs::add('maintenance', $task, json_encode($out, JSON_UNESCAPED_UNICODE) ?: '');

        Http::ok($out);
    }

    // ------------------------------------------------------------------ 内部

    private static function backupDir(): string
    {
        return Config::dataDir() . '/backups';
    }

    /**
     * 收下上传的备份，落到数据目录暂存。
     *
     * @return array{0:string,1:string,2:string} [staging 路径, token, 原始文件名]
     */
    private static function receiveRestoreUpload(): array
    {
        $f = $_FILES['file'] ?? null;

        if (!is_array($f)) {
            // 最常见的原因其实是「文件太大被 php.ini 拦了」——那种情况 $_FILES 直接是空的
            Http::fail(
                '没有收到文件。如果文件比较大，多半是被服务器的上传上限拦了：'
                . '本程序这边允许 ' . (int) Config::get('restoreMaxMb', 512) . 'MB，'
                . 'PHP 的 upload_max_filesize / post_max_size 也得跟着放大。',
                400,
                'file'
            );
        }

        $err = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($err !== UPLOAD_ERR_OK) {
            Http::fail(self::uploadErrorText($err), 400, 'file');
        }

        $size = (int) ($f['size'] ?? 0);

        if ($size <= 0) {
            Http::fail('文件是空的', 400, 'file');
        }

        $max = max(1, (int) Config::get('restoreMaxMb', 512)) * 1048576;

        if ($size > $max) {
            Http::fail(
                '这个文件 ' . Images::humanSize($size) . '，超过恢复上限 '
                . (int) Config::get('restoreMaxMb', 512) . 'MB',
                413,
                'file'
            );
        }

        $dir = Config::dataDir();

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            Http::fail('数据目录写不进去：' . $dir, 500);
        }

        $token = bin2hex(random_bytes(8));
        $path  = self::stagingPath($token);
        $name  = basename((string) ($f['name'] ?? ''));

        if ($path === ''
            || !is_uploaded_file((string) ($f['tmp_name'] ?? ''))
            || !@move_uploaded_file((string) $f['tmp_name'], $path)) {
            Http::fail('文件没能存到服务器上，检查 data 目录的权限', 500);
        }

        return array($path, $token, $name !== '' ? $name : '上传的备份');
    }

    /** staging 文件名：藏在数据目录里，以 .pending 结尾——前面那个点保证不会被 backupFiles() 扫进备份列表 */
    private static function stagingPath(string $token): string
    {
        if (preg_match('/^[a-f0-9]{16}$/', $token) !== 1) {
            return '';
        }

        return Config::dataDir() . '/.restore-' . $token . '.pending';
    }

    /** 清掉上次留下的残骸（用户传了文件又不想恢复了） */
    private static function stagingGc(int $maxAge = 86400): void
    {
        foreach (glob(Config::dataDir() . '/.restore-*.pending') ?: array() as $f) {
            if (is_file($f) && (int) (@filemtime($f) ?: 0) < time() - $maxAge) {
                @unlink($f);
            }
        }

        foreach (glob(Config::dataDir() . '/.restore-*', GLOB_ONLYDIR) ?: array() as $d) {
            if ((int) (@filemtime($d) ?: 0) < time() - $maxAge) {
                self::rmDir($d);
            }
        }

        // 打包/体检留下的临时库
        foreach (glob(Config::dataDir() . '/.pack-*.sqlite') ?: array() as $f) {
            if ((int) (@filemtime($f) ?: 0) < time() - $maxAge) {
                @unlink($f);
            }
        }
    }

    /**
     * 把包里的图片并进 data/uploads。
     *
     * 只增不减：同名覆盖（同名必然同内容——文件名是随机 16 位十六进制），
     * 包里没有的文件原样留着。所以这一步无论怎么跑都不会丢图，
     * 这也正是「先铺图、后换库」的底气所在。
     *
     * @return array{copied:int,skipped:int,failed:int}
     */
    private static function placeUploads(string $srcDir): array
    {
        $out = array('copied' => 0, 'skipped' => 0, 'failed' => 0);

        if (!is_dir($srcDir)) {
            return $out;
        }

        $root = Config::uploadsDir();

        if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) {
            Http::fail('图片目录建不出来：' . $root, 500);
        }

        $prefix = strlen(str_replace('\\', '/', $srcDir));
        $it     = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $f) {
            if (!$f->isFile()) {
                continue;
            }

            $rel = ltrim(substr(str_replace('\\', '/', $f->getPathname()), $prefix), '/');

            // 双重保险：包已经解过一遍（Zip 里挡过 zip slip），这里再挡一次穿越
            if ($rel === '' || preg_match('#(^|/)\.\.(/|$)#', $rel) === 1) {
                $out['skipped']++;
                continue;
            }

            $dst = $root . '/' . $rel;
            $dir = dirname($dst);

            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                $out['failed']++;
                continue;
            }

            // 同分区优先 rename（近乎零成本），跨分区再退回复制
            if (@rename($f->getPathname(), $dst) || @copy($f->getPathname(), $dst)) {
                $out['copied']++;
            } else {
                $out['failed']++;
            }
        }

        return $out;
    }

    /** 递归删目录（staging 用，路径是我们自己拼的，不看用户输入） */
    private static function rmDir(?string $dir): void
    {
        if ($dir === null || $dir === '' || !is_dir($dir)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $f) {
            if ($f->isDir()) {
                @rmdir($f->getPathname());
            } else {
                @unlink($f->getPathname());
            }
        }

        @rmdir($dir);
    }

    /**
     * 备份体检：先看它是什么格式，再逐关验。
     *
     * 两种行李都认：
     *   .zip   —— 新版整站包（库 + 图片），恢复能把图片一起带回来
     *   .sqlite—— 老格式，只有库；图片得靠服务器上本来就有
     *
     * 任何一关不过就回 400，绝不让一个来历不明的文件进入第二步。
     */
    private static function inspectBackupFile(string $path): array
    {
        return SitePack::isZip($path)
            ? self::inspectSitePack($path)
            : self::inspectSqliteFile($path);
    }

    /**
     * 老格式（只有数据库）的体检。
     *
     * @return array<string,mixed>
     */
    private static function inspectSqliteFile(string $path): array
    {
        $base = self::inspectSqlite($path);

        // 图片不在数据库里，恢复前先报告「有几张图根本没跟着走」
        $missing = 0;

        foreach (array_keys($base['refs']) as $rel) {
            if (!is_file(Config::uploadsDir() . '/' . $rel)) {
                $missing++;
            }
        }

        $base['kind']           = 'db';
        $base['packed_images']  = 0;
        $base['missing_images'] = $missing;

        if ($base['images'] > 0 && $missing > 0) {
            $base['warnings'][] = '这是老格式的备份，只装了数据库。里面 ' . $missing . '/' . $base['images']
                . ' 张图不在这个文件里，恢复后这些菜谱会缺图（除非服务器上本来就有）。'
                . '重新部署建议用新版生成的整站包。';
        } else {
            $base['warnings'][] = '这是老格式的备份：只含数据库，不含图片。新版本会把图片一起打包，换机器恢复更省事。';
        }

        return $base;
    }

    /**
     * 整站包（zip）的体检：解开看一眼里面装了什么。
     *
     * 只看不写——把 database.sqlite 解到数据目录的临时文件里过一遍检查，
     * 转头就删；图片只数个数，一个字节都不动。
     *
     * @return array<string,mixed>
     */
    private static function inspectSitePack(string $path): array
    {
        clearstatcache(true, $path);

        try {
            $zip = Zip::open($path);
        } catch (\Throwable $e) {
            Http::fail('打不开这个压缩包：' . $e->getMessage(), 400, 'file');
        }

        $entries = $zip->entries();

        // 规范化名 => 包内真名。外来包的条目名可能带 ./ 或反斜杠，
        // 统一查一遍，免得「文件明明在里面却说没有」。
        $byName = array();

        foreach ($entries as $raw => $meta) {
            $byName[ltrim(str_replace('\\', '/', (string) $raw), '/')] = (string) $raw;
        }

        // manifest 在根上是 ''；整个包被套了一层文件夹也能认出来（Windows 右键压缩的常见结果）
        $prefix = SitePack::rootPrefix($entries);

        if ($prefix === null) {
            $zip->closeRead();
            Http::fail(SitePack::foreignHint($entries), 400, 'file');
        }

        $manifestKey = $byName[$prefix . SitePack::MANIFEST] ?? null;
        $dbKey       = $byName[$prefix . SitePack::DB] ?? null;

        $manifest = array();

        if ($manifestKey !== null) {
            $decoded = json_decode((string) $zip->read($manifestKey), true);

            if (is_array($decoded)) {
                $manifest = $decoded;
            }
        }

        if ((string) ($manifest['app'] ?? '') !== 'recipe-hub') {
            $zip->closeRead();
            Http::fail(SitePack::foreignHint($entries), 400, 'file');
        }

        if ($dbKey === null) {
            $zip->closeRead();
            Http::fail('这个包里没有 database.sqlite，装不了。', 400, 'file');
        }

        // 包里的图片：数个数、称个重
        $packed   = array();
        $upPrefix = $prefix . SitePack::UPLOADS;

        foreach ($byName as $norm => $raw) {
            if (strpos($norm, $upPrefix) !== 0) {
                continue;
            }

            $rel = substr($norm, strlen($upPrefix));

            if ($rel !== '' && substr($rel, -1) !== '/') {
                $packed[$rel] = (int) $entries[$raw]['usize'];
            }
        }

        // 库解到临时文件过一遍检查。放数据目录：同一个分区，删起来干净
        $probe = Config::dataDir() . '/.pack-' . bin2hex(random_bytes(4)) . '.sqlite';

        try {
            $zip->extractTo($dbKey, $probe);
        } catch (\Throwable $e) {
            $zip->closeRead();
            Http::fail('包里的数据库解不开：' . $e->getMessage(), 400, 'file');
        }

        $zip->closeRead();

        $base = self::inspectSqlite($probe);
        @unlink($probe);

        // 库引用了几张图、其中几张包里没有——这才是有意义的「缺图」判断
        $missing = 0;

        foreach (array_keys($base['refs']) as $rel) {
            if (!isset($packed[$rel])) {
                $missing++;
            }
        }

        $base['kind']           = 'site';
        $base['size']           = (int) (@filesize($path) ?: 0);
        $base['human']          = Images::humanSize($base['size']);
        $base['packed_images']  = count($packed);
        $base['missing_images'] = $missing;
        $base['created_at']     = (string) ($manifest['created_at'] ?? '');
        $base['created_ts']     = (int) ($manifest['created_ts'] ?? 0);
        $base['pack_version']   = (string) ($manifest['version'] ?? '');
        $base['pack_prefix']    = $prefix;

        if ($prefix !== '') {
            $base['warnings'][] = '这个包外面套了一层「' . rtrim($prefix, '/') . '/」文件夹，已自动识别，不影响恢复。';
        }

        $imgBytes = array_sum($packed);

        if ($missing > 0) {
            $base['warnings'][] = '库里有 ' . $missing . '/' . $base['images'] . ' 张图在这个包里找不到，恢复后那些菜谱会缺图。';
        } elseif ($base['images'] > 0) {
            $base['warnings'][] = '图片齐全：库里引用的 ' . $base['images'] . ' 张图都在包里。';
        }

        if ($base['packed_images'] > 0) {
            $base['warnings'][] = '这是整站包：数据库 + ' . $base['packed_images'] . ' 个图片文件（'
                . Images::humanSize($imgBytes) . '），恢复时会一起铺回 data/uploads/。';
        }

        return $base;
    }

    /**
     * 用一个独立的 PDO 开候选文件，逐关验：
     * 文件头 → 完整性 → 表结构 → 版本 → 行数 → 图片引用。
     * 任何一关不过就 400 回绝。
     *
     * @return array{size:int,human:string,schema:int,counts:array,warnings:array,images:int,refs:array<string,bool>}
     */
    private static function inspectSqlite(string $path): array
    {
        clearstatcache(true, $path);

        $size = (int) (@filesize($path) ?: 0);

        if ($size <= 0) {
            Http::fail('文件是空的', 400, 'file');
        }

        // 先看身份证：SQLite 文件的前 16 个字节是固定的
        $head = @file_get_contents($path, false, null, 0, 16);

        if (!is_string($head) || strncmp($head, "SQLite format 3\0", 16) !== 0) {
            Http::fail('这不是 SQLite 备份文件（文件头对不上）。请选本站生成的 recipe-*.zip。', 400, 'file');
        }

        $pdo = null;

        try {
            $pdo = new \PDO('sqlite:' . $path, null, null, array(
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT            => 5,
            ));

            $check = (string) $pdo->query('PRAGMA integrity_check')->fetchColumn();
            $ver   = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        } catch (\Throwable $e) {
            Http::fail('这个文件打不开：' . $e->getMessage(), 400, 'file');
        }

        if (trim($check) !== 'ok') {
            $pdo = null;
            Http::fail('这个备份自身有问题（完整性检查没通过），别拿它恢复。', 400, 'file');
        }

        $tables = array();

        foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll() as $r) {
            $tables[] = (string) $r['name'];
        }

        $need = array('users', 'sessions', 'settings', 'recipes', 'recipe_ingredients', 'recipe_steps', 'tags', 'ingredients');
        $lack = array_values(array_diff($need, $tables));

        if ($lack) {
            $pdo = null;
            Http::fail('这个库缺本站的表（' . implode('、', $lack) . '），不是菜谱的备份。', 400, 'file');
        }

        if ($ver > Schema::VERSION) {
            $pdo = null;

            Http::fail(
                '这份备份来自更新的版本（库结构 v' . $ver . '，本机最高只认到 v' . Schema::VERSION
                . '）。请先把程序升到新版再恢复。',
                400,
                'file'
            );
        }

        // 口径跟站点后台一致：回收站里的菜谱不算「你有的菜谱」。
        // 老版本备份没有 deleted_at 这一列，问过 PRAGMA 再决定要不要过滤。
        $hasTrash = false;

        foreach ($pdo->query('PRAGMA table_info(recipes)')->fetchAll() as $c) {
            if ((string) $c['name'] === 'deleted_at') {
                $hasTrash = true;
                break;
            }
        }

        $alive = $hasTrash ? ' WHERE deleted_at = 0' : '';

        $counts = array();

        foreach (array('ingredients', 'tags', 'pantry_items', 'meal_plans', 'shopping_lists', 'recipe_comments', 'cook_logs', 'users') as $t) {
            $counts[$t] = in_array($t, $tables, true) ? (int) $pdo->query('SELECT COUNT(*) FROM ' . $t)->fetchColumn() : 0;
        }

        $counts['recipes'] = (int) $pdo->query('SELECT COUNT(*) FROM recipes' . $alive)->fetchColumn();

        $refs = array();

        foreach ($pdo->query('SELECT cover FROM recipes' . $alive)->fetchAll() as $r) {
            $rel = (string) $r['cover'];

            // 没封面的菜谱 cover 是空串，别把它算成「一张找不到的图」
            if ($rel !== '') {
                $refs[$rel] = true;
            }
        }

        if (in_array('recipe_steps', $tables, true)) {
            foreach ($pdo->query("SELECT image FROM recipe_steps WHERE image <> ''")->fetchAll() as $r) {
                $rel = (string) $r['image'];

                if ($rel !== '') {
                    $refs[$rel] = true;
                }
            }
        }

        $pdo = null;

        $warnings = array();

        if ($ver < Schema::VERSION) {
            $warnings[] = '这份备份的库结构是 v' . $ver . '，恢复时会自动升级到 v' . Schema::VERSION . '（只加表加列，不删数据）。';
        }

        if ($ver === 0) {
            $warnings[] = '这份文件没有版本号标记，可能不是完整备份。';
        }

        return array(
            'size'     => $size,
            'human'    => Images::humanSize($size),
            'schema'   => $ver,
            'counts'   => $counts,
            'warnings' => $warnings,
            'images'   => count($refs),
            'refs'     => $refs,
        );
    }

    /**
     * 整库替换的本体。
     *
     * 顺序是刻意的：
     *   1) VACUUM INTO 给现状拍照（写操作中也能拿到一致快照）
     *   2) 断开 PDO（不断开 Windows 上改不动名）
     *   3) 旧库让位 → 新库上位 → probe 能不能开 → 不行就退回
     *   4) 重连 boot() 触发迁移
     *   5) 把当前会话塞回新库，别把管理员自己关在门外
     *
     * @param array<string,mixed> $info inspectBackupFile 的结果
     */
    private static function swapDatabase(
        string $src,
        array $info,
        string $label,
        string $sid,
        string $username,
        int $meId
    ): array {
        $dst = Config::dbPath();
        $dir = self::backupDir();

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            Http::fail('备份目录创建失败：' . $dir, 500);
        }

        // ---- 1. 先给现状拍照。恢复完觉得不对，靠它能一键退回。
        $snap = $dir . '/before-restore-' . date('Ymd-His') . '.sqlite';

        if (is_file($snap)) {
            $snap = $dir . '/before-restore-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.sqlite';
        }

        try {
            Db::pdo()->exec('VACUUM INTO ' . Db::pdo()->quote($snap));
        } catch (\Throwable $e) {
            Http::fail('恢复前的安全快照没做成：' . $e->getMessage() . '。已经中止，当前数据没动。', 500);
        }

        if (!is_file($snap)) {
            Http::fail('恢复前的安全快照没做成（磁盘可能满了）。已经中止，当前数据没动。', 500);
        }

        // ---- 2. 断开连接
        Db::close();

        // ---- 3. 换文件
        $old   = $dst . '.old';
        $walShm = array($dst . '-wal', $dst . '-shm');

        foreach (array_merge(array($old), $walShm) as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }

        // 可能有在途请求正握着这个库，给它三次机会（每次等 300ms）
        $moved = false;

        for ($i = 0; $i < 3; $i++) {
            if (!is_file($dst) || @rename($dst, $old)) {
                $moved = true;
                break;
            }

            usleep(300000);
        }

        if (!$moved) {
            Db::pdo();
            Http::fail('数据库文件被占用（可能还有请求没处理完），稍等几秒再试。当前数据没动。', 409);
        }

        if (!@rename($src, $dst)) {
            // staging 过来失败：把老库放回原位
            if (is_file($old) && !is_file($dst)) {
                @rename($old, $dst);
            }

            foreach ($walShm as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }

            Db::pdo();
            Http::fail('恢复失败：写不进数据库文件，已经原样退回。', 500);
        }

        // 备份文件理论上不带 wal/shm，有也当作脏东西清掉
        foreach ($walShm as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }

        @chmod($dst, 0664);

        // ---- 4. 先确认新库能用，再认账
        try {
            $probe = new \PDO('sqlite:' . $dst, null, null, array(
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 5,
            ));

            $res = (string) $probe->query('PRAGMA quick_check')->fetchColumn();
            $probe = null;

            if (trim($res) !== 'ok') {
                throw new \RuntimeException('完整性检查结果：' . $res);
            }
        } catch (\Throwable $e) {
            if (is_file($dst)) {
                @unlink($dst);
            }

            if (is_file($old)) {
                @rename($old, $dst);
            }

            Db::pdo();
            Http::fail('恢复后的数据库读不出来（' . $e->getMessage() . '），已经退回原来的数据。', 500);
        }

        @unlink($old);

        // ---- 5. 重连 + 迁移到当前版本
        Db::boot();

        // ---- 6. 别把自己锁在门外：sessions 表也在这个库里，换完之后旧 token 多半没了
        $relogin   = false;
        $nowAdmin  = false;

        if ($sid !== '' && preg_match('/^[a-f0-9]{48}$/', $sid) === 1) {
            $alive = (int) Db::value('SELECT COUNT(*) FROM sessions WHERE id = ?', array($sid));

            if ($alive > 0) {
                $nowAdmin = (int) Db::value(
                    'SELECT COUNT(*) FROM sessions s JOIN users u ON u.id = s.user_id
                      WHERE s.id = ? AND u.role = ?',
                    array($sid, 'admin')
                ) === 1;
            } elseif ($username !== '') {
                $u = Db::one('SELECT * FROM users WHERE username = ?', array($username));

                if ($u && (int) $u['is_active'] === 1) {
                    Db::exec(
                        'INSERT OR REPLACE INTO sessions (id, user_id, created_at, expires_at, ip, ua)
                         VALUES (?, ?, ?, ?, ?, ?)',
                        array(
                            $sid,
                            (int) $u['id'],
                            time(),
                            time() + max(1, (int) Config::get('sessionDays', 30)) * 86400,
                            Http::ip(),
                            Http::ua(),
                        )
                    );

                    $nowAdmin = (string) $u['role'] === 'admin';
                } else {
                    // 新库里没这个账号了（比如恢复到了更早的备份）
                    $relogin = true;
                }
            } else {
                $relogin = true;
            }
        } else {
            $relogin = true;
        }

        // 跟管理后台总览口径一致：回收站里的不算数
        $counts = array(
            'recipes' => (int) Db::value('SELECT COUNT(*) FROM recipes WHERE deleted_at = 0'),
            'users'   => (int) Db::value('SELECT COUNT(*) FROM users'),
        );

        // 日志里连 this 信息一起留档：谁的账号执行的、换之前的库留在哪个文件、包里带了几张图
        Logs::add(
            'backup_restore',
            $label,
            '恢复前快照 ' . basename($snap) . '；恢复后 ' . $counts['recipes'] . ' 道菜谱 / '
            . $counts['users'] . ' 个账号（库结构 v' . (int) ($info['schema'] ?? 0) . ' → v' . Db::version() . '）'
            . (($info['kind'] ?? 'db') === 'site' ? '；包内图片 ' . (int) ($info['packed_images'] ?? 0) . ' 个' : '（老格式，不含图片）'),
            $meId,
            $username
        );

        self::backupPrune();

        return array(
            'file'          => $label,
            'snapshot'      => basename($snap),
            'schema_before' => (int) ($info['schema'] ?? 0),
            'schema_after'  => Db::version(),
            'migrated'      => Db::version() > (int) ($info['schema'] ?? 0),
            'counts'        => $counts,
            'relogin'       => $relogin,
            'still_admin'   => $nowAdmin,
            'items'         => self::backupFiles(),
        );
    }

    /** 上传错误码翻译成人话（跟 Images 里那套保持一致） */
    private static function uploadErrorText(int $code): string
    {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return '文件超过服务器允许的大小（改 PHP 的 upload_max_filesize / post_max_size）';
            case UPLOAD_ERR_PARTIAL:
                return '文件只传了一半，换个网络再试';
            case UPLOAD_ERR_NO_FILE:
                return '没有选择文件';
            case UPLOAD_ERR_NO_TMP_DIR:
                return '服务器缺临时目录';
            case UPLOAD_ERR_CANT_WRITE:
                return '服务器写磁盘失败';
            default:
                return '上传失败（错误码 ' . $code . '）';
        }
    }

    private static function backupFiles(): array
    {
        $dir = self::backupDir();

        if (!is_dir($dir)) {
            return array();
        }

        $out = array();

        // .zip 是整站包（库 + 图片）；.sqlite 是老格式，只有库，还能恢复但会缺图
        foreach (glob($dir . '/*.zip') ?: array() as $f) {
            $out[] = self::backupItem($f, true);
        }

        foreach (glob($dir . '/*.sqlite') ?: array() as $f) {
            $out[] = self::backupItem($f, false);
        }

        usort($out, static function (array $a, array $b): int {
            return $b['time'] <=> $a['time'];
        });

        return $out;
    }

    private static function backupItem(string $f, bool $pack): array
    {
        $base = basename($f);
        $size = (int) (@filesize($f) ?: 0);

        return array(
            'file'  => $base,
            // before-restore-* 是恢复前自动拍的快照：它留在列表里，
            // 就是给「刚恢复完发现不对」准备的回滚通道
            'kind'  => strpos($base, 'before-restore-') === 0 ? 'pre-restore' : 'backup',
            'pack'  => $pack,
            'size'  => $size,
            'human' => Images::humanSize($size),
            'time'  => (int) (@filemtime($f) ?: 0),
        );
    }

    /**
     * 淘汰旧备份。
     *
     * 手建的备份和「恢复前的自动快照」分开算：快照是回滚通道，
     * 不留的话等于把 undo 键扣掉了，但也别让它无限堆积。
     */
    private static function backupPrune(int $keep = 10, int $keepAuto = 3): int
    {
        $files = self::backupFiles();
        $n     = 0;

        foreach (array('backup' => $keep, 'pre-restore' => $keepAuto) as $kind => $limit) {
            $same = array();

            foreach ($files as $f) {
                if ($f['kind'] === $kind) {
                    $same[] = $f;
                }
            }

            foreach (array_slice($same, $limit) as $f) {
                if (@unlink(self::backupDir() . '/' . $f['file'])) {
                    $n++;
                }
            }
        }

        return $n;
    }

    /**
     * 备份文件名白名单。
     * 认两种：recipe-*（手建）和 before-restore-*（恢复前自动快照）；
     * 后缀 .zip 是整站包，.sqlite 是老的纯数据库格式。
     */
    private static function safeBackupName(string $file): string
    {
        $file = basename(trim($file));

        return preg_match('/^(recipe|before-restore)-\d{8}-\d{6}(-[a-f0-9]{4})?\.(zip|sqlite)$/', $file) ? $file : '';
    }

    private static function dbSize(): array
    {
        $bytes = 0;

        foreach (array('', '-wal', '-shm') as $suffix) {
            $f = Config::dbPath() . $suffix;

            if (is_file($f)) {
                $bytes += (int) (@filesize($f) ?: 0);
            }
        }

        return array('bytes' => $bytes, 'human' => Images::humanSize($bytes));
    }
}
