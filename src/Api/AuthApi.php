<?php
declare(strict_types=1);

namespace RH\Api;

use RH\Auth;
use RH\Db;
use RH\Http;
use RH\Repo\Settings;
use RH\Repo\User;
use RH\Schema;

final class AuthApi
{
    /** 首次安装：创建管理员，可选带一份示例菜谱 */
    public static function setup(): void
    {
        if (Auth::userCount() > 0) {
            Http::fail('这个实例已经初始化过了', 409);
        }

        $username = Http::str('username');
        $password = Http::str('password');

        $user = Auth::register($username, $password, Http::str('display_name'));

        // 首次安装顺手把站点信息改了
        $siteName = Http::str('site_name');

        if ($siteName !== '') {
            Settings::set('site_name', $siteName);
        }

        if (Http::bool('allow_register')) {
            Settings::set('allow_register', '1');
        }

        $demo = 0;

        if (Http::bool('demo', true)) {
            $demo = Schema::seedDemo((int) $user['id']);
        }

        Settings::set('site_name', (string) Settings::get('site_name', '家常菜谱'));

        Http::ok(array('user' => $user, 'demo_recipes' => $demo));
    }

    public static function login(): void
    {
        $user = Auth::login(Http::str('username'), (string) Http::input('password', ''));
        Http::ok(array('user' => $user, 'csrf' => Auth::csrf()));
    }

    public static function register(): void
    {
        $user = Auth::register(Http::str('username'), (string) Http::input('password', ''), Http::str('display_name'));
        Http::ok(array('user' => $user));
    }

    public static function logout(): void
    {
        Auth::logout();
        Http::ok(array('ok' => true));
    }

    public static function me(): void
    {
        $u = Auth::requireUser();

        Http::ok(array(
            'user'    => Auth::publicUser($u),
            'canWrite' => Auth::canWrite(),
            'isAdmin'  => Auth::isAdmin(),
            'csrf'     => Auth::csrf(),
        ));
    }

    public static function password(): void
    {
        $u = Auth::requireUser();

        Auth::changePassword(
            (int) $u['id'],
            (string) Http::input('old_password', ''),
            (string) Http::input('new_password', '')
        );

        Http::ok(array('changed' => true));
    }

    /**
     * 个人偏好：显示名 / 单位偏好 / 头像
     *
     * 注意这里只改「账号本身」。数据层面的导出/导入已经收归到管理后台的「备份」——
     * 整站打包（数据库 + 全部图片），一个入口一份文件，不再有第二套个人 JSON。
     */
    public static function profile(): void
    {
        $u = Auth::requireUser();

        User::updateProfile((int) $u['id'], array(
            'display_name' => Http::str('display_name', (string) $u['display_name']),
            'unit_pref'    => Http::str('unit_pref', (string) $u['unit_pref']),
            'avatar'       => Http::str('avatar', (string) $u['avatar']),
        ));

        $fresh = Db::find('users', (int) $u['id']);

        Http::ok(array('user' => Auth::publicUser($fresh ?: array())));
    }
}
