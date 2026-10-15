<?php
declare(strict_types=1);

namespace RH;

/**
 * 认证与权限。
 *
 * 密码用 password_hash（bcrypt/argon 由 PHP 决定）；
 * 会话是数据库里的一行 + HttpOnly Cookie，可以随时踢人；
 * CSRF 走双提交 Cookie；权限只有三档：管理员 / 读写成员 / 只读成员。
 */
final class Auth
{
    public const COOKIE      = 'rh_sid';
    public const CSRF_COOKIE = 'rh_csrf';

    private static ?array $user   = null;
    private static bool   $loaded = false;

    // ------------------------------------------------------------------ 当前用户

    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }

        self::$loaded = true;
        self::$user   = null;

        if (PHP_SAPI === 'cli') {
            return null;
        }

        $sid = (string) ($_COOKIE[self::COOKIE] ?? '');

        if ($sid === '' || !preg_match('/^[a-f0-9]{48}$/', $sid)) {
            return null;
        }

        $row = Db::one(
            'SELECT u.*, s.id AS sid, s.expires_at
               FROM sessions s
               JOIN users u ON u.id = s.user_id
              WHERE s.id = ? AND s.expires_at > ?',
            array($sid, time())
        );

        if (!$row || (int) $row['is_active'] !== 1) {
            return null;
        }

        $row['sid']     = $sid;
        self::$user     = $row;

        return self::$user;
    }

    public static function id(): int
    {
        $u = self::user();

        return $u ? (int) $u['id'] : 0;
    }

    public static function name(): string
    {
        $u = self::user();

        return $u ? (string) $u['username'] : '';
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function isAdmin(): bool
    {
        $u = self::user();

        return $u && (string) $u['role'] === 'admin';
    }

    public static function canWrite(): bool
    {
        $u = self::user();

        if (!$u) {
            return false;
        }

        return (string) $u['role'] === 'admin' || (int) $u['can_write'] === 1;
    }

    /** 用户总数——为 0 说明还没初始化，前端会跳安装引导 */
    public static function userCount(): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM users');
    }

    // ------------------------------------------------------------------ 门槛

    public static function requireUser(): array
    {
        $u = self::user();

        if (!$u) {
            Http::fail('请先登录', 401);
        }

        return $u;
    }

    public static function requireAdmin(): void
    {
        self::requireUser();

        if (!self::isAdmin()) {
            Http::fail('只有管理员能操作', 403);
        }
    }

    /** 写操作的门槛：只读成员只能看 */
    public static function requireWrite(string $method): void
    {
        $method = strtoupper($method);

        if ($method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS') {
            return;
        }

        if (!self::canWrite()) {
            Http::fail('你的账号是只读权限，不能修改内容', 403);
        }
    }

    // ------------------------------------------------------------------ 登录 / 注册

    public static function login(string $username, string $password): array
    {
        $username = trim($username);

        if ($username === '' || $password === '') {
            Http::fail('请填写账号和密码', 400);
        }

        $max  = (int) Config::get('loginMaxFail', 8);
        $mins = (int) Config::get('loginLockMin', 15);

        if (Logs::countFails($username, Http::ip(), $mins) >= $max) {
            Http::fail('连续输错太多次，请 ' . $mins . ' 分钟后再试', 429);
        }

        $u = Db::one('SELECT * FROM users WHERE username = ?', array($username));

        if (!$u || !password_verify($password, (string) $u['password_hash'])) {
            Logs::add('login_fail', $username, '账号或密码不正确', (int) ($u['id'] ?? 0), $username);
            Http::fail('账号或密码不对', 401);
        }

        if ((int) $u['is_active'] !== 1) {
            Http::fail('这个账号已被停用，请联系管理员', 403);
        }

        if (password_needs_rehash((string) $u['password_hash'], PASSWORD_DEFAULT)) {
            Db::updateRow('users', (int) $u['id'], array(
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ));
        }

        self::sessionCreate((int) $u['id']);
        Db::updateRow('users', (int) $u['id'], array('last_login_at' => time()));
        Logs::add('login', $username, '', (int) $u['id'], $username);

        $u['last_login_at'] = time();

        return self::publicUser($u);
    }

    /** 注册；第一个注册的人自动成为管理员 */
    public static function register(string $username, string $password, string $displayName = ''): array
    {
        $username = trim($username);
        $first    = self::userCount() === 0;

        if (!$first && (string) Repo\Settings::get('allow_register', '0') !== '1') {
            Http::fail('本实例未开放注册', 403);
        }

        self::validateCredentials($username, $password);

        if (Db::one('SELECT id FROM users WHERE username = ?', array($username))) {
            Http::fail('这个用户名已经有人用了', 409, 'username');
        }

        $id = Db::insert('users', array(
            'username'      => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'display_name'  => $displayName !== '' ? $displayName : $username,
            'role'          => $first ? 'admin' : 'member',
            'can_write'     => 1,
            'unit_pref'     => '克',
            'is_active'     => 1,
            'created_at'    => time(),
            'last_login_at' => 0,
        ));

        Logs::add('register', $username, $first ? '首个账号，已设为管理员' : '', $id, $username);

        self::sessionCreate($id);

        $u = Db::find('users', $id);

        return self::publicUser($u ?: array());
    }

    public static function validateCredentials(string $username, string $password): void
    {
        $len = mb_strlen($username);

        if ($len < 2 || $len > 24) {
            Http::fail('用户名请控制在 2~24 个字符', 400, 'username');
        }

        if (!preg_match('/^[\x{4e00}-\x{9fa5}A-Za-z0-9_.@-]+$/u', $username)) {
            Http::fail('用户名只能包含中英文、数字和 _ . @ -', 400, 'username');
        }

        if (strlen($password) < 6) {
            Http::fail('密码至少 6 位', 400, 'password');
        }

        if (strlen($password) > 200) {
            Http::fail('密码太长了', 400, 'password');
        }
    }

    public static function changePassword(int $userId, string $old, string $new): void
    {
        $u = Db::find('users', $userId);

        if (!$u) {
            Http::fail('用户不存在', 404);
        }

        // 管理员给自己改密码可以不填旧密码（比如安全重置后）
        if ($old !== '' || !self::isAdmin()) {
            if (!password_verify($old, (string) $u['password_hash'])) {
                Http::fail('原密码不对', 400, 'old_password');
            }
        }

        if (strlen($new) < 6) {
            Http::fail('新密码至少 6 位', 400, 'new_password');
        }

        Db::updateRow('users', $userId, array(
            'password_hash' => password_hash($new, PASSWORD_DEFAULT),
        ));

        // 改完密码把其它设备上的会话全踢掉，只留当前这个
        $keep = (string) ($_COOKIE[self::COOKIE] ?? '');
        Db::exec('DELETE FROM sessions WHERE user_id = ? AND id <> ?', array($userId, $keep));

        Logs::add('password_change', (string) $u['username'], '', $userId, (string) $u['username']);
    }

    // ------------------------------------------------------------------ 会话

    public static function sessionCreate(int $userId): string
    {
        $token = bin2hex(random_bytes(24));
        $days  = max(1, (int) Config::get('sessionDays', 30));
        $exp   = time() + $days * 86400;

        Db::insert('sessions', array(
            'id'         => $token,
            'user_id'    => $userId,
            'created_at' => time(),
            'expires_at' => $exp,
            'ip'         => Http::ip(),
            'ua'         => Http::ua(),
        ));

        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            setcookie(self::COOKIE, $token, array(
                'expires'  => $exp,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => Http::isHttps(),
            ));
        }

        // 顺手清一清过期会话，1% 概率
        if (random_int(1, 100) === 1) {
            self::sessionGc();
        }

        return $token;
    }

    public static function logout(): void
    {
        $sid = (string) ($_COOKIE[self::COOKIE] ?? '');

        if ($sid !== '') {
            Db::exec('DELETE FROM sessions WHERE id = ?', array($sid));
        }

        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            setcookie(self::COOKIE, '', array(
                'expires'  => time() - 3600,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ));
        }

        if (self::check()) {
            Logs::add('logout', self::name(), '', self::id(), self::name());
        }

        self::$user   = null;
        self::$loaded = true;
    }

    public static function sessionGc(): int
    {
        return Db::exec('DELETE FROM sessions WHERE expires_at < ?', array(time()));
    }

    /** 踢掉某个用户的全部会话 */
    public static function sessionKillUser(int $userId): int
    {
        return Db::exec('DELETE FROM sessions WHERE user_id = ?', array($userId));
    }

    // ------------------------------------------------------------------ CSRF

    public static function csrf(): string
    {
        $cur = (string) ($_COOKIE[self::CSRF_COOKIE] ?? '');

        if (preg_match('/^[a-f0-9]{32}$/', $cur)) {
            return $cur;
        }

        $token = bin2hex(random_bytes(16));

        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            setcookie(self::CSRF_COOKIE, $token, array(
                'expires'  => time() + 86400 * 30,
                'path'     => '/',
                'httponly' => false,   // 前端 JS 要读出来放进请求头
                'samesite' => 'Lax',
                'secure'   => Http::isHttps(),
            ));
        }

        $_COOKIE[self::CSRF_COOKIE] = $token;

        return $token;
    }

    /**
     * 写请求的 CSRF 校验。
     * 双提交 Cookie 为主；退而求其次查同源 Origin / Referer。
     * 非浏览器客户端（没有 Origin/Referer、也没有 Cookie）本身不构成 CSRF 场景。
     */
    public static function checkCsrf(): void
    {
        $method = Http::method();

        if ($method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS') {
            return;
        }

        $sent   = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $cookie = (string) ($_COOKIE[self::CSRF_COOKIE] ?? '');

        if ($cookie !== '' && $sent !== '' && hash_equals($cookie, $sent)) {
            return;
        }

        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');

        if ($origin === '') {
            $origin = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        }

        if ($origin !== '') {
            $host = strtolower((string) parse_url($origin, PHP_URL_HOST));
            $self = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
            $host = preg_replace('/:\d+$/', '', $host) ?? $host;
            $self = preg_replace('/:\d+$/', '', $self) ?? $self;

            if ($host !== '' && $host === $self) {
                return;
            }

            Http::fail('请求来源校验失败，请刷新页面重试', 403);
        }

        if ($cookie === '') {
            return;
        }

        Http::fail('请求校验失败，请刷新页面重试', 403);
    }

    // ------------------------------------------------------------------ 工具

    /** 抹掉敏感字段，可以安全地吐给前端 */
    public static function publicUser(?array $u): array
    {
        if (!$u) {
            return array();
        }

        unset($u['password_hash']);

        return array(
            'id'           => (int) $u['id'],
            'username'     => (string) $u['username'],
            'display_name' => (string) ($u['display_name'] ?? ''),
            'avatar'       => (string) ($u['avatar'] ?? ''),
            'role'         => (string) $u['role'],
            'can_write'    => (int) $u['can_write'],
            'unit_pref'    => (string) ($u['unit_pref'] ?? '克'),
            'is_active'    => (int) ($u['is_active'] ?? 1),
            'created_at'   => (int) ($u['created_at'] ?? 0),
            'last_login_at' => (int) ($u['last_login_at'] ?? 0),
        );
    }
}
