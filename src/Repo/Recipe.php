<?php
declare(strict_types=1);

namespace RH\Repo;

use RH\Auth;
use RH\Db;
use RH\Http;
use RH\Logs;

/**
 * 菜谱。整个系统的核心实体。
 *
 * 一个菜谱 = 主表 + 食材行 + 步骤 + 标签。另外维护一个 search_text
 * 汇总列（菜名 + 简介 + 食材名 + 步骤文字 + 标签），搜索时只扫这一列。
 * 中餐场景下 LIKE 完全够快，也免掉 SQLite FTS5 对中文分词的麻烦。
 */
final class Recipe
{
    public const DIFFICULTY = array('简单', '中等', '复杂');

    /**
     * 私有菜谱的可见性条件。
     * 管理员看得到全部；其他人只能看自己的私有菜谱。
     *
     * @return array{0:string,1:array} [SQL 片段（空串表示不过滤）, 绑定参数]
     */
    public static function visibilityClause(string $alias = 'r'): array
    {
        if (Auth::isAdmin()) {
            return array('', array());
        }

        return array(
            '(' . $alias . ".visibility <> ? OR " . $alias . '.owner_id = ?)',
            array('private', Auth::id()),
        );
    }

    /** 某个用户能不能看到这条菜谱 */
    public static function canSee(array $recipe): bool
    {
        return (string) $recipe['visibility'] !== 'private'
            || Auth::isAdmin()
            || (int) $recipe['owner_id'] === Auth::id();
    }

    // ------------------------------------------------------------------ 读

    /**
     * @param bool $withDeleted true 时连回收站里的也查出来（恢复/彻底删除要用）
     */
    public static function find(int $id, bool $withDeleted = false): ?array
    {
        $uid = Auth::id();

        $r = Db::one(
            'SELECT r.*, u.username AS owner_name, u.display_name AS owner_display,
                    (SELECT COUNT(*) FROM cook_logs c WHERE c.recipe_id = r.id) AS cook_count,
                    (SELECT COALESCE(MAX(c.created_at), 0) FROM cook_logs c WHERE c.recipe_id = r.id) AS last_cook_at,
                    EXISTS(SELECT 1 FROM favorites f WHERE f.recipe_id = r.id AND f.user_id = ?) AS is_fav
               FROM recipes r
               LEFT JOIN users u ON u.id = r.owner_id
              WHERE r.id = ?' . ($withDeleted ? '' : ' AND r.deleted_at = 0'),
            array($uid, $id)
        );

        if (!$r) {
            return null;
        }

        return self::decorate($r, true);
    }

    public static function findByName(string $name): ?array
    {
        return Db::one('SELECT * FROM recipes WHERE name = ? AND deleted_at = 0 LIMIT 1', array($name));
    }

