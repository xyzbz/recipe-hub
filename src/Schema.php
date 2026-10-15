<?php
declare(strict_types=1);

namespace RH;

/**
 * 表结构 + 初始数据。
 *
 * 迁移用 SQLite 自带的 PRAGMA user_version 记版本，不引入迁移框架。
 * 每次请求都会跑 migrate()，靠 IF NOT EXISTS 和版本号保证幂等。
 */
final class Schema
{
    public const VERSION = 3;

    public static function migrate(): void
    {
        $current = Db::version();

        if ($current >= self::VERSION) {
            return;
        }

        // 逐步升级：每一级各自提交版本号，中途崩了下次从断点继续，
        // 也不会把新库（$current = 0）漏掉某个版本的表。
        if ($current < 1) {
            Db::tx(static function (): void {
                Db::pdo()->exec(self::ddlV1());
            });

            Db::setVersion(1);
        }

        if ($current < 2) {
            Db::tx(static function (): void {
                foreach (self::upgradeV2() as $sql) {
                    Db::pdo()->exec($sql);
                }
            });

            Db::setVersion(2);
        }

        if ($current < 3) {
            Db::tx(static function (): void {
                foreach (self::upgradeV3() as $sql) {
                    Db::pdo()->exec($sql);
                }
            });

            Db::setVersion(3);
        }
    }

    /**
     * v2 -> v3：膳食计划定时推送。
     *
     * notify_channels 存渠道配置（凭据放 config JSON）。
     * notify_log 除了审计，还承担**去重**职责：同一个 dedupe_key
     * （日期+餐次）+ 同一个渠道只许有一条成功记录，否则 cron 每分钟跑一次
     * 会把同一顿饭推 60 遍。
     */
    private static function upgradeV3(): array
    {
        return array(
            <<<'SQL'
CREATE TABLE IF NOT EXISTS notify_channels (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  name       TEXT    NOT NULL,
  type       TEXT    NOT NULL DEFAULT 'webhook',
  config     TEXT    NOT NULL DEFAULT '{}',
  enabled    INTEGER NOT NULL DEFAULT 1,
  created_by INTEGER NOT NULL DEFAULT 0,
  created_at INTEGER NOT NULL DEFAULT 0,
  updated_at INTEGER NOT NULL DEFAULT 0
);
SQL,
            'CREATE INDEX IF NOT EXISTS idx_nc_enabled ON notify_channels (enabled)',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS notify_log (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  channel_id INTEGER NOT NULL DEFAULT 0,
  dedupe_key TEXT    NOT NULL DEFAULT '',
  slot       TEXT    NOT NULL DEFAULT '',
  plan_date  TEXT    NOT NULL DEFAULT '',
  title      TEXT    NOT NULL DEFAULT '',
  body       TEXT    NOT NULL DEFAULT '',
  status     TEXT    NOT NULL DEFAULT 'fail',
  error      TEXT    NOT NULL DEFAULT '',
  created_at INTEGER NOT NULL DEFAULT 0
);
SQL,
            'CREATE INDEX IF NOT EXISTS idx_nl_dedupe  ON notify_log (dedupe_key, channel_id)',
            'CREATE INDEX IF NOT EXISTS idx_nl_created ON notify_log (created_at DESC)',
        );
    }

    /**
     * v1 -> v2：回收站、菜谱留言、下厨记录。
     *
     * SQLite 的 ALTER TABLE 没有 IF NOT EXISTS，加列前先问 PRAGMA，
     * 否则重复跑一次迁移就会炸。
     */
    private static function upgradeV2(): array
    {
        $sql = array();

        $hasDeleted = false;

        foreach (Db::all('PRAGMA table_info(recipes)') as $col) {
            if ((string) $col['name'] === 'deleted_at') {
                $hasDeleted = true;
                break;
            }
        }

        if (!$hasDeleted) {
            $sql[] = 'ALTER TABLE recipes ADD COLUMN deleted_at INTEGER NOT NULL DEFAULT 0';
        }

        $sql[] = <<<'SQL'
CREATE TABLE IF NOT EXISTS recipe_comments (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  recipe_id  INTEGER NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
  user_id    INTEGER NOT NULL DEFAULT 0,
  text       TEXT    NOT NULL DEFAULT '',
  created_at INTEGER NOT NULL DEFAULT 0
);
SQL;

        $sql[] = 'CREATE INDEX IF NOT EXISTS idx_rc_recipe ON recipe_comments (recipe_id, created_at)';

        $sql[] = <<<'SQL'
CREATE TABLE IF NOT EXISTS cook_logs (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  recipe_id  INTEGER NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
  user_id    INTEGER NOT NULL DEFAULT 0,
  plan_id    INTEGER NOT NULL DEFAULT 0,
  servings   REAL    NOT NULL DEFAULT 0,
  note       TEXT    NOT NULL DEFAULT '',
  created_at INTEGER NOT NULL DEFAULT 0
);
SQL;

        $sql[] = 'CREATE INDEX IF NOT EXISTS idx_cl_recipe ON cook_logs (recipe_id, created_at DESC)';
        $sql[] = 'CREATE INDEX IF NOT EXISTS idx_cl_created ON cook_logs (created_at DESC)';

        return $sql;
    }

