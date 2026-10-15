<?php
declare(strict_types=1);

namespace RH;

/**
 * 图片处理。
 *
 * 上传的图一律重新编码：缩放 + 转 WebP + 生成缩略图。
 * 手机随手拍的 4MB 原图，进来之后通常剩 150KB 左右，
 * 一个家庭用几年的图片量也就几百 MB。
 */
final class Images
{
    private const ALLOWED = array(
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_GIF  => 'gif',
    );

    private const MIME = array(
        'jpg'  => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'gif'  => 'image/gif',
    );

    /**
     * 接收一个上传文件，落盘并返回相对路径。
     *
     * @return array{path:string,thumb:string,width:int,height:int,size:int}
     */
    public static function upload(array $file): array
    {
        if (!isset($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
            Http::fail('没有收到文件', 400, 'file');
        }

        $err = (int) ($file['error'] ?? 0);

        if ($err !== UPLOAD_ERR_OK) {
            Http::fail(self::uploadErrorText($err), 400, 'file');
        }

        $maxBytes = max(1, (int) Config::get('uploadMaxMb', 8)) * 1024 * 1024;
        $size     = (int) ($file['size'] ?? 0);

        if ($size <= 0) {
            Http::fail('文件是空的', 400, 'file');
        }

        if ($size > $maxBytes) {
            Http::fail('图片不能超过 ' . (int) Config::get('uploadMaxMb', 8) . 'MB', 413, 'file');
        }

        $info = @getimagesize((string) $file['tmp_name']);

        if (!$info || !isset($info[2]) || !isset(self::ALLOWED[(int) $info[2]])) {
            Http::fail('只支持 JPG / PNG / WebP / GIF 图片', 415, 'file');
        }

        // 环境自检要放在文件校验之后：用户选错文件时，先给准确的那条报错。
        // 走到这里还报 GD 缺失，那是服务器没装好，得给出能定位的线索。
        if (!function_exists('imagecreatetruecolor')) {
            Logs::add('upload', 'gd-missing', 'PHP 没有 GD 扩展，图片上传不可用');

            Http::fail(
                '服务器缺少 GD 图片扩展，暂时无法处理图片上传。'
                . 'Docker 部署请确认镜像里的 gd 扩展编译成功；'
                . '非 Docker 部署请安装 php-gd。',
                500,
                'file'
            );
        }

        $src = self::load((string) $file['tmp_name'], (int) $info[2]);

        if (!$src) {
            Http::fail('图片解析失败，可能文件损坏了', 400, 'file');
        }

        return self::store($src, (string) $file['tmp_name'], (int) $info[2], $size, (string) ($file['name'] ?? ''));
    }

    /**
     * 收下一张已经读进来的图：EXIF 纠正方向 → 出主图 + 缩略图 → 存成 WebP。
     *
     * HTTP 上传和命令行导入共用这一段——两条路各写一遍迟早会分叉
     * （比如一边转 WebP 一边不转），共用一个出口就不会。
     *
     * @param resource|\GdImage $src
     */
    private static function store($src, string $srcPath, int $type, int $size, string $name): array
    {
        $src = self::applyExifRotation($src, $srcPath, $type);

        $w0 = imagesx($src);
        $h0 = imagesy($src);

        // 主图
        $mainW = (int) Config::get('imageMaxWidth', 1600);
        $main  = self::fit($src, $mainW, $mainW);
        $thumb = self::fit($src, (int) Config::get('thumbWidth', 480), (int) Config::get('thumbWidth', 480));
        imagedestroy($src);

        $stamp  = date('Y/m');
        $base   = self::randomName();
        $relDir = $stamp;

        $dir = Config::uploadsDir() . '/' . $relDir;

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            imagedestroy($main);
            imagedestroy($thumb);
            Http::fail('图片目录写不进去，检查 data 卷的权限', 500);
        }

        $relMain  = $relDir . '/' . $base . '.webp';
        $relThumb = $relDir . '/' . $base . '.t.webp';

        $ok = self::saveWebp($main, $dir . '/' . $base . '.webp')
            && self::saveWebp($thumb, $dir . '/' . $base . '.t.webp');

        $outW = imagesx($main);
        $outH = imagesy($main);

        imagedestroy($main);
        imagedestroy($thumb);

        if (!$ok) {
            @unlink($dir . '/' . $base . '.webp');
            @unlink($dir . '/' . $base . '.t.webp');
            Http::fail('图片写入失败', 500);
        }

        $finalSize = (int) (@filesize(Config::uploadsDir() . '/' . $relMain) ?: 0);

        Logs::add('upload', $relMain, $outW . 'x' . $outH . ' · ' . self::humanSize($size) . ' -> ' . self::humanSize($finalSize));

        return array(
            'path'   => $relMain,
            'thumb'  => $relThumb,
            'width'  => $outW,
            'height' => $outH,
            'size'   => $finalSize,
        );
    }

