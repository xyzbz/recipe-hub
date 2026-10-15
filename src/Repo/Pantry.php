<?php
declare(strict_types=1);

namespace RH\Repo;

use RH\Auth;
use RH\Db;
use RH\Http;
use RH\Logs;

/**
 * 家庭食材库存。
 *
 * 除了记录，它还负责回答那个真正有用的问题：
 * 「家里现在这些东西，能做哪几道菜？」
 */
final class Pantry
{
    public const LOCATIONS = array('冷藏', '冷冻', '常温', '橱柜', '其他');

    /** 临期阈值（天） */
    public const SOON_DAYS = 3;

    public static function list(array $opt = array()): array
    {
        $where  = array();
        $params = array();

        $q = trim((string) ($opt['q'] ?? ''));

        if ($q !== '') {
            $where[]  = 'name LIKE ?';
            $params[] = '%' . $q . '%';
        }

        $loc = trim((string) ($opt['location'] ?? ''));

        if ($loc !== '') {
            $where[]  = 'location = ?';
            $params[] = $loc;
        }

        if (!empty($opt['expiring'])) {
            $limit    = time() + max(1, (int) ($opt['expiring_days'] ?? 7)) * 86400;
            $where[]  = 'expire_at > 0 AND expire_at <= ?';
            $params[] = $limit;
        }

        $sql = 'SELECT * FROM pantry_items';

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        // 有保质期的排前面，快到期的更靠前
        $sql .= ' ORDER BY CASE WHEN expire_at > 0 THEN 0 ELSE 1 END, expire_at ASC, id DESC';

        $rows = Db::all($sql, $params);

        foreach ($rows as &$r) {
            $r = self::decorate($r);
        }

        unset($r);

        return $rows;
    }

    public static function decorate(array $r): array
    {
        $r['id']            = (int) $r['id'];
        $r['ingredient_id'] = (int) $r['ingredient_id'];
        $r['amount']        = $r['amount'] === null ? null : (float) $r['amount'];
        $r['bought_at']     = (int) $r['bought_at'];
        $r['expire_at']     = (int) $r['expire_at'];
        $r['created_by']    = (int) $r['created_by'];
        $r['kind']          = \RH\Units::kind((string) $r['unit']);

        $qty = trim((string) $r['qty_text']);

        if ($qty !== '') {
            $r['display'] = $qty;
        } elseif ($r['amount'] !== null) {
            $unit = trim((string) $r['unit']);
            $r['display'] = \RH\Units::trimNum((float) $r['amount']) . ($unit !== '' ? ' ' . $unit : '');
        } else {
            $r['display'] = trim((string) $r['unit']);
        }

        // 到期状态
        $r['days_left'] = null;
        $r['state']     = 'none';

        if ($r['expire_at'] > 0) {
            $today          = strtotime(date('Y-m-d'));
            $r['days_left'] = (int) floor(($r['expire_at'] - $today) / 86400);

            if ($r['days_left'] < 0) {
                $r['state'] = 'expired';
            } elseif ($r['days_left'] <= self::SOON_DAYS) {
                $r['state'] = 'soon';
            } else {
                $r['state'] = 'fresh';
            }
        }

        return $r;
    }

