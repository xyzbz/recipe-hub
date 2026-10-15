<?php
declare(strict_types=1);

namespace RH;

/**
 * HTTP 请求 / 响应辅助。没有框架，够用就行。
 */
final class Http
{
    public static function method(): string
    {
        $m = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        // 表单里可以带 _method 模拟 PUT/DELETE
        if ($m === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper((string) $_POST['_method']);

            if (in_array($override, array('PUT', 'PATCH', 'DELETE'), true)) {
                return $override;
            }
        }

        return $m;
    }

    /** 去掉查询串、去掉脚本名前缀的请求路径，形如 /api/recipes/12 */
    public static function path(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $pos = strpos($uri, '?');

        if ($pos !== false) {
            $uri = substr($uri, 0, $pos);
        }

        $uri = rawurldecode($uri);

        // 某些环境（Apache + FallbackResource）会把脚本路径塞进 PATH_INFO。
        //
        // 两个保险：脚本名必须以 .php 结尾，而且必须比 URI 短。
        // 少了这两个条件，`php -S` 内置服务器遇到 /uploads/xx.webp 这种带扩展名的
        // 地址时（它会把 SCRIPT_NAME 设成请求路径本身）会把整条路径剥成空串，
        // 图片请求就全变成返回 SPA 外壳了。
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');

        if ($script !== '' && $script !== '/index.php'
            && substr($script, -4) === '.php'
            && strlen($uri) > strlen($script)
            && strncmp($uri, $script, strlen($script)) === 0) {
            $uri = substr($uri, strlen($script));
        }

        $uri = '/' . ltrim($uri, '/');

        if ($uri !== '/' ) {
            $uri = rtrim($uri, '/');

            if ($uri === '') {
                $uri = '/';
            }
        }

        return $uri;
    }

    /** 请求体：JSON 优先，退回表单 */
    public static function body(): array
    {
        static $cached = null;

        if (is_array($cached)) {
            return $cached;
        }

        $raw = file_get_contents('php://input');
        $out = array();

        if (is_string($raw) && trim($raw) !== '') {
            $ct = (string) ($_SERVER['CONTENT_TYPE'] ?? '');

            if (stripos($ct, 'json') !== false || $raw[0] === '{' || $raw[0] === '[') {
                $decoded = json_decode($raw, true);

                if (is_array($decoded)) {
                    $out = $decoded;
                }
            } else {
                parse_str($raw, $parsed);

                if (is_array($parsed)) {
                    $out = $parsed;
                }
            }
        }

        $cached = array_merge($_GET, $_POST, $out);

        return $cached;
    }

    public static function input(string $key, $default = null)
    {
        $b = self::body();

        return array_key_exists($key, $b) ? $b[$key] : $default;
    }

    public static function str(string $key, string $default = ''): string
    {
        $v = self::input($key, $default);

        if (is_array($v)) {
            return $default;
        }

        return trim((string) $v);
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::input($key, $default);

        return is_numeric($v) ? (int) $v : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::input($key, $default);

        if (is_bool($v)) {
            return $v;
        }

        return in_array((string) $v, array('1', 'true', 'yes', 'on'), true);
    }

    public static function float(string $key, float $default = 0.0): float
    {
        $v = self::input($key, $default);

        return is_numeric($v) ? (float) $v : $default;
    }

    public static function query(string $key, $default = null)
    {
        return array_key_exists($key, $_GET) ? $_GET[$key] : $default;
    }

    public static function ip(): string
    {
        foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR') as $k) {
            $v = (string) ($_SERVER[$k] ?? '');

            if ($v !== '') {
                $parts = explode(',', $v);

                return substr(trim($parts[0]), 0, 64);
            }
        }

        return '';
    }

    public static function ua(): string
    {
        return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    // ------------------------------------------------------------------ 响应

    public static function json($data, int $code = 200): void
    {
        if (ob_get_level() > 0) {
            @ob_clean();
        }

        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public static function ok($data = null): void
    {
        self::json(array('ok' => true, 'data' => $data));
    }

    public static function fail(string $msg, int $code = 400, string $field = ''): void
    {
        $out = array('ok' => false, 'error' => $msg);

        if ($field !== '') {
            $out['field'] = $field;
        }

        self::json($out, $code);
    }

    public static function redirect(string $url, int $code = 302): void
    {
        header('Location: ' . $url, true, $code);
        exit;
    }

    /** 以流的方式吐一个文件（上传图片走这里，避免把 data/ 暴露在 docroot） */
    public static function streamFile(string $file, string $mime, int $maxAge = 2592000): void
    {
        if (!is_file($file)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not found';
            exit;
        }

        $size  = filesize($file);
        $mtime = filemtime($file);
        $etag  = '"' . md5($file . '|' . $size . '|' . $mtime) . '"';

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . ($size === false ? 0 : $size));
        header('Cache-Control: public, max-age=' . $maxAge);
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime ?: time()) . ' GMT');

        $ifNone = (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');

        if ($ifNone !== '' && trim($ifNone) === $etag) {
            http_response_code(304);
            exit;
        }

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        readfile($file);
        exit;
    }
}
