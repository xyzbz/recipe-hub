<?php
declare(strict_types=1);

namespace RH\Repo;

use RH\Auth;
use RH\Db;
use RH\Http;
use RH\Logs;

/**
 * 食材库。
 *
 * 中餐的麻烦在于同一样东西有一堆叫法：生抽 / 酱油 / 豉油 / 味极鲜，
 * 土豆 / 马铃薯 / 洋芋。所以每条食材可以挂别名，录入菜谱时
 * 按「正名 → 别名」两级匹配，采购清单才不会给你列出四种酱油。
 */
final class Ingredient
{
    public const CATEGORIES = array(
        '蔬菜', '肉类', '水产', '蛋奶', '豆制品', '干货', '调味料', '主食', '水果', '其他',
    );

    /** 「正名 + 别名 => 食材 id」索引，单次请求内复用（PHP 每个请求会重置静态属性） */
    private static ?array $nameIndex = null;

    /**
     * 名字索引。别名先到先得，正名优先。
     *
     * 采购清单合并时要把「生抽 / 酱油 / 豉油」认成同一行，
     * 逐条去扫全表太慢，这里一次性建好。
     */
    public static function nameIndex(): array
    {
        if (self::$nameIndex !== null) {
            return self::$nameIndex;
        }

        $idx = array();

        foreach (Db::all('SELECT id, name, aliases FROM ingredients') as $r) {
            $id = (int) $r['id'];

            $idx[(string) $r['name']] = $id;

            foreach (self::aliasList((string) $r['aliases']) as $a) {
                if (!isset($idx[$a])) {
                    $idx[$a] = $id;
                }
            }
        }

        self::$nameIndex = $idx;

        return $idx;
    }

    /** 食材库变了就把索引丢掉，免得同一个请求里读到旧数据 */
    public static function flushIndex(): void
    {
        self::$nameIndex = null;
    }

    public static function list(array $opt = array()): array
    {
        $where  = array();
        $params = array();

        $q = trim((string) ($opt['q'] ?? ''));

        if ($q !== '') {
            $where[]  = '(name LIKE ? OR aliases LIKE ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }

        $cat = trim((string) ($opt['category'] ?? ''));

        if ($cat !== '') {
            $where[]  = 'category = ?';
            $params[] = $cat;
        }

        $sql = 'SELECT * FROM ingredients';

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY used_count DESC, name ASC LIMIT ' . max(1, min(500, (int) ($opt['limit'] ?? 300)));

        $rows = Db::all($sql, $params);

        foreach ($rows as &$r) {
            $r['id']         = (int) $r['id'];
            $r['is_builtin'] = (int) $r['is_builtin'];
            $r['used_count'] = (int) $r['used_count'];
            $r['alias_list'] = self::aliasList((string) $r['aliases']);
        }

        unset($r);

        return $rows;
    }

    public static function categories(): array
    {
        $rows = Db::all('SELECT category, COUNT(*) c FROM ingredients GROUP BY category ORDER BY c DESC');
        $out  = array();

        foreach (self::CATEGORIES as $c) {
            $out[$c] = 0;
        }

        foreach ($rows as $r) {
            $out[(string) $r['category']] = (int) $r['c'];
        }

        $ret = array();

        foreach ($out as $name => $count) {
            $ret[] = array('name' => $name, 'count' => $count);
        }

        return $ret;
    }

    public static function aliasList(string $aliases): array
    {
        if (trim($aliases) === '') {
            return array();
        }

        $parts = preg_split('/[,，、|]+/u', $aliases) ?: array();

        return array_values(array_filter(array_map('trim', $parts), static function ($s) {
            return $s !== '';
        }));
    }

    /**
     * 录入菜谱时把自由文本的食材名落到食材库上。
     * 从前端传下来的可能是 id，也可能只是一个名字，也可能什么都没给。
     */
    public static function resolve(string $name, int $ingredientId = 0): int
    {
        if ($ingredientId > 0 && Db::one('SELECT id FROM ingredients WHERE id = ?', array($ingredientId))) {
            return $ingredientId;
        }

        return self::findIdByName($name);
    }

    /** 正名 → 别名 两级匹配，找不到返回 0 */
    public static function findIdByName(string $name): int
    {
        $name = trim($name);

        if ($name === '') {
            return 0;
        }

        // 索引里正名和别名是拉平的，一次查到位
        return (int) (self::nameIndex()[$name] ?? 0);
    }

    /** 找不到就建一条，返回 id（自动标注为自定义食材） */
    public static function ensure(string $name, string $category = '其他'): int
    {
        $name = trim($name);

        if ($name === '') {
            return 0;
        }

        $id = self::findIdByName($name);

        if ($id) {
            return $id;
        }

        $new = Db::insert('ingredients', array(
            'name'         => mb_substr($name, 0, 40),
            'category'     => in_array($category, self::CATEGORIES, true) ? $category : '其他',
            'aliases'      => '',
            'default_unit' => '克',
            'is_builtin'   => 0,
            'note'         => '',
            'created_by'   => Auth::id(),
            'created_at'   => time(),
            'used_count'   => 0,
        ));

        self::flushIndex();

        return $new;
    }

    public static function create(array $data): int
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            Http::fail('食材名称不能为空', 400, 'name');
        }

        if (Db::one('SELECT id FROM ingredients WHERE name = ?', array($name))) {
            Http::fail('食材库里已经有「' . $name . '」了', 409, 'name');
        }

