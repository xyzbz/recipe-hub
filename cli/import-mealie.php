<?php
declare(strict_types=1);

/**
 * 从 Mealie 迁移菜谱。
 *
 *   # Mealie 后台「导出数据」给的是一个 zip（每个菜谱一个目录，里面有 recipe.json + assets/）
 *   php cli/import-mealie.php mealie-export.zip --images
 *
 *   # 已经解压开了
 *   php cli/import-mealie.php /opt/mealie-export --images
 *
 *   # 单个菜谱的 JSON（Mealie 菜谱页 → 导出 → JSON）
 *   php cli/import-mealie.php 可乐鸡翅.json --dry-run
 *
 * 选项
 *   --owner=1        归属用户（填 id 或用户名；默认取第一个管理员）
 *   --overwrite      同名菜谱覆盖更新（默认跳过，不改动已有菜谱）
 *   --images         连图片一起导（zip/dir 里的 assets/ 或同级图片）
 *   --dry-run        只解析、只报告，不写库——强烈建议先跑一遍
 *   --limit=N        只处理前 N 条（试水用）
 *   --json           结果输出成 JSON（方便脚本接）
 *
 * 说明
 * - 同名判定按菜名精确匹配。Mealie 里重名的菜只会进来一个，其余算「跳过」，
 *   要全都要就加 --overwrite（会覆盖成最后一条）。
 * - zip 是直接在内存里读条目的，不解压到磁盘——中文目录名在某些系统上
 *   解压会串味，绕开文件系统最稳。
 * - 图片走的是和网页上传同一条管线：转 WebP、出缩略图、按 YYYY/MM 归档。
 *   GD 扩展没装的话图片会跳过，但菜谱文字部分照常导入。
 * - 食材单位是「尽力翻译」：Mealie 的 cup / ounce 这类单位我们表里没有，
 *   会原样留着，导入后自己改一眼。见 README「从 Mealie 迁移」。
 */

require dirname(__DIR__) . '/bootstrap.php';

use RH\Db;
use RH\Images;
use RH\Logs;
use RH\Repo\Recipe;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "这个脚本只能在命令行跑。\n");
    exit(1);
}

// ------------------------------------------------------------------ 参数

$opts = array('owner' => '', 'overwrite' => false, 'images' => false, 'dryRun' => false, 'limit' => 0, 'json' => false);
$path = '';

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--overwrite') {
        $opts['overwrite'] = true;
    } elseif ($arg === '--images') {
        $opts['images'] = true;
    } elseif ($arg === '--dry-run') {
        $opts['dryRun'] = true;
    } elseif ($arg === '--json') {
        $opts['json'] = true;
    } elseif (strpos($arg, '--owner=') === 0) {
        $opts['owner'] = substr($arg, 8);
    } elseif (strpos($arg, '--limit=') === 0) {
        $opts['limit'] = max(0, (int) substr($arg, 8));
    } elseif (strpos($arg, '-') === 0) {
        fwrite(STDERR, "不认识的参数：{$arg}\n");
        exit(2);
    } else {
        $path = $arg;
    }
}

if ($path === '') {
    fwrite(STDERR, "用法：php cli/import-mealie.php <zip|目录|recipe.json> [--images] [--overwrite] [--dry-run]\n");
    exit(2);
}

// ------------------------------------------------------------------ 归属用户

$ownerId = 0;

if ($opts['owner'] !== '') {
    $who   = $opts['owner'];
    $rows  = ctype_digit($who)
        ? Db::all('SELECT * FROM users WHERE id = ?', array((int) $who))
        : Db::all('SELECT * FROM users WHERE username = ?', array($who));

    if ($rows === array()) {
        fwrite(STDERR, "找不到用户：{$who}\n");
        exit(1);
    }

    $ownerId = (int) $rows[0]['id'];
} else {
    $ownerId = (int) Db::value("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id ASC LIMIT 1");

    if ($ownerId <= 0) {
        $ownerId = (int) Db::value('SELECT id FROM users ORDER BY id ASC LIMIT 1');
    }

    if ($ownerId <= 0) {
        fwrite(STDERR, "站点里还没有用户，先在浏览器里完成安装引导再来导入。\n");
        exit(1);
    }
}

