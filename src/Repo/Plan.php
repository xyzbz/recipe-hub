<?php
declare(strict_types=1);

namespace RH\Repo;

use RH\Auth;
use RH\Db;
use RH\Http;
use RH\Logs;
use RH\Units;

/**
 * 膳食计划日历。
 *
 * 它是整个闭环的中转站：菜谱排进来 → 买菜清单从这里长出来 → 做完了回填库存。
 */
final class Plan
{
    public const SLOTS = array('breakfast', 'lunch', 'dinner', 'snack');

    public const SLOT_LABEL = array(
        'breakfast' => '早餐',
        'lunch'     => '午餐',
        'dinner'    => '晚餐',
        'snack'     => '加餐',
    );

    // ------------------------------------------------------------------ 读

    /** 区间内的计划，附带菜谱摘要 */
    public static function range(string $from, string $to): array
    {
        $from = self::day($from);
        $to   = self::day($to);

        if ($from > $to) {
            $tmp  = $from;
            $from = $to;
            $to   = $tmp;
        }

        $rows = Db::all(
            'SELECT p.*,
                    r.name       AS recipe_name,
                    r.cover      AS recipe_cover,
                    r.servings   AS recipe_servings,
                    r.total_min  AS recipe_min,
                    r.prep_min   AS recipe_prep,
                    r.cook_min   AS recipe_cook,
                    r.difficulty AS recipe_difficulty,
                    r.cuisine    AS recipe_cuisine
               FROM meal_plans p
               LEFT JOIN recipes r ON r.id = p.recipe_id
              WHERE p.plan_date BETWEEN ? AND ?'
            . self::planVisibilitySql('r')
            . ' ORDER BY p.plan_date ASC, p.slot ASC, p.sort ASC, p.id ASC',
            array($from, $to)
        );

        foreach ($rows as &$r) {
            $r['id']         = (int) $r['id'];
            $r['recipe_id']  = (int) $r['recipe_id'];
            $r['servings']   = (float) $r['servings'];
            $r['sort']       = (int) $r['sort'];
            $r['title']      = (string) $r['title'] !== '' ? (string) $r['title'] : (string) ($r['recipe_name'] ?? '');
            $r['cover_url']  = \RH\Images::thumbUrl((string) ($r['recipe_cover'] ?? ''));
            $r['slot_label'] = self::SLOT_LABEL[(string) $r['slot']] ?? (string) $r['slot'];
        }

        unset($r);

        return $rows;
    }

    /** 按天 + 餐次分组，前端直接渲染 */
    public static function grouped(string $from, string $to): array
    {
        $days = array();

        $cur  = strtotime(self::day($from));
        $end  = strtotime(self::day($to));

        while ($cur <= $end) {
            $d          = date('Y-m-d', $cur);
            $days[$d]   = array(
                'date'  => $d,
                'week'  => (int) date('N', $cur),
                'meals' => array(),
            );

            foreach (self::SLOTS as $s) {
                $days[$d]['meals'][$s] = array();
            }

            $cur = strtotime('+1 day', $cur);
        }

        foreach (self::range($from, $to) as $r) {
            $d = (string) $r['plan_date'];

            if (!isset($days[$d])) {
                continue;
            }

            $slot = (string) $r['slot'];

            if (!isset($days[$d]['meals'][$slot])) {
                $slot = 'dinner';
            }

            $days[$d]['meals'][$slot][] = $r;
        }

        return array_values($days);
    }

    // ------------------------------------------------------------------ 写

    public static function create(array $data): int
    {
        $date = self::day($data['plan_date'] ?? '');

        if ($date === '') {
            Http::fail('没有指定日期', 400, 'plan_date');
        }

        $slot = (string) ($data['slot'] ?? 'dinner');

        if (!in_array($slot, self::SLOTS, true)) {
            $slot = 'dinner';
        }

        $recipeId = (int) ($data['recipe_id'] ?? 0);

        if ($recipeId > 0) {
            $rec = Db::find('recipes', $recipeId);

            if (!$rec) {
                Http::fail('菜谱不存在', 404, 'recipe_id');
            }

            if (!Recipe::canSee($rec)) {
                Http::fail('这是一道别人的私有菜谱，排不了', 403, 'recipe_id');
            }
        }

        $now = time();

        $id = Db::insert('meal_plans', array(
            'plan_date'  => $date,
            'slot'       => $slot,
            'recipe_id'  => $recipeId,
            'title'      => mb_substr(trim((string) ($data['title'] ?? '')), 0, 60),
            'servings'   => max(0.0, (float) ($data['servings'] ?? 0)),
            'note'       => mb_substr(trim((string) ($data['note'] ?? '')), 0, 120),
            'sort'       => (int) ($data['sort'] ?? 0),
            'created_by' => Auth::id(),
            'created_at' => $now,
            'updated_at' => $now,
        ));

        Logs::add('plan_add', $date . ' ' . (self::SLOT_LABEL[$slot] ?? $slot), $recipeId > 0 ? ('#' . $recipeId) : (string) ($data['title'] ?? ''));

        return $id;
    }

