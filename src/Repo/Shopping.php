<?php
declare(strict_types=1);

namespace RH\Repo;

use RH\Auth;
use RH\Db;
use RH\Http;
use RH\Logs;
use RH\Units;

/**
 * 采购清单。
 *
 * 全系统最「中餐」的一块在这里：汇总时要能把
 * 「2 斤五花肉」+「300 克五花肉」+「1 两五花肉」算成一行「1430 克（2.86 斤）」，
 * 同时把「生抽少许」原样单列——因为它根本没法参与算术。
 */
final class Shopping
{
    /** 分类排序，采购时按这个顺序逛菜市场比较顺 */
    private const CAT_ORDER = array(
        '蔬菜', '肉类', '水产', '蛋奶', '豆制品', '干货', '调味料', '主食', '水果', '其他', '',
    );

    // ------------------------------------------------------------------ 清单

    public static function lists(bool $onlyOpen = false): array
    {
        $sql = 'SELECT l.*,
                       (SELECT COUNT(*) FROM shopping_items i WHERE i.list_id = l.id) AS item_count,
                       (SELECT COUNT(*) FROM shopping_items i WHERE i.list_id = l.id AND i.checked = 1) AS checked_count
                  FROM shopping_lists l';

        if ($onlyOpen) {
            $sql .= " WHERE l.status = 'open'";
        }

        $sql .= ' ORDER BY l.updated_at DESC, l.id DESC';

        $rows = Db::all($sql);

        foreach ($rows as &$r) {
            $r['id']            = (int) $r['id'];
            $r['item_count']    = (int) $r['item_count'];
            $r['checked_count'] = (int) $r['checked_count'];
            $r['created_at']    = (int) $r['created_at'];
            $r['updated_at']    = (int) $r['updated_at'];
        }

        unset($r);

        return $rows;
    }

    public static function find(int $id): ?array
    {
        $l = Db::find('shopping_lists', $id);

        if (!$l) {
            return null;
        }

        $l['id']         = (int) $l['id'];
        $l['created_at'] = (int) $l['created_at'];
        $l['updated_at'] = (int) $l['updated_at'];
        $l['items']      = self::items($id);

        $done = 0;

        foreach ($l['items'] as $i) {
            if ($i['checked']) {
                $done++;
            }
        }

        $l['item_count']    = count($l['items']);
        $l['checked_count'] = $done;

        return $l;
    }

    public static function create(string $name = ''): int
    {
        $name = trim($name);

        if ($name === '') {
            $name = '采购清单 ' . date('n月j日');
        }

        $now = time();

        $id = Db::insert('shopping_lists', array(
            'name'       => mb_substr($name, 0, 40),
            'status'     => 'open',
            'created_by' => Auth::id(),
            'created_at' => $now,
            'updated_at' => $now,
        ));

        Logs::add('shopping_create', $name, '#' . $id);

        return $id;
    }

    public static function update(int $id, array $data): void
    {
        if (!Db::find('shopping_lists', $id)) {
            Http::fail('清单不存在', 404);
        }

        $patch = array('updated_at' => time());

        if (array_key_exists('name', $data)) {
            $patch['name'] = mb_substr(trim((string) $data['name']), 0, 40);
        }

        if (array_key_exists('status', $data)) {
            $s = (string) $data['status'];
            $patch['status'] = in_array($s, array('open', 'done'), true) ? $s : 'open';
        }

        Db::updateRow('shopping_lists', $id, $patch);
    }

    public static function remove(int $id): void
    {
        $l = Db::find('shopping_lists', $id);

        if (!$l) {
            Http::fail('清单不存在', 404);
        }

        Db::delete('shopping_lists', $id);
        Logs::add('shopping_delete', (string) $l['name'], '#' . $id);
    }

    public static function touch(int $listId): void
    {
        Db::updateRow('shopping_lists', $listId, array('updated_at' => time()));
    }

    // ------------------------------------------------------------------ 条目

    public static function items(int $listId): array
    {
        $rows = Db::all(
            'SELECT * FROM shopping_items WHERE list_id = ? ORDER BY sort ASC, id ASC',
            array($listId)
        );

        $cats = self::categoryMap($rows);

        $out = array();

        foreach ($rows as $r) {
            $out[] = self::decorateItem($r, $cats);
        }

        usort($out, array(self::class, 'cmpItem'));

        return $out;
    }