// ------------------------------------------------------------------ 读取来源

$IMAGE_EXT = array('jpg', 'jpeg', 'png', 'webp', 'gif');
$tmpFiles  = array();   // 用完要删的临时图

/** 去掉 BOM，解 JSON，展开成菜谱数组（单条 / {recipes:[...]} / {items:[...]}） */
function rh_expand(string $text): array
{
    if (strpos($text, "\xEF\xBB\xBF") === 0) {
        $text = substr($text, 3);
    }

    $data = json_decode($text, true);

    if (!is_array($data)) {
        return array();
    }

    if (isset($data['recipes']) && is_array($data['recipes'])) {
        return $data['recipes'];
    }

    if (isset($data['items']) && is_array($data['items'])) {
        return $data['items'];
    }

    return array($data);
}

/**
 * 从一堆候选图里挑最能代表这道菜的。
 *
 * Mealie 的导出目录里一张图会拆成三份：original.webp / min-original.webp（缩略图）
 * / tiny-original.webp（更小的缩略图）。按文件名排序会把 min- 那个先挑出来，
 * 封面就成了一张模糊图。所以先排除缩略图，剩下的再优先 original.*。
 */
function rh_pick_image(array $names): string
{
    $names = array_values(array_filter($names, static function (string $n): bool {
        return trim($n) !== '';
    }));

    if ($names === array()) {
        return '';
    }

    sort($names, SORT_NATURAL | SORT_FLAG_CASE);

    $full = array_values(array_filter($names, static function (string $n): bool {
        $b = strtolower(basename($n));

        return strpos($b, 'min-') !== 0 && strpos($b, 'tiny-') !== 0 && strpos($b, 'thumb') === false;
    }));

    if ($full !== array()) {
        usort($full, static function (string $a, string $b): int {
            return (int) (strpos(strtolower(basename($b)), 'original') === 0)
                - (int) (strpos(strtolower(basename($a)), 'original') === 0);
        });

        return $full[0];
    }

    return $names[0];
}

/** zip 里找图：同级、assets/、images/ 三级，都找不到再退到包根目录 */
function rh_zip_image_for(string $dir, array $byDir): string
{
    foreach (array($dir, $dir . '/assets', $dir . '/images', '') as $d) {
        if (!isset($byDir[$d]) || $byDir[$d] === array()) {
            continue;
        }

        $hit = rh_pick_image($byDir[$d]);

        if ($hit !== '') {
            return $hit;
        }
    }

    return '';
}

function rh_is_image(string $name, array $exts): bool
{
    return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $exts, true);
}

/** zip：直接在内存里读条目，不解压 */
function rh_read_zip(string $zip, array $exts): array
{
    if (!class_exists('ZipArchive')) {
        fwrite(STDERR, "PHP 没有 zip 扩展，读不了 {$zip}。请先在服务器上把它解压开，再把目录传给我。\n");
        exit(1);
    }

    $za = new ZipArchive();

    if ($za->open($zip) !== true) {
        fwrite(STDERR, "打不开 zip：{$zip}\n");
        exit(1);
    }

    $recipes  = array();   // ['dir'=>..., 'raw'=>...]
    $byDir    = array();   // dir => [zip 条目名]
    $otherJs  = array();   // 没有 recipe.json 时，退而求其次
    $tmpFiles = array();

    for ($i = 0; $i < $za->numFiles; $i++) {
        $name = (string) $za->getNameIndex($i);

        if ($name === '' || substr($name, -1) === '/') {
            continue;
        }

        $name = str_replace('\\', '/', $name);
        $base = basename($name);
        $dir  = trim(dirname($name), '/.');
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if ($ext === 'json') {
            $text = $za->getFromIndex($i);

            if (!is_string($text)) {
                continue;
            }

            foreach (rh_expand($text) as $r) {
                if ($base === 'recipe.json') {
                    $recipes[] = array('dir' => $dir, 'raw' => $r, 'image' => '');
                } else {
                    $otherJs[] = array('dir' => $dir, 'raw' => $r, 'image' => '');
                }
            }

            continue;
        }

        if (rh_is_image($name, $exts)) {
            $byDir[$dir][] = $name;
        }
    }

    if ($recipes === array()) {
        $recipes = $otherJs;
    }

    // 图片：写到一个 ASCII 名字的临时文件再入库（GD 只认真实路径）
    foreach ($recipes as $k => $item) {
        $entry = rh_zip_image_for((string) $item['dir'], $byDir);

        if ($entry === '') {
            continue;
        }

        $bin = $za->getFromName($entry);

        if (!is_string($bin) || $bin === '') {
            continue;
        }

        $tmp = sys_get_temp_dir() . '/rh-img-' . bin2hex(random_bytes(6)) . '.' . pathinfo($entry, PATHINFO_EXTENSION);

        if (@file_put_contents($tmp, $bin) === false) {
            continue;
        }

        $tmpFiles[]     = $tmp;
        $recipes[$k]['image'] = $tmp;
    }

    $za->close();

    return array($recipes, $tmpFiles);
}

