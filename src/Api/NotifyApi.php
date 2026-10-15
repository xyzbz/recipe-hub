<?php
declare(strict_types=1);

namespace RH\Api;

use RH\Auth;
use RH\Http;
use RH\Logs;
use RH\Repo\Notify;

/**
 * 推送渠道的管理接口。这些配置里躺着各家平台的密钥，所以只放管理员后面。
 */
final class NotifyApi
{
    /** 渠道元信息 + 渠道列表 + 排期，前端一个请求拿全 */
    public static function channels(): void
    {
        Auth::requireAdmin();

        Http::ok(array(
            'items'    => Notify::channels(),
            'types'    => Notify::TYPES,
            'schedule' => Notify::schedule(),
            'slots'    => Notify::SLOT_LABEL,
        ));
    }

    /** 新增或更新渠道。id = 0 或不存在即为新增 */
    public static function channelSave(): void
    {
        Auth::requireAdmin();

        $res = Notify::save(array(
            'id'      => Http::int('id', 0),
            'name'    => Http::str('name'),
            'type'    => Http::str('type'),
            'enabled' => Http::bool('enabled', true),
            'config'  => Http::input('config', array()),
        ));

        if (!$res['ok']) {
            Http::fail($res['error'], 400);
        }

        Logs::add('notify_channel_save', (string) $res['id'], Http::str('name'));

        Http::ok(array('items' => Notify::channels()));
    }

    public static function channelRemove(array $p): void
    {
        Auth::requireAdmin();

        $id = (int) ($p['id'] ?? 0);

        if ($id <= 0) {
            Http::fail('缺少渠道 id', 400);
        }

        Notify::remove($id);
        Logs::add('notify_channel_remove', (string) $id, '');

        Http::ok(array('items' => Notify::channels()));
    }

    /**
     * 发一条测试消息。
     *
     * 用 test|时间戳 做去重键，免得把正式推送的「今天已发过」标记弄脏。
     */
    public static function channelTest(): void
    {
        Auth::requireAdmin();

        $id = Http::int('id', 0);

        if ($id <= 0) {
            Http::fail('缺少渠道 id', 400);
        }

        $ch = Notify::find($id);

        if ($ch === null) {
            Http::fail('渠道不存在', 404);
        }

        $date    = date('Y-m-d');
        $content = Notify::renderMeal($date, 'dinner');

        if ($content === null) {
            $content = array(
                'title' => '菜谱本推送测试',
                'body'  => "这是一条测试消息。\n\n如果你在微信/手机里看到它，说明渠道配置没问题。",
            );
        }

        $res = Notify::send(Notify::channelRaw($id), $content);

        Notify::log(array(
            'channel_id' => $id,
            'dedupe_key' => 'test|' . time(),
            'slot'       => 'test',
            'plan_date'  => $date,
            'title'      => $content['title'],
            'body'       => $content['body'],
            'status'     => $res['ok'] ? 'ok' : 'fail',
            'error'      => $res['error'],
        ));

        if (!$res['ok']) {
            Http::fail('发送失败：' . $res['error'], 502);
        }

        Http::ok(array('ok' => true, 'title' => $content['title']));
    }

    public static function scheduleSave(): void
    {
        Auth::requireAdmin();

        $raw = Http::input('schedule', array());

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : array();
        }

        if (!is_array($raw)) {
            Http::fail('排期格式不对', 400);
        }

        Notify::saveSchedule($raw);
        Logs::add('notify_schedule_save', '', '');

        Http::ok(array('schedule' => Notify::schedule()));
    }

    /** 手动触发一次推送（不走去重，方便当场看效果） */
    public static function runNow(): void
    {
        Auth::requireAdmin();

        $date = Http::str('date', date('Y-m-d'));
        $slot = Http::str('slot', 'dinner');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            Http::fail('日期格式应为 YYYY-MM-DD', 400);
        }

        if (!isset(Notify::SLOT_LABEL[$slot])) {
            Http::fail('不认识的餐次：' . $slot, 400);
        }

        $res = Notify::pushSlot($date, $slot, false, 'manual|' . time());

        if (!empty($res['empty'])) {
            Http::fail('这一天' . (Notify::SLOT_LABEL[$slot] ?? $slot) . '没有安排，没东西可推', 404);
        }

        Logs::add('notify_manual', $slot, $date);

        Http::ok($res);
    }

    public static function logs(): void
    {
        Auth::requireAdmin();

        Http::ok(array('items' => Notify::logs(Http::int('limit', 50))));
    }

    public static function logClear(): void
    {
        Auth::requireAdmin();

        $n = Notify::logClear();

        Http::ok(array('removed' => $n));
    }
}