    /**
     * 从磁盘上的一个普通文件收图（Mealie / 备份导入这类命令行场景用）。
     *
     * 为什么不能直接调 upload()：那条路会校验 is_uploaded_file()，
     * 而导入的图片是从 zip 里解压出来的，PHP 不认它是「上传来的」。
     * 校验规则（格式白名单、大小上限、GD 自检）跟上传保持一致。
     *
     * @return array{path: string, thumb: string, width: int, height: int, size: int}
     */
    public static function ingest(string $path, string $name = ''): array
    {
        $maxBytes = max(1, (int) Config::get('uploadMaxMb', 8)) * 1024 * 1024;

        if (!is_file($path)) {
            self::reject('图片不存在：' . $path, 400);
        }

        $size = (int) (@filesize($path) ?: 0);

        if ($size <= 0) {
            self::reject('文件是空的：' . basename($path), 400);
        }

        if ($size > $maxBytes) {
            self::reject('图片不能超过 ' . (int) Config::get('uploadMaxMb', 8) . 'MB：' . basename($path), 413);
        }

        $info = @getimagesize($path);

        if (!$info || !isset($info[2]) || !isset(self::ALLOWED[(int) $info[2]])) {
            self::reject('只支持 JPG / PNG / WebP / GIF 图片：' . basename($path), 415);
        }

        if (!function_exists('imagecreatetruecolor')) {
            Logs::add('ingest', 'gd-missing', 'PHP 没有 GD 扩展，图片导入跳过');

            self::reject('服务器缺少 GD 图片扩展，图片导入不可用（菜谱文字部分不受影响）。', 500);
        }

        $src = self::load($path, (int) $info[2]);

        if (!$src) {
            self::reject('图片解析失败，可能文件损坏了：' . basename($path), 400);
        }

        return self::store($src, $path, (int) $info[2], $size, $name !== '' ? $name : basename($path));
    }

    /**
     * 报个错。命令行下必须抛异常而不是调 Http::fail——
     * 后者是给 HTTP 请求用的，最后会 exit，导入几百道菜谱时
     * 一张坏图就会把整个进程掐死，前面的全白干。
     */
    private static function reject(string $msg, int $code): void
    {
        if (PHP_SAPI === 'cli') {
            throw new \RuntimeException($msg);
        }

        Http::fail($msg, $code);
    }

    /** 删除一张图（连带缩略图）。只在路径合法时动手。 */
    public static function remove(?string $rel): void
    {
        $rel = self::safeRel($rel);

        if ($rel === '') {
            return;
        }

        $base = Config::uploadsDir() . '/' . $rel;

        if (is_file($base)) {
            @unlink($base);
        }

        $thumb = preg_replace('/\.webp$/', '.t.webp', $base);

        if (is_string($thumb) && $thumb !== $base && is_file($thumb)) {
            @unlink($thumb);
        }
    }

    public static function absPath(?string $rel): string
    {
        $rel = self::safeRel($rel);

        return $rel === '' ? '' : Config::uploadsDir() . '/' . $rel;
    }

    public static function url(?string $rel): string
    {
        $rel = self::safeRel($rel);

        return $rel === '' ? '' : '/uploads/' . $rel;
    }

    public static function thumbUrl(?string $rel): string
    {
        $rel = self::safeRel($rel);

        if ($rel === '') {
            return '';
        }

        return '/uploads/' . (string) preg_replace('/\.webp$/', '.t.webp', $rel);
    }

    public static function mimeOf(string $rel): string
    {
        $ext = strtolower((string) pathinfo($rel, PATHINFO_EXTENSION));

        return self::MIME[$ext] ?? 'application/octet-stream';
    }

