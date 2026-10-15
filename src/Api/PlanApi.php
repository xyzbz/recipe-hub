<?php
declare(strict_types=1);

namespace RH\Api;

use RH\Http;
use RH\Repo\Plan;
use RH\Repo\Shopping;

final class PlanApi
{
    public static function range(): void
    {
        $from = Http::str('from', date('Y-m-d'));
        $to   = Http::str('to', $from);

        if (Http::bool('group')) {
            Http::ok(array(
                'days'  => Plan::grouped($from, $to),
                'from'  => Plan::day($from),
                'to'    => Plan::day($to),
                'slots' => array_map(static function (string $s): array {
                    return array('key' => $s, 'label' => Plan::SLOT_LABEL[$s]);
                }, Plan::SLOTS),
            ));
        }

        Http::ok(array(
            'items' => Plan::range($from, $to),
            'from'  => Plan::day($from),
            'to'    => Plan::day($to),
        ));
    }

    /** 默认给一周，前端日历直接用 */
    public static function week(): void
    {
        $start = Http::str('start');

        if ($start === '') {
            $start = Plan::weekStart();
        }

        $from = Plan::day($start);
        $to   = date('Y-m-d', strtotime($from . ' +6 day'));

        Http::ok(array(
            'days'  => Plan::grouped($from, $to),
            'from'  => $from,
            'to'    => $to,
            'start' => $from,
            'end'   => $to,
            'slots' => array_map(static function (string $s): array {
                return array('key' => $s, 'label' => Plan::SLOT_LABEL[$s]);
            }, Plan::SLOTS),
        ));
    }

    public static function create(): void
    {
        $id = Plan::create(Http::body());

        Http::ok(array('id' => $id));
    }

    public static function update(array $p): void
    {
        Plan::update((int) ($p['id'] ?? 0), Http::body());

        Http::ok(array('updated' => true));
    }

    /** 拖拽换位置 */
    public static function move(array $p): void
    {
        Plan::move((int) ($p['id'] ?? 0), Http::str('plan_date'), Http::str('slot', 'dinner'));

        Http::ok(array('moved' => true));
    }

    /** 一键换菜：不想吃这顿了，从没排过的菜里随机来一道 */
    public static function shuffle(array $p): void
    {
        Http::ok(Plan::shuffle((int) ($p['id'] ?? 0)));
    }

    public static function remove(array $p): void
    {
        Plan::remove((int) ($p['id'] ?? 0));

        Http::ok(array('removed' => true));
    }

    public static function clear(): void
    {
        $from = Http::str('from', date('Y-m-d'));
        $to   = Http::str('to', $from);

        Http::ok(array('removed' => Plan::clearRange($from, $to)));
    }

    /** 把一段时间整体搬到另一天（整周复用） */
    public static function copy(): void
    {
        $n = Plan::copyRange(
            Http::str('from'),
            Http::str('to'),
            Http::str('new_start', date('Y-m-d'))
        );

        Http::ok(array('copied' => $n));
    }

    // ---- 周计划模板 ----

    public static function templates(): void
    {
        Http::ok(Plan::templates());
    }

    public static function templateCreate(): void
    {
        $id = Plan::saveTemplate(
            Http::str('name'),
            Http::str('from', date('Y-m-d')),
            Http::str('to', date('Y-m-d'))
        );

        Http::ok(array('id' => $id));
    }

    public static function templateApply(array $p): void
    {
        $n = Plan::applyTemplate((int) ($p['id'] ?? 0), Http::str('start', date('Y-m-d')));

        Http::ok(array('applied' => $n));
    }

    public static function templateRemove(array $p): void
    {
        Plan::removeTemplate((int) ($p['id'] ?? 0));

        Http::ok(array('removed' => true));
    }

    // ---- 生成采购清单 ----

    /** 直接新建一份清单 */
    public static function toShopping(): void
    {
        $from = Http::str('from', date('Y-m-d'));
        $to   = Http::str('to', $from);

        $res = Shopping::createFromPlan($from, $to, Http::str('name'));

        Http::ok($res);
    }

    /** 把一个区间摊平成原料行，前端可以先看一眼再决定 */
    public static function preview(): void
    {
        $from = Http::str('from', date('Y-m-d'));
        $to   = Http::str('to', $from);

        $rows = Plan::ingredientRows($from, $to);

        Http::ok(array(
            'raw'    => count($rows),
            'merged' => Shopping::mergeRows($rows),
        ));
    }
}
