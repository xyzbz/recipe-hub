<?php
declare(strict_types=1);

namespace RH\Api;

use RH\Auth;
use RH\Db;
use RH\Http;
use RH\Logs;
use RH\Repo\Recipe;
use RH\Repo\Settings;

final class RecipeApi
{
    public static function list(): void
    {
        $per = Http::int('per', 24);

        $result = Recipe::search(array(
            'q'          => Http::str('q'),
            'tags'       => Http::input('tags', array()),
            'tagmode'    => Http::str('tagmode', 'all'),
            'cuisine'    => Http::str('cuisine'),
            'difficulty' => Http::str('difficulty'),
            'max_time'   => Http::int('max_time'),
            'fav'        => Http::bool('fav'),
            'mine'       => Http::bool('mine'),
            'ingredient' => Http::str('ingredient'),
            'sort'       => Http::str('sort', 'updated'),
            'page'       => Http::int('page', 1),
            'per'        => $per > 0 ? $per : 24,
        ));

        Http::ok($result);
    }

    // ------------------------------------------------------------------ 游客浏览

    /**
     * 站点开了「游客浏览」后，未登录可以看公开菜谱（只读）。
     * 筛选只放行跟内容有关的那几个，收藏/我的这类个人维度没有意义。
     */
    public static function publicList(): void
    {
        if (!Settings::bool('public_browse', false)) {
            Http::fail('本站没有开放游客浏览', 403);
        }

        $per = Http::int('per', 24);

        $result = Recipe::search(array(
            'q'          => Http::str('q'),
            'tags'       => Http::input('tags', array()),
            'tagmode'    => Http::str('tagmode', 'all'),
            'cuisine'    => Http::str('cuisine'),
            'difficulty' => Http::str('difficulty'),
            'sort'       => Http::str('sort', 'updated'),
            'page'       => Http::int('page', 1),
            'per'        => $per > 0 ? $per : 24,
        ));

        Http::ok($result);
    }

    public static function publicGet(array $p): void
    {
        if (!Settings::bool('public_browse', false)) {
            Http::fail('本站没有开放游客浏览', 403);
        }

        $id = (int) ($p['id'] ?? 0);
        $r  = $id > 0 ? Recipe::find($id) : null;

        // 私有菜谱对外只报「不存在」，不暴露它存在这件事
        if (!$r || (string) $r['visibility'] !== 'public') {
            Http::fail('菜谱不存在', 404);
        }

        Http::ok($r);
    }

    public static function get(array $p): void
    {
        $id = (int) ($p['id'] ?? 0);

        // site.php 那种域名式地址以后可能也用，这里只认数字
        if ($id <= 0) {
            Http::fail('菜谱不存在', 404);
        }

        $r = Recipe::find($id);

        if (!$r) {
            Http::fail('菜谱不存在', 404);
        }

        if ((string) $r['visibility'] === 'private' && (int) $r['owner_id'] !== Auth::id() && !Auth::isAdmin()) {
            Http::fail('这是一道私有菜谱', 403);
        }

        Http::ok($r);
    }

    public static function create(): void
    {
        $id = Recipe::create(Http::body());

        Http::ok(array('id' => $id, 'recipe' => Recipe::find($id)));
    }

    public static function update(array $p): void
    {
        $id = (int) ($p['id'] ?? 0);

        self::guardOwner($id);

        Recipe::update($id, Http::body());

        Http::ok(array('id' => $id, 'recipe' => Recipe::find($id)));
    }

    public static function remove(array $p): void
    {
        $id = (int) ($p['id'] ?? 0);

        self::guardOwner($id);

        Recipe::remove($id);

        Http::ok(array('removed' => $id));
    }

    public static function duplicate(array $p): void
    {
        $id  = (int) ($p['id'] ?? 0);
        $new = Recipe::duplicate($id);

        Http::ok(array('id' => $new, 'recipe' => Recipe::find($new)));
    }

    public static function favorite(array $p): void
    {
        $id = (int) ($p['id'] ?? 0);
        $on = Recipe::toggleFavorite(Auth::id(), $id);

        Http::ok(array('id' => $id, 'is_fav' => $on ? 1 : 0));
    }

    // ------------------------------------------------------------------ 今天吃什么

    /**
     * 按条件随机抽一道。「手头有料」模式只挑必需用料都在库存里的，
     * 抽不到就明说，不要随便塞一道糊弄人。
     */
    public static function random(): void
    {
        $id = Recipe::randomPick(array(
            'cuisine'    => Http::str('cuisine'),
            'difficulty' => Http::str('difficulty'),
            'max_time'   => Http::int('max_time'),
            'tags'       => Http::input('tags', array()),
            'pantry'     => Http::bool('pantry'),
            'exclude'    => Http::input('exclude', array()),
        ));

        if (!$id) {
            Http::fail('没有符合条件的菜谱，把条件放宽点试试', 404);
        }

        Http::ok(array('id' => $id, 'recipe' => Recipe::find($id)));
    }

    // ------------------------------------------------------------------ 回收站

    public static function trash(): void
    {
        Http::ok(Recipe::trash());
    }

    public static function trashRestore(array $p): void
    {
        $id = (int) ($p['id'] ?? 0);

        self::guardOwner($id);
        Recipe::restore($id);

        Http::ok(array('restored' => $id));
    }