/** 目录 / 单个 json 文件 */
function rh_read_fs(string $src, array $exts): array
{
    $files = array();

    if (is_file($src)) {
        $files = array($src);
    } else {
        $it      = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
        $named   = array();
        $allJson = array();

        foreach ($it as $f) {
            if (!$f->isFile() || strtolower($f->getExtension()) !== 'json') {
                continue;
            }

            if (strtolower($f->getFilename()) === 'recipe.json') {
                $named[] = $f->getPathname();
            } else {
                $allJson[] = $f->getPathname();
            }
        }

        sort($named);
        sort($allJson);
        $files = $named !== array() ? $named : $allJson;
    }

    $out = array();

    foreach ($files as $file) {
        $text = (string) file_get_contents($file);

        foreach (rh_expand($text) as $r) {
            $img = '';

            if (is_dir(dirname($file))) {
                // Mealie 新版把图放在同级 images/ 里，老版是 assets/
                foreach (array(dirname($file) . '/images', dirname($file) . '/assets', dirname($file)) as $d) {
                    if (!is_dir($d)) {
                        continue;
                    }

                    $picks = array();

                    foreach (scandir($d) ?: array() as $n) {
                        if ($n === '.' || $n === '..' || is_dir($d . '/' . $n)) {
                            continue;
                        }

                        if (rh_is_image($n, $exts)) {
                            $picks[] = $d . '/' . $n;
                        }
                    }

                    if ($picks !== array()) {
                        $img = rh_pick_image($picks);
                        break;
                    }
                }
            }

            $out[] = array('dir' => basename(dirname($file)), 'raw' => $r, 'image' => $img);
        }
    }

    return array($out, array());
}

if (is_file($path) && preg_match('/\.zip$/i', $path)) {
    list($queue, $tmpFiles) = rh_read_zip($path, $IMAGE_EXT);
} elseif (is_dir($path) || is_file($path)) {
    list($queue, $tmpFiles) = rh_read_fs($path, $IMAGE_EXT);
} else {
    fwrite(STDERR, "找不到：{$path}\n");
    exit(1);
}

if ($opts['limit'] > 0) {
    $queue = array_slice($queue, 0, $opts['limit']);
}

// ------------------------------------------------------------------ 开跑

$stats = array(
    'total'   => count($queue),
    'created' => 0,
    'updated' => 0,
    'skipped' => 0,
    'failed'  => 0,
    'images'  => 0,
    'errors'  => array(),
);

if ($queue === array()) {
    fwrite(STDERR, "没在这份数据里找到菜谱。检查是不是给错了路径，或者 Mealie 导出的是别的格式。\n");
    exit(1);
}

