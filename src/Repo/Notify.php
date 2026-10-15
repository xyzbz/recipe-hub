<?php
declare(strict_types=1);

namespace RH\Repo;

use RH\Config;
use RH\Db;
use RH\Images;
use RH\Logs;

/**
 * 膳食计划定时推送。
 *
 * 站点自己不认识任何一家推送平台：渠道配置存在表里，发送时按 type 分派。
 * 哪天某个平台改接口或者跑路了，改这里一个方法就行，业务代码不用动。
 *
 * 支持五种：
 *   wecom_app  企业微信自建应用（配合微信插件，消息直接落到个人微信）
 *   pushplus   PushPlus 服务号
 *   serverchan Server酱（方糖）
 *   wxpusher   WxPusher
 *   webhook    自定义地址：自动按协议发飞书 / 钉钉 / 企微群机器人 / Telegram，
 *              也可发通用 JSON（带 HMAC-SHA256 签名头）接 n8n / 自建服务
 *
 * 富文本：有站点地址 + 开关打开时，各渠道按自己的能力发卡片 / 图文 / HTML——
 * 企微走 news 图文、飞书走 interactive 卡片、钉钉走 feedCard、TG 走 HTML、
 * PushPlus / Server酱 / WxPusher 切 html 或 markdown。
 * 没配站点地址就整体退回纯文本：相对路径的链接推到微信里点了也是白点，
 * 图片更是外部平台抓不到的，宁可不发。
 */
final class Notify
{
    /** 每种渠道要填哪些字段。required 为空则整条渠道不可用。 */
    public const TYPES = array(
        'wecom_app'  => array(
            'label' => '企业微信自建应用',
            'hint'  => '消息会直接出现在个人微信里（需关注微信插件）',
            'fields' => array(
                'corpid'     => array('label' => '企业 ID', 'required' => true),
                'corpsecret' => array('label' => '应用 Secret', 'required' => true, 'secret' => true),
                'agentid'    => array('label' => '应用 AgentId', 'required' => true),
                'touser'     => array('label' => '接收成员', 'required' => false, 'default' => '@all', 'hint' => '多人用 | 分隔，全部填 @all'),
            ),
        ),
        'pushplus'   => array(
            'label' => 'PushPlus',
            'hint'  => '微信扫码关注公众号后拿 token',
            'fields' => array(
                'token'  => array('label' => 'Token', 'required' => true, 'secret' => true),
                'topic'  => array('label' => '群组编码', 'required' => false, 'hint' => '一对多群组推送才填'),
            ),
        ),
        'serverchan' => array(
            'label' => 'Server酱',
            'hint'  => 'SendKey 形如 SCTxxxx',
            'fields' => array(
                'sendkey' => array('label' => 'SendKey', 'required' => true, 'secret' => true),
            ),
        ),
        'wxpusher'   => array(
            'label' => 'WxPusher',
            'hint'  => '关注应用后拿 UID，或用主题号一对多',
            'fields' => array(
                'apptoken' => array('label' => 'AppToken', 'required' => true, 'secret' => true),
                'uid'      => array('label' => 'UID', 'required' => false, 'hint' => '多个用英文逗号分隔'),
                'topicid'  => array('label' => '主题号', 'required' => false),
            ),
        ),
        'webhook'    => array(
            'label' => '自定义 Webhook',
            'hint'  => '直接支持飞书 / 钉钉 / 企微群机器人 / Telegram，也能发通用 JSON',
            'fields' => array(
                'format' => array(
                    'label'    => '消息格式',
                    'required' => false,
                    'type'     => 'select',
                    'default'  => 'auto',
                    'options'  => array(
                        array('value' => 'auto',      'label' => '自动识别（按地址判断）'),
                        array('value' => 'feishu',    'label' => '飞书群机器人'),
                        array('value' => 'dingtalk',  'label' => '钉钉群机器人'),
                        array('value' => 'wecom_bot', 'label' => '企业微信群机器人'),
                        array('value' => 'telegram',  'label' => 'Telegram Bot'),
                        array('value' => 'generic',   'label' => '通用 JSON（n8n / 自建服务）'),
                    ),
                    'hint' => '各家机器人收的 JSON 长得不一样，选错协议发不过去',
                ),
                'url'     => array('label' => '地址', 'required' => true, 'hint' => '粘贴机器人 Webhook 地址；Telegram 填 https://api.telegram.org/bot<令牌>'),
                'chat_id' => array('label' => 'Chat ID', 'required' => false, 'hint' => '仅 Telegram 需要，用 @userinfobot 查'),
                'secret'  => array('label' => '签名密钥', 'required' => false, 'secret' => true, 'hint' => '飞书/钉钉填加签密钥；通用 JSON 则用作 HMAC 签名头'),
            ),
        ),
    );

    public const SLOT_LABEL = array(
        'breakfast' => '早餐',
        'lunch'     => '午餐',
        'dinner'    => '晚餐',
        'snack'     => '加餐',
    );

    /** 默认推送时间：早 7:30、午 11:00、晚 17:00，加餐默认关 */
    public const DEFAULT_SCHEDULE = array(
        'breakfast' => array('on' => false, 'time' => '07:30'),
        'lunch'     => array('on' => true,  'time' => '11:00'),
        'dinner'    => array('on' => true,  'time' => '17:00'),
        'snack'     => array('on' => false, 'time' => '15:00'),
    );

    // ------------------------------------------------------------ 站点地址与富文本

    /**
     * 消息里的链接和封面图必须是绝对地址：外部平台是拿自己的服务器来抓图的，
     * 相对路径它们看不懂。优先级：后台填的 site_url > data/config.php 或环境变量。
     */
    public static function siteUrl(): string
    {
        $url = trim((string) Settings::get('site_url', ''));

        if ($url === '') {
            $url = trim((string) Config::get('siteUrl', ''));
        }

        return self::normalizeSiteUrl($url);
    }

