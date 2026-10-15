<?php
declare(strict_types=1);

namespace RH\Api;

use RH\Auth;
use RH\Config;
use RH\Http;
use RH\Repo\Ingredient;
use RH\Repo\Pantry;
use RH\Repo\Plan;
use RH\Repo\Recipe;
use RH\Repo\Settings;
use RH\Units;

/**
 * 站点元信息。前端启动时拉这一次，把单位表、标签、菜系、权限都装进内存。
 */
final class MetaApi
{
    public static function bootstrap(): void
    {
        $user = Auth::user();

        Http::ok(array(
            'site'                 => Settings::siteInfo(),
            'user'                 => $user ? Auth::publicUser($user) : null,
            'needsSetup'           => Auth::userCount() === 0,
            'canWrite'             => Auth::canWrite(),
            'isAdmin'              => Auth::isAdmin(),
            'publicBrowse'         => Settings::bool('public_browse', false),
            'csrf'                 => Auth::csrf(),
            'units'                => Units::groups(),
            'difficulties'         => Recipe::DIFFICULTY,
            'slots'                => array_map(static function (string $s): array {
                return array('key' => $s, 'label' => Plan::SLOT_LABEL[$s]);
            }, Plan::SLOTS),
            'pantryLocations'      => Pantry::LOCATIONS,
            'ingredientCategories' => Ingredient::categories(),
            'tags'                 => Recipe::allTags(),
            'cuisines'             => Recipe::allCuisines(),
            'stats'                => array_merge(Recipe::stats(), array(
                'pantry_expired' => Pantry::stats()['expired'],
                'pantry_soon'    => Pantry::stats()['soon'],
            )),
            'uploadMaxMb'          => (int) Config::get('uploadMaxMb', 8),
            'version'              => RH_VERSION,
        ));
    }

    public static function units(): void
    {
        Http::ok(Units::groups());
    }

    /** 搜索框的候选：食材库里用得多的一些名字 */
    public static function suggestions(): void
    {
        $q = Http::str('q');

        Http::ok(Ingredient::list(array('q' => $q, 'limit' => 20)));
    }
}
