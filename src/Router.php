<?php
declare(strict_types=1);

namespace RH;

/**
 * 极简路由器。
 *
 * 支持 /api/recipes/{id} 这种路径参数，够 REST 用。
 * 不搞中间件栈，需要认证的分组直接挂 auth 闭包。
 */
final class Router
{
    /** @var array<int, array{method:string, pattern:string, regex:string, keys:array, handler:callable, guest:bool, self:bool}> */
    private array $routes = array();

    private string $prefix = '';

    public function group(string $prefix, callable $fn): void
    {
        $saved        = $this->prefix;
        $this->prefix .= rtrim($prefix, '/');
        $fn($this);
        $this->prefix = $saved;
    }

    /** 需要登录，且会写入内容（只读成员会被拦） */
    public function auth(string $method, string $pattern, callable $handler): void
    {
        $this->add($method, $pattern, $handler, false, false);
    }

    /** 免登录（登录、注册、站点元信息） */
    public function guest(string $method, string $pattern, callable $handler): void
    {
        $this->add($method, $pattern, $handler, true, false);
    }

    /**
     * 只要求登录，不受「只读成员」限制。
     * 用在「动的是自己」而不是「动内容」的接口上：登出、改自己的密码/资料、收藏。
     */
    public function self(string $method, string $pattern, callable $handler): void
    {
        $this->add($method, $pattern, $handler, false, true);
    }

    public function get(string $p, callable $h, bool $guest = false): void
    {
        $this->add('GET', $p, $h, $guest, false);
    }

    public function post(string $p, callable $h, bool $guest = false): void
    {
        $this->add('POST', $p, $h, $guest, false);
    }

    public function put(string $p, callable $h, bool $guest = false): void
    {
        $this->add('PUT', $p, $h, $guest, false);
    }

    public function delete(string $p, callable $h, bool $guest = false): void
    {
        $this->add('DELETE', $p, $h, $guest, false);
    }

    private function add(string $method, string $pattern, callable $handler, bool $guest, bool $self): void
    {
        $full = $this->prefix . $pattern;

        $regex = preg_replace_callback(
            '/\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            static function (array $m): string {
                return '(?P<' . $m[1] . '>[^/]+)';
            },
            $full
        );

        $keys = array();

        if (preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $full, $mm)) {
            $keys = $mm[1];
        }

        $this->routes[] = array(
            'method'  => strtoupper($method),
            'pattern' => $full,
            'regex'   => '#^' . $regex . '$#',
            'keys'    => $keys,
            'handler' => $handler,
            'guest'   => $guest,
            'self'    => $self,
        );
    }

    public function dispatch(string $method, string $path): void
    {
        $method   = strtoupper($method);
        $headOnly = $method === 'HEAD';
        $matched  = false;

        foreach ($this->routes as $r) {
            if (!preg_match($r['regex'], $path, $m)) {
                continue;
            }

            // 路径匹配了但方法不对 —— 记下来，最后回 405 而不是 404
            if ($r['method'] !== $method && !($headOnly && $r['method'] === 'GET')) {
                $matched = true;
                continue;
            }

            $params = array();

            foreach ($r['keys'] as $k) {
                $params[$k] = isset($m[$k]) ? rawurldecode($m[$k]) : '';
            }

            if (!$r['guest']) {
                Auth::requireUser();

                if (!$r['self']) {
                    Auth::requireWrite($method);
                }
            }

            ($r['handler'])($params);

            return;
        }

        if ($matched) {
            Http::fail('请求方法不被支持', 405);
        }

        Http::fail('接口不存在：' . $path, 404);
    }
}
