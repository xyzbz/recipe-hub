<?php
declare(strict_types=1);

namespace RH\Api;

use RH\Db;
use RH\Http;
use RH\Repo\Shopping;

final class ShoppingApi
{
    public static function lists(): void
    {
        Http::ok(Shopping::lists(Http::bool('open')));
    }

    public static function get(array $p): void
    {
        $l = Shopping::find((int) ($p['id'] ?? 0));

        if (!$l) {
            Http::fail('清单不存在', 404);
        }

        Http::ok($l);
    }

    public static function create(): void
    {
        $id = Shopping::create(Http::str('name'));

        Http::ok(array('id' => $id, 'list' => Shopping::find($id)));
    }

    public static function update(array $p): void
    {
        Shopping::update((int) ($p['id'] ?? 0), Http::body());

        Http::ok(array('updated' => true));
    }

    public static function remove(array $p): void
    {
        Shopping::remove((int) ($p['id'] ?? 0));

        Http::ok(array('removed' => true));
    }

    // ---- 条目 ----

    public static function addItem(array $p): void
    {
        $listId = (int) ($p['id'] ?? 0);
        $id     = Shopping::addItem($listId, Http::body());

        Http::ok(array('id' => $id, 'list' => Shopping::find($listId)));
    }

    public static function updateItem(array $p): void
    {
        Shopping::updateItem((int) ($p['itemId'] ?? 0), Http::body());

        Http::ok(array('updated' => true));
    }

    public static function toggleItem(array $p): void
    {
        $on = Shopping::toggleItem((int) ($p['itemId'] ?? 0));

        Http::ok(array('checked' => $on));
    }

    public static function removeItem(array $p): void
    {
        Shopping::removeItem((int) ($p['itemId'] ?? 0));

        Http::ok(array('removed' => true));
    }

    public static function clearChecked(array $p): void
    {
        Http::ok(array('removed' => Shopping::clearChecked((int) ($p['id'] ?? 0))));
    }

    public static function uncheckAll(array $p): void
    {
        Http::ok(array('reset' => Shopping::uncheckAll((int) ($p['id'] ?? 0))));
    }

    public static function clearAll(array $p): void
    {
        Http::ok(array('removed' => Shopping::clearAll((int) ($p['id'] ?? 0))));
    }

    /** 重新整理：把清单里的重复项合并一遍 */
    public static function tidy(array $p): void
    {
        $listId = (int) ($p['id'] ?? 0);

        Http::ok(array('count' => Shopping::tidy($listId), 'list' => Shopping::find($listId)));
    }

    // ---- 从别处灌进来 ----

    /** 按膳食计划区间生成 / 刷新这份清单 */
    public static function fromPlan(array $p): void
    {
        $listId = (int) ($p['id'] ?? 0);

        $n = Shopping::fillFromPlan(
            $listId,
            Http::str('from', date('Y-m-d')),
            Http::str('to', Http::str('from', date('Y-m-d')))
        );

        Http::ok(array('count' => $n, 'list' => Shopping::find($listId)));
    }

    /** 直接把几道菜的材料加进清单 */
    public static function addRecipes(array $p): void
    {
        $listId = (int) ($p['id'] ?? 0);
        $ids    = Http::input('recipe_ids', array());

        if (is_string($ids)) {
            $ids = array_filter(array_map('intval', explode(',', $ids)));
        }

        if (!is_array($ids) || !$ids) {
            Http::fail('没有选菜谱', 400, 'recipe_ids');
        }

        $n = Shopping::addRecipes($listId, $ids, Http::float('multiplier', 1.0) ?: 1.0);

        Http::ok(array('count' => $n, 'list' => Shopping::find($listId)));
    }

    // ---- 导出 ----

    /** 纯文本，方便复制到微信 / 备忘录 */
    public static function export(array $p): void
    {
        $listId = (int) ($p['id'] ?? 0);
        $text   = Shopping::exportText($listId, Http::bool('unchecked_only'));

        if (Http::bool('download')) {
            if (ob_get_level() > 0) {
                @ob_clean();
            }

            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="shopping-' . $listId . '.txt"');
            echo $text;
            exit;
        }

        Http::ok(array('text' => $text));
    }

    /** 把已买的东西回填到家庭库存 */
    public static function toPantry(array $p): void
    {
        $listId = (int) ($p['id'] ?? 0);
        $l      = Shopping::find($listId);

        if (!$l) {
            Http::fail('清单不存在', 404);
        }

        $n = 0;

        Db::tx(static function () use ($l, &$n): void {
            foreach ($l['items'] as $i) {
                if (!$i['checked']) {
                    continue;
                }

                \RH\Repo\Pantry::create(array(
                    'name'          => (string) $i['name'],
                    'ingredient_id' => (int) $i['ingredient_id'],
                    'amount'        => $i['amount'],
                    'unit'          => (string) $i['unit'],
                    'qty_text'      => (string) $i['qty_text'],
                    'location'      => '冷藏',
                    'bought_at'     => date('Y-m-d'),
                ));

                $n++;
            }
        });

        if ($n === 0) {
            Http::fail('清单里还没有勾选已买的条目', 400);
        }

        Http::ok(array('added' => $n));
    }
}