    /**
     * 列表查询。
     * @param array $f q / tags / tagmode / cuisine / difficulty / max_time / fav / mine
     *                 ingredient / sort / page / per
     */
    public static function search(array $f): array
    {
        $uid    = Auth::id();
        $where  = array();
        $params = array();

        // 别人的私有菜谱不出现在列表里
        [$visSql, $visParams] = self::visibilityClause('r');

        if ($visSql !== '') {
            $where[] = $visSql;
            $params  = array_merge($params, $visParams);
        }

        // 回收站里的不算菜谱
        $where[] = 'r.deleted_at = 0';

        $q = trim((string) ($f['q'] ?? ''));

        if ($q !== '') {
            // 空格分开的多关键词：全都要命中（与逻辑，缩小范围）
            foreach (preg_split('/\s+/u', $q) ?: array() as $word) {
                $word = trim($word);

                if ($word === '') {
                    continue;
                }

                $where[]  = "r.search_text LIKE ? ESCAPE '\\'";
                $params[] = '%' . self::escapeLike(mb_strtolower($word)) . '%';
            }
        }

        $cuisine = trim((string) ($f['cuisine'] ?? ''));

        if ($cuisine !== '') {
            $where[]  = 'r.cuisine = ?';
            $params[] = $cuisine;
        }

        $diff = trim((string) ($f['difficulty'] ?? ''));

        if ($diff !== '') {
            $where[]  = 'r.difficulty = ?';
            $params[] = $diff;
        }

        $maxTime = (int) ($f['max_time'] ?? 0);

        if ($maxTime > 0) {
            $where[]  = '(CASE WHEN r.total_min > 0 THEN r.total_min ELSE r.prep_min + r.cook_min END) <= ?';
            $params[] = $maxTime;
        }

        $tags = $f['tags'] ?? array();

        if (is_string($tags)) {
            $tags = array_filter(array_map('trim', explode(',', $tags)));
        }

        $tags = is_array($tags) ? array_values(array_filter($tags, static function ($t) {
            return trim((string) $t) !== '';
        })) : array();

        if ($tags) {
            $ph = implode(',', array_fill(0, count($tags), '?'));

            if (($f['tagmode'] ?? 'all') === 'any') {
                $where[] = "r.id IN (SELECT rt.recipe_id FROM recipe_tags rt
                                        JOIN tags t ON t.id = rt.tag_id WHERE t.name IN ($ph))";
                $params  = array_merge($params, $tags);
            } else {
                // 全部命中：数量对得上才算
                $where[] = "r.id IN (SELECT rt.recipe_id FROM recipe_tags rt
                                        JOIN tags t ON t.id = rt.tag_id
                                       WHERE t.name IN ($ph)
                                       GROUP BY rt.recipe_id HAVING COUNT(DISTINCT t.name) = ?)";
                $params  = array_merge($params, $tags, array(count($tags)));
            }
        }

        if (!empty($f['fav'])) {
            // favorites 是复合主键表，没有 id 列，只能拿 recipe_id 判存在
            $where[]  = 'f.recipe_id IS NOT NULL';
            $params   = array_merge(array($uid), $params);
            $favJoin  = 'LEFT JOIN favorites f ON f.recipe_id = r.id AND f.user_id = ?';
        } else {
            $favJoin = 'LEFT JOIN favorites f ON f.recipe_id = r.id AND f.user_id = -1';
        }

        if (!empty($f['mine'])) {
            $where[]  = 'r.owner_id = ?';
            $params[] = $uid;
        }

        $ing = trim((string) ($f['ingredient'] ?? ''));

        if ($ing !== '') {
            $where[]  = "r.id IN (SELECT recipe_id FROM recipe_ingredients WHERE name LIKE ? ESCAPE '\\')";
            $params[] = '%' . self::escapeLike($ing) . '%';
        }

        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $sort = (string) ($f['sort'] ?? 'updated');
        $orderBy = 'r.updated_at DESC';

        switch ($sort) {
            case 'created':
                $orderBy = 'r.created_at DESC';
                break;
            case 'name':
                $orderBy = 'r.name ASC';
                break;
            case 'time_asc':
                $orderBy = '(CASE WHEN r.total_min > 0 THEN r.total_min ELSE r.prep_min + r.cook_min END) ASC';
                break;
            case 'time_desc':
                $orderBy = '(CASE WHEN r.total_min > 0 THEN r.total_min ELSE r.prep_min + r.cook_min END) DESC';
                break;
            case 'oldest':
                $orderBy = 'r.updated_at ASC';
                break;
        }

        $per  = max(1, min(100, (int) ($f['per'] ?? 24)));
        $page = max(1, (int) ($f['page'] ?? 1));

        $total = (int) Db::value(
            'SELECT COUNT(*) FROM recipes r ' . $favJoin . $whereSql,
            $params
        );

        $pages = max(1, (int) ceil($total / $per));
        $page  = min($page, $pages);
        $off   = ($page - 1) * $per;

        $rows = Db::all(
            'SELECT r.*,
                    EXISTS(SELECT 1 FROM favorites f2 WHERE f2.recipe_id = r.id AND f2.user_id = ' . (int) $uid . ') AS is_fav,
                    (SELECT COUNT(*) FROM recipe_ingredients ri WHERE ri.recipe_id = r.id) AS ing_count,
                    (SELECT COUNT(*) FROM recipe_steps rs WHERE rs.recipe_id = r.id) AS step_count
               FROM recipes r ' . $favJoin . $whereSql
            . ' ORDER BY ' . $orderBy . ' LIMIT ' . $per . ' OFFSET ' . $off,
            $params
        );

        $rows = self::attachTags($rows);

        foreach ($rows as &$r) {
            self::shape($r, false);
        }

        unset($r);

        return array(
            'items' => $rows,
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
            'per'   => $per,
        );
    }

    /** 随机挑一道当前用户可见的菜谱，避开 $excludeIds（一键换菜用） */
    public static function randomExcept(array $excludeIds = array()): ?array
    {
        $ex = array();

        foreach ($excludeIds as $id) {
            $id = (int) $id;

            if ($id > 0) {
                $ex[$id] = $id;
            }
        }

        [$visSql, $visParams] = self::visibilityClause('r');

        $where  = array('r.deleted_at = 0');
        $params = $visParams;

        if ($visSql !== '') {
            $where[] = $visSql;
        }

        $ex = array_values($ex);

        if ($ex) {
            $where[] = 'r.id NOT IN (' . implode(',', array_fill(0, count($ex), '?')) . ')';
            $params  = array_merge($params, $ex);
        }

        $row = Db::one(
            'SELECT r.* FROM recipes r'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY RANDOM() LIMIT 1',
            $params
        );

        return $row ?: null;
    }

    /**
     * 「今天吃什么」：按条件随机抽一道，返回 id（抽不到返回 null）。
     *
     * @param array $f cuisine / difficulty / max_time(int) / tags(string|array) /
     *                 pantry(bool 只选手头食材齐的) / exclude(array 排除的 id)
     */
    public static function randomPick(array $f): ?int
    {
        [$visSql, $visParams] = self::visibilityClause('r');

        $where  = array('r.deleted_at = 0');
        $params = array();

        if ($visSql !== '') {
            $where[]  = $visSql;
            $params   = array_merge($params, $visParams);
        }

        $cuisine = trim((string) ($f['cuisine'] ?? ''));

        if ($cuisine !== '') {
            $where[]  = 'r.cuisine = ?';
            $params[] = $cuisine;
        }

        $diff = trim((string) ($f['difficulty'] ?? ''));

        if ($diff !== '') {
            $where[]  = 'r.difficulty = ?';
            $params[] = $diff;
        }

        $maxTime = (int) ($f['max_time'] ?? 0);

        if ($maxTime > 0) {
            $where[]  = '(CASE WHEN r.total_min > 0 THEN r.total_min ELSE r.prep_min + r.cook_min END) <= ?';
            $params[] = $maxTime;
        }

        $tags = $f['tags'] ?? array();

        if (is_string($tags)) {
            $tags = array_filter(array_map('trim', explode(',', $tags)));
        }

        $tags = is_array($tags) ? array_values(array_filter($tags, static function ($t) {
            return trim((string) $t) !== '';
        })) : array();

        if ($tags) {
            $ph       = implode(',', array_fill(0, count($tags), '?'));
            $where[]  = "r.id IN (SELECT rt.recipe_id FROM recipe_tags rt
                                    JOIN tags t ON t.id = rt.tag_id WHERE t.name IN ($ph))";
            $params   = array_merge($params, $tags);
        }

        // 「手头有料」：必需用料在库存里都有才算。可选料不算门槛。
        if (!empty($f['fav'])) {
            $where[]  = 'EXISTS (SELECT 1 FROM favorites f WHERE f.recipe_id = r.id AND f.user_id = ?)';
            $params[] = Auth::id();
        }

        if (!empty($f['mine'])) {
            $where[]  = 'r.owner_id = ?';
            $params[] = Auth::id();
        }

        if (!empty($f['pantry'])) {
            $where[] = "NOT EXISTS (
                SELECT 1 FROM recipe_ingredients ri
                 WHERE ri.recipe_id = r.id
                   AND ri.optional = 0
                   AND NOT EXISTS (
                       SELECT 1 FROM pantry_items p
                        WHERE p.ingredient_id = ri.ingredient_id OR LOWER(p.name) = LOWER(ri.name)
                   )
            )";
        }

        $ex = array();

        foreach ((array) ($f['exclude'] ?? array()) as $id) {
            $id = (int) $id;

            if ($id > 0) {
                $ex[$id] = $id;
            }
        }

        if ($ex) {
            $where[] = 'r.id NOT IN (' . implode(',', array_fill(0, count($ex), '?')) . ')';
            $params  = array_merge($params, array_values($ex));
        }

        // 至少要有一行用料，否则会抽到还没填完的空壳菜谱
        $row = Db::one(
            'SELECT r.id FROM recipes r
              WHERE ' . implode(' AND ', $where) . '
                AND EXISTS (SELECT 1 FROM recipe_ingredients ri WHERE ri.recipe_id = r.id)
              ORDER BY RANDOM() LIMIT 1',
            $params
        );

        return $row ? (int) $row['id'] : null;
    }

    // ------------------------------------------------------------------ 留言

    public static function comments(int $recipeId): array
    {
        return Db::all(
            'SELECT c.id, c.text, c.created_at, c.user_id,
                    COALESCE(NULLIF(u.display_name, \'\'), u.username, \'\') AS user_name
               FROM recipe_comments c
               LEFT JOIN users u ON u.id = c.user_id
              WHERE c.recipe_id = ?
              ORDER BY c.created_at ASC, c.id ASC',
            array($recipeId)
        );
    }

    /** @return array 新增的留言行 */
    public static function addComment(int $recipeId, string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            Http::fail('留言不能是空的', 400, 'text');
        }

        if (mb_strlen($text) > 500) {
            Http::fail('留言最多 500 字', 400, 'text');
        }

        $id = Db::insert('recipe_comments', array(
            'recipe_id'  => $recipeId,
            'user_id'    => Auth::id(),
            'text'       => $text,
            'created_at' => time(),
        ));

        Logs::add('recipe_comment', '', '#' . $recipeId);

        $me = Auth::user() ?: array();

        return array(
            'id'         => $id,
            'text'       => $text,
            'created_at' => time(),
            'user_id'    => Auth::id(),
            'user_name'  => (string) ($me['display_name'] ?? $me['username'] ?? ''),
        );
    }

    /** 只能删自己的留言，管理员可以删任何人的 */
    public static function removeComment(int $id): void
    {
        $row = Db::find('recipe_comments', $id);

        if (!$row) {
            Http::fail('留言不存在', 404);
        }

        if (!Auth::isAdmin() && (int) $row['user_id'] !== Auth::id()) {
            Http::fail('只能删自己的留言', 403);
        }

        Db::delete('recipe_comments', $id);
    }

    // ------------------------------------------------------------------ 下厨记录

    /** 这道菜被做过几次、上次什么时候做的 */
    public static function cookSummary(int $recipeId): array
    {
        $row = Db::one(
            'SELECT COUNT(*) AS cook_count, COALESCE(MAX(created_at), 0) AS last_cook_at
               FROM cook_logs WHERE recipe_id = ?',
            array($recipeId)
        ) ?: array();

        return array(
            'cook_count'   => (int) ($row['cook_count'] ?? 0),
            'last_cook_at' => (int) ($row['last_cook_at'] ?? 0),
        );
    }

    public static function cookLogs(int $recipeId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        return Db::all(
            'SELECT c.id, c.created_at, c.servings, c.note, c.user_id,
                    COALESCE(NULLIF(u.display_name, \'\'), u.username, \'\') AS user_name
               FROM cook_logs c
               LEFT JOIN users u ON u.id = c.user_id
              WHERE c.recipe_id = ?
              ORDER BY c.created_at DESC, c.id DESC
              LIMIT ' . $limit,
            array($recipeId)
        );
    }

    /** 记一次「我做过这道菜了」 */
    public static function addCookLog(int $recipeId, array $data = array()): int
    {
        $id = Db::insert('cook_logs', array(
            'recipe_id'  => $recipeId,
            'user_id'    => Auth::id(),
            'plan_id'    => (int) ($data['plan_id'] ?? 0),
            'servings'   => (float) ($data['servings'] ?? 0),
            'note'       => mb_substr(trim((string) ($data['note'] ?? '')), 0, 200),
            'created_at' => time(),
        ));

        Logs::add('recipe_cook', '', '#' . $recipeId);

        return $id;
    }

    public static function removeCookLog(int $id): void
    {
        $row = Db::find('cook_logs', $id);

        if (!$row) {
            Http::fail('记录不存在', 404);
        }

        if (!Auth::isAdmin() && (int) $row['user_id'] !== Auth::id()) {
            Http::fail('只能删自己的记录', 403);
        }

        Db::delete('cook_logs', $id);
    }

    // ------------------------------------------------------------------ 回收站

    /**
     * 回收站列表。管理员看所有人的，普通成员只看自己删的。
     * @return array{items:array,total:int}
     */
    public static function trash(): array
    {
        $params = array();
        $owner  = '';

        if (!Auth::isAdmin()) {
            $owner    = ' AND r.owner_id = ?';
            $params[] = Auth::id();
        }

        $rows = Db::all(
            'SELECT r.id, r.name, r.cover, r.cuisine, r.deleted_at, r.owner_id,
                    COALESCE(NULLIF(u.display_name, \'\'), u.username, \'\') AS owner_name
               FROM recipes r
               LEFT JOIN users u ON u.id = r.owner_id
              WHERE r.deleted_at > 0' . $owner . '
              ORDER BY r.deleted_at DESC',
            $params
        );

        foreach ($rows as &$r) {
            $r['id']         = (int) $r['id'];
            $r['deleted_at'] = (int) $r['deleted_at'];
            $r['cover_url']  = (string) $r['cover'] === '' ? '' : \RH\Images::thumbUrl((string) $r['cover']);
        }

        unset($r);

        return array('items' => $rows, 'total' => count($rows));
    }

    public static function restore(int $id): void
    {
        $r = Db::find('recipes', $id);

        if (!$r || (int) $r['deleted_at'] === 0) {
            Http::fail('这道菜不在回收站里', 404);
        }

        Db::updateRow('recipes', $id, array('deleted_at' => 0));
        Logs::add('recipe_restore', (string) $r['name'], '#' . $id);
    }

    /** 彻底删除：行 + 封面图 + 步骤图，不可恢复 */
    public static function purge(int $id): void
    {
        $r = Db::find('recipes', $id);

        if (!$r) {
            Http::fail('菜谱不存在', 404);
        }

        if ((string) $r['cover'] !== '') {
            \RH\Images::remove((string) $r['cover']);
        }

        foreach (Db::all('SELECT image FROM recipe_steps WHERE recipe_id = ? AND image <> ?', array($id, '')) as $s) {
            \RH\Images::remove((string) $s['image']);
        }

        Db::delete('recipes', $id);
        Logs::add('recipe_purge', (string) $r['name'], '#' . $id);
    }

    // ------------------------------------------------------------------ 写

    public static function create(array $data): int
    {
        $d = self::normalize($data);

        if ($d['name'] === '') {
            Http::fail('菜名不能为空', 400, 'name');
        }

        return Db::tx(static function () use ($d, $data): int {
            $now = time();

            $id = Db::insert('recipes', array(
                'owner_id'      => (int) ($d['owner_id'] ?? Auth::id()),
                'name'          => $d['name'],
                'description'   => $d['description'],
                'cover'         => $d['cover'],
                'servings'      => $d['servings'],
                'servings_text' => $d['servings_text'],
                'prep_min'      => $d['prep_min'],
                'cook_min'      => $d['cook_min'],
                'total_min'     => $d['total_min'],
                'difficulty'    => $d['difficulty'],
                'cuisine'       => $d['cuisine'],
                'tips'          => $d['tips'],
                'taboo'         => $d['taboo'],
                'storage'       => $d['storage'],
                'leftover'      => $d['leftover'],
                'nutrition'     => $d['nutrition'],
                'visibility'    => $d['visibility'],
                'source'        => $d['source'],
                'search_text'   => '',
                'created_at'    => $now,
                'updated_at'    => $now,
            ));

            self::writeIngredients($id, $d['ingredients']);
            self::writeSteps($id, $d['steps']);
            self::syncTags($id, $d['tags']);
            self::refreshSearchText($id);

            Logs::add('recipe_create', $d['name'], '#' . $id);

            return $id;
        });
    }

    /**
     * 局部更新。
     *
     * 食材 / 步骤 / 标签是「全删重建」式的子表，所以必须区分
     * 「字段没传」和「字段传了空数组」——不然后者还好，
     * 前者会让一次只改封面的请求把所有食材和步骤清光。
     */
    public static function update(int $id, array $data): void
    {
        $exists = Db::find('recipes', $id);

        if (!$exists) {
            Http::fail('菜谱不存在', 404);
        }

        $d = self::normalize($data, $exists);

        if ($d['name'] === '') {
            Http::fail('菜名不能为空', 400, 'name');
        }

        $touchIngredients = array_key_exists('ingredients', $data);
        $touchSteps       = array_key_exists('steps', $data);
        $touchTags        = array_key_exists('tags', $data);

        Db::tx(static function () use ($id, $d, $touchIngredients, $touchSteps, $touchTags): void {
            Db::updateRow('recipes', $id, array(
                'name'          => $d['name'],
                'description'   => $d['description'],
                'cover'         => $d['cover'],
                'servings'      => $d['servings'],
                'servings_text' => $d['servings_text'],
                'prep_min'      => $d['prep_min'],
                'cook_min'      => $d['cook_min'],
                'total_min'     => $d['total_min'],
                'difficulty'    => $d['difficulty'],
                'cuisine'       => $d['cuisine'],
                'tips'          => $d['tips'],
                'taboo'         => $d['taboo'],
                'storage'       => $d['storage'],
                'leftover'      => $d['leftover'],
                'nutrition'     => $d['nutrition'],
                'visibility'    => $d['visibility'],
                'updated_at'    => time(),
            ));

            if ($touchIngredients) {
                self::writeIngredients($id, $d['ingredients']);
            }

            if ($touchSteps) {
                self::writeSteps($id, $d['steps']);
            }

            if ($touchTags) {
                self::syncTags($id, $d['tags']);
            }

            self::refreshSearchText($id);

            Logs::add('recipe_update', $d['name'], '#' . $id);
        });
    }

    /**
     * 删除 = 扔进回收站。菜谱行还在，配料/步骤/标签也还在，随时能恢复；
     * 图片不动（万一恢复了图就没了）。真正抹掉走 purge()。
     */
    public static function remove(int $id): void
    {
        $r = Db::find('recipes', $id);

        if (!$r || (int) $r['deleted_at'] > 0) {
            Http::fail('菜谱不存在', 404);
        }

        Db::updateRow('recipes', $id, array('deleted_at' => time()));
        Logs::add('recipe_trash', (string) $r['name'], '#' . $id);
    }

    /** 基于现有菜谱复制一份，方便微调配方 */
    public static function duplicate(int $id): int
    {
        $src = self::find($id);

        if (!$src) {
            Http::fail('菜谱不存在', 404);
        }

        $data = array(
            'name'          => (string) $src['name'] . '（副本）',
            'description'   => (string) $src['description'],
            'cover'         => (string) $src['cover'],
            'servings'      => $src['servings'],
            'servings_text' => (string) $src['servings_text'],
            'prep_min'      => $src['prep_min'],
            'cook_min'      => $src['cook_min'],
            'total_min'     => $src['total_min'],
            'difficulty'    => (string) $src['difficulty'],
            'cuisine'       => (string) $src['cuisine'],
            'tips'          => (string) $src['tips'],
            'taboo'         => (string) $src['taboo'],
            'storage'       => (string) $src['storage'],
            'leftover'      => (string) $src['leftover'],
            'nutrition'     => $src['nutrition_raw'] ?? array(),
            'visibility'    => (string) $src['visibility'],
            'tags'          => array_column($src['tags'], 'name'),
            'ingredients'   => array_map(static function (array $i): array {
                return array(
                    'name'          => $i['name'],
                    'ingredient_id' => $i['ingredient_id'],
                    'amount'        => $i['amount'],
                    'unit'          => $i['unit'],
                    'qty_text'      => $i['qty_text'],
                    'note'          => $i['note'],
                    'optional'      => $i['optional'],
                );
            }, $src['ingredients']),
            'steps'         => array_map(static function (array $s): array {
                return array(
                    'text'         => $s['text'],
                    'image'        => $s['image'],
                    'duration_min' => $s['duration_min'],
                );
            }, $src['steps']),
        );

        return self::create($data);
    }

    public static function toggleFavorite(int $userId, int $recipeId): bool
    {
        if (!Db::find('recipes', $recipeId)) {
            Http::fail('菜谱不存在', 404);
        }

        $has = Db::one('SELECT 1 AS x FROM favorites WHERE user_id = ? AND recipe_id = ?', array($userId, $recipeId));

        if ($has) {
            Db::exec('DELETE FROM favorites WHERE user_id = ? AND recipe_id = ?', array($userId, $recipeId));

            return false;
        }

        Db::insert('favorites', array(
            'user_id'    => $userId,
            'recipe_id'  => $recipeId,
            'created_at' => time(),
        ));

        return true;
    }

    // ------------------------------------------------------------------ 导入 / 导出

    /** 导出为可再导入的 JSON 结构 */
    public static function export(array $ids = array(), bool $all = false): array
    {
        [$visSql, $visParams] = self::visibilityClause('recipes');

        if ($all || !$ids) {
            // 回收站里的不导出：它不是「我的菜谱」，是要丢掉的东西
            $ids = array_map(static function (array $r): int {
                return (int) $r['id'];
            }, Db::all(
                'SELECT id FROM recipes WHERE deleted_at = 0'
                . ($visSql !== '' ? ' AND ' . $visSql : '') . ' ORDER BY id ASC',
                $visParams
            ));
        }

        $recipes = array();

        foreach ($ids as $id) {
            $r = self::find((int) $id);

            if (!$r) {
                continue;
            }

            // 手动指定 id 也不能把别人的私有菜谱导出去
            if (!self::canSee($r)) {
                continue;
            }

            $recipes[] = array(
                'name'          => $r['name'],
                'description'   => $r['description'],
                'cover'         => $r['cover'],
                'servings'      => $r['servings'],
                'servings_text' => $r['servings_text'],
                'prep_min'      => $r['prep_min'],
                'cook_min'      => $r['cook_min'],
                'total_min'     => $r['total_min'],
                'difficulty'    => $r['difficulty'],
                'cuisine'       => $r['cuisine'],
                'tips'          => $r['tips'],
                'taboo'         => $r['taboo'],
                'storage'       => $r['storage'],
                'leftover'      => $r['leftover'],
                'nutrition'     => $r['nutrition_raw'] ?? array(),
                'visibility'    => $r['visibility'],
                'tags'          => array_column($r['tags'], 'name'),
                'ingredients'   => array_map(static function (array $i): array {
                    return array(
                        'name'     => $i['name'],
                        'amount'   => $i['amount'],
                        'unit'     => $i['unit'],
                        'qty_text' => $i['qty_text'],
                        'note'     => $i['note'],
                        'optional' => $i['optional'],
                    );
                }, $r['ingredients']),
                'steps'         => array_map(static function (array $s): array {
                    return array(
                        'text'         => $s['text'],
                        'image'        => $s['image'],
                        'duration_min' => $s['duration_min'],
                    );
                }, $r['steps']),
            );
        }

        return array(
            'app'         => 'recipe-hub',
            'format'      => 'recipe-hub/v1',
            'exported_at' => date('c'),
            'count'       => count($recipes),
            'recipes'     => $recipes,
        );
    }

    /**
     * 导入。除了自家的 JSON，也尽量吃下 Mealie / 通用 schema.org 风格的字段。
     * @return array{created:int,skipped:int,errors:array}
     */
    public static function import(array $payload, int $ownerId, bool $overwrite = false): array
    {
        $list = $payload;

        if (isset($payload['recipes']) && is_array($payload['recipes'])) {
            $list = $payload['recipes'];
        } elseif (isset($payload['items']) && is_array($payload['items'])) {
            $list = $payload['items'];
        }

        if (!is_array($list)) {
            Http::fail('看不懂这个 JSON 结构', 400);
        }

        $created = 0;
        $skipped = 0;
        $errors  = array();

        foreach ($list as $i => $raw) {
            if (!is_array($raw)) {
                continue;
            }

            try {
                $data = self::adaptForeign($raw);

                if ($data['name'] === '') {
                    $errors[] = '第 ' . ($i + 1) . ' 条没有菜名，跳过';
                    continue;
                }

                $dup = self::findByName($data['name']);

                if ($dup && !$overwrite) {
                    $skipped++;
                    continue;
                }

                if ($dup) {
                    unset($data['owner_id']);
                    self::update((int) $dup['id'], $data);
                    $created++;
                    continue;
                }

                $data['owner_id'] = $ownerId;
                self::create($data);
                $created++;
            } catch (\Throwable $e) {
                $errors[] = '第 ' . ($i + 1) . ' 条失败：' . $e->getMessage();
            }
        }

        Logs::add('recipe_import', '共 ' . count($list) . ' 条', '新增/更新 ' . $created . '，重复跳过 ' . $skipped);

        return array('created' => $created, 'skipped' => $skipped, 'errors' => array_slice($errors, 0, 20));
    }

    // ------------------------------------------------------------------ 统计

    public static function stats(): array
    {
        return array(
            // 回收站里的不计数：删掉的东西不该让数字变好看
            'recipes'     => (int) Db::value('SELECT COUNT(*) FROM recipes WHERE deleted_at = 0'),
            'ingredients' => (int) Db::value('SELECT COUNT(*) FROM ingredients'),
            'pantry'      => (int) Db::value('SELECT COUNT(*) FROM pantry_items'),
            'plans'       => (int) Db::value('SELECT COUNT(*) FROM meal_plans WHERE plan_date >= ?', array(date('Y-m-d'))),
            'favorites'   => (int) Db::value('SELECT COUNT(*) FROM favorites WHERE user_id = ?', array(Auth::id())),
            'cuisines'    => (int) Db::value("SELECT COUNT(DISTINCT cuisine) FROM recipes WHERE cuisine <> '' AND deleted_at = 0"),
            'updated_7d'  => (int) Db::value('SELECT COUNT(*) FROM recipes WHERE updated_at > ? AND deleted_at = 0', array(time() - 7 * 86400)),
        );
    }

    /** 全部标签 + 使用次数，给筛选器和编辑器的标签选择用 */
    public static function allTags(): array
    {
        $rows = Db::all(
            'SELECT t.id, t.name, t.kind, COUNT(rt.recipe_id) AS count
               FROM tags t
               LEFT JOIN recipe_tags rt ON rt.tag_id = t.id
              GROUP BY t.id
              ORDER BY t.kind ASC, count DESC, t.sort ASC, t.name ASC'
        );

        foreach ($rows as &$r) {
            $r['id']    = (int) $r['id'];
            $r['count'] = (int) $r['count'];
        }

        unset($r);

        return $rows;
    }

    public static function allCuisines(): array
    {
        $used = array();

        foreach (Db::all("SELECT cuisine, COUNT(*) c FROM recipes WHERE cuisine <> '' AND deleted_at = 0 GROUP BY cuisine") as $r) {
            $used[(string) $r['cuisine']] = (int) $r['c'];
        }

        $preset = Db::all("SELECT name FROM tags WHERE kind = 'cuisine' ORDER BY sort ASC");
        $out    = array();

        foreach ($preset as $p) {
            $n = (string) $p['name'];
            $out[$n] = $used[$n] ?? 0;
            unset($used[$n]);
        }

        foreach ($used as $n => $c) {
            $out[(string) $n] = $c;
        }

        $ret = array();

        foreach ($out as $name => $count) {
            $ret[] = array('name' => $name, 'count' => $count);
        }

        return $ret;
    }

    // ------------------------------------------------------------------ 内部

    /** 写食材行：全删重建，最简单也最不容易出错 */
    private static function writeIngredients(int $recipeId, array $items): void
    {
        Db::exec('DELETE FROM recipe_ingredients WHERE recipe_id = ?', array($recipeId));

        $sort = 0;

        foreach ($items as $it) {
            $name = trim((string) ($it['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $unit   = \RH\Units::norm((string) ($it['unit'] ?? ''));
            $amount = $it['amount'] ?? null;

            if ($amount === '' || $amount === null || !is_numeric($amount)) {
                $amount = null;
            } else {
                $amount = (float) $amount;
            }

            // 数量 + 单位两个都没给，又没写自由文本，那这行没意义
            $qtyText = trim((string) ($it['qty_text'] ?? ''));

            if ($amount === null && $unit === '' && $qtyText === '') {
                $unit = '适量';
            }

            // 食材库里没有这个名字就顺手登记一条：
            // 采购清单的分类归组、同物异名合并、库存推荐都依赖 ingredient_id。
            $ingredientId = Ingredient::resolve($name, (int) ($it['ingredient_id'] ?? 0));

            if ($ingredientId === 0 && (int) \RH\Config::get('autoIngredient', 1) === 1) {
                $ingredientId = Ingredient::ensure($name);
            }

            Db::insert('recipe_ingredients', array(
                'recipe_id'     => $recipeId,
                'ingredient_id' => $ingredientId,
                'name'          => mb_substr($name, 0, 40),
                'amount'        => $amount,
                'unit'          => mb_substr($unit, 0, 8),
                'qty_text'      => mb_substr($qtyText, 0, 32),
                'note'          => mb_substr(trim((string) ($it['note'] ?? '')), 0, 80),
                'optional'      => !empty($it['optional']) ? 1 : 0,
                'sort'          => $sort++,
            ));
        }

        Ingredient::refreshUsage();
    }

    private static function writeSteps(int $recipeId, array $steps): void
    {
        Db::exec('DELETE FROM recipe_steps WHERE recipe_id = ?', array($recipeId));

        $sort = 0;

        foreach ($steps as $s) {
            $text = trim((string) ($s['text'] ?? ''));
            $img  = (string) ($s['image'] ?? '');

            if ($text === '' && $img === '') {
                continue;
            }

            Db::insert('recipe_steps', array(
                'recipe_id'    => $recipeId,
                'sort'         => $sort++,
                'text'         => $text,
                'image'        => $img,
                'duration_min' => max(0, (int) ($s['duration_min'] ?? 0)),
            ));
        }
    }

    /** 标签按名字来，不存在就自动建 */
    private static function syncTags(int $recipeId, array $names): void
    {
        Db::exec('DELETE FROM recipe_tags WHERE recipe_id = ?', array($recipeId));

        foreach (array_unique($names) as $name) {
            $name = trim((string) $name);

            if ($name === '' || mb_strlen($name) > 16) {
                continue;
            }

            $tid = Db::value('SELECT id FROM tags WHERE name = ?', array($name));

            if (!$tid) {
                $tid = Db::insert('tags', array('name' => $name, 'kind' => 'tag', 'sort' => 999));
            }

            Db::exec('INSERT OR IGNORE INTO recipe_tags (recipe_id, tag_id) VALUES (?, ?)', array($recipeId, (int) $tid));
        }
    }

    /** 重建汇总搜索列 */
    public static function refreshSearchText(int $id): void
    {
        $r = Db::find('recipes', $id);

        if (!$r) {
            return;
        }

        $parts = array((string) $r['name'], (string) $r['description'], (string) $r['cuisine']);

        foreach (Db::all('SELECT name, note, qty_text FROM recipe_ingredients WHERE recipe_id = ?', array($id)) as $i) {
            $parts[] = (string) $i['name'];
            $parts[] = (string) $i['note'];
            $parts[] = (string) $i['qty_text'];
        }

        foreach (Db::all('SELECT text FROM recipe_steps WHERE recipe_id = ?', array($id)) as $s) {
            $parts[] = (string) $s['text'];
        }

        foreach (Db::all('SELECT t.name FROM recipe_tags rt JOIN tags t ON t.id = rt.tag_id WHERE rt.recipe_id = ?', array($id)) as $t) {
            $parts[] = (string) $t['name'];
        }

        $parts = array_filter($parts, static function ($p): bool {
            return trim((string) $p) !== '';
        });

        Db::updateRow('recipes', $id, array(
            'search_text' => mb_strtolower(mb_substr(implode(' ', $parts), 0, 8000)),
        ));
    }

    /** 统一入口：把前端/导入的数据整理成内部结构 */
    private static function normalize(array $data, ?array $existing = null): array
    {
        $name = trim((string) ($data['name'] ?? $existing['name'] ?? ''));

        $nutrition = $data['nutrition'] ?? null;

        if ($nutrition === null && $existing) {
            $nutrition = (string) $existing['nutrition'];
        }

        if (is_array($nutrition)) {
            $clean = array();

            foreach ($nutrition as $k => $v) {
                if ($v === '' || $v === null || !is_numeric($v)) {
                    continue;
                }

                $clean[(string) $k] = (float) $v;
            }

            $nutrition = $clean ? json_encode($clean, JSON_UNESCAPED_UNICODE) : '';
        }

        $prep = max(0, (int) ($data['prep_min'] ?? $existing['prep_min'] ?? 0));
        $cook = max(0, (int) ($data['cook_min'] ?? $existing['cook_min'] ?? 0));

        $total = $data['total_min'] ?? null;

        if ($total === null || $total === '' || (int) $total <= 0) {
            $total = $prep + $cook;
        } else {
            $total = max(0, (int) $total);
        }

        $diff = (string) ($data['difficulty'] ?? $existing['difficulty'] ?? '简单');

        if (!in_array($diff, self::DIFFICULTY, true)) {
            $diff = '简单';
        }

        $vis = (string) ($data['visibility'] ?? $existing['visibility'] ?? 'public');

        if (!in_array($vis, array('public', 'private'), true)) {
            $vis = 'public';
        }

        $ings = $data['ingredients'] ?? array();
        $steps = $data['steps'] ?? array();
        $tags = $data['tags'] ?? array();

        if (is_string($tags)) {
            $tags = array_filter(array_map('trim', preg_split('/[,，]+/u', $tags) ?: array()));
        }

        return array(
            'owner_id'      => $data['owner_id'] ?? ($existing['owner_id'] ?? Auth::id()),
            'name'          => mb_substr($name, 0, 80),
            'description'   => mb_substr(trim((string) ($data['description'] ?? $existing['description'] ?? '')), 0, 1000),
            'cover'         => \RH\Images::safeRel((string) ($data['cover'] ?? $existing['cover'] ?? '')),
            'servings'      => max(0.0, (float) ($data['servings'] ?? $existing['servings'] ?? 0)),
            'servings_text' => mb_substr(trim((string) ($data['servings_text'] ?? $existing['servings_text'] ?? '')), 0, 24),
            'prep_min'      => $prep,
            'cook_min'      => $cook,
            'total_min'     => $total,
            'difficulty'    => $diff,
            'cuisine'       => mb_substr(trim((string) ($data['cuisine'] ?? $existing['cuisine'] ?? '')), 0, 16),
            'tips'          => mb_substr(trim((string) ($data['tips'] ?? $existing['tips'] ?? '')), 0, 2000),
            'taboo'         => mb_substr(trim((string) ($data['taboo'] ?? $existing['taboo'] ?? '')), 0, 600),
            'storage'       => mb_substr(trim((string) ($data['storage'] ?? $existing['storage'] ?? '')), 0, 600),
            'leftover'      => mb_substr(trim((string) ($data['leftover'] ?? $existing['leftover'] ?? '')), 0, 600),
            'nutrition'     => is_string($nutrition) ? $nutrition : '',
            'visibility'    => $vis,
            'source'        => mb_substr(trim((string) ($data['source'] ?? $existing['source'] ?? '')), 0, 120),
            'tags'          => array_slice(array_values(array_unique(array_map(static function ($t) {
                return trim((string) $t);
            }, is_array($tags) ? $tags : array()))), 0, 12),
            'ingredients'   => is_array($ings) ? array_slice($ings, 0, 60) : array(),
            'steps'         => is_array($steps) ? array_slice($steps, 0, 60) : array(),
        );
    }

    /**
     * 兼容外部格式（Mealie / schema.org 风格）。
     *
     * Mealie 有两个世代的导出：
     *   老版本：recipeIngredient 是字符串数组（"500克五花肉"）
     *   v1 之后：recipeIngredient 是对象数组（quantity + unit{name} + food{name} + note）
     * 两种都得认，不然新版本导进来的食材名会全变成 "Array"。
     */
    private static function adaptForeign(array $raw): array
    {
        // 已经是自家格式就补一下缺省字段
        $name = trim((string) ($raw['name'] ?? $raw['title'] ?? ''));

        // Mealie 整站导出是 snake_case（recipe_ingredient / total_time …），
        // 单条导出和老版本是 camelCase。统一成 camelCase 再往下走，
        // 下面的逻辑只认一套名字，不用到处写两遍取值。
        $alias = array(
            'recipe_ingredient'   => 'recipeIngredient',
            'recipe_instructions' => 'recipeInstructions',
            'recipe_category'     => 'recipeCategory',
            'total_time'          => 'totalTime',
            'prep_time'           => 'prepTime',
            'cook_time'           => 'cookTime',
            'recipe_yield'        => 'recipeYield',
            'org_url'             => 'orgURL',
        );

        foreach ($alias as $from => $to) {
            if (isset($raw[$from]) && !isset($raw[$to])) {
                $raw[$to] = $raw[$from];
            }
        }

        $data = $raw;
        $data['name'] = $name;

        // 食材：字符串数组直接逐行解析；对象数组按 Mealie 的结构拆
        if (empty($data['ingredients']) && !empty($raw['recipeIngredient']) && is_array($raw['recipeIngredient'])) {
            $ings = array();

            foreach ($raw['recipeIngredient'] as $it) {
                if (is_array($it)) {
                    $mapped = self::mapMealieIngredient($it);

                    if ($mapped !== null) {
                        $ings[] = $mapped;
                    }

                    continue;
                }

                $ings[] = self::parseIngredientLine($it);
            }

            $data['ingredients'] = $ings;
        }

        // Mealie / schema.org：recipeInstructions 可能是字符串、字符串数组或 {text:...} 数组
        if (empty($data['steps']) && !empty($raw['recipeInstructions'])) {
            $ins = $raw['recipeInstructions'];

            if (is_string($ins)) {
                $ins = preg_split('/\r?\n+/u', $ins) ?: array();
            }

            $steps = array();

            foreach ((array) $ins as $s) {
                if (is_array($s)) {
                    // Mealie：title 是步骤标题、summary 是短标题、text 才是正文。
                    // 正文为空时退回 summary，别导出一个空步骤。
                    $txt = trim((string) ($s['text'] ?? ''));

                    if ($txt === '') {
                        $txt = trim((string) ($s['summary'] ?? $s['name'] ?? ''));
                    }

                    if ($txt === '') {
                        continue;
                    }

                    $steps[] = array('text' => $txt);
                } else {
                    $steps[] = array('text' => (string) $s);
                }
            }

            $data['steps'] = $steps;
        }

        // 标签：字符串逗号分隔、字符串数组、Mealie 的对象数组（tags / categories）都认
        //
        // 注意这里不能写成「$data['tags'] 为空才转换」：Mealie 自己的 tags 就是
        // 对象数组，非空判断会直接跳过转换，把整包 [object] 当字符串塞进库，
        // 落出来是三个字母「Array」。所以只要见到外部标签字段，就无条件接管。
        $foreign = array();

        foreach (array('tags', 'categories', 'recipeCategory') as $key) {
            $src = $raw[$key] ?? null;

            if (is_string($src)) {
                foreach (preg_split('/[,，]+/u', $src) ?: array() as $t) {
                    if (trim($t) !== '') {
                        $foreign[] = trim($t);
                    }
                }

                continue;
            }

            if (!is_array($src)) {
                continue;
            }

            foreach ($src as $t) {
                if (is_array($t)) {
                    $t = (string) ($t['name'] ?? $t['slug'] ?? $t['title'] ?? '');
                }

                if (trim((string) $t) !== '') {
                    $foreign[] = trim((string) $t);
                }
            }
        }

        // 自家格式（字符串数组）的标签要留着，跟外来的合并
        $own = array();

        if (isset($data['tags'])) {
            $ownSrc = is_string($data['tags']) ? preg_split('/[,，]+/u', $data['tags']) ?: array() : (array) $data['tags'];

            foreach ($ownSrc as $t) {
                if (!is_array($t) && trim((string) $t) !== '') {
                    $own[] = trim((string) $t);
                }
            }
        }

        $merged = array_values(array_unique(array_merge($own, $foreign)));

        if ($merged === array() && !empty($raw['keywords']) && is_string($raw['keywords'])) {
            $merged = array_values(array_filter(array_map('trim', explode(',', $raw['keywords']))));
        }

        $data['tags'] = $merged;

        if (empty($data['description'])) {
            $desc = (string) ($raw['description'] ?? $raw['summary'] ?? '');

            if ($desc === '' && !empty($raw['extras']) && is_array($raw['extras'])) {
                $desc = (string) ($raw['extras']['message'] ?? $raw['extras']['description'] ?? '');
            }

            if ($desc !== '') {
                $data['description'] = $desc;
            }
        }

        // 份量：新版本 Recipe 直接给了 recipe_servings 数字，比 recipeYield 自由文本准
        if (empty($data['servings']) && isset($raw['recipe_servings']) && is_numeric($raw['recipe_servings'])) {
            $data['servings'] = (float) $raw['recipe_servings'];
        }

        // Mealie 的 recipeYield 是「4 servings」这种自由文本（有时还会是一句菜名，取不到数字就不填）
        if (empty($data['servings']) && !empty($raw['recipeYield'])) {
            $yield = trim((string) $raw['recipeYield']);

            if (preg_match('/^(\d+(?:\.\d+)?)/u', $yield, $ym)) {
                $data['servings'] = (float) $ym[1];
            }

            $data['servings_text'] = mb_substr($yield, 0, 24);
        }

        // 笔记：Mealie 的 notes 是 [{title, text}]，搬不走的字段里最有价值的就属它了
        if (!empty($raw['notes']) && is_array($raw['notes'])) {
            $chunks = array();

            foreach ($raw['notes'] as $n) {
                if (!is_array($n)) {
                    $chunks[] = trim((string) $n);
                    continue;
                }

                $t    = trim((string) ($n['title'] ?? ''));
                $body = trim((string) ($n['text'] ?? ''));

                if ($body === '') {
                    continue;
                }

                $chunks[] = $t !== '' ? $t . "\n" . $body : $body;
            }

            $notes = trim(implode("\n\n", array_filter($chunks)));

            if ($notes !== '') {
                $data['tips'] = trim(((string) ($data['tips'] ?? '')) !== ''
                    ? trim((string) $data['tips']) . "\n\n" . $notes
                    : $notes);
            }
        }

        // 时间：Mealie 用 ISO 8601（PT1H30M），三个字段各归各位
        $timeMap = array('prepTime' => 'prep_min', 'cookTime' => 'cook_min', 'totalTime' => 'total_min');

        foreach ($timeMap as $from => $to) {
            if (!empty($raw[$from]) && empty($data[$to])) {
                $mins = self::isoDurationToMinutes((string) $raw[$from]);

                if ($mins > 0) {
                    $data[$to] = $mins;
                }
            }
        }

        // 原始出处：Mealie 记在 orgURL
        if (empty($data['source']) && !empty($raw['orgURL'])) {
            $data['source'] = (string) $raw['orgURL'];
        }

        return $data;
    }

    /**
     * Mealie v1+ 的食材对象 → 我们的食材行。
     *
     * 返回 null 表示这行该丢掉——Mealie 里「食材分组标题」（For the sauce 之类）
     * 也是一条 recipeIngredient，但它没有 food，导进来就是一行废话。
     *
     * @return array<string, mixed>|null
     */
    private static function mapMealieIngredient(array $it): ?array
    {
        // 食材名：food 是对象（v1+），也可能是纯字符串（某些导出工具）
        $food = $it['food'] ?? null;

        if (is_array($food)) {
            $food = (string) ($food['name'] ?? $food['pluralName'] ?? '');
        } elseif ($food === null) {
            $food = (string) ($it['name'] ?? '');
        }

        $food = trim((string) $food);

        // 没有食材名就退回 Mealie 自己渲染的那行文本（"1 cup flour"）
        if ($food === '') {
            $display = trim((string) ($it['display'] ?? $it['originalText'] ?? $it['note'] ?? ''));

            return $display !== '' ? self::parseIngredientLine($display) : null;
        }

        $unit = $it['unit'] ?? null;

        if (is_array($unit)) {
            $unit = (string) ($unit['name'] ?? $unit['pluralName'] ?? $unit['abbreviation'] ?? '');
        }

        $unit   = trim((string) $unit);
        $amount = $it['quantity'] ?? $it['amount'] ?? null;

        // disableAmount = 这条不显示用量（"盐适量"那种），按我们的模糊量处理
        if (!empty($it['disableAmount']) || !is_numeric($amount)) {
            $amount = null;
        } else {
            $amount = (float) $amount;
        }

        if ($amount === null) {
            $unit = '';
        }

        return array(
            'name'     => $food,
            'amount'   => $amount,
            'unit'     => $unit,
            'qty_text' => '',
            'note'     => trim((string) ($it['note'] ?? '')),
        );
    }

    /** 转成分钟。ISO 8601（PT1H30M）和 Mealie 新版那种纯分钟数字（"30"）都要认。 */
    public static function isoDurationToMinutes(string $iso): int
    {
        $iso = trim($iso);

        if (is_numeric($iso)) {
            return max(0, (int) round((float) $iso));
        }

        if (!preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?)?/i', $iso, $m)) {
            return 0;
        }

        return ((int) ($m[1] ?? 0)) * 1440 + ((int) ($m[2] ?? 0)) * 60 + ((int) ($m[3] ?? 0));
    }

    /** 「500克五花肉」「五花肉 2 斤」这类自由文本拆成结构化食材行 */
    public static function parseIngredientLine($line): array
    {
        $s = trim((string) $line);

        if ($s === '') {
            return array('name' => '', 'amount' => null, 'unit' => '', 'qty_text' => '');
        }

        // 「生抽 适量」「盐少许」——结尾是模糊量词的，先摘出来
        if (preg_match('#^(.+?)\s*(少许|适量|少量|些许|一些|若干|一点|按需|随意|随口味)$#u', $s, $m)) {
            return array(
                'name'     => trim($m[1]),
                'amount'   => null,
                'unit'     => $m[2],
                'qty_text' => '',
            );
        }

        // 单位用「已知单位表」去匹配，而不是随便截三个字：
        // 截字符的话「500克鸡胸肉」会解析成「克鸡胸」+「肉」。
        $u = \RH\Units::pattern();

        // 「500克五花肉」/「1/2 小勺盐」
        if (preg_match('#^([\d]+(?:\.\d+)?(?:/[\d]+)?)\s*(' . $u . ')\s*(.+)$#u', $s, $m)) {
            return array('name' => trim($m[3]), 'amount' => self::frac($m[1]), 'unit' => $m[2], 'qty_text' => '');
        }

        // 「500克」这种只有数量的
        if (preg_match('#^([\d]+(?:\.\d+)?(?:/[\d]+)?)\s*(' . $u . ')$#u', $s, $m)) {
            return array('name' => '', 'amount' => self::frac($m[1]), 'unit' => $m[2], 'qty_text' => '');
        }

        // 「五花肉 2 斤」
        if (preg_match('#^(.+?)\s*([\d]+(?:\.\d+)?(?:/[\d]+)?)\s*(' . $u . ')$#u', $s, $m)) {
            return array('name' => trim($m[1]), 'amount' => self::frac($m[2]), 'unit' => $m[3], 'qty_text' => '');
        }

        // 「五花肉 2」——有数量没单位
        if (preg_match('#^(.+?)\s+([\d]+(?:\.\d+)?(?:/[\d]+)?)$#u', $s, $m)) {
            return array('name' => trim($m[1]), 'amount' => self::frac($m[2]), 'unit' => '', 'qty_text' => '');
        }

        return array('name' => $s, 'amount' => null, 'unit' => '适量', 'qty_text' => '');
    }

    private static function frac(string $s)
    {
        if (strpos($s, '/') !== false) {
            $p = explode('/', $s, 2);
            $d = (float) $p[1];

            return $d > 0 ? round((float) $p[0] / $d, 3) : null;
        }

        return (float) $s;
    }

    private static function escapeLike(string $s): string
    {
        return str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $s);
    }

    /** 一次性把一批菜谱的标签查出来，避免 N+1 */
    private static function attachTags(array $rows): array
    {
        if (!$rows) {
            return $rows;
        }

        $ids = array_map(static function (array $r): int {
            return (int) $r['id'];
        }, $rows);

        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $map = array();

        foreach (Db::all(
            "SELECT rt.recipe_id, t.id, t.name, t.kind
               FROM recipe_tags rt JOIN tags t ON t.id = rt.tag_id
              WHERE rt.recipe_id IN ($ph)
              ORDER BY t.kind ASC, t.sort ASC, t.name ASC",
            $ids
        ) as $t) {
            $map[(int) $t['recipe_id']][] = array(
                'id'   => (int) $t['id'],
                'name' => (string) $t['name'],
                'kind' => (string) $t['kind'],
            );
        }

        foreach ($rows as &$r) {
            $r['tags'] = $map[(int) $r['id']] ?? array();
        }

        unset($r);

        return $rows;
    }

    private static function decorate(array $r, bool $detail): array
    {
        $r['id']         = (int) $r['id'];
        $r['owner_id']   = (int) $r['owner_id'];
        $r['prep_min']   = (int) $r['prep_min'];
        $r['cook_min']   = (int) $r['cook_min'];
        $r['total_min']  = (int) $r['total_min'];
        $r['servings']   = (float) $r['servings'];
        $r['is_fav']     = (int) ($r['is_fav'] ?? 0);
        $r['visibility'] = (string) $r['visibility'];
        $r['created_at'] = (int) $r['created_at'];
        $r['updated_at'] = (int) $r['updated_at'];

        $r['cook_count']   = (int) ($r['cook_count'] ?? 0);
        $r['last_cook_at'] = (int) ($r['last_cook_at'] ?? 0);
        $r['deleted_at']   = (int) ($r['deleted_at'] ?? 0);

        $nut = json_decode((string) $r['nutrition'], true);
        $r['nutrition_raw'] = is_array($nut) ? $nut : array();
        $r['nutrition']     = is_array($nut) ? $nut : new \stdClass();

        $tags = Db::all(
            'SELECT t.id, t.name, t.kind FROM recipe_tags rt JOIN tags t ON t.id = rt.tag_id
              WHERE rt.recipe_id = ? ORDER BY t.kind ASC, t.sort ASC, t.name ASC',
            array($r['id'])
        );

        foreach ($tags as &$t) {
            $t['id'] = (int) $t['id'];
        }

        unset($t);

        $r['tags'] = $tags;

        if (!$detail) {
            unset($r['search_text']);

            return $r;
        }

        $ings = Db::all('SELECT * FROM recipe_ingredients WHERE recipe_id = ? ORDER BY sort ASC, id ASC', array($r['id']));

        foreach ($ings as &$i) {
            $i['id']            = (int) $i['id'];
            $i['recipe_id']     = (int) $i['recipe_id'];
            $i['ingredient_id'] = (int) $i['ingredient_id'];
            $i['amount']        = $i['amount'] === null ? null : (float) $i['amount'];
            $i['optional']      = (int) $i['optional'];
            $i['sort']          = (int) $i['sort'];
            $i['display']       = self::qtyDisplay($i);
            $i['kind']          = \RH\Units::kind((string) $i['unit']);
        }

        unset($i);

        $steps = Db::all('SELECT * FROM recipe_steps WHERE recipe_id = ? ORDER BY sort ASC, id ASC', array($r['id']));

        foreach ($steps as &$s) {
            $s['id']           = (int) $s['id'];
            $s['sort']         = (int) $s['sort'];
            $s['duration_min'] = (int) $s['duration_min'];
            $s['image_url']    = \RH\Images::url((string) $s['image']);
        }

        unset($s);

        $r['ingredients'] = $ings;
        $r['steps']       = $steps;
        $r['cover_url']   = \RH\Images::url((string) $r['cover']);
        $r['cover_thumb'] = \RH\Images::thumbUrl((string) $r['cover']);

        return $r;
    }

    /** 食材行的数量展示文本 */
    public static function qtyDisplay(array $i): string
    {
        $qty = trim((string) ($i['qty_text'] ?? ''));

        if ($qty !== '') {
            return $qty;
        }

        $unit = trim((string) ($i['unit'] ?? ''));

        if ($i['amount'] === null || $i['amount'] === '') {
            return $unit !== '' ? $unit : '';
        }

        $amount = \RH\Units::trimNum((float) $i['amount']);

        return $unit === '' ? $amount : $amount . ' ' . $unit;
    }

    private static function shape(array &$r, bool $detail): void
    {
        $r['id']         = (int) $r['id'];
        $r['owner_id']   = (int) $r['owner_id'];
        $r['prep_min']   = (int) $r['prep_min'];
        $r['cook_min']   = (int) $r['cook_min'];
        $r['total_min']  = (int) $r['total_min'];
        $r['servings']   = (float) $r['servings'];
        $r['is_fav']     = (int) ($r['is_fav'] ?? 0);
        $r['ing_count']  = (int) ($r['ing_count'] ?? 0);
        $r['step_count'] = (int) ($r['step_count'] ?? 0);
        $r['created_at'] = (int) $r['created_at'];
        $r['updated_at'] = (int) $r['updated_at'];
        $r['cover_url']  = \RH\Images::url((string) $r['cover']);
        $r['cover_thumb'] = \RH\Images::thumbUrl((string) $r['cover']);

        unset($r['search_text']);
    }
}