    public static function addItem(int $listId, array $data): int
    {
        if (!Db::find('shopping_lists', $listId)) {
            Http::fail('清单不存在', 404);
        }

        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            Http::fail('写个食材名吧', 400, 'name');
        }

        $id = Db::insert('shopping_items', array(
            'list_id'       => $listId,
            'ingredient_id' => Ingredient::resolve($name, (int) ($data['ingredient_id'] ?? 0)),
            'name'          => mb_substr($name, 0, 40),
            'amount'        => self::num($data['amount'] ?? null),
            'unit'          => mb_substr(Units::norm((string) ($data['unit'] ?? '')), 0, 8),
            'qty_text'      => mb_substr(trim((string) ($data['qty_text'] ?? '')), 0, 32),
            'note'          => mb_substr(trim((string) ($data['note'] ?? '')), 0, 80),
            'checked'       => !empty($data['checked']) ? 1 : 0,
            'manual'        => 1,
            'sources'       => mb_substr(trim((string) ($data['sources'] ?? '')), 0, 200),
            'sort'          => (int) ($data['sort'] ?? 0),
            'created_at'    => time(),
        ));

        self::touch($listId);

        return $id;
    }

    public static function updateItem(int $id, array $data): void
    {
        $it = Db::find('shopping_items', $id);

        if (!$it) {
            Http::fail('这条不在清单里', 404);
        }

        $patch = array();

        if (array_key_exists('name', $data)) {
            $n = trim((string) $data['name']);

            if ($n === '') {
                Http::fail('食材名不能为空', 400, 'name');
            }

            $patch['name'] = mb_substr($n, 0, 40);
        }

        if (array_key_exists('amount', $data)) {
            $patch['amount'] = self::num($data['amount']);
        }

        if (array_key_exists('unit', $data)) {
            $patch['unit'] = mb_substr(Units::norm((string) $data['unit']), 0, 8);
        }

        if (array_key_exists('qty_text', $data)) {
            $patch['qty_text'] = mb_substr(trim((string) $data['qty_text']), 0, 32);
        }

        if (array_key_exists('note', $data)) {
            $patch['note'] = mb_substr(trim((string) $data['note']), 0, 80);
        }

        if (array_key_exists('checked', $data)) {
            $patch['checked'] = !empty($data['checked']) ? 1 : 0;
        }

        if (array_key_exists('sort', $data)) {
            $patch['sort'] = (int) $data['sort'];
        }

        if ($patch) {
            Db::updateRow('shopping_items', $id, $patch);
            self::touch((int) $it['list_id']);
        }
    }

    public static function toggleItem(int $id): bool
    {
        $it = Db::find('shopping_items', $id);

        if (!$it) {
            Http::fail('这条不在清单里', 404);
        }

        $next = (int) $it['checked'] === 1 ? 0 : 1;
        Db::updateRow('shopping_items', $id, array('checked' => $next));
        self::touch((int) $it['list_id']);

        return $next === 1;
    }

    public static function removeItem(int $id): void
    {
        $it = Db::find('shopping_items', $id);

        if (!$it) {
            return;
        }

        Db::delete('shopping_items', $id);
        self::touch((int) $it['list_id']);
    }

    public static function clearChecked(int $listId): int
    {
        $n = Db::exec('DELETE FROM shopping_items WHERE list_id = ? AND checked = 1', array($listId));
        self::touch($listId);

        return $n;
    }

    public static function uncheckAll(int $listId): int
    {
        $n = Db::exec('UPDATE shopping_items SET checked = 0 WHERE list_id = ?', array($listId));
        self::touch($listId);

        return $n;
    }

    public static function clearAll(int $listId): int
    {
        $n = Db::exec('DELETE FROM shopping_items WHERE list_id = ?', array($listId));
        self::touch($listId);

        return $n;
    }

    // ------------------------------------------------------------------ 合并（核心）

    /**
     * 把一堆原料行合并成采购条目。
     *
     * 合并规则：
     *   同物异名  —— 先按食材库归到正名：生抽 / 酱油 / 豉油 是同一条
     *   重量/体积 —— 换算到基准单位后相加。2斤 + 300克 = 1300 克
     *   计数      —— 单位字面相同才相加。2个 + 3个 = 5个，但「2个」和「3片」各占一行
     *   模糊      —— 完全不参与计算，「生抽 少许」自己一行
     */
    public static function mergeRows(array $rows): array
    {
        $acc  = array();
        $cats = array();
        $look = self::nameLookup($rows);

        foreach ($rows as $r) {
            $raw = trim((string) ($r['name'] ?? ''));

            if ($raw === '') {
                continue;
            }

            // 归到食材库的正名；库里没有这个名字就保持原样
            $hit   = $look[self::key($raw)] ?? null;
            $name  = $hit !== null ? $hit['name'] : $raw;
            $ingId = $hit !== null ? $hit['id'] : (int) ($r['ingredient_id'] ?? 0);

            $nameKey = self::key($name);
            $base    = Units::toBase($r['amount'] ?? null, (string) ($r['unit'] ?? ''));
            $manual  = !empty($r['manual']);
            $source  = trim((string) ($r['sources'] ?? ''));

            if ($base !== null) {
                // —— 可计算：重量 / 体积 / 计数
                $unitLabel = $base['unit'] !== '' ? $base['unit'] : Units::baseLabel($base['kind']);
                $key       = $nameKey . '|' . $base['kind'] . '|' . $unitLabel;

                if (!isset($acc[$key])) {
                    $acc[$key] = array(
                        'name'          => $name,
                        'measurable'    => true,
                        'kind'          => $base['kind'],
                        'unit'          => $unitLabel,
                        'sum'           => 0.0,
                        'qty_text'      => '',
                        'note'          => '',
                        'optional'      => false,
                        'manual'        => $manual,
                        'ingredient_id' => $ingId,
                        'sources'       => array(),
                    );
                }

                $acc[$key]['sum'] += (float) $base['value'];

                if (!$manual) {
                    $acc[$key]['manual'] = false;
                }

                if (!empty($r['optional'])) {
                    $acc[$key]['optional'] = true;
                }

                if ($ingId > 0 && $acc[$key]['ingredient_id'] === 0) {
                    $acc[$key]['ingredient_id'] = $ingId;
                }
            } else {
                // —— 不可计算：少许/适量，或者单位不在表里
                $label = trim((string) ($r['qty_text'] ?? ''));

                if ($label === '') {
                    $label = trim((string) ($r['unit'] ?? ''));
                }

                if ($label === '' && isset($r['amount']) && is_numeric($r['amount'])) {
                    $label = Units::trimNum((float) $r['amount']);
                }

                if ($label === '') {
                    $label = '适量';
                }

                $key = $nameKey . '|f|' . $label;

                if (!isset($acc[$key])) {
                    $acc[$key] = array(
                        'name'          => $name,
                        'measurable'    => false,
                        'kind'          => Units::isFuzzy($label) ? Units::FUZZY : Units::OTHER,
                        'unit'          => $label,
                        'sum'           => 0.0,
                        'qty_text'      => $label,
                        'note'          => '',
                        'optional'      => false,
                        'manual'        => $manual,
                        'ingredient_id' => $ingId,
                        'sources'       => array(),
                    );
                }

                if (!$manual) {
                    $acc[$key]['manual'] = false;
                }

                if (!empty($r['optional'])) {
                    $acc[$key]['optional'] = true;
                }
            }

            if ($source !== '') {
                $acc[$key]['sources'][$source] = true;
            }

            $note = trim((string) ($r['note'] ?? ''));

            if ($note !== '' && $acc[$key]['note'] === '') {
                $acc[$key]['note'] = $note;
            }
        }

        $ingIds = array();

        foreach ($acc as $a) {
            if ($a['ingredient_id'] > 0) {
                $ingIds[] = $a['ingredient_id'];
            }
        }

        if ($ingIds) {
            $ph = implode(',', array_fill(0, count($ingIds), '?'));

            foreach (Db::all("SELECT id, category FROM ingredients WHERE id IN ($ph)", array_values(array_unique($ingIds))) as $c) {
                $cats[(int) $c['id']] = (string) $c['category'];
            }
        }

        $out = array();

        foreach ($acc as $a) {
            $a['sources'] = implode('、', array_slice(array_keys($a['sources']), 0, 6));
            $a['category'] = $cats[$a['ingredient_id']] ?? '';

            if ($a['measurable']) {
                $sum           = round($a['sum'], 2);
                $a['amount']   = $sum;
                $a['qty_text'] = '';
                $a['display']  = $a['kind'] === Units::COUNT
                    ? Units::trimNum($sum) . ' ' . $a['unit']
                    : Units::formatTotal($a['kind'], $sum);
            } else {
                $a['amount']  = null;
                $a['display'] = $a['unit'];
            }

            unset($a['sum']);

            $out[] = $a;
        }

        usort($out, array(self::class, 'cmpItem'));

        return $out;
    }

    /** 合并用的名字归一键：去掉空格、转小写 */
    private static function key(string $name): string
    {
        return mb_strtolower(str_replace(array(' ', "\u{3000}"), '', trim($name)));
    }

    /**
     * 一次把整批原料行的名字查成「食材库正名 + id」。
     *
     * 分两步：先用食材库的名字/别名索引（内存里）把名字对到 id，
     * 再一次性把 id 换成正名。避免逐行查库，也避免同一个名字查两遍。
     *
     * @return array<string, array{id:int, name:string}> 名字归一键 => 正名信息
     */
    private static function nameLookup(array $rows): array
    {
        $index = Ingredient::nameIndex();
        $pairs = array();
        $ids   = array();

        foreach ($rows as $r) {
            $raw = trim((string) ($r['name'] ?? ''));

            if ($raw === '') {
                continue;
            }

            $id = (int) ($r['ingredient_id'] ?? 0);

            if ($id <= 0) {
                $id = (int) ($index[$raw] ?? 0);
            }

            if ($id > 0) {
                $ids[] = $id;
            }

            $pairs[self::key($raw)] = $id;
        }

        if (!$ids) {
            return array();
        }

        $names = array();
        $ph    = implode(',', array_fill(0, count($ids), '?'));

        foreach (Db::all(
            "SELECT id, name FROM ingredients WHERE id IN ($ph)",
            array_values(array_unique($ids))
        ) as $c) {
            $names[(int) $c['id']] = (string) $c['name'];
        }

        $out = array();

        foreach ($pairs as $k => $id) {
            if ($id > 0 && isset($names[$id])) {
                $out[$k] = array('id' => $id, 'name' => $names[$id]);
            }
        }

        return $out;
    }

    /**
     * 用一批原料行重建清单里的自动条目。
     *
     * @param bool $mergeExistingManual 是否顺手把清单里已有的手动条目也并进来。
     *        refill 自己会拉手动条目，所以调用方如果已经把它们放进 $rows 了，
     *        这里必须传 false —— 否则手动条目会被算两遍（踩过）。
     */
    public static function refill(int $listId, array $rows, bool $mergeExistingManual = true): int
    {
        if (!Db::find('shopping_lists', $listId)) {
            Http::fail('清单不存在', 404);
        }

        return Db::tx(static function () use ($listId, $rows, $mergeExistingManual): int {
            // 已经勾掉的名字记下来，重新合并后原样保留勾选状态
            $checkedNames = array();

            foreach (Db::all('SELECT name, checked FROM shopping_items WHERE list_id = ?', array($listId)) as $old) {
                if ((int) $old['checked'] === 1) {
                    $checkedNames[mb_strtolower(trim((string) $old['name']))] = true;
                }
            }

            // 手动加的条目也要参与合并 —— 否则「我顺手买的那箱牛奶」会被冲掉
            if ($mergeExistingManual) {
                foreach (Db::all('SELECT * FROM shopping_items WHERE list_id = ? AND manual = 1', array($listId)) as $m) {
                    $rows[] = array(
                        'name'          => (string) $m['name'],
                        'amount'        => $m['amount'],
                        'unit'          => (string) $m['unit'],
                        'qty_text'      => (string) $m['qty_text'],
                        'note'          => (string) $m['note'],
                        'optional'      => 0,
                        'ingredient_id' => (int) $m['ingredient_id'],
                        'sources'       => '',
                        'manual'        => true,
                    );
                }
            }

            $merged = self::mergeRows($rows);

            Db::exec('DELETE FROM shopping_items WHERE list_id = ?', array($listId));

            $sort = 0;

            foreach ($merged as $m) {
                Db::insert('shopping_items', array(
                    'list_id'       => $listId,
                    'ingredient_id' => (int) $m['ingredient_id'],
                    'name'          => mb_substr((string) $m['name'], 0, 40),
                    'amount'        => $m['amount'],
                    'unit'          => mb_substr((string) $m['unit'], 0, 8),
                    'qty_text'      => mb_substr((string) $m['qty_text'], 0, 32),
                    'note'          => mb_substr((string) $m['note'], 0, 80),
                    'checked'       => isset($checkedNames[mb_strtolower(trim((string) $m['name']))]) ? 1 : 0,
                    'manual'        => !empty($m['manual']) ? 1 : 0,
                    'sources'       => mb_substr((string) $m['sources'], 0, 200),
                    'sort'          => $sort++,
                    'created_at'    => time(),
                ));
            }

            self::touch($listId);

            return count($merged);
        });
    }

    /** 按当前清单里的条目重新整理一次（合并重复项） */
    public static function tidy(int $listId): int
    {
        $rows = array();

        foreach (Db::all('SELECT * FROM shopping_items WHERE list_id = ?', array($listId)) as $i) {
            $rows[] = array(
                'name'          => (string) $i['name'],
                'amount'        => $i['amount'],
                'unit'          => (string) $i['unit'],
                'qty_text'      => (string) $i['qty_text'],
                'note'          => (string) $i['note'],
                'optional'      => 0,
                'ingredient_id' => (int) $i['ingredient_id'],
                'sources'       => (string) $i['sources'],
                'manual'        => (int) $i['manual'] === 1,
            );
        }

        return self::refill($listId, $rows, false);
    }

    // ------------------------------------------------------------------ 从别处生成

    /** 从膳食计划区间生成（或刷新）清单 */
    public static function fillFromPlan(int $listId, string $from, string $to): int
    {
        $rows = Plan::ingredientRows($from, $to);

        if (!$rows) {
            Http::fail('这段时间的菜单里没有排菜，先排几道再来', 400);
        }

        $n = self::refill($listId, $rows);

        Logs::add('shopping_from_plan', Plan::day($from) . ' ~ ' . Plan::day($to), '共 ' . $n . ' 条');

        return $n;
    }

    /** 从膳食计划新建一份清单，返回 [listId, 条目数] */
    public static function createFromPlan(string $from, string $to, string $name = ''): array
    {
        $rows = Plan::ingredientRows($from, $to);

        if (!$rows) {
            Http::fail('这段时间的菜单里没有排菜，先排几道再来', 400);
        }

        $listId = self::create($name);
        $n      = self::refill($listId, $rows);

        Logs::add('shopping_from_plan', Plan::day($from) . ' ~ ' . Plan::day($to), '#' . $listId . '，共 ' . $n . ' 条');

        return array('list_id' => $listId, 'count' => $n);
    }

    /** 把几道菜谱直接加进清单 */
    public static function addRecipes(int $listId, array $recipeIds, float $multiplier = 1.0): int
    {
        $rows = Plan::ingredientRowsForRecipes($recipeIds, $multiplier);

        if (!$rows) {
            Http::fail('这些菜谱里没有食材', 400);
        }

        // 保留清单原有内容，把新的并进去一起合并
        foreach (Db::all('SELECT * FROM shopping_items WHERE list_id = ?', array($listId)) as $i) {
            $rows[] = array(
                'name'          => (string) $i['name'],
                'amount'        => $i['amount'],
                'unit'          => (string) $i['unit'],
                'qty_text'      => (string) $i['qty_text'],
                'note'          => (string) $i['note'],
                'optional'      => 0,
                'ingredient_id' => (int) $i['ingredient_id'],
                'sources'       => (string) $i['sources'],
                'manual'        => (int) $i['manual'] === 1,
            );
        }

        return self::refill($listId, $rows, false);
    }

    // ------------------------------------------------------------------ 导出

    public static function exportText(int $listId, bool $onlyUnchecked = false): string
    {
        $l = self::find($listId);

        if (!$l) {
            Http::fail('清单不存在', 404);
        }

        $lines = array();
        $lines[] = (string) $l['name'];
        $lines[] = '共 ' . $l['item_count'] . ' 项，已买 ' . $l['checked_count'] . ' 项';
        $lines[] = str_repeat('─', 22);

        $byCat = array();

        foreach ($l['items'] as $i) {
            if ($onlyUnchecked && $i['checked']) {
                continue;
            }

            $cat = (string) ($i['category'] ?? '');
            $byCat[$cat === '' ? '其他' : $cat][] = $i;
        }

        foreach (self::CAT_ORDER as $cat) {
            if (!isset($byCat[$cat])) {
                continue;
            }

            $lines[] = '';
            $lines[] = '【' . $cat . '】';

            foreach ($byCat[$cat] as $i) {
                $mark = $i['checked'] ? '[√]' : '[ ]';
                $line = $mark . ' ' . $i['name'] . ' ' . $i['display'];

                if ($i['note'] !== '') {
                    $line .= '（' . $i['note'] . '）';
                }

                $lines[] = $line;
            }

            unset($byCat[$cat]);
        }

        foreach ($byCat as $cat => $items) {
            $lines[] = '';
            $lines[] = '【' . $cat . '】';

            foreach ($items as $i) {
                $mark = $i['checked'] ? '[√]' : '[ ]';
                $lines[] = $mark . ' ' . $i['name'] . ' ' . $i['display'];
            }
        }

        return implode("\n", $lines);
    }

    // ------------------------------------------------------------------ 内部

    private static function decorateItem(array $r, array $cats = array()): array
    {
        $r['id']            = (int) $r['id'];
        $r['list_id']       = (int) $r['list_id'];
        $r['ingredient_id'] = (int) $r['ingredient_id'];
        $r['amount']        = $r['amount'] === null ? null : (float) $r['amount'];
        $r['checked']       = (int) $r['checked'];
        $r['manual']        = (int) $r['manual'];
        $r['sort']          = (int) $r['sort'];
        $r['kind']          = Units::kind((string) $r['unit']);
        $r['category']      = $cats[$r['ingredient_id']] ?? '';

        // 用一个合法单位存着、但拆开看还得像原来那样显示
        $qty = trim((string) $r['qty_text']);

        if ($qty !== '') {
            $r['display'] = $qty;
        } elseif ($r['amount'] !== null) {
            $r['display'] = $r['kind'] === Units::COUNT
                ? Units::trimNum((float) $r['amount']) . ' ' . (string) $r['unit']
                : Units::formatTotal($r['kind'], (float) $r['amount']);
        } else {
            $r['display'] = trim((string) $r['unit']);
        }

        return $r;
    }

    private static function categoryMap(array $rows): array
    {
        $ids = array();

        foreach ($rows as $r) {
            if ((int) $r['ingredient_id'] > 0) {
                $ids[] = (int) $r['ingredient_id'];
            }
        }

        if (!$ids) {
            return array();
        }

        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $map = array();

        foreach (Db::all("SELECT id, category FROM ingredients WHERE id IN ($ph)", array_values(array_unique($ids))) as $c) {
            $map[(int) $c['id']] = (string) $c['category'];
        }

        return $map;
    }

    /** 排序：未勾选在前 → 按食材分类 → 按名字 */
    public static function cmpItem(array $a, array $b): int
    {
        $ca = (int) ($a['checked'] ?? 0);
        $cb = (int) ($b['checked'] ?? 0);

        if ($ca !== $cb) {
            return $ca <=> $cb;
        }

        $oa = array_search((string) ($a['category'] ?? ''), self::CAT_ORDER, true);
        $ob = array_search((string) ($b['category'] ?? ''), self::CAT_ORDER, true);

        $oa = $oa === false ? 99 : $oa;
        $ob = $ob === false ? 99 : $ob;

        if ($oa !== $ob) {
            return $oa <=> $ob;
        }

        return strcmp((string) $a['name'], (string) $b['name']);
    }

    private static function num($v)
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return null;
        }

        return (float) $v;
    }
}
