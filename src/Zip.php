<?php
declare(strict_types=1);

namespace RH;

/**
 * 纯 PHP 的 zip 读写。
 *
 * 为什么不直接用 ZipArchive：Docker 镜像（php:8.3-apache）没编译 zip 扩展，
 * 宝塔上装的 PHP 也不保证有。而「一个文件把整个站装走」是备份功能的地基，
 * 不能挑环境——所以这里按 ZIP 规范自己读写，只用到 zlib（gzdeflate/gzinflate，
 * PHP 核心自带，连扩展都算不上）。
 *
 * 支持范围够用即止：
 *   - 压缩方式只做 store(0) 与 deflate(8)——zip 里最常见的两种
 *   - 不做 ZIP64（家用实例撑死几百 MB，到不了 4GB 那条线）
 *   - 打包时数据整个读进内存：图片都是程序生成的 WebP，单张几百 KB，
 *     一次只驻留一个文件，不会把内存撑爆
 *
 * 读的那一半是流式的：从中央目录定位、按需 seek，不整个读进内存，
 * 所以「上传一个 300MB 的备份包来体检」不会顶到 memory_limit。
 */
final class Zip
{
    /** @var resource|null */
    private $fh = null;

    private string $path = '';

    /** 写：待写进中央目录的条目 */
    private array $central = array();

    /** 写：当前写入偏移 */
    private int $offset = 0;

    /** 读：条目名 => 条目信息 */
    private array $index = array();

    /** 读：中央目录偏移 */
    private int $cdOffset = 0;

    private function __construct()
    {
    }

    // ================================================================ 写

    /** 开一个新 zip 等着往里塞东西 */
    public static function create(string $path): self
    {
        $z  = new self();
        $fh = @fopen($path, 'wb');

        if (!$fh) {
            throw new \RuntimeException('建不了压缩包：' . $path);
        }

        $z->fh     = $fh;
        $z->path   = $path;
        $z->offset = 0;

        return $z;
    }

    /**
     * 把一个磁盘文件塞进包里。
     *
     * @param bool $compress 已经压过的内容（webp 图片）就别再 deflate 了，
     *                       白烧 CPU 换不来体积，还慢
     */
    public function addFile(string $srcPath, string $entry, bool $compress = true): void
    {
        if (!is_file($srcPath)) {
            throw new \RuntimeException('要打包的文件不存在：' . $srcPath);
        }

        $data = @file_get_contents($srcPath);

        if ($data === false) {
            throw new \RuntimeException('读不了这个文件：' . $srcPath);
        }

        $this->addData($entry, $data, $compress, (int) (@filemtime($srcPath) ?: time()));
    }

    /** 把一段内存里的内容塞进包里（manifest 这类小文件） */
    public function addString(string $entry, string $data, bool $compress = true): void
    {
        $this->addData($entry, $data, $compress, time());
    }

    private function addData(string $entry, string $data, bool $compress, int $mtime): void
    {
        if (!$this->fh) {
            throw new \RuntimeException('这个压缩包已经关掉了');
        }

        $entry = self::normName($entry);

        if ($entry === '' || strlen($entry) > 0xffff) {
            throw new \RuntimeException('条目名不合法：' . $entry);
        }

        $usize  = strlen($data);
        $crc    = crc32($data);
        $method = 0;
        $payload = $data;

        // 太小的东西压了反而更大（deflate 要占头），阈值以下直接原样存
        if ($compress && $usize > 200) {
            $packed = @gzdeflate($data, 6);

            if (is_string($packed) && strlen($packed) < $usize) {
                $method  = 8;
                $payload = $packed;
            }
        }

        $csize = strlen($payload);
        list($dosTime, $dosDate) = self::dosTime($mtime);

        // 本地头起点：写下去之前先记下来，中央目录要用
        $localOffset = $this->offset;

        $head = pack(
            'VvvvvvVVVvv',
            0x04034b50,     // 本地文件头签名
            20,             // 需要的版本（2.0）
            0x0800,         // 标志位：文件名是 UTF-8
            $method,
            $dosTime,
            $dosDate,
            $crc,
            $csize,
            $usize,
            strlen($entry),
            0               // 无扩展字段
        );

        $this->write($head . $entry . $payload);

        // 中央目录项留到最后统一写
        $this->central[] = array(
            'entry'  => $entry,
            'method' => $method,
            'crc'    => $crc,
            'csize'  => $csize,
            'usize'  => $usize,
            'time'   => $dosTime,
            'date'   => $dosDate,
            'offset' => $localOffset,
        );
    }