    /** 少了协议就补 https，去掉尾斜杠；乱填的一律当没填——总比推出半个坏链接强 */
    public static function normalizeSiteUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        return rtrim($url, '/');
    }

    /** 站内相对路径 / hash 路由 → 绝对地址；没配站点地址就返回空，调用方据此降级 */
    public static function absUrl(string $path): string
    {
        $base = self::siteUrl();

        if ($base === '' || $path === '') {
            return '';
        }

        // hash 路由（#/recipes/12）直接拼在根后面，不用再加斜杠
        if ($path[0] === '#') {
            return $base . '/' . $path;
        }

        return $base . ($path[0] === '/' ? $path : '/' . $path);
    }

    /** 富文本总开关。关掉之后所有渠道退回纯文本，图、链接、卡片都不带 */
    public static function richEnabled(): bool
    {
        return Settings::get('notify_rich', '1') !== '0';
    }

    // ------------------------------------------------------------------ 渠道

    /** @return array<int, array<string, mixed>> */
    public static function channels(bool $onlyEnabled = false): array
    {
        $sql = 'SELECT * FROM notify_channels';

        if ($onlyEnabled) {
            $sql .= ' WHERE enabled = 1';
        }

        $sql   .= ' ORDER BY id ASC';
        $out    = array();

        foreach (Db::all($sql) as $r) {
            $out[] = self::decorate($r);
        }

        return $out;
    }

    public static function find(int $id): ?array
    {
        $r = Db::one('SELECT * FROM notify_channels WHERE id = ?', array($id));

        return $r === null ? null : self::decorate($r);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{ok: bool, id: int, error: string}
     */
    public static function save(array $data): array
    {
        $id   = (int) ($data['id'] ?? 0);
        $name = trim((string) ($data['name'] ?? ''));
        $type = trim((string) ($data['type'] ?? ''));

        if ($name === '') {
            return array('ok' => false, 'id' => 0, 'error' => '渠道名称不能为空');
        }

        if (!isset(self::TYPES[$type])) {
            return array('ok' => false, 'id' => 0, 'error' => '不认识的渠道类型：' . $type);
        }

        $cfg = $data['config'] ?? array();

        if (is_string($cfg)) {
            $decoded = json_decode($cfg, true);
            $cfg     = is_array($decoded) ? $decoded : array();
        }

        if (!is_array($cfg)) {
            $cfg = array();
        }

        // 编辑时前端拿到的密钥是打过码的（SCT12******abcd）。用户没改就直接保存，
        // 会把星号当成真值写回库里，渠道当场报废——所以带码的值一律沿用旧值。
        if ($id > 0) {
            $cfg = self::keepMaskedSecrets($id, $type, $cfg);
        }

        $normalized = self::normalizeConfig($type, $cfg);
        $missing    = self::missingFields($type, $normalized);

        if ($missing !== array()) {
            return array('ok' => false, 'id' => 0, 'error' => '缺少必填项：' . implode('、', $missing));
        }

        $now     = time();
        $userId  = (int) ($data['created_by'] ?? 0);
        $json    = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // 没显式给 enabled 就当启用——新增渠道就是要它能发
        $enabled = array_key_exists('enabled', $data) ? (!empty($data['enabled']) ? 1 : 0) : 1;

        if ($id > 0 && Db::one('SELECT id FROM notify_channels WHERE id = ?', array($id)) !== null) {
            Db::exec(
                'UPDATE notify_channels SET name = ?, type = ?, config = ?, enabled = ?, updated_at = ? WHERE id = ?',
                array($name, $type, (string) $json, $enabled, $now, $id)
            );
        } else {
            Db::exec(
                'INSERT INTO notify_channels (name, type, config, enabled, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                array($name, $type, (string) $json, $enabled, $userId, $now, $now)
            );

            $id = (int) Db::pdo()->lastInsertId();
        }

        return array('ok' => true, 'id' => $id, 'error' => '');
    }

    public static function remove(int $id): bool
    {
        Db::exec('DELETE FROM notify_log WHERE channel_id = ?', array($id));
        Db::exec('DELETE FROM notify_channels WHERE id = ?', array($id));

        return true;
    }

    /** 带掩码的密钥字段沿用库里的真值 */
    private static function keepMaskedSecrets(int $id, string $type, array $cfg): array
    {
        $old = Db::one('SELECT config FROM notify_channels WHERE id = ?', array($id));

        if ($old === null) {
            return $cfg;
        }

        $oldCfg = json_decode((string) $old['config'], true);

        if (!is_array($oldCfg)) {
            return $cfg;
        }

        foreach (self::TYPES[$type]['fields'] ?? array() as $key => $meta) {
            if (empty($meta['secret'])) {
                continue;
            }

            $new = (string) ($cfg[$key] ?? '');

            if ($new !== '' && strpos($new, '******') !== false) {
                $cfg[$key] = (string) ($oldCfg[$key] ?? '');
            }
        }

        return $cfg;
    }

    /** 只取类型里声明过的字段，顺手补默认值、去掉空白 */
    private static function normalizeConfig(string $type, array $cfg): array
    {
        $def  = self::TYPES[$type]['fields'] ?? array();
        $out  = array();

        foreach ($def as $key => $meta) {
            $v = isset($cfg[$key]) ? trim((string) $cfg[$key]) : '';

            if ($v === '' && isset($meta['default'])) {
                $v = (string) $meta['default'];
            }

            $out[$key] = $v;
        }

        return $out;
    }

    /** @return array<int, string> 缺的字段 label 列表 */
    public static function missingFields(string $type, array $cfg): array
    {
        $missing = array();

        foreach (self::TYPES[$type]['fields'] ?? array() as $key => $meta) {
            if (empty($meta['required'])) {
                continue;
            }

            if (trim((string) ($cfg[$key] ?? '')) === '') {
                $missing[] = (string) $meta['label'];
            }
        }

        return $missing;
    }

    private static function decorate(array $r): array
    {
        $cfg = json_decode((string) $r['config'], true);

        if (!is_array($cfg)) {
            $cfg = array();
        }

        // 密钥字段回前端时打码，避免整页截图泄露；真值只在保存时写入
        $masked = $cfg;

        foreach (self::TYPES[(string) $r['type']]['fields'] ?? array() as $key => $meta) {
            if (!empty($meta['secret']) && trim((string) ($cfg[$key] ?? '')) !== '') {
                $v = (string) $cfg[$key];
                $masked[$key] = strlen($v) <= 8
                    ? str_repeat('*', strlen($v))
                    : substr($v, 0, 4) . str_repeat('*', 6) . substr($v, -4);
            }
        }

        return array(
            'id'         => (int) $r['id'],
            'name'       => (string) $r['name'],
            'type'       => (string) $r['type'],
            'typeLabel'  => (string) (self::TYPES[(string) $r['type']]['label'] ?? $r['type']),
            'enabled'    => (int) $r['enabled'] === 1,
            'config'     => $masked,
            'hasSecret'  => true,
            'created_at' => (int) $r['created_at'],
            'updated_at' => (int) $r['updated_at'],
        );
    }

    // ------------------------------------------------------------------ 定时

    /** 从设置里读排期，缺省值兜底，坏数据不会让整个推送挂掉 */
    public static function schedule(): array
    {
        $raw = Settings::get('notify_schedule', '');

        if ($raw === '' || $raw === null) {
            return self::DEFAULT_SCHEDULE;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return self::DEFAULT_SCHEDULE;
        }

        $out = array();

        foreach (self::DEFAULT_SCHEDULE as $slot => $def) {
            $item  = is_array($decoded[$slot] ?? null) ? $decoded[$slot] : array();
            $time  = (string) ($item['time'] ?? $def['time']);

            if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time)) {
                $time = $def['time'];
            }

            $out[$slot] = array(
                'on'   => !empty($item['on']),
                'time' => $time,
                'label' => self::SLOT_LABEL[$slot] ?? $slot,
            );
        }

        return $out;
    }

    /** @param array<string, array<string, mixed>> $schedule */
    public static function saveSchedule(array $schedule): void
    {
        $out = array();

        foreach (self::DEFAULT_SCHEDULE as $slot => $def) {
            $item  = is_array($schedule[$slot] ?? null) ? $schedule[$slot] : array();
            $time  = (string) ($item['time'] ?? $def['time']);

            if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time)) {
                $time = $def['time'];
            }

            $out[$slot] = array('on' => !empty($item['on']), 'time' => $time);
        }

        Settings::set('notify_schedule', (string) json_encode($out, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 当前时刻该发哪些餐次。cron 每分钟跑一次，所以这里只做「整分匹配」。
     *
     * @param string $now 'H:i'
     * @return array<int, string>
     */
    public static function dueSlots(string $now): array
    {
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $now)) {
            return array();
        }

        $out = array();

        foreach (self::schedule() as $slot => $item) {
            if (!empty($item['on']) && $item['time'] === $now) {
                $out[] = $slot;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ 内容

    /**
     * 渲染某天某餐次的推送内容。没有安排就返回 null（不推空消息打扰人）。
     *
     * 返回两套：title / body 是纯文本兜底（任何渠道都能发），
     * items 是结构化数据——菜名、简介、链接、封面图各归各的字段，
     * 富文本渠道拿它拼卡片，纯文本渠道无视它。
     *
     * @return array{title: string, body: string, date: string, slot: string, url: string, items: array<int, array<string, string>>}|null
     */
    public static function renderMeal(string $date, string $slot): ?array
    {
        $rows = Db::all(
            'SELECT p.*, r.name AS recipe_name, r.total_min, r.difficulty,
                    r.cover, r.description
             FROM meal_plans p
             LEFT JOIN recipes r ON r.id = p.recipe_id
             WHERE p.plan_date = ? AND p.slot = ?
             ORDER BY p.sort ASC, p.id ASC',
            array($date, $slot)
        );

        if ($rows === array()) {
            return null;
        }

        $label = self::SLOT_LABEL[$slot] ?? $slot;
        $lines = array();
        $items = array();
        $i     = 0;
        $rich  = self::richEnabled();

        foreach ($rows as $r) {
            $i++;
            $name = trim((string) ($r['recipe_name'] ?? ''));

            if ($name === '') {
                $name = trim((string) $r['title']) !== '' ? trim((string) $r['title']) : '（未指定菜谱）';
            }

            $bits = array();

            if ((float) $r['servings'] > 0) {
                $bits[] = rtrim(rtrim(number_format((float) $r['servings'], 1), '0'), '.') . ' 人份';
            }

            if ((int) ($r['total_min'] ?? 0) > 0) {
                $bits[] = '约 ' . (int) $r['total_min'] . ' 分钟';
            }

            if (trim((string) ($r['difficulty'] ?? '')) !== '') {
                $bits[] = (string) $r['difficulty'];
            }

            $line = $i . '. ' . $name;

            if ($bits !== array()) {
                $line .= '（' . implode('、', $bits) . '）';
            }

            $lines[] = $line;

            $note = trim((string) $r['note']);

            if ($note !== '') {
                $lines[] = '   备注：' . $note;
            }

            // ---- 结构化版本（富文本渠道吃这个）----
            $recipeId = (int) ($r['recipe_id'] ?? 0);

            $items[] = array(
                'name'    => $name,
                'summary' => $rich ? self::oneLine((string) ($r['description'] ?? ''), 60) : '',
                'url'     => $rich && $recipeId > 0 ? self::absUrl('#/recipes/' . $recipeId) : '',
                'image'   => $rich ? self::absUrl(Images::thumbUrl((string) ($r['cover'] ?? ''))) : '',
                'meta'    => $bits === array() ? '' : implode(' · ', $bits),
                'note'    => $note,
            );
        }

        $title = self::prettyDate($date) . ' ' . $label . ' · ' . count($rows) . ' 道菜';
        $body  = '今日' . $label . '（' . self::prettyDate($date) . "）\n\n"
            . implode("\n", $lines)
            . "\n\n—— 打开菜谱本看做法";

        return array(
            'title' => $title,
            'body'  => $body,
            'date'  => $date,
            'slot'  => $slot,
            'url'   => $rich ? self::absUrl('#/plan') : '',
            'items' => $items,
        );
    }

    /** 摘要压成一行：去换行、挤空格、按字数截断 */
    private static function oneLine(string $text, int $max): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            return '';
        }

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
    }

    private static function prettyDate(string $date): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            return $date;
        }

        return $m[2] . '月' . ltrim($m[3], '0') . '日';
    }

    // ------------------------------------------------------------------ 发送

    /**
     * 往一个渠道发一条消息。
     *
     * @param array<string, mixed> $content renderMeal() 的产出：title / body / url / items
     * @return array{ok: bool, error: string}
     */
    public static function send(array $channel, array $content): array
    {
        $type = (string) ($channel['type'] ?? '');
        $cfg  = $channel['config'] ?? array();

        if (is_string($cfg)) {
            $decoded = json_decode($cfg, true);
            $cfg     = is_array($decoded) ? $decoded : array();
        }

        $content = self::normalizeContent($content);

        try {
            switch ($type) {
                case 'wecom_app':
                    return self::sendWecom($cfg, $content);
                case 'pushplus':
                    return self::sendPushPlus($cfg, $content);
                case 'serverchan':
                    return self::sendServerChan($cfg, $content);
                case 'wxpusher':
                    return self::sendWxPusher($cfg, $content);
                case 'webhook':
                    return self::sendWebhook($cfg, $content);
                default:
                    return array('ok' => false, 'error' => '不认识的渠道类型：' . $type);
            }
        } catch (\Throwable $e) {
            return array('ok' => false, 'error' => $e->getMessage());
        }
    }

    /** 兜底：任何渠道都能拿到的 title / body / items 三件套，缺啥补啥 */
    private static function normalizeContent(array $content): array
    {
        $title = trim((string) ($content['title'] ?? ''));
        $body  = (string) ($content['body'] ?? '');
        $items = isset($content['items']) && is_array($content['items']) ? $content['items'] : array();

        return array(
            'title' => $title,
            'body'  => $body,
            'date'  => (string) ($content['date'] ?? ''),
            'slot'  => (string) ($content['slot'] ?? ''),
            'url'   => (string) ($content['url'] ?? ''),
            'items' => $items,
        );
    }

    /** 能用来做卡片 / 图文的条目：至少要有个能点的链接，不然卡片点了没反应 */
    private static function richItems(array $content): array
    {
        if (!self::richEnabled()) {
            return array();
        }

        $out = array();

        foreach ($content['items'] as $it) {
            if (!is_array($it)) {
                continue;
            }

            if (trim((string) ($it['url'] ?? '')) === '') {
                continue;
            }

            $out[] = array(
                'name'    => (string) ($it['name'] ?? ''),
                'summary' => (string) ($it['summary'] ?? ''),
                'url'     => (string) $it['url'],
                'image'   => (string) ($it['image'] ?? ''),
                'meta'    => (string) ($it['meta'] ?? ''),
                'note'    => (string) ($it['note'] ?? ''),
            );
        }

        return $out;
    }

    /** 第一张能用的封面图（TG 单独发图时用） */
    private static function firstImage(array $items): string
    {
        foreach ($items as $it) {
            if (trim((string) ($it['image'] ?? '')) !== '') {
                return (string) $it['image'];
            }
        }

        return '';
    }

    // ------------------------------------------------------------ 正文渲染

    private static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Markdown 里会把结构搞坏的两个字符 */
    private static function mdText(string $s): string
    {
        return str_replace(array('[', ']'), array('\\[', '\\]'), $s);
    }

    /**
     * Markdown 正文：菜名是链接、封面图用 ![]()、简介跟在后面。
     * 钉钉、Server酱、飞书卡片里的 lark_md 都吃这一套。
     */
    private static function mdContent(array $content, bool $images = true): string
    {
        $items = $content['items'];
        $lines = array();

        if ($items === array()) {
            return $content['body'];
        }

        $i = 0;

        foreach ($items as $it) {
            $i++;
            $name = self::mdText((string) ($it['name'] ?? ''));
            $url  = (string) ($it['url'] ?? '');
            $line = $i . '. ' . ($url !== '' ? '[' . $name . '](' . $url . ')' : $name);

            if (trim((string) ($it['meta'] ?? '')) !== '') {
                $line .= ' · ' . self::mdText((string) $it['meta']);
            }

            $lines[] = $line;

            if ($images && trim((string) ($it['image'] ?? '')) !== '') {
                $lines[] = '   ![' . $name . '](' . (string) $it['image'] . ')';
            }

            if (trim((string) ($it['summary'] ?? '')) !== '') {
                $lines[] = '   ' . self::mdText((string) $it['summary']);
            }

            if (trim((string) ($it['note'] ?? '')) !== '') {
                $lines[] = '   备注：' . self::mdText((string) $it['note']);
            }
        }

        $out = '**' . self::mdText($content['title']) . "**\n\n" . implode("\n", $lines);

        if (trim((string) $content['url']) !== '') {
            $out .= "\n\n[打开菜谱本看做法](" . $content['url'] . ')';
        }

        return $out;
    }

    /**
     * HTML 正文：PushPlus / WxPusher 直接渲染，Telegram 走 parse_mode=HTML。
     * 所有字段都过 htmlspecialchars——菜名是用户自己填的，不能信。
     */
    private static function htmlContent(array $content, bool $images = true): string
    {
        $items = $content['items'];

        if ($items === array()) {
            return nl2br(self::h($content['body']));
        }

        $out = '<b>' . self::h($content['title']) . '</b><br><br>';
        $i   = 0;

        foreach ($items as $it) {
            $i++;
            $name = (string) ($it['name'] ?? '');
            $url  = (string) ($it['url'] ?? '');
            $head = $i . '. ' . ($url !== '' ? '<a href="' . self::h($url) . '">' . self::h($name) . '</a>' : self::h($name));

            if (trim((string) ($it['meta'] ?? '')) !== '') {
                $head .= ' <i>' . self::h((string) $it['meta']) . '</i>';
            }

            $out .= $head . '<br>';

            if ($images && trim((string) ($it['image'] ?? '')) !== '') {
                $out .= '<img src="' . self::h((string) $it['image']) . '" width="240"><br>';
            }

            if (trim((string) ($it['summary'] ?? '')) !== '') {
                $out .= self::h((string) $it['summary']) . '<br>';
            }

            if (trim((string) ($it['note'] ?? '')) !== '') {
                $out .= '备注：' . self::h((string) $it['note']) . '<br>';
            }

            $out .= '<br>';
        }

        if (trim((string) $content['url']) !== '') {
            $out .= '<a href="' . self::h($content['url']) . '">打开菜谱本看做法</a>';
        }

        return $out;
    }

    /** 企微图文卡片。企微上限 8 条，超了就只发前 8 道 */
    private static function wecomArticles(array $items): array
    {
        $out   = array();
        $order = array('①', '②', '③', '④', '⑤', '⑥', '⑦', '⑧');
        $i     = 0;

        foreach (array_slice($items, 0, 8) as $it) {
            $desc = $it['summary'];

            if ($it['meta'] !== '') {
                $desc = $desc === '' ? $it['meta'] : $desc . ' · ' . $it['meta'];
            }

            $a = array(
                'title'       => ($order[$i] ?? ($i + 1) . '.') . ' ' . $it['name'],
                'description' => mb_substr($desc, 0, 120),
                'url'         => $it['url'],
            );

            // 没封面图就别带 picurl，带了反而是个占位灰块
            if ($it['image'] !== '') {
                $a['picurl'] = $it['image'];
            }

            $out[] = $a;
            $i++;
        }

        return $out;
    }

    private static function sendWecom(array $cfg, array $content): array
    {
        $tokenRes = self::request(
            'GET',
            'https://qyapi.weixin.qq.com/cgi-bin/gettoken?corpid='
                . rawurlencode((string) ($cfg['corpid'] ?? ''))
                . '&corpsecret=' . rawurlencode((string) ($cfg['corpsecret'] ?? ''))
        );

        if (!$tokenRes['ok']) {
            return array('ok' => false, 'error' => '取 access_token 失败：' . $tokenRes['error']);
        }

        $json = json_decode((string) $tokenRes['body'], true);
        $tok  = (string) ($json['access_token'] ?? '');

        if ($tok === '') {
            $err = (string) ($json['errmsg'] ?? '返回里没有 access_token');

            return array('ok' => false, 'error' => '取 access_token 失败：' . $err);
        }

        // 有链接就发图文卡片（微信里点得开、还带封面图），否则退回纯文本
        $items   = self::richItems($content);
        $payload = array(
            'touser'  => (string) ($cfg['touser'] ?? '@all') ?: '@all',
            'agentid' => (int) ($cfg['agentid'] ?? 0),
        );

        if ($items !== array()) {
            $payload['msgtype'] = 'news';
            $payload['news']    = array('articles' => self::wecomArticles($items));
        } else {
            $payload['msgtype'] = 'text';
            $payload['text']    = array('content' => $content['title'] . "\n\n" . $content['body']);
        }

        $res = self::request(
            'POST',
            'https://qyapi.weixin.qq.com/cgi-bin/message/send?access_token=' . rawurlencode($tok),
            $payload
        );

        if (!$res['ok']) {
            return array('ok' => false, 'error' => '发送失败：' . $res['error']);
        }

        $back = json_decode((string) $res['body'], true);
        $code = (int) ($back['errcode'] ?? -1);

        if ($code !== 0) {
            return array('ok' => false, 'error' => '发送失败：' . (string) ($back['errmsg'] ?? '未知错误') . '（' . $code . '）');
        }

        return array('ok' => true, 'error' => '');
    }

    private static function sendPushPlus(array $cfg, array $content): array
    {
        // PushPlus 的 html 模板能渲染 <img> 和 <a>，但只有有链接时才值得切
        $rich    = self::richItems($content) !== array();
        $payload = array(
            'token'    => (string) ($cfg['token'] ?? ''),
            'title'    => $content['title'],
            'content'  => $rich ? self::htmlContent($content, true) : $content['body'],
            'template' => $rich ? 'html' : 'txt',
        );

        if (trim((string) ($cfg['topic'] ?? '')) !== '') {
            $payload['topic'] = trim((string) $cfg['topic']);
        }

        $res = self::request('POST', 'https://www.pushplus.plus/send', $payload);

        if (!$res['ok']) {
            return array('ok' => false, 'error' => '发送失败：' . $res['error']);
        }

        $back = json_decode((string) $res['body'], true);
        $code = (int) ($back['code'] ?? -1);

        if ($code !== 200) {
            return array('ok' => false, 'error' => '发送失败：' . (string) ($back['msg'] ?? '未知错误'));
        }

        return array('ok' => true, 'error' => '');
    }

    private static function sendServerChan(array $cfg, array $content): array
    {
        // Server酱的 desp 本身就吃 Markdown，直接把链接和封面图写进去
        $rich = self::richItems($content) !== array();
        $key  = trim((string) ($cfg['sendkey'] ?? ''));
        $res  = self::request(
            'POST',
            'https://sctapi.ftqq.com/' . rawurlencode($key) . '.send',
            array(
                'title' => $content['title'],
                'desp'  => $rich ? self::mdContent($content, true) : $content['body'],
            )
        );

        if (!$res['ok']) {
            return array('ok' => false, 'error' => '发送失败：' . $res['error']);
        }

        $back = json_decode((string) $res['body'], true);
        $code = (int) ($back['code'] ?? -1);

        if ($code !== 0) {
            return array('ok' => false, 'error' => '发送失败：' . (string) ($back['message'] ?? '未知错误'));
        }

        return array('ok' => true, 'error' => '');
    }

    private static function sendWxPusher(array $cfg, array $content): array
    {
        $uids = array();

        foreach (explode(',', (string) ($cfg['uid'] ?? '')) as $u) {
            $u = trim($u);

            if ($u !== '') {
                $uids[] = $u;
            }
        }

        $topics = array();

        foreach (explode(',', (string) ($cfg['topicid'] ?? '')) as $t) {
            $t = trim($t);

            if ($t !== '') {
                $topics[] = (int) $t;
            }
        }

        // contentType：1 纯文本、2 HTML。有链接就上 HTML，微信里菜名可点、图能显示
        $rich    = self::richItems($content) !== array();
        $payload = array(
            'appToken'    => (string) ($cfg['apptoken'] ?? ''),
            'content'     => $rich
                ? self::htmlContent($content, true)
                : $content['title'] . "\n\n" . $content['body'],
            'summary'     => mb_substr($content['title'], 0, 96),
            'contentType' => $rich ? 2 : 1,
            'uids'        => $uids,
            'topicIds'    => $topics,
        );

        $res = self::request('POST', 'https://wxpusher.zjiecode.com/api/send/message', $payload);

        if (!$res['ok']) {
            return array('ok' => false, 'error' => '发送失败：' . $res['error']);
        }

        $back = json_decode((string) $res['body'], true);
        $code = (int) ($back['code'] ?? -1);

        if ($code !== 1000) {
            return array('ok' => false, 'error' => '发送失败：' . (string) ($back['msg'] ?? '未知错误'));
        }

        return array('ok' => true, 'error' => '');
    }

    private static function sendWebhook(array $cfg, array $content): array
    {
        $url = trim((string) ($cfg['url'] ?? ''));

        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return array('ok' => false, 'error' => 'Webhook 地址不合法');
        }

        $format = trim((string) ($cfg['format'] ?? ''));

        if ($format === '' || $format === 'auto') {
            $format = self::detectWebhookFormat($url);
        }

        switch ($format) {
            case 'feishu':
                return self::sendHookFeishu($url, $cfg, $content);
            case 'dingtalk':
                return self::sendHookDingtalk($url, $cfg, $content);
            case 'wecom_bot':
                return self::sendHookWecomBot($url, $content);
            case 'telegram':
                return self::sendHookTelegram($url, $cfg, $content);
            default:
                return self::sendHookGeneric($url, $cfg, $content);
        }
    }

    /** 按域名猜协议——用户只要把机器人地址粘进来就行，不强制选格式 */
    private static function detectWebhookFormat(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        if (strpos($host, 'feishu.cn') !== false || strpos($host, 'larksuite.com') !== false) {
            return 'feishu';
        }

        if (strpos($host, 'dingtalk.com') !== false) {
            return 'dingtalk';
        }

        if (strpos($host, 'qq.com') !== false && strpos($path, '/cgi-bin/webhook/send') !== false) {
            return 'wecom_bot';
        }

        if (strpos($host, 'telegram.org') !== false) {
            return 'telegram';
        }

        return 'generic';
    }

    /**
     * 飞书群机器人。有链接时发 interactive 卡片（lark_md 里菜名可点），否则发纯文本。
     *
     * 注意：飞书卡片里的图片只能用 img_key（要先调上传接口，群机器人没这权限），
     * 所以飞书这条路不带封面图——链接和简介照样有，只是没图。
     */
    private static function sendHookFeishu(string $url, array $cfg, array $content): array
    {
        $items = self::richItems($content);

        if ($items !== array()) {
            $payload = array(
                'msg_type' => 'interactive',
                'card'     => array(
                    'config'   => array('wide_screen_mode' => true),
                    'header'   => array(
                        'title'    => array('tag' => 'plain_text', 'content' => mb_substr($content['title'], 0, 100)),
                        'template' => 'orange',
                    ),
                    'elements' => array(
                        array(
                            'tag'  => 'div',
                            'text' => array('tag' => 'lark_md', 'content' => self::mdContent($content, false)),
                        ),
                        array('tag' => 'hr'),
                        array(
                            'tag'      => 'note',
                            'elements' => array(
                                array('tag' => 'plain_text', 'content' => '来自家常菜谱 · 点菜名看做法'),
                            ),
                        ),
                    ),
                ),
            );
        } else {
            $payload = array(
                'msg_type' => 'text',
                'content'  => array('text' => $content['title'] . "\n\n" . $content['body']),
            );
        }

        $secret = trim((string) ($cfg['secret'] ?? ''));

        if ($secret !== '') {
            // 加签是拿「时间戳\n密钥」当 key 对空串做 HMAC，跟卡片还是文本无关
            $ts = (string) time();
            $payload['timestamp'] = $ts;
            $payload['sign']      = base64_encode(hash_hmac('sha256', '', $ts . "\n" . $secret, true));
        }

        $res = self::request('POST', $url, $payload);

        if (!$res['ok']) {
            return array('ok' => false, 'error' => '发送失败：' . $res['error']);
        }

        $back = json_decode((string) $res['body'], true);
        $code = (int) ($back['code'] ?? -1);

        if ($code !== 0) {
            return array('ok' => false, 'error' => '飞书返回错误：' . (string) ($back['msg'] ?? '未知错误') . '（' . $code . '）');
        }

        return array('ok' => true, 'error' => '');
    }

    /**
     * 钉钉群机器人：有链接走 markdown（图、链接、简介全都有），否则纯文本。
     * 没走 feedCard 是因为它每条只有「标题+图+链接」，放不下简介。
     */
    private static function sendHookDingtalk(string $url, array $cfg, array $content): array
    {
        $secret = trim((string) ($cfg['secret'] ?? ''));

        if ($secret !== '') {
            $ts   = (string) round(microtime(true) * 1000);
            $sign = rawurlencode(base64_encode(hash_hmac('sha256', $ts . "\n" . $secret, $secret, true)));
            $url .= (strpos($url, '?') === false ? '?' : '&') . 'timestamp=' . $ts . '&sign=' . $sign;
        }

        $rich = self::richItems($content) !== array();

        $payload = $rich
            ? array(
                'msgtype'  => 'markdown',
                'markdown' => array(
                    'title' => $content['title'],
                    'text'  => self::mdContent($content, true),
                ),
            )
            : array(
                'msgtype' => 'text',
                'text'    => array('content' => $content['title'] . "\n\n" . $content['body']),
            );

        $res = self::request('POST', $url, $payload);

        if (!$res['ok']) {
            return array('ok' => false, 'error' => '发送失败：' . $res['error']);
        }

        $back = json_decode((string) $res['body'], true);
        $code = (int) ($back['errcode'] ?? -1);

        if ($code !== 0) {
            return array('ok' => false, 'error' => '钉钉返回错误：' . (string) ($back['errmsg'] ?? '未知错误') . '（' . $code . '）');
        }

        return array('ok' => true, 'error' => '');
    }

    /** 企微 text 消息正文上限 2048 字节，按字节截断（不是按字数） */
    private static function clamp2048(string $text): string
    {
        if (strlen($text) <= 2000) {
            return $text;
        }

        return mb_substr($text, 0, 600) . "\n…（内容过长已截断）";
    }

    /**
     * 企业微信群机器人：有链接就发 news 图文（每道菜一张小图，点进去是菜谱），
     * 否则退回 text。纯文本那条路径仍有 2048 字节上限，超了要截断。
     */
    private static function sendHookWecomBot(string $url, array $content): array
    {
        $items = self::richItems($content);

        $payload = $items !== array()
            ? array('msgtype' => 'news', 'news' => array('articles' => self::wecomArticles($items)))
            : array(
                'msgtype' => 'text',
                'text'    => array('content' => self::clamp2048($content['title'] . "\n\n" . $content['body'])),
            );

        $res = self::request('POST', $url, $payload);

        if (!$res['ok']) {
            return array('ok' => false, 'error' => '发送失败：' . $res['error']);
        }

        $back = json_decode((string) $res['body'], true);
        $code = (int) ($back['errcode'] ?? -1);

        if ($code !== 0) {
            return array('ok' => false, 'error' => '企微机器人返回错误：' . (string) ($back['errmsg'] ?? '未知错误') . '（' . $code . '）');
        }

        return array('ok' => true, 'error' => '');
    }

    /**
     * Telegram Bot：正文走 HTML（菜名是 <a>），封面图单独用 sendPhoto 发——
     * TG 的 HTML 只认 b/i/a/code 那几个标签，没有 <img> 这一说。
     */
    private static function sendHookTelegram(string $url, array $cfg, array $content): array
    {
        $chatId = trim((string) ($cfg['chat_id'] ?? ''));

        if ($chatId === '') {
            return array('ok' => false, 'error' => 'Telegram 需要 Chat ID（给 @userinfobot 发条消息就能查到）');
        }

        // 只粘 bot 令牌地址（少了 /sendMessage）也帮补全；代理域名同样适用
        $url = rtrim($url, '/');

        if (substr(strtolower($url), -12) !== '/sendmessage') {
            $url .= '/sendMessage';
        }

        $rich    = self::richItems($content) !== array();
        $payload = array(
            'chat_id'                  => $chatId,
            'text'                     => $rich
                ? self::htmlContent($content, false)
                : $content['title'] . "\n\n" . $content['body'],
            'disable_web_page_preview' => true,
        );

        if ($rich) {
            $payload['parse_mode'] = 'HTML';
        }

        $res = self::request('POST', $url, $payload);

        if (!$res['ok']) {
            return array('ok' => false, 'error' => '发送失败：' . $res['error']);
        }

        $back = json_decode((string) $res['body'], true);

        if (empty($back['ok'])) {
            return array('ok' => false, 'error' => 'Telegram 返回错误：' . (string) ($back['description'] ?? '未知错误'));
        }

        // 图没发出去不算整条失败——文字已经到了，为一张图把推送判死不值当
        $img = $rich ? self::firstImage($content['items']) : '';

        if ($img !== '') {
            $photoUrl = preg_replace('#/sendMessage$#i', '/sendPhoto', $url);
            $pr       = self::request('POST', (string) $photoUrl, array('chat_id' => $chatId, 'photo' => $img));

            if (!$pr['ok']) {
                Logs::add('notify_photo_fail', 'telegram', $pr['error']);
            }
        }

        return array('ok' => true, 'error' => '');
    }

    /**
     * 通用 JSON：对接 n8n / 自建转发服务，带 HMAC 签名头。
     * items 是结构化的（菜名 / 简介 / 链接 / 封面图分开），下游想怎么渲染都行。
     */
    private static function sendHookGeneric(string $url, array $cfg, array $content): array
    {
        $payload = array(
            'event'     => 'meal_plan.reminder',
            'title'     => $content['title'],
            'body'      => $content['body'],
            'timestamp' => time(),
        );

        // 站外只有配了站点地址才有意义，没配就不塞空字段
        if (trim((string) $content['url']) !== '') {
            $payload['url'] = (string) $content['url'];
        }

        $items = self::richItems($content);

        if ($items !== array()) {
            $payload['items'] = $items;
        }

        $payload['html'] = $items !== array() ? self::htmlContent($content, true) : '';
        $payload['markdown'] = $items !== array() ? self::mdContent($content, true) : '';

        $raw     = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = array('Content-Type: application/json');
        $secret  = trim((string) ($cfg['secret'] ?? ''));

        if ($secret !== '') {
            // 跟 GitHub 一个套路：对原始 body 做 HMAC-SHA256，方便对端用 hash_equals 校验
            $headers[] = 'X-RH-Signature: sha256=' . hash_hmac('sha256', (string) $raw, $secret);
        }

        $headers[] = 'X-RH-Event: meal_plan.reminder';

        $res = self::request('POST', $url, (string) $raw, $headers);

        if (!$res['ok']) {
            return array('ok' => false, 'error' => '发送失败：' . $res['error']);
        }

        $status = (int) $res['status'];

        if ($status < 200 || $status >= 300) {
            return array('ok' => false, 'error' => '对方返回状态码 ' . $status);
        }

        return array('ok' => true, 'error' => '');
    }

    /**
     * 发一个 HTTP 请求。有 curl 用 curl，没有就用 stream 兜底——
     * 有些精简镜像没编译 curl，但总不至于连 fopen 都砍了。
     *
     * @param array<string, mixed>|string|null $body
     * @param array<int, string>               $headers
     * @return array{ok: bool, status: int, body: string, error: string}
     */
    private static function request(string $method, string $url, $body = null, array $headers = array(), int $timeout = 10): array
    {
        $raw = null;

        if ($body !== null) {
            if (is_array($body)) {
                // 没显式给 Content-Type 的数组一律按 JSON 发
                $hasCt = false;

                foreach ($headers as $h) {
                    if (stripos($h, 'Content-Type') === 0) {
                        $hasCt = true;
                        break;
                    }
                }

                if (!$hasCt) {
                    $headers[] = 'Content-Type: application/json';
                }

                $raw = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                $raw = (string) $body;
            }
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);

            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
            ));

            if ($raw !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $raw);
            }

            if ($headers !== array()) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }

            $out    = curl_exec($ch);
            $err    = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_close($ch);

            if ($out === false) {
                return array('ok' => false, 'status' => 0, 'body' => '', 'error' => $err !== '' ? $err : '请求失败');
            }

            return array('ok' => $status >= 200 && $status < 400, 'status' => $status, 'body' => (string) $out, 'error' => '');
        }

        // ---- 没有 curl 的兜底 ----
        $ctx = stream_context_create(array(
            'http' => array(
                'method'        => $method,
                'header'        => $headers === array() ? '' : implode("\r\n", $headers),
                'content'       => $raw,
                'timeout'       => $timeout,
                'ignore_errors' => true,
            ),
        ));

        $out = @file_get_contents($url, false, $ctx);

        if ($out === false) {
            $err = error_get_last();

            return array('ok' => false, 'status' => 0, 'body' => '', 'error' => $err['message'] ?? '请求失败');
        }

        $status = 200;

        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', (string) $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }

        return array('ok' => $status >= 200 && $status < 400, 'status' => $status, 'body' => (string) $out, 'error' => '');
    }

    // ------------------------------------------------------------------ 去重与日志

    public static function dedupeKey(string $date, string $slot): string
    {
        return $date . '|' . $slot;
    }

    public static function alreadySent(int $channelId, string $dedupeKey): bool
    {
        return Db::value(
            'SELECT COUNT(*) FROM notify_log WHERE channel_id = ? AND dedupe_key = ? AND status = ?',
            array($channelId, $dedupeKey, 'ok')
        ) > 0;
    }

    public static function log(array $row): void
    {
        Db::exec(
            'INSERT INTO notify_log (channel_id, dedupe_key, slot, plan_date, title, body, status, error, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array(
                (int) ($row['channel_id'] ?? 0),
                (string) ($row['dedupe_key'] ?? ''),
                (string) ($row['slot'] ?? ''),
                (string) ($row['plan_date'] ?? ''),
                (string) ($row['title'] ?? ''),
                (string) ($row['body'] ?? ''),
                (string) ($row['status'] ?? 'fail'),
                mb_substr((string) ($row['error'] ?? ''), 0, 500),
                time(),
            )
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function logs(int $limit = 50): array
    {
        $rows = Db::all(
            'SELECT l.*, c.name AS channel_name
             FROM notify_log l
             LEFT JOIN notify_channels c ON c.id = l.channel_id
             ORDER BY l.id DESC
             LIMIT ' . max(1, min(200, $limit))
        );

        foreach ($rows as $i => $r) {
            $rows[$i]['id']         = (int) $r['id'];
            $rows[$i]['channel_id'] = (int) $r['channel_id'];
            $rows[$i]['created_at'] = (int) $r['created_at'];
        }

        return $rows;
    }

    public static function logClear(): int
    {
        $n = (int) Db::value('SELECT COUNT(*) FROM notify_log');

        Db::exec('DELETE FROM notify_log');

        return $n;
    }

    /**
     * 把某个餐次推给所有启用渠道。cron 入口和测试都走这里。
     *
     * @return array{sent: int, failed: int, skipped: int, details: array<int, array<string, mixed>>}
     */
    public static function pushSlot(string $date, string $slot, bool $dryRun = false, string $forceKey = ''): array
    {
        $content = self::renderMeal($date, $slot);

        if ($content === null) {
            return array('sent' => 0, 'failed' => 0, 'skipped' => 0, 'details' => array(), 'empty' => true);
        }

        $key     = $forceKey !== '' ? $forceKey : self::dedupeKey($date, $slot);
        $details = array();
        $sent    = 0;
        $failed  = 0;
        $skipped = 0;

        foreach (self::channels(true) as $ch) {
            if (!$dryRun && self::alreadySent((int) $ch['id'], $key)) {
                $skipped++;
                $details[] = array('channel' => $ch['name'], 'status' => 'skipped', 'error' => '这个餐次已经推过了');

                continue;
            }

            if ($dryRun) {
                $details[] = array('channel' => $ch['name'], 'status' => 'dry', 'error' => '');

                continue;
            }

            $res = self::send(self::channelRaw((int) $ch['id']), $content);

            self::log(array(
                'channel_id' => (int) $ch['id'],
                'dedupe_key' => $key,
                'slot'       => $slot,
                'plan_date'  => $date,
                'title'      => $content['title'],
                'body'       => $content['body'],
                'status'     => $res['ok'] ? 'ok' : 'fail',
                'error'      => $res['error'],
            ));

            if ($res['ok']) {
                $sent++;
            } else {
                $failed++;
                Logs::add('notify_fail', (string) $ch['name'], $res['error']);
            }

            $details[] = array('channel' => $ch['name'], 'status' => $res['ok'] ? 'ok' : 'fail', 'error' => $res['error']);
        }

        return array('sent' => $sent, 'failed' => $failed, 'skipped' => $skipped, 'details' => $details, 'empty' => false);
    }

    /** 取带真值的渠道（发送时用，不能拿脱敏那份去请求） */
    public static function channelRaw(int $id): array
    {
        $r = Db::one('SELECT * FROM notify_channels WHERE id = ?', array($id));

        if ($r === null) {
            return array();
        }

        $cfg = json_decode((string) $r['config'], true);

        return array(
            'id'     => (int) $r['id'],
            'name'   => (string) $r['name'],
            'type'   => (string) $r['type'],
            'config' => is_array($cfg) ? $cfg : array(),
        );
    }
}