    private static function ddlV1(): string
    {
        return <<<'SQL'
CREATE TABLE IF NOT EXISTS users (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  username      TEXT    NOT NULL UNIQUE,
  password_hash TEXT    NOT NULL,
  display_name  TEXT    NOT NULL DEFAULT '',
  avatar        TEXT    NOT NULL DEFAULT '',
  role          TEXT    NOT NULL DEFAULT 'member',
  can_write     INTEGER NOT NULL DEFAULT 1,
  unit_pref     TEXT    NOT NULL DEFAULT '克',
  is_active     INTEGER NOT NULL DEFAULT 1,
  created_at    INTEGER NOT NULL DEFAULT 0,
  last_login_at INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS sessions (
  id         TEXT    PRIMARY KEY,
  user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  created_at INTEGER NOT NULL DEFAULT 0,
  expires_at INTEGER NOT NULL DEFAULT 0,
  ip         TEXT    NOT NULL DEFAULT '',
  ua         TEXT    NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_sessions_user   ON sessions (user_id);
CREATE INDEX IF NOT EXISTS idx_sessions_expire ON sessions (expires_at);

CREATE TABLE IF NOT EXISTS settings (
  k TEXT PRIMARY KEY,
  v TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS tags (
  id   INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT    NOT NULL UNIQUE,
  kind TEXT    NOT NULL DEFAULT 'tag',
  sort INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_tags_kind ON tags (kind);

CREATE TABLE IF NOT EXISTS ingredients (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  name         TEXT    NOT NULL UNIQUE,
  category     TEXT    NOT NULL DEFAULT '其他',
  aliases      TEXT    NOT NULL DEFAULT '',
  default_unit TEXT    NOT NULL DEFAULT '克',
  is_builtin   INTEGER NOT NULL DEFAULT 0,
  note         TEXT    NOT NULL DEFAULT '',
  created_by   INTEGER NOT NULL DEFAULT 0,
  created_at   INTEGER NOT NULL DEFAULT 0,
  used_count   INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_ing_category ON ingredients (category);

CREATE TABLE IF NOT EXISTS recipes (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  owner_id      INTEGER NOT NULL DEFAULT 0,
  name          TEXT    NOT NULL,
  description   TEXT    NOT NULL DEFAULT '',
  cover         TEXT    NOT NULL DEFAULT '',
  servings      REAL    NOT NULL DEFAULT 0,
  servings_text TEXT    NOT NULL DEFAULT '',
  prep_min      INTEGER NOT NULL DEFAULT 0,
  cook_min      INTEGER NOT NULL DEFAULT 0,
  total_min     INTEGER NOT NULL DEFAULT 0,
  difficulty    TEXT    NOT NULL DEFAULT '简单',
  cuisine       TEXT    NOT NULL DEFAULT '',
  tips          TEXT    NOT NULL DEFAULT '',
  taboo         TEXT    NOT NULL DEFAULT '',
  storage       TEXT    NOT NULL DEFAULT '',
  leftover      TEXT    NOT NULL DEFAULT '',
  nutrition     TEXT    NOT NULL DEFAULT '',
  visibility    TEXT    NOT NULL DEFAULT 'public',
  source        TEXT    NOT NULL DEFAULT '',
  search_text   TEXT    NOT NULL DEFAULT '',
  created_at    INTEGER NOT NULL DEFAULT 0,
  updated_at    INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_recipes_updated ON recipes (updated_at DESC);
CREATE INDEX IF NOT EXISTS idx_recipes_cuisine ON recipes (cuisine);
CREATE INDEX IF NOT EXISTS idx_recipes_owner   ON recipes (owner_id);

CREATE TABLE IF NOT EXISTS recipe_ingredients (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  recipe_id     INTEGER NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
  ingredient_id INTEGER NOT NULL DEFAULT 0,
  name          TEXT    NOT NULL,
  amount        REAL,
  unit          TEXT    NOT NULL DEFAULT '',
  qty_text      TEXT    NOT NULL DEFAULT '',
  note          TEXT    NOT NULL DEFAULT '',
  optional      INTEGER NOT NULL DEFAULT 0,
  sort          INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_ri_recipe ON recipe_ingredients (recipe_id);
CREATE INDEX IF NOT EXISTS idx_ri_name   ON recipe_ingredients (name);
CREATE INDEX IF NOT EXISTS idx_ri_ing    ON recipe_ingredients (ingredient_id);

CREATE TABLE IF NOT EXISTS recipe_steps (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  recipe_id    INTEGER NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
  sort         INTEGER NOT NULL DEFAULT 0,
  text         TEXT    NOT NULL DEFAULT '',
  image        TEXT    NOT NULL DEFAULT '',
  duration_min INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_steps_recipe ON recipe_steps (recipe_id);

CREATE TABLE IF NOT EXISTS recipe_tags (
  recipe_id INTEGER NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
  tag_id    INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
  PRIMARY KEY (recipe_id, tag_id)
);
CREATE INDEX IF NOT EXISTS idx_rt_tag ON recipe_tags (tag_id);

CREATE TABLE IF NOT EXISTS favorites (
  user_id    INTEGER NOT NULL,
  recipe_id  INTEGER NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
  created_at INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, recipe_id)
);

CREATE TABLE IF NOT EXISTS pantry_items (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  ingredient_id INTEGER NOT NULL DEFAULT 0,
  name          TEXT    NOT NULL,
  amount        REAL,
  unit          TEXT    NOT NULL DEFAULT '',
  qty_text      TEXT    NOT NULL DEFAULT '',
  location      TEXT    NOT NULL DEFAULT '冷藏',
  bought_at     INTEGER NOT NULL DEFAULT 0,
  expire_at     INTEGER NOT NULL DEFAULT 0,
  note          TEXT    NOT NULL DEFAULT '',
  created_by    INTEGER NOT NULL DEFAULT 0,
  created_at    INTEGER NOT NULL DEFAULT 0,
  updated_at    INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_pantry_expire ON pantry_items (expire_at);
CREATE INDEX IF NOT EXISTS idx_pantry_name   ON pantry_items (name);

CREATE TABLE IF NOT EXISTS meal_plans (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  plan_date  TEXT    NOT NULL,
  slot       TEXT    NOT NULL DEFAULT 'dinner',
  recipe_id  INTEGER NOT NULL DEFAULT 0,
  title      TEXT    NOT NULL DEFAULT '',
  servings   REAL    NOT NULL DEFAULT 0,
  note       TEXT    NOT NULL DEFAULT '',
  sort       INTEGER NOT NULL DEFAULT 0,
  created_by INTEGER NOT NULL DEFAULT 0,
  created_at INTEGER NOT NULL DEFAULT 0,
  updated_at INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_plan_date ON meal_plans (plan_date);

CREATE TABLE IF NOT EXISTS plan_templates (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  name       TEXT    NOT NULL,
  payload    TEXT    NOT NULL DEFAULT '',
  created_by INTEGER NOT NULL DEFAULT 0,
  created_at INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS shopping_lists (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  name       TEXT    NOT NULL DEFAULT '采购清单',
  status     TEXT    NOT NULL DEFAULT 'open',
  created_by INTEGER NOT NULL DEFAULT 0,
  created_at INTEGER NOT NULL DEFAULT 0,
  updated_at INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS shopping_items (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  list_id       INTEGER NOT NULL REFERENCES shopping_lists(id) ON DELETE CASCADE,
  ingredient_id INTEGER NOT NULL DEFAULT 0,
  name          TEXT    NOT NULL,
  amount        REAL,
  unit          TEXT    NOT NULL DEFAULT '',
  qty_text      TEXT    NOT NULL DEFAULT '',
  note          TEXT    NOT NULL DEFAULT '',
  checked       INTEGER NOT NULL DEFAULT 0,
  manual        INTEGER NOT NULL DEFAULT 0,
  sources       TEXT    NOT NULL DEFAULT '',
  sort          INTEGER NOT NULL DEFAULT 0,
  created_at    INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_si_list ON shopping_items (list_id);

CREATE TABLE IF NOT EXISTS logs (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER NOT NULL DEFAULT 0,
  username   TEXT    NOT NULL DEFAULT '',
  action     TEXT    NOT NULL DEFAULT '',
  target     TEXT    NOT NULL DEFAULT '',
  detail     TEXT    NOT NULL DEFAULT '',
  ip         TEXT    NOT NULL DEFAULT '',
  ua         TEXT    NOT NULL DEFAULT '',
  created_at INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_logs_created ON logs (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_logs_action  ON logs (action);
SQL;
    }

    /**
     * 灌初始数据：中餐食材库 + 预置标签 + 菜系。
     * 只在真正的首次安装跑一次，之后用户删掉内置食材也不会被恢复。
     */
    public static function seed(): void
    {
        if (Repo\Settings::get('seeded_at', '') !== '') {
            return;
        }

        Db::tx(static function (): void {
            $pdo  = Db::pdo();
            $now  = time();

            // ---- 预置标签 & 菜系 ----
            $tags = require RH_SEED . '/tags.php';

            $insTag = $pdo->prepare('INSERT OR IGNORE INTO tags (name, kind, sort) VALUES (?, ?, ?)');

            foreach ($tags as $i => $t) {
                $insTag->execute(array($t[0], $t[1], $i));
            }

            // ---- 预置食材库 ----
            // 格式：分类 => ['名称', '名称|别名1,别名2', '名称|别名|默认单位']
            $sections = require RH_SEED . '/ingredients.php';
            $insIng   = $pdo->prepare(
                'INSERT OR IGNORE INTO ingredients (name, category, aliases, default_unit, is_builtin, created_at)
                 VALUES (?, ?, ?, ?, 1, ?)'
            );

            foreach ($sections as $cat => $list) {
                foreach ($list as $entry) {
                    $parts = explode('|', (string) $entry);
                    $name  = trim($parts[0]);

                    if ($name === '') {
                        continue;
                    }

                    $insIng->execute(array(
                        $name,
                        (string) $cat,
                        isset($parts[1]) ? trim($parts[1]) : '',
                        isset($parts[2]) && trim($parts[2]) !== '' ? trim($parts[2]) : self::guessUnit((string) $cat),
                        $now,
                    ));
                }
            }

            Repo\Settings::set('seeded_at', (string) $now);

            // ---- 站点默认设置 ----
            $defaults = array(
                'allow_register' => (string) (int) Config::get('allowRegister', 0),
                'installed_at'   => (string) $now,
                'site_name'      => (string) Config::get('siteName', '家常菜谱'),
                'site_desc'      => (string) Config::get('siteDesc', ''),
            );

            foreach ($defaults as $k => $v) {
                if (Repo\Settings::get($k, null) === null) {
                    Repo\Settings::set($k, $v);
                }
            }
        });
    }

    /** 按分类猜一个默认计量单位，省得食材表里每条都写一遍 */
    private static function guessUnit(string $cat): string
    {
        $map = array(
            '蛋奶' => '个',
            '水果' => '个',
            '蔬菜' => '克',
            '肉类' => '克',
            '水产' => '克',
            '豆制品' => '克',
            '干货' => '克',
            '调味料' => '克',
            '主食' => '克',
        );

        return $map[$cat] ?? '克';
    }

    /** 示例菜谱：首次安装后让界面不至于空着。可跳过。 */
    public static function seedDemo(int $ownerId): int
    {
        $recipes = require RH_SEED . '/demo.php';
        $n       = 0;

        foreach ($recipes as $r) {
            if (Repo\Recipe::findByName($r['name'])) {
                continue;
            }

            $r['owner_id'] = $ownerId;
            Repo\Recipe::create($r);
            $n++;
        }

        return $n;
    }
}