    /** 彻底删除，不可恢复 */
    public static function trashPurge(array $p): void
    {
        $id = (int) ($p['id'] ?? 0);

        self::guardOwner($id);
        Recipe::purge($id);

        Http::ok(array('purged' => $id));
    }

    // ------------------------------------------------------------------ 留言

    public static function comments(array $p): void
    {
        $id = self::assertVisible((int) ($p['id'] ?? 0));

        Http::ok(array('items' => Recipe::comments($id)));
    }

    public static function commentAdd(array $p): void
    {
        $id = self::assertVisible((int) ($p['id'] ?? 0));

        Http::ok(Recipe::addComment($id, Http::str('text')));
    }

    public static function commentRemove(array $p): void
    {
        $id = (int) ($p['id'] ?? 0);

        Recipe::removeComment($id);

        Http::ok(array('removed' => $id));
    }

    // ------------------------------------------------------------------ 下厨记录

    public static function cooks(array $p): void
    {
        $id = self::assertVisible((int) ($p['id'] ?? 0));

        Http::ok(array(
            'items'   => Recipe::cookLogs($id, Http::int('limit', 20) ?: 20),
            'summary' => Recipe::cookSummary($id),
        ));
    }

    /** 记一次「我做过这道菜了」 */
    public static function cookAdd(array $p): void
    {
        $id = self::assertVisible((int) ($p['id'] ?? 0));

        $log = Recipe::addCookLog($id, array(
            'plan_id'  => Http::int('plan_id'),
            'servings' => Http::float('servings'),
            'note'     => Http::str('note'),
        ));

        Http::ok(array('id' => $log, 'summary' => Recipe::cookSummary($id)));
    }

    public static function cookRemove(array $p): void
    {
        $id = (int) ($p['id'] ?? 0);

        Recipe::removeCookLog($id);

        Http::ok(array('removed' => $id));
    }

    /**
     * 确认这条菜谱在当前用户眼里是存在的。
     * 别人的私有菜谱报 403 —— 留言/下厨记录都挂在菜谱上，这道墙得先过。
     */
    private static function assertVisible(int $id): int
    {
        if ($id <= 0) {
            Http::fail('菜谱不存在', 404);
        }

        $r = Recipe::find($id);

        if (!$r) {
            Http::fail('菜谱不存在', 404);
        }

        if ((string) $r['visibility'] === 'private' && (int) $r['owner_id'] !== Auth::id() && !Auth::isAdmin()) {
            Http::fail('这是一道私有菜谱', 403);
        }

        return $id;
    }

    public static function export(): void
    {
        $ids = Http::str('ids');
        $all = Http::bool('all');

        $list = array();

        if ($ids !== '') {
            $list = array_filter(array_map('intval', explode(',', $ids)));
        }

        $data = Recipe::export($list, $all || !$list);

        Logs::add('recipe_export', '共 ' . (int) $data['count'] . ' 道');

        $name = 'recipe-hub-' . date('Ymd-His') . '.json';

        if (ob_get_level() > 0) {
            @ob_clean();
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('X-Content-Type-Options: nosniff');

        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    public static function import(): void
    {
        $payload = null;

        // 1) 上传的 .json 文件
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file((string) $_FILES['file']['tmp_name'])) {
            $raw = (string) file_get_contents((string) $_FILES['file']['tmp_name']);

            if (strlen($raw) > 20 * 1024 * 1024) {
                Http::fail('文件太大了', 413, 'file');
            }

            $payload = json_decode($raw, true);

            if (!is_array($payload)) {
                Http::fail('这个文件不是合法的 JSON', 400, 'file');
            }
        }

        // 2) 粘贴的 JSON 文本
        if ($payload === null) {
            $text = Http::str('text');

            if ($text !== '') {
                $payload = json_decode($text, true);

                if (!is_array($payload)) {
                    Http::fail('粘贴的内容不是合法的 JSON', 400, 'text');
                }
            }
        }

        // 3) 直接给的结构
        if ($payload === null) {
            $body = Http::body();

            if (isset($body['payload']) && is_array($body['payload'])) {
                $payload = $body['payload'];
            }
        }

        if (!is_array($payload)) {
            Http::fail('没有可导入的内容', 400);
        }

        $result = Recipe::import($payload, Auth::id(), Http::bool('overwrite'));

        Http::ok($result);
    }

    public static function stats(): void
    {
        Http::ok(Recipe::stats());
    }

    public static function tags(): void
    {
        Http::ok(Recipe::allTags());
    }

    public static function cuisines(): void
    {
        Http::ok(Recipe::allCuisines());
    }

    /**
     * 改删前的护栏。
     * 家里共用一套菜谱本，所以有写权限的成员都能改别人的菜——
     * 但别人的「私有」菜谱不行。
     */
    private static function guardOwner(int $id): void
    {
        $r = Db::find('recipes', $id);

        if (!$r) {
            Http::fail('菜谱不存在', 404);
        }

        if (Auth::isAdmin() || (int) $r['owner_id'] === Auth::id()) {
            return;
        }

        if ((string) $r['visibility'] === 'private') {
            Http::fail('这是别人的私有菜谱，改不了', 403);
        }
    }
}