    /** 上传目录占用情况 */
    public static function usage(): array
    {
        $dir   = Config::uploadsDir();
        $bytes = 0;
        $files = 0;

        if (is_dir($dir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($it as $f) {
                if ($f->isFile()) {
                    $bytes += (int) $f->getSize();
                    $files++;
                }
            }
        }

        return array('bytes' => $bytes, 'files' => $files, 'human' => self::humanSize($bytes));
    }

    // ------------------------------------------------------------------ 内部

    /** 相对路径白名单校验：不允许 ..、不允许绝对路径、只允许图片后缀 */
    public static function safeRel(?string $rel): string
    {
        $rel = trim(str_replace('\\', '/', (string) $rel), '/');

        if ($rel === '' || strpos($rel, '..') !== false) {
            return '';
        }

        if (!preg_match('#^[0-9]{4}/[0-9]{2}/[a-f0-9]{16}(\.t)?\.webp$#', $rel)) {
            return '';
        }

        return $rel;
    }

    private static function randomName(): string
    {
        return bin2hex(random_bytes(8));
    }

    private static function load(string $file, int $type)
    {
        switch ($type) {
            case IMAGETYPE_JPEG:
                $im = @imagecreatefromjpeg($file);
                break;
            case IMAGETYPE_PNG:
                $im = @imagecreatefrompng($file);
                break;
            case IMAGETYPE_WEBP:
                $im = @imagecreatefromwebp($file);
                break;
            case IMAGETYPE_GIF:
                $im = @imagecreatefromgif($file);
                break;
            default:
                $im = null;
        }

        if (!$im) {
            return null;
        }

        // 调色板图（PNG8 / GIF）转真彩，否则缩放会花
        if (!imageistruecolor($im)) {
            imagepalettetotruecolor($im);
        }

        imagealphablending($im, true);
        imagesavealpha($im, true);

        return $im;
    }

    /** 按最长边等比缩放；比目标还小就原样返回 */
    private static function fit($src, int $maxW, int $maxH)
    {
        $w = imagesx($src);
        $h = imagesy($src);

        $scale = min($maxW / max(1, $w), $maxH / max(1, $h));

        if ($scale >= 1) {
            $dst = imagecreatetruecolor($w, $h);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);

            return $dst;
        }

        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        return $dst;
    }

    private static function saveWebp($im, string $path): bool
    {
        $q = max(40, min(100, (int) Config::get('imageQuality', 82)));

        if (function_exists('imagewebp')) {
            return (bool) @imagewebp($im, $path, $q);
        }

        // 没有 WebP 编码器就退回 JPEG
        return (bool) @imagejpeg($im, $path, $q);
    }

    /** 手机竖拍的照片带 EXIF 方向，不纠正会躺着 */
    private static function applyExifRotation($im, string $file, int $type)
    {
        if ($type !== IMAGETYPE_JPEG || !function_exists('exif_read_data')) {
            return $im;
        }

        $exif = @exif_read_data($file);

        if (!is_array($exif) || empty($exif['Orientation'])) {
            return $im;
        }

        switch ((int) $exif['Orientation']) {
            case 3:
                $rot = imagerotate($im, 180, 0);
                break;
            case 6:
                $rot = imagerotate($im, -90, 0);
                break;
            case 8:
                $rot = imagerotate($im, 90, 0);
                break;
            default:
                return $im;
        }

        if ($rot) {
            imagedestroy($im);

            return $rot;
        }

        return $im;
    }

    private static function uploadErrorText(int $code): string
    {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return '图片超出服务器允许的大小（看 docker/php.ini 里的 upload_max_filesize）';
            case UPLOAD_ERR_PARTIAL:
                return '图片只传了一半，重试一下';
            case UPLOAD_ERR_NO_FILE:
                return '没有选择文件';
            case UPLOAD_ERR_NO_TMP_DIR:
                return '服务器缺少临时目录';
            case UPLOAD_ERR_CANT_WRITE:
                return '服务器写磁盘失败';
            default:
                return '上传失败（错误码 ' . $code . '）';
        }
    }

    public static function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024) . ' KB';
        }

        return $bytes . ' B';
    }
}