foreach ($queue as $item) {
    $raw = $item['raw'];

    if (!is_array($raw)) {
        $stats['failed']++;
        $stats['errors'][] = '有一条不是有效的 JSON 对象，跳过';
        continue;
    }

    $name = trim((string) ($raw['name'] ?? $raw['title'] ?? ''));

    if ($name === '') {
        $stats['failed']++;
        $stats['errors'][] = '有一条没有菜名，跳过';
        continue;
    }

    // 图片：先入库，再把相对路径塞进 cover，跟网页上传落在同一个地方。
    // 已经存在又不覆盖的话就别收图了——菜谱反正要跳过，收了就是一张没人认领的孤儿图。
    $cover = '';

    if ($opts['images'] && $item['image'] !== '' && ($opts['overwrite'] || Recipe::findByName($name) === null)) {
        try {
            $saved = Images::ingest($item['image'], basename($item['image']));
            $cover = (string) ($saved['path'] ?? '');
            $stats['images']++;
        } catch (\Throwable $e) {
            // 图片失败不算菜谱失败：图没了还能补，菜谱没了才亏
            $stats['errors'][] = $name . '：' . $e->getMessage() . '（菜谱照常导入）';
        }
    }

    if ($cover !== '') {
        $raw['cover'] = $cover;
    }

    if ($opts['dryRun']) {
        $ings  = $raw['recipeIngredient'] ?? $raw['recipe_ingredient'] ?? $raw['ingredients'] ?? array();
        $steps = $raw['recipeInstructions'] ?? $raw['recipe_instructions'] ?? $raw['steps'] ?? array();
        $notes = $raw['notes'] ?? array();

        printf(
            "  %-26s 食材 %2d · 步骤 %2d · 笔记 %d%s\n",
            mb_substr($name, 0, 26),
            is_array($ings) ? count($ings) : 0,
            is_array($steps) ? count($steps) : 0,
            is_array($notes) ? count($notes) : 0,
            $cover !== '' ? ' · 有图' : ''
        );
        continue;
    }

    try {
        $existed = Recipe::findByName($name) !== null;
        $res     = Recipe::import(array('recipes' => array($raw)), $ownerId, $opts['overwrite']);

        if ($existed && $opts['overwrite']) {
            $stats['updated'] += (int) $res['created'];
        } else {
            $stats['created'] += (int) $res['created'];
        }

        $stats['skipped'] += (int) $res['skipped'];

        if ($res['errors'] !== array()) {
            $stats['failed'] += count($res['errors']);
            $stats['errors']  = array_merge($stats['errors'], $res['errors']);
        }
    } catch (\Throwable $e) {
        $stats['failed']++;
        $stats['errors'][] = $name . '：' . $e->getMessage();
    }
}

foreach ($tmpFiles as $t) {
    @unlink($t);
}

if (!$opts['dryRun']) {
    Logs::add(
        'mealie_import',
        basename($path),
        '共 ' . $stats['total'] . ' 条：新增 ' . $stats['created'] . '、更新 ' . $stats['updated']
            . '、跳过 ' . $stats['skipped'] . '、失败 ' . $stats['failed'] . '、图片 ' . $stats['images']
    );
}

// ------------------------------------------------------------------ 结果

if ($opts['json']) {
    echo json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit($stats['failed'] > 0 ? 1 : 0);
}

echo "\n========================================\n";
echo $opts['dryRun'] ? "预演（没写库）\n" : "导入完成\n";
echo "========================================\n";
echo "菜谱总数：{$stats['total']}\n";

if (!$opts['dryRun']) {
    echo "新增：{$stats['created']}    更新：{$stats['updated']}    跳过（同名）：{$stats['skipped']}\n";
    echo "图片：{$stats['images']}    失败：{$stats['failed']}\n";
}

if ($stats['errors'] !== array()) {
    echo "\n问题（最多列 15 条）：\n";

    foreach (array_slice($stats['errors'], 0, 15) as $e) {
        echo '  - ' . $e . "\n";
    }
}

if ($opts['dryRun']) {
    echo "\n看着没问题就把 --dry-run 去掉再跑一次。\n";
}

exit($stats['failed'] > 0 ? 1 : 0);