    public static function update(int $id, array $data): void
    {
        if (!Db::find('meal_plans', $id)) {
            Http::fail('计划条目不存在', 404);
        }

        $patch = array('updated_at' => time());

        if (array_key_exists('plan_date', $data)) {
            $d = self::day($data['plan_date']);

            if ($d === '') {
                Http::fail('日期不合法', 400, 'plan_date');
            }

            $patch['plan_date'] = $d;
        }

        if (array_key_exists('slot', $data)) {
            $slot = (string) $data['slot'];
            $patch['slot'] = in_array($slot, self::SLOTS, true) ? $slot : 'dinner';
        }

        if (array_key_exists('recipe_id', $data)) {
            $rid = (int) $data['recipe_id'];

            if ($rid > 0) {
                $rec = Db::find('recipes', $rid);

                if (!$rec) {
                    Http::fail('菜谱不存在', 404, 'recipe_id');
                }

                if (!Recipe::canSee($rec)) {
                    Http::fail('这是一道别人的私有菜谱，排不了', 403, 'recipe_id');
                }
            }

            $patch['recipe_id'] = $rid;
        }

        if (array_key_exists('title', $data)) {
            $patch['title'] = mb_substr(trim((string) $data['title']), 0, 60);
        }

        if (array_key_exists('servings', $data)) {
            $patch['servings'] = max(0.0, (float) $data['servings']);
        }

        if (array_key_exists('note', $data)) {
            $patch['note'] = mb_substr(trim((string) $data['note']), 0, 120);
        }

        if (array_key_exists('sort', $data)) {
            $patch['sort'] = (int) $data['sort'];
        }

        Db::updateRow('meal_plans', $id, $patch);
    }

    /** 拖拽换日期/餐次走这里 */
    public static function move(int $id, string $date, string $slot): void
    {
        self::update($id, array('plan_date' => $date, 'slot' => $slot));
    }

    /**
     * 一键换菜：这条计划不想吃了，随机换一道「本周还没排过」的可见菜谱。
     * 自由文本条目（没绑菜谱的）也能换——正好用「不知道吃啥，来一道随机的」。
     */
    public static function shuffle(int $id): array
    {
        $plan = Db::find('meal_plans', $id);

        if (!$plan) {
            Http::fail('计划条目不存在', 404);
        }

        $date  = (string) $plan['plan_date'];
        $start = self::weekStart($date);
        $end   = date('Y-m-d', strtotime($start . ' +6 day'));

        // 本周已经端上桌的菜不重复排；自己当前这道也要排除，
        // 否则「换一道」可能随机抽回同一道，等于没换
        $excluded = array((int) $plan['recipe_id']);

        foreach (Db::all(
            'SELECT DISTINCT recipe_id FROM meal_plans
              WHERE plan_date BETWEEN ? AND ? AND recipe_id > 0 AND id <> ?',
            array($start, $end, $id)
        ) as $row) {
            $excluded[] = (int) $row['recipe_id'];
        }

        $pick = Recipe::randomExcept($excluded);

        if (!$pick) {
            Http::fail('没有可换的菜谱了——库里其他菜都排在本周', 409);
        }

        Db::updateRow('meal_plans', $id, array(
            'recipe_id'  => (int) $pick['id'],
            'title'      => '',
            'updated_at' => time(),
        ));

        Logs::add('plan_shuffle', $date . ' ' . (string) $plan['slot'], (string) $pick['name']);

        return array(
            'id'     => $id,
            'recipe' => array(
                'id'         => (int) $pick['id'],
                'name'       => (string) $pick['name'],
                'cover_url'  => \RH\Images::thumbUrl((string) $pick['cover']),
                'total_min'  => (int) $pick['total_min'],
                'difficulty' => (string) $pick['difficulty'],
                'servings'   => (float) $pick['servings'],
            ),
        );
    }

    public static function remove(int $id): void
    {
        Db::delete('meal_plans', $id);
    }

    public static function clearRange(string $from, string $to): int
    {
        return Db::exec('DELETE FROM meal_plans WHERE plan_date BETWEEN ? AND ?', array(self::day($from), self::day($to)));
    }