        $id = Db::insert('ingredients', array(
            'name'         => mb_substr($name, 0, 40),
            'category'     => self::cat($data['category'] ?? '其他'),
            'aliases'      => self::aliasToString($data['aliases'] ?? ''),
            'default_unit' => (string) ($data['default_unit'] ?? '克'),
            'is_builtin'   => 0,
            'note'         => mb_substr((string) ($data['note'] ?? ''), 0, 200),
            'created_by'   => Auth::id(),
            'created_at'   => time(),
            'used_count'   => 0,
        ));

        Logs::add('ingredient_create', $name);

        self::flushIndex();

        return $id;
    }

    public static function update(int $id, array $data): void
    {
        $row = Db::find('ingredients', $id);

        if (!$row) {
            Http::fail('食材不存在', 404);
        }

        $patch = array();

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);

            if ($name === '') {
                Http::fail('食材名称不能为空', 400, 'name');
            }

            $dup = Db::value('SELECT id FROM ingredients WHERE name = ? AND id <> ?', array($name, $id));

            if ($dup) {
                Http::fail('已经有同名食材了', 409, 'name');
            }

            $patch['name'] = mb_substr($name, 0, 40);
        }

        if (array_key_exists('category', $data)) {
            $patch['category'] = self::cat($data['category']);
        }

        if (array_key_exists('aliases', $data)) {
            $patch['aliases'] = self::aliasToString($data['aliases']);
        }

        if (array_key_exists('default_unit', $data)) {
            $patch['default_unit'] = (string) $data['default_unit'];
        }

        if (array_key_exists('note', $data)) {
            $patch['note'] = mb_substr((string) $data['note'], 0, 200);
        }

        if ($patch) {
            Db::updateRow('ingredients', $id, $patch);
            self::flushIndex();
        }
    }

    public static function remove(int $id): void
    {
        $row = Db::find('ingredients', $id);

        if (!$row) {
            Http::fail('食材不存在', 404);
        }

        // 菜谱里的食材行保留（它们是自由文本），只把关联 id 清掉
        Db::exec('UPDATE recipe_ingredients SET ingredient_id = 0 WHERE ingredient_id = ?', array($id));
        Db::exec('UPDATE pantry_items SET ingredient_id = 0 WHERE ingredient_id = ?', array($id));
        Db::exec('UPDATE shopping_items SET ingredient_id = 0 WHERE ingredient_id = ?', array($id));
        Db::delete('ingredients', $id);

        self::flushIndex();

        Logs::add('ingredient_delete', (string) $row['name'], '菜谱中的引用已解除关联，条目本身保留');
    }

    /**
     * 合并食材：把 from 并进 to。
     * 用于处理「土豆 / 马铃薯」这种同一物的两条记录。
     */
    public static function merge(int $fromId, int $toId): array
    {
        if ($fromId === $toId || $fromId <= 0 || $toId <= 0) {
            Http::fail('请选择两条不同的食材', 400);
        }

        $from = Db::find('ingredients', $fromId);
        $to   = Db::find('ingredients', $toId);

        if (!$from || !$to) {
            Http::fail('食材不存在', 404);
        }

        return Db::tx(static function () use ($from, $to, $fromId, $toId): array {
            $ing = Db::exec('UPDATE recipe_ingredients SET ingredient_id = ?, name = ? WHERE ingredient_id = ?',
                array($toId, $to['name'], $fromId));

            $pan = Db::exec('UPDATE pantry_items SET ingredient_id = ?, name = ? WHERE ingredient_id = ?',
                array($toId, $to['name'], $fromId));

            $sho = Db::exec('UPDATE shopping_items SET ingredient_id = ?, name = ? WHERE ingredient_id = ?',
                array($toId, $to['name'], $fromId));

            // 被合并方的名字进别名，下次录入还能匹配上
            $aliases = array_merge(
                self::aliasList((string) $to['aliases']),
                array((string) $from['name']),
                self::aliasList((string) $from['aliases'])
            );

            $aliases = array_values(array_unique(array_filter($aliases, static function ($a) use ($to) {
                return $a !== (string) $to['name'];
            })));

            Db::updateRow('ingredients', $toId, array(
                'aliases'    => implode(',', $aliases),
                'used_count' => (int) $to['used_count'] + (int) $from['used_count'],
            ));

            Db::delete('ingredients', $fromId);

            self::flushIndex();

            Logs::add('ingredient_merge', (string) $from['name'] . ' -> ' . (string) $to['name'],
                '菜谱 ' . $ing . ' 处、库存 ' . $pan . ' 处、清单 ' . $sho . ' 处已改指');

            return array('recipes' => $ing, 'pantry' => $pan, 'shopping' => $sho);
        });
    }

    /** 菜谱保存后刷新「用了几次」，搜索排序用 */
    public static function refreshUsage(): void
    {
        Db::exec('UPDATE ingredients SET used_count = (
            SELECT COUNT(*) FROM recipe_ingredients ri WHERE ri.ingredient_id = ingredients.id
        )');
    }

    public static function total(): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM ingredients');
    }

    /** 把数组或逗号串统一成「,a,b,」形式存库 */
    public static function aliasToString($aliases): string
    {
        if (is_array($aliases)) {
            $list = $aliases;
        } else {
            $list = self::aliasList((string) $aliases);
        }

        $list = array_values(array_unique(array_filter(array_map(static function ($a) {
            return trim((string) $a);
        }, $list), static function ($a) {
            return $a !== '';
        })));

        return implode(',', array_slice($list, 0, 20));
    }

    private static function cat($c): string
    {
        $c = (string) $c;

        return in_array($c, self::CATEGORIES, true) ? $c : '其他';
    }
}