    public static function create(array $data): int
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            Http::fail('食材名称不能为空', 400, 'name');
        }

        $now = time();

        $id = Db::insert('pantry_items', array(
            'ingredient_id' => Ingredient::resolve($name, (int) ($data['ingredient_id'] ?? 0)),
            'name'          => mb_substr($name, 0, 40),
            'amount'        => self::num($data['amount'] ?? null),
            'unit'          => mb_substr(\RH\Units::norm((string) ($data['unit'] ?? '')), 0, 8),
            'qty_text'      => mb_substr(trim((string) ($data['qty_text'] ?? '')), 0, 32),
            'location'      => self::loc($data['location'] ?? '冷藏'),
            'bought_at'     => self::date($data['bought_at'] ?? null, $now),
            'expire_at'     => self::date($data['expire_at'] ?? null, 0),
            'note'          => mb_substr(trim((string) ($data['note'] ?? '')), 0, 120),
            'created_by'    => Auth::id(),
            'created_at'    => $now,
            'updated_at'    => $now,
        ));

        Logs::add('pantry_add', $name);

        return $id;
    }

    public static function update(int $id, array $data): void
    {
        if (!Db::find('pantry_items', $id)) {
            Http::fail('库存里没有这条', 404);
        }

        $cur   = Db::find('pantry_items', $id);
        $patch = array('updated_at' => time());

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);

            if ($name === '') {
                Http::fail('食材名称不能为空', 400, 'name');
            }

            $patch['name']          = mb_substr($name, 0, 40);
            $patch['ingredient_id'] = Ingredient::resolve($name, 0);
        }

        if (array_key_exists('amount', $data)) {
            $patch['amount'] = self::num($data['amount']);
        }

        if (array_key_exists('unit', $data)) {
            $patch['unit'] = mb_substr(\RH\Units::norm((string) $data['unit']), 0, 8);
        }

        if (array_key_exists('qty_text', $data)) {
            $patch['qty_text'] = mb_substr(trim((string) $data['qty_text']), 0, 32);
        }

        if (array_key_exists('location', $data)) {
            $patch['location'] = self::loc($data['location']);
        }

        if (array_key_exists('bought_at', $data)) {
            $patch['bought_at'] = self::date($data['bought_at'], (int) $cur['bought_at']);
        }

        if (array_key_exists('expire_at', $data)) {
            $patch['expire_at'] = self::date($data['expire_at'], 0);
        }

        if (array_key_exists('note', $data)) {
            $patch['note'] = mb_substr(trim((string) $data['note']), 0, 120);
        }

        Db::updateRow('pantry_items', $id, $patch);
    }

    public static function remove(int $id): void
    {
        $r = Db::find('pantry_items', $id);

        if (!$r) {
            Http::fail('库存里没有这条', 404);
        }

        Db::delete('pantry_items', $id);
        Logs::add('pantry_remove', (string) $r['name']);
    }

    /** 用完 / 买回来了，加减数量 */
    public static function adjust(int $id, float $delta): array
    {
        $r = Db::find('pantry_items', $id);

        if (!$r) {
            Http::fail('库存里没有这条', 404);
        }

        if ($r['amount'] === null) {
            Http::fail('这条没记具体数量，改不了', 400);
        }

        $next = (float) $r['amount'] + $delta;

        if ($next <= 0.0001) {
            Db::delete('pantry_items', $id);

            return array('removed' => true, 'amount' => 0.0);
        }

        Db::updateRow('pantry_items', $id, array('amount' => $next, 'updated_at' => time()));

        return array('removed' => false, 'amount' => $next);
    }

    /**
     * 按库存推荐菜谱。
     * 评分 = 命中食材数 / 需要食材数，缺的东西一并列出来，
     * 这样一眼能看出「就差一样，顺路买一下」。
     */
    public static function suggest(int $limit = 20, float $minRatio = 0.5): array
    {
        $pantry = array();

        foreach (Db::all('SELECT name, ingredient_id FROM pantry_items') as $p) {
            $pantry[self::norm((string) $p['name'])] = true;

            if ((int) $p['ingredient_id'] > 0) {
                $ing = Db::find('ingredients', (int) $p['ingredient_id']);

                if ($ing) {
                    $pantry[self::norm((string) $ing['name'])] = true;

                    foreach (Ingredient::aliasList((string) $ing['aliases']) as $a) {
                        $pantry[self::norm($a)] = true;
                    }
                }
            }
        }

        if (!$pantry) {
            return array();
        }

        // 库存里有食材的别名也要能匹配上菜谱里的写法
        foreach (Db::all("SELECT name, aliases FROM ingredients WHERE aliases <> ''") as $row) {
            $n = self::norm((string) $row['name']);

            if (!isset($pantry[$n])) {
                continue;
            }

            foreach (Ingredient::aliasList((string) $row['aliases']) as $a) {
                $pantry[self::norm($a)] = true;
            }
        }

        [$visSql, $visParams] = Recipe::visibilityClause('recipes');

        // 回收站里的菜不参与推荐——它的料其实也没打算再买
        $where = array('recipes.deleted_at = 0');

        if ($visSql !== '') {
            $where[] = $visSql;
        }

        $recipes = Db::all(
            'SELECT id, name, cover, total_min, prep_min, cook_min, difficulty, cuisine, servings, updated_at
               FROM recipes WHERE ' . implode(' AND ', $where) . '
              ORDER BY id ASC',
            $visParams
        );

        $need = array();

        foreach (Db::all('SELECT recipe_id, name, optional FROM recipe_ingredients ORDER BY recipe_id ASC, sort ASC') as $ri) {
            $need[(int) $ri['recipe_id']][] = array(
                'name'     => (string) $ri['name'],
                'optional' => (int) $ri['optional'],
            );
        }

        $out = array();

        foreach ($recipes as $r) {
            $items = $need[(int) $r['id']] ?? array();

            if (!$items) {
                continue;
            }

            // 可选项不算进分母，否则「想做红烧肉就差一根葱」会被算成不达标
            $required = array_values(array_filter($items, static function (array $i): bool {
                return $i['optional'] === 0;
            }));

            if (count($required) < 2) {
                $required = $items;
            }

            $have    = array();
            $missing = array();

            foreach ($required as $i) {
                $n = self::norm($i['name']);

                if (isset($pantry[$n]) || self::fuzzyHit($n, $pantry)) {
                    $have[] = $i['name'];
                } else {
                    $missing[] = $i['name'];
                }
            }

            $total = count($required);

            if ($total === 0) {
                continue;
            }

            $ratio = count($have) / $total;

            if ($ratio < $minRatio) {
                continue;
            }

            $out[] = array(
                'id'         => (int) $r['id'],
                'name'       => (string) $r['name'],
                'cover'      => (string) $r['cover'],
                'cover_url'  => \RH\Images::url((string) $r['cover']),
                'cover_thumb' => \RH\Images::thumbUrl((string) $r['cover']),
                'total_min'  => (int) $r['total_min'],
                'difficulty' => (string) $r['difficulty'],
                'cuisine'    => (string) $r['cuisine'],
                'have'       => $have,
                'missing'    => $missing,
                'have_count' => count($have),
                'need_count' => $total,
                'ratio'      => round($ratio, 3),
                'score'      => count($have),
            );
        }

        usort($out, static function (array $a, array $b): int {
            if ($a['ratio'] !== $b['ratio']) {
                return $b['ratio'] <=> $a['ratio'];
            }

            if ($a['missing'] !== $b['missing'] && count($a['missing']) !== count($b['missing'])) {
                return count($a['missing']) <=> count($b['missing']);
            }

            return $b['score'] <=> $a['score'];
        });

        return array_slice($out, 0, max(1, min(100, $limit)));
    }

    /** 模糊匹配：菜谱写「小葱」，库存写「葱」也能算命中 */
    private static function fuzzyHit(string $needle, array $haystack): bool
    {
        if (mb_strlen($needle) < 2) {
            return false;
        }

        foreach (array_keys($haystack) as $k) {
            if (mb_strlen($k) < 2) {
                continue;
            }

            if (mb_strpos($needle, $k) !== false || mb_strpos($k, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    public static function stats(): array
    {
        $today = strtotime(date('Y-m-d'));
        $soon  = $today + self::SOON_DAYS * 86400;

        return array(
            'total'    => (int) Db::value('SELECT COUNT(*) FROM pantry_items'),
            'expired'  => (int) Db::value('SELECT COUNT(*) FROM pantry_items WHERE expire_at > 0 AND expire_at < ?', array($today)),
            'soon'     => (int) Db::value(
                'SELECT COUNT(*) FROM pantry_items WHERE expire_at >= ? AND expire_at <= ?',
                array($today, $soon)
            ),
            'with_exp' => (int) Db::value('SELECT COUNT(*) FROM pantry_items WHERE expire_at > 0'),
        );
    }

    private static function norm(string $s): string
    {
        return mb_strtolower(trim(str_replace(array(' ', "\u{3000}"), '', $s)));
    }

    private static function num($v)
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return null;
        }

        return (float) $v;
    }

    private static function loc($v): string
    {
        $v = (string) $v;

        return in_array($v, self::LOCATIONS, true) ? $v : '其他';
    }

    /** 接受时间戳或 Y-m-d 字符串 */
    private static function date($v, int $default): int
    {
        if ($v === null || $v === '') {
            return $default;
        }

        if (is_numeric($v)) {
            return (int) $v;
        }

        $ts = strtotime((string) $v);

        return $ts === false ? $default : (int) strtotime(date('Y-m-d', $ts));
    }
}