    /**
     * 把某段时间的菜单原样复制到另一段时间。
     * 「上周那套挺好的，再来一遍」。
     */
    public static function copyRange(string $from, string $to, string $newStart): int
    {
        $rows = self::range($from, $to);

        if (!$rows) {
            Http::fail('这段时间没有安排', 400);
        }

        $base = strtotime(self::day($from));
        $dest = strtotime(self::day($newStart));
        $shift = (int) round(($dest - $base) / 86400);
        $n = 0;

        Db::tx(static function () use ($rows, $shift, &$n): void {
            foreach ($rows as $r) {
                $newDate = date('Y-m-d', strtotime((string) $r['plan_date'] . ' +' . $shift . ' day'));

                Db::insert('meal_plans', array(
                    'plan_date'  => $newDate,
                    'slot'       => (string) $r['slot'],
                    'recipe_id'  => (int) $r['recipe_id'],
                    'title'      => (string) $r['title'],
                    'servings'   => (float) $r['servings'],
                    'note'       => (string) $r['note'],
                    'sort'       => (int) $r['sort'],
                    'created_by' => Auth::id(),
                    'created_at' => time(),
                    'updated_at' => time(),
                ));

                $n++;
            }
        });

        Logs::add('plan_copy', $from . '~' . $to . ' -> ' . $newStart, '共 ' . $n . ' 条');

        return $n;
    }

    // ------------------------------------------------------------------ 周计划模板

    public static function templates(): array
    {
        $rows = Db::all('SELECT * FROM plan_templates ORDER BY id DESC');
        $out  = array();

        foreach ($rows as $r) {
            $payload = json_decode((string) $r['payload'], true);

            $out[] = array(
                'id'         => (int) $r['id'],
                'name'       => (string) $r['name'],
                'count'      => is_array($payload) ? count($payload) : 0,
                'created_at' => (int) $r['created_at'],
            );
        }

        return $out;
    }

    /** 把一段菜单存成模板（按「第几天」记，跟具体日期解耦） */
    public static function saveTemplate(string $name, string $from, string $to): int
    {
        $name = trim($name);

        if ($name === '') {
            Http::fail('给这套菜单起个名字', 400, 'name');
        }

        $rows = self::range($from, $to);

        if (!$rows) {
            Http::fail('这段时间没有安排，没什么可存的', 400);
        }

        $base    = strtotime(self::day($from));
        $payload = array();

        foreach ($rows as $r) {
            $payload[] = array(
                'offset'    => (int) round((strtotime((string) $r['plan_date']) - $base) / 86400),
                'slot'      => (string) $r['slot'],
                'recipe_id' => (int) $r['recipe_id'],
                'title'     => (string) $r['title'],
                'servings'  => (float) $r['servings'],
                'note'      => (string) $r['note'],
            );
        }

        $id = Db::insert('plan_templates', array(
            'name'       => mb_substr($name, 0, 40),
            'payload'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'created_by' => Auth::id(),
            'created_at' => time(),
        ));

        Logs::add('plan_template_save', $name, count($payload) . ' 条');

        return $id;
    }

    public static function applyTemplate(int $id, string $startDate): int
    {
        $t = Db::find('plan_templates', $id);

        if (!$t) {
            Http::fail('模板不存在', 404);
        }

        $payload = json_decode((string) $t['payload'], true);

        if (!is_array($payload) || !$payload) {
            Http::fail('模板是空的', 400);
        }

        $base = strtotime(self::day($startDate));
        $n    = 0;

        Db::tx(static function () use ($payload, $base, &$n): void {
            foreach ($payload as $p) {
                $date = date('Y-m-d', $base + ((int) ($p['offset'] ?? 0)) * 86400);

                Db::insert('meal_plans', array(
                    'plan_date'  => $date,
                    'slot'       => (string) ($p['slot'] ?? 'dinner'),
                    'recipe_id'  => (int) ($p['recipe_id'] ?? 0),
                    'title'      => (string) ($p['title'] ?? ''),
                    'servings'   => (float) ($p['servings'] ?? 0),
                    'note'       => (string) ($p['note'] ?? ''),
                    'sort'       => 0,
                    'created_by' => Auth::id(),
                    'created_at' => time(),
                    'updated_at' => time(),
                ));

                $n++;
            }
        });

        Logs::add('plan_template_apply', (string) $t['name'], '落在 ' . self::day($startDate) . '，共 ' . $n . ' 条');

        return $n;
    }

    public static function removeTemplate(int $id): void
    {
        Db::delete('plan_templates', $id);
    }

    // ------------------------------------------------------------------ 采购清单的原料

