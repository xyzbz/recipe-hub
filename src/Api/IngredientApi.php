<?php
declare(strict_types=1);

namespace RH\Api;

use RH\Auth;
use RH\Http;
use RH\Repo\Ingredient;

final class IngredientApi
{
    public static function list(): void
    {
        Http::ok(Ingredient::list(array(
            'q'        => Http::str('q'),
            'category' => Http::str('category'),
            'limit'    => Http::int('limit', 300),
        )));
    }

    public static function categories(): void
    {
        Http::ok(Ingredient::categories());
    }

    public static function create(): void
    {
        $id = Ingredient::create(Http::body());

        Http::ok(array('id' => $id));
    }

    public static function update(array $p): void
    {
        Ingredient::update((int) ($p['id'] ?? 0), Http::body());

        Http::ok(array('updated' => true));
    }

    public static function remove(array $p): void
    {
        Ingredient::remove((int) ($p['id'] ?? 0));

        Http::ok(array('removed' => true));
    }

    /** 合并两条食材（处理同物异名） */
    public static function merge(): void
    {
        $from = Http::int('from');
        $to   = Http::int('to');

        Http::ok(Ingredient::merge($from, $to));
    }

    /** 老库升级用：把食材引用关系重算一遍 */
    public static function refresh(): void
    {
        Auth::requireAdmin();
        Ingredient::refreshUsage();

        Http::ok(array('refreshed' => true));
    }
}