    /** 收尾：写中央目录 + 结尾记录。不调它这个 zip 就是坏的。 */
    public function close(): void
    {
        if (!$this->fh) {
            return;
        }

        $cd     = '';
        $offset = $this->offset;

        foreach ($this->central as $c) {
            $cd .= pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,     // 中央目录签名
                0x031e,         // 制作者：UNIX + 3.0
                20,             // 需要的版本
                0x0800,
                $c['method'],
                $c['time'],
                $c['date'],
                $c['crc'],
                $c['csize'],
                $c['usize'],
                strlen($c['entry']),
                0,              // 扩展字段长度
                0,              // 注释长度
                0,              // 起始磁盘号
                0,              // 内部属性
                0100644 << 16,  // 外部属性：普通文件 rw-r--r--
                $c['offset']
            ) . $c['entry'];
        }

        $n = count($this->central);

        $this->write($cd);
        $this->write(pack(
            'VvvvvVVv',
            0x06054b50,         // 结尾记录签名
            0,
            0,
            $n,
            $n,
            strlen($cd),
            $offset,
            0                   // 无注释
        ));

        @fclose($this->fh);
        $this->fh = null;
    }

    public function count(): int
    {
        return count($this->central);
    }

    private function write(string $bytes): void
    {
        $len = strlen($bytes);
        $put = @fwrite($this->fh, $bytes);

        if ($put === false || $put !== $len) {
            throw new \RuntimeException('写不进压缩包（磁盘满了？）');
        }

        $this->offset += $len;
    }

    // ================================================================ 读

    /** 打开一个 zip 读条目。只读中央目录，不进内存。 */
    public static function open(string $path): self
    {
        $z = new self();

        if (!is_file($path)) {
            throw new \RuntimeException('压缩包不存在：' . $path);
        }

        $fh = @fopen($path, 'rb');

        if (!$fh) {
            throw new \RuntimeException('打不开压缩包：' . $path);
        }

        $z->fh   = $fh;
        $z->path = $path;

        $stat = fstat($fh);
        $size = (int) ($stat['size'] ?? 0);

        if ($size < 22) {
            throw new \RuntimeException('这不是个 zip 文件（太小了）');
        }

        // 结尾记录在文件尾部，注释最长 64KB，所以从尾巴往回找 66KB 就够
        $tailLen = min($size, 66560);
        fseek($fh, $size - $tailLen);
        $tail = (string) fread($fh, $tailLen);
        $pos  = strrpos($tail, "PK\x05\x06");

        if ($pos === false) {
            throw new \RuntimeException('这不是个 zip 文件（找不到目录结尾）');
        }

        $eocd = unpack(
            'vdisk/vcdDisk/vnDisk/vnTotal/VcdSize/VcdOffset/vcomment',
            substr($tail, $pos + 4, 18)
        );

        $total    = (int) $eocd['nTotal'];
        $cdOffset = (int) $eocd['cdOffset'];
        $cdSize   = (int) $eocd['cdSize'];
        if ($total === 0 || $cdOffset <= 0 || $cdSize <= 0) {
            throw new \RuntimeException('压缩包里没有条目');
        }

        $z->cdOffset = $cdOffset;
        $z->readCentral($cdOffset, $cdSize);

        return $z;
    }

    private function readCentral(int $offset, int $size): void
    {
        fseek($this->fh, $offset);
        $cd  = '';
        $left = $size;

        while ($left > 0) {
            $chunk = fread($this->fh, min(1048576, $left));

            if ($chunk === false || $chunk === '') {
                break;
            }

            $cd  .= $chunk;
            $left -= strlen($chunk);
        }

        $o   = 0;
        $len = strlen($cd);

        while ($o + 46 <= $len) {
            $h = unpack(
                'Vsig/vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnamelen/vextralen/vclen/vdiskno/vinternal/Vexternal/Vloff',
                substr($cd, $o, 46)
            );

            if ((int) $h['sig'] !== 0x02014b50) {
                break;
            }

            $namelen = (int) $h['namelen'];
            $name    = substr($cd, $o + 46, $namelen);

            $this->index[$name] = array(
                'name'   => $name,
                'method' => (int) $h['method'],
                'crc'    => (int) $h['crc'],
                'csize'  => (int) $h['csize'],
                'usize'  => (int) $h['usize'],
                'mtime'  => self::dosToUnix((int) $h['date'], (int) $h['time']),
                'offset' => (int) $h['loff'],
            );

            $o += 46 + $namelen + (int) $h['extralen'] + (int) $h['clen'];
        }
    }

    /**
     * 条目清单。
     *
     * @return array<string,array{name:string,method:int,crc:int,csize:int,usize:int,mtime:int,offset:int}>
     */
    public function entries(): array
    {
        return $this->index;
    }

    public function has(string $name): bool
    {
        return isset($this->index[self::normName($name)]);
    }

    public function sizeOf(string $name): int
    {
        $e = $this->index[self::normName($name)] ?? null;

        return $e ? $e['usize'] : 0;
    }

    /** 把一个条目整个读进内存（manifest、database.sqlite 这种） */
    public function read(string $name): ?string
    {
        $e = $this->index[self::normName($name)] ?? null;

        if (!$e) {
            return null;
        }

        list($raw, $method) = $this->rawOf($e);

        if ($raw === null) {
            return null;
        }

        if ($method === 8) {
            $out = @gzinflate($raw);

            return is_string($out) ? $out : null;
        }

        return $raw;
    }

    /**
     * 解出一个条目到磁盘。store 模式是分块拷贝，大文件也不会吃内存。
     *
     * @return bool 成功与否
     */
    public function extractTo(string $name, string $destPath): bool
    {
        $key = self::normName($name);
        $e   = $this->index[$key] ?? null;

        if (!$e) {
            return false;
        }

        $dir = dirname($destPath);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('建不了目录：' . $dir);
        }

        $out = @fopen($destPath, 'wb');

        if (!$out) {
            throw new \RuntimeException('写不了文件：' . $destPath);
        }

        try {
            list($csize, $dataOff, $method) = $this->locate($e);

            fseek($this->fh, $dataOff);

            if ($method === 8) {
                // 压缩过的得整个过一遍 gzinflate
                $raw   = self::readExact($this->fh, $csize);
                $plain = @gzinflate($raw);

                if (!is_string($plain)) {
                    throw new \RuntimeException('这个条目解不开（可能文件坏了）：' . $key);
                }

                fwrite($out, $plain);
            } else {
                $left = $csize;

                while ($left > 0) {
                    $chunk = fread($this->fh, min(1048576, $left));

                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    fwrite($out, $chunk);
                    $left -= strlen($chunk);
                }

                if ($left > 0) {
                    throw new \RuntimeException('这个条目读不完整（可能文件被截断了）：' . $key);
                }
            }
        } finally {
            @fclose($out);
        }

        @touch($destPath, $e['mtime'] ?: time());

        return true;
    }

    /**
     * 解一批条目到某个目录，保留包里的相对路径。
     *
     * @param callable|null $filter 收条目名，返回 false 就跳过
     * @return int 解出来的文件数
     */
    public function extractAll(string $destDir, ?callable $filter = null): int
    {
        $n = 0;

        foreach ($this->index as $name => $e) {
            if (substr($name, -1) === '/') {
                continue;   // 目录条目，跳过（解文件时自建目录）
            }

            $rel = self::safeEntry($name);

            if ($rel === '') {
                continue;   // 名字带 .. 或绝对路径：zip slip，直接不要
            }

            if ($filter !== null && $filter($name) === false) {
                continue;
            }

            if ($this->extractTo($name, rtrim($destDir, '/\\') . '/' . $rel)) {
                $n++;
            }
        }

        return $n;
    }

    public function closeRead(): void
    {
        if ($this->fh) {
            @fclose($this->fh);
            $this->fh = null;
        }
    }

    public function __destruct()
    {
        $this->closeRead();
    }

    // ================================================================ 内部

    /** 定位条目数据的真实起点：本地头里的扩展字段长度跟中央目录可能不一样 */
    private function locate(array $e): array
    {
        fseek($this->fh, $e['offset']);
        $lh = (string) fread($this->fh, 30);

        if (strlen($lh) < 30) {
            throw new \RuntimeException('本地头读不出来：' . $e['name']);
        }

        $u = unpack('Vsig/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnamelen/vextralen', $lh);

        if ((int) $u['sig'] !== 0x04034b50) {
            throw new \RuntimeException('本地头签名不对：' . $e['name']);
        }

        $namelen  = (int) $u['namelen'];
        $extralen = (int) $u['extralen'];

        // 有的实现会把真实大小放在数据描述符里（标志位 3），那 csize 就是 0。
        // 我们自己的包不会这么写，但收到的文件可能是别的工具产的。
        $csize = $e['csize'] > 0 ? $e['csize'] : (int) $u['csize'];

        return array($csize, $e['offset'] + 30 + $namelen + $extralen, (int) $u['method']);
    }

    private function rawOf(array $e): array
    {
        list($csize, $dataOff, $method) = $this->locate($e);

        fseek($this->fh, $dataOff);

        return array(self::readExact($this->fh, $csize), $method);
    }

    private static function readExact($fh, int $bytes): string
    {
        $out  = '';
        $left = $bytes;

        while ($left > 0) {
            $chunk = fread($fh, min(1048576, $left));

            if ($chunk === false || $chunk === '') {
                break;
            }

            $out  .= $chunk;
            $left -= strlen($chunk);
        }

        return $out;
    }

    /** 条目名归一化：统一斜杠、去掉开头的 / */
    private static function normName(string $name): string
    {
        $name = str_replace('\\', '/', trim($name));

        return ltrim($name, '/');
    }

    /**
     * 解包时的相对路径：不允许绝对路径、不允许 .. 跳出去（zip slip）。
     * 留空的条目名由调用方跳过。
     */
    public static function safeEntry(string $name): string
    {
        $name = self::normName($name);

        if ($name === '' || substr($name, -1) === '/') {
            return '';
        }

        foreach (explode('/', $name) as $seg) {
            if ($seg === '..' || $seg === '.') {
                return '';
            }
        }

        return $name;
    }

    /** 打包时的路径校验，顺带挡掉控制字符 */
    private static function dosTime(int $unix): array
    {
        $t = getdate($unix > 0 ? $unix : time());
        $y = (int) $t['year'];

        if ($y < 1980) {
            $y = 1980;
        }

        if ($y > 2107) {
            $y = 2107;
        }

        $time = (($t['hours'] << 11) | ($t['minutes'] << 5) | ($t['seconds'] >> 1)) & 0xffff;
        $date = ((($y - 1980) << 9) | ($t['mon'] << 5) | $t['mday']) & 0xffff;

        return array($time, $date);
    }

    private static function dosToUnix(int $date, int $time): int
    {
        if ($date === 0) {
            return time();
        }

        $y  = (($date >> 9) & 0x7f) + 1980;
        $mo = ($date >> 5) & 0x0f;
        $d  = $date & 0x1f;
        $h  = ($time >> 11) & 0x1f;
        $mi = ($time >> 5) & 0x3f;
        $s  = ($time & 0x1f) << 1;

        if ($mo < 1 || $mo > 12 || $d < 1) {
            return time();
        }

        return (int) @mktime($h, $mi, $s, $mo, $d, $y);
    }
}