    /**
     * 把一段时间的菜单摊平成采购原料行。
     * 份数会按「计划人数 / 菜谱份数」等比放大——做 6 个人的饭，材料当然要多买。
     *
     * @return array<int, array{name:string, amount:?float, unit:string, qty_text:string, sources:string}>
     */
    public static function ingredientRows(string $from, string $to): array
    {
        $plans = Db::all(
            'SELECT p.recipe_id, p.servings AS plan_servings, p.title, r.name AS recipe_name, r.servings AS recipe_servings
               FROM meal_plans p
               LEFT JOIN recipes r ON r.id = p.recipe_id
              WHERE p.plan_date BETWEEN ? AND ?'
            . self::planVisibilitySql('r'),
            array(self::day($from), self::day($to))
        );

        if (!$plans) {
            return array();
        }

        $rows = array();

        foreach ($plans as $p) {
            $rid = (int) $p['recipe_id'];

            if ($rid <= 0) {
                continue;
            }

            $label = (string) $p['recipe_name'];

            if ($label === '') {
                $label = (string) $p['title'];
            }

            // 份数换算
            $mult = 1.0;
            $planS = (float) $p['plan_servings'];
            $recS  = (float) $p['recipe_servings'];

            if ($planS > 0 && $recS > 0) {
                $mult = $planS / $recS;
            }

            $mult = max(0.1, min(20.0, $mult));

            foreach (Db::all(
                'SELECT name, amount, unit, qty_text, note, optional, ingredient_id
                   FROM recipe_ingredients WHERE recipe_id = ? ORDER BY sort ASC',
                array($rid)
            ) as $i) {
                $rows[] = array(
                    'name'          => (string) $i['name'],
                    'amount'        => $i['amount'] === null ? null : (float) $i['amount'] * $mult,
                    'unit'          => (string) $i['unit'],
                    'qty_text'      => (string) $i['qty_text'],
                    'note'          => (string) $i['note'],
                    'optional'      => (int) $i['optional'],
                    'ingredient_id' => (int) $i['ingredient_id'],
                    'sources'       => $label . ($mult !== 1.0 ? '（' . Units::trimNum($mult) . ' 倍）' : ''),
                );
            }
        }

        return $rows;
    }

    /** 直接给一批菜谱摊平原料 */
    public static function ingredientRowsForRecipes(array $recipeIds, float $multiplier = 1.0): array
    {
        $rows = array();

        foreach (array_unique(array_map('intval', $recipeIds)) as $rid) {
            if ($rid <= 0) {
                continue;
            }

            $r = Db::find('recipes', $rid);

            if (!$r) {
                continue;
            }

            $mult = $multiplier > 0 ? $multiplier : 1.0;

            foreach (Db::all(
                'SELECT name, amount, unit, qty_text, note, optional, ingredient_id
                   FROM recipe_ingredients WHERE recipe_id = ? ORDER BY sort ASC',
                array($rid)
            ) as $i) {
                $rows[] = array(
                    'name'          => (string) $i['name'],
                    'amount'        => $i['amount'] === null ? null : (float) $i['amount'] * $mult,
                    'unit'          => (string) $i['unit'],
                    'qty_text'      => (string) $i['qty_text'],
                    'note'          => (string) $i['note'],
                    'optional'      => (int) $i['optional'],
                    'ingredient_id' => (int) $i['ingredient_id'],
                    'sources'       => (string) $r['name'],
                );
            }
        }

        return $rows;
    }

    // ------------------------------------------------------------------ 工具

    /**
     * 膳食计划是全家共享的一张表，但别人的「私有」菜谱不该在这里露脸
     * —— 否则菜名、封面、连带的食材都会顺着计划表漏出去。
     * 管理员不受限；id 为 NULL 的是纯文本条目（只写了标题，没挂菜谱），照常显示。
     */
    private static function planVisibilitySql(string $alias): string
    {
        if (Auth::isAdmin()) {
            return '';
        }

        return ' AND (' . $alias . '.id IS NULL'
            . ' OR ' . $alias . ".visibility <> 'private'"
            . ' OR ' . $alias . '.owner_id = ' . (int) Auth::id() . ')';
    }

    /** 把各种输入统一成 Y-m-d；空或非法返回 '' */
    public static function day($v): string
    {
        if ($v === null || $v === '') {
            return '';
        }

        if (is_numeric($v)) {
            return date('Y-m-d', (int) $v);
        }

        $ts = strtotime((string) $v);

        return $ts === false ? '' : date('Y-m-d', $ts);
    }

    /** 本周一 */
    public static function weekStart(string $date = ''): string
    {
        $ts = $date === '' ? time() : (int) strtotime($date);
        $n  = (int) date('N', $ts);

        return date('Y-m-d', strtotime('-' . ($n - 1) . ' day', $ts));
    }
}
