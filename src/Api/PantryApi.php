<?php
declare(strict_types=1);

namespace RH\Api;

use RH\Http;
use RH\Repo\Pantry;

final class PantryApi
{
    public static function list(): void
    {
        Http::ok(array(
            'items' => Pantry::list(array(
                'q'             => Http::str('q'),
                'location'      => Http::str('location'),
                'expiring'      => Http::bool('expiring'),
                'expiring_days' => Http::int('expiring_days', 7),
            )),
            'locations' => Pantry::LOCATIONS,
            'stats'     => Pantry::stats(),
        ));
    }

    public static function stats(): void
    {
        Http::ok(Pantry::stats());
    }

    public static function create(): void
    {
        $id = Pantry::create(Http::body());

        Http::ok(array('id' => $id, 'item' => Pantry::decorate((array) \RH\Db::find('pantry_items', $id))));
    }

    public static function update(array $p): void
    {
        $id = (int) ($p['id'] ?? 0);
        Pantry::update($id, Http::body());

        Http::ok(array('item' => Pantry::decorate((array) \RH\Db::find('pantry_items', $id))));
    }

    public static function remove(array $p): void
    {
        Pantry::remove((int) ($p['id'] ?? 0));

        Http::ok(array('removed' => true));
    }

    /** 加减数量：买回来补货、用掉一部分 */
    public static function adjust(array $p): void
    {
        $id    = (int) ($p['id'] ?? 0);
        $delta = Http::float('delta');

        Http::ok(Pantry::adjust($id, $delta));
    }

    /** 按现有库存推荐能做的菜 */
    public static function suggest(): void
    {
        Http::ok(array(
            'items' => Pantry::suggest(
                Http::int('limit', 20),
                (float) (Http::input('min_ratio', 0.5) ?: 0.5)
            ),
        ));
    }
}
