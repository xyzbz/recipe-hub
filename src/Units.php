<?php
declare(strict_types=1);

namespace RH;

/**
 * 中式计量单位。
 *
 * 这是本系统跟 Mealie 最本质的差别所在：
 *   - 基准单位只有三个：克（重量）、毫升（体积）、个（计数）
 *   - 「斤 = 500 克」「两 = 50 克」这种中式市制是硬编码的换算，不是可选插件
 *   - 「少许 / 适量 / 一把」这类模糊量词**不参与任何计算**，采购清单里单列并标灰
 *
 * 合并规则（采购清单汇总时用）：
 *   重量 / 体积 —— 换算到基准单位后可以相加（2斤 + 300克 = 1300 克）
 *   计数       —— 只有单位字面完全一致才合并（「2个」和「3片」是两回事）
 *   模糊       —— 永不合并
 */
final class Units
{
    public const WEIGHT = 'weight';
    public const VOLUME = 'volume';
    public const COUNT  = 'count';
    public const FUZZY  = 'fuzzy';
    public const OTHER  = 'other';

    /** 单位 => [类别, 换算到基准单位的倍数] */
    private const TABLE = array(
        // ---- 重量，基准：克 ----
        '克'   => array(self::WEIGHT, 1.0),
        'g'    => array(self::WEIGHT, 1.0),
        'G'    => array(self::WEIGHT, 1.0),
        '千克' => array(self::WEIGHT, 1000.0),
        '公斤' => array(self::WEIGHT, 1000.0),
        'kg'   => array(self::WEIGHT, 1000.0),
        'KG'   => array(self::WEIGHT, 1000.0),
        '斤'   => array(self::WEIGHT, 500.0),
        '市斤' => array(self::WEIGHT, 500.0),
        '两'   => array(self::WEIGHT, 50.0),
        '市两' => array(self::WEIGHT, 50.0),
        '钱'   => array(self::WEIGHT, 5.0),
        '毫克' => array(self::WEIGHT, 0.001),
        'mg'   => array(self::WEIGHT, 0.001),

        // ---- 体积，基准：毫升 ----
        '毫升' => array(self::VOLUME, 1.0),
        'ml'   => array(self::VOLUME, 1.0),
        'ML'   => array(self::VOLUME, 1.0),
        '升'   => array(self::VOLUME, 1000.0),
        'l'    => array(self::VOLUME, 1000.0),
        'L'    => array(self::VOLUME, 1000.0),
        '大勺' => array(self::VOLUME, 15.0),
        '汤勺' => array(self::VOLUME, 15.0),
        '汤匙' => array(self::VOLUME, 15.0),
        '勺'   => array(self::VOLUME, 15.0),
        '小勺' => array(self::VOLUME, 5.0),
        '茶匙' => array(self::VOLUME, 5.0),
        'tsp'  => array(self::VOLUME, 5.0),
        '碗'   => array(self::VOLUME, 250.0),
        '杯'   => array(self::VOLUME, 240.0),

        // ---- 计数，不跨单位合并 ----
        '个'   => array(self::COUNT, 1.0),
        '只'   => array(self::COUNT, 1.0),
        '条'   => array(self::COUNT, 1.0),
        '根'   => array(self::COUNT, 1.0),
        '片'   => array(self::COUNT, 1.0),
        '瓣'   => array(self::COUNT, 1.0),
        '颗'   => array(self::COUNT, 1.0),
        '粒'   => array(self::COUNT, 1.0),
        '把'   => array(self::COUNT, 1.0),
        '块'   => array(self::COUNT, 1.0),
        '段'   => array(self::COUNT, 1.0),
        '张'   => array(self::COUNT, 1.0),
        '束'   => array(self::COUNT, 1.0),
        '盒'   => array(self::COUNT, 1.0),
        '袋'   => array(self::COUNT, 1.0),
        '包'   => array(self::COUNT, 1.0),
        '瓶'   => array(self::COUNT, 1.0),
        '罐'   => array(self::COUNT, 1.0),
        '听'   => array(self::COUNT, 1.0),
        '扎'   => array(self::COUNT, 1.0),
        '打'   => array(self::COUNT, 12.0),

        // ---- 模糊量词：能选，但不参与运算 ----
        '少许'   => array(self::FUZZY, 0.0),
        '适量'   => array(self::FUZZY, 0.0),
        '少量'   => array(self::FUZZY, 0.0),
        '些许'   => array(self::FUZZY, 0.0),
        '一些'   => array(self::FUZZY, 0.0),
        '若干'   => array(self::FUZZY, 0.0),
        '一点'   => array(self::FUZZY, 0.0),
        '按需'   => array(self::FUZZY, 0.0),
        '随意'   => array(self::FUZZY, 0.0),
        '随口味' => array(self::FUZZY, 0.0),
    );

    /** 基准单位的中文名 */
    private const BASE_LABEL = array(
        self::WEIGHT => '克',
        self::VOLUME => '毫升',
        self::COUNT  => '个',
    );

    /** 前端下拉里按这个顺序、这些分组展示 */
    private const PREFERRED = array(
        self::WEIGHT => array('克', '斤', '两', '千克', '毫克'),
        self::VOLUME => array('毫升', '升', '大勺', '小勺', '茶匙', '碗', '杯'),
        self::COUNT  => array('个', '只', '条', '根', '片', '瓣', '颗', '粒', '块', '段', '把', '张', '盒', '袋', '包', '瓶', '罐', '束'),
        self::FUZZY  => array('少许', '适量', '少量', '一些', '若干', '按需'),
    );

    /** 去掉首尾空白与全角空格 */
    public static function norm(?string $unit): string
    {
        return self::stripModifier(trim(str_replace(array("\u{3000}", ' '), '', (string) $unit)));
    }

    /**
     * 「1 小块」「2 大段」这类写法归到「块」「段」。
     *
     * 只在「整串不是已知单位」且「去掉修饰后是已知的单字计数单位」时生效，
     * 否则会把「小勺」错认成「勺」（一个 5ml 一个 15ml，量差三倍）。
     */
    private static function stripModifier(string $unit): string
    {
        if ($unit === '' || mb_strlen($unit) !== 2 || isset(self::TABLE[$unit])) {
            return $unit;
        }

        $head = mb_substr($unit, 0, 1);
        $tail = mb_substr($unit, 1, 1);

        if (($head === '大' || $head === '小') && isset(self::TABLE[$tail]) && self::TABLE[$tail][0] === self::COUNT) {
            return $tail;
        }

        return $unit;
    }

    /** 单位本身在不在表里（不做修饰剥离，供 stripModifier 内部用） */
    public static function has(string $unit): bool
    {
        return isset(self::TABLE[trim(str_replace(array("\u{3000}", ' '), '', $unit))]);
    }

    /** 单位类别：weight / volume / count / fuzzy / other */
    public static function kind(?string $unit): string
    {
        $u = self::norm($unit);

        return self::TABLE[$u][0] ?? self::OTHER;
    }

    /** 换算倍数（相对基准单位）；未知单位返回 0 */
    public static function factor(?string $unit): float
    {
        $u = self::norm($unit);

        return self::TABLE[$u][1] ?? 0.0;
    }

    public static function isFuzzy(?string $unit): bool
    {
        return self::kind($unit) === self::FUZZY;
    }

    /** 重量 / 体积 / 计数——能参与计算的类别 */
    public static function isMeasurable(?string $unit): bool
    {
        $k = self::kind($unit);

        return $k === self::WEIGHT || $k === self::VOLUME || $k === self::COUNT;
    }

    /**
     * 换算到基准单位。
     * @return array|null null = 不可计算（模糊量词、未知单位、没填数量）
     */
    public static function toBase($amount, ?string $unit): ?array
    {
        if ($amount === null || $amount === '' || !is_numeric($amount)) {
            return null;
        }

        $u = self::norm($unit);

        if (!isset(self::TABLE[$u])) {
            return null;
        }

        $kind = self::TABLE[$u][0];

        if ($kind === self::FUZZY) {
            return null;
        }

        return array(
            'kind'  => $kind,
            'value' => (float) $amount * self::TABLE[$u][1],
            // 计数类不跨单位合并，所以要把原单位带上当合并键的一部分
            'unit'  => $kind === self::COUNT ? $u : '',
        );
    }

    /** 基准单位 → 别的单位 */
    public static function fromBase(float $value, string $kind, string $to): float
    {
        $f = self::factor($to);

        return $f > 0 ? $value / $f : $value;
    }

    /**
     * 把基准总量排版成给人看的字符串。
     * 中式习惯：超过 500 克同时给出斤，超过 1000 毫升同时给出升。
     */
    public static function formatTotal(string $kind, float $value): string
    {
        $value = round($value, 2);

        if ($kind === self::COUNT) {
            return self::trimNum($value) . ' 个';
        }

        if ($kind === self::VOLUME) {
            $base = self::trimNum($value) . ' 毫升';

            if ($value >= 1000) {
                $base .= '（' . self::trimNum(round($value / 1000, 2)) . ' 升）';
            }

            return $base;
        }

        // 重量
        $base = self::trimNum($value) . ' 克';

        if ($value >= 500) {
            $base .= '（' . self::trimNum(round($value / 500, 2)) . ' 斤）';
        }

        return $base;
    }

    public static function baseLabel(string $kind): string
    {
        return self::BASE_LABEL[$kind] ?? '';
    }

    /** 数字转字符串，去掉多余的小数零 */
    public static function trimNum(float $n): string
    {
        if (abs($n - round($n)) < 0.001) {
            return (string) (int) round($n);
        }

        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    /**
     * 所有单位名拼成的正则交替式，按长度倒序。
     * 长名优先很重要：「千克」不能被「克」截胡，「大勺」不能被「勺」截胡。
     * 解析导入的菜谱行时用这个，比「取前三个字符」靠谱得多。
     */
    /**
     * 生成「已知单位」的正则交替式，长的排前面。
     *
     * 另外带上「大/小 + 单字计数单位」的形式（小块、大段…），
     * 好让「生姜 1 小块」这种自由文本也能拆干净。
     * 顺序上长名优先，所以「小勺」不会被「勺」抢走。
     */
    public static function pattern(): string
    {
        $names = array_keys(self::TABLE);

        foreach (self::TABLE as $name => $meta) {
            if ($meta[0] !== self::COUNT || mb_strlen($name) !== 1) {
                continue;
            }

            $names[] = '大' . $name;
            $names[] = '小' . $name;
        }

        usort($names, static function (string $a, string $b): int {
            return mb_strlen($b) <=> mb_strlen($a);
        });

        $out = array();

        foreach ($names as $n) {
            $out[] = preg_quote($n, '#');
        }

        return implode('|', $out);
    }

    /**
     * 给前端的单位选项，按类别分组。
     * @return array
     */
    public static function groups(): array
    {
        $labels = array(
            self::WEIGHT => '重量',
            self::VOLUME => '体积',
            self::COUNT  => '计数',
            self::FUZZY  => '模糊（不参与汇总）',
        );

        $out = array();

        foreach (self::PREFERRED as $kind => $list) {
            $out[] = array('kind' => $kind, 'label' => $labels[$kind], 'units' => $list);
        }

        return $out;
    }

    /**
     * 同一批食材能否合并成一行。
     * @return string 合并键；返回 '' 表示不能合并
     */
    public static function mergeKey(string $name, $amount, ?string $unit): string
    {
        $base = self::toBase($amount, $unit);

        if ($base === null) {
            return '';
        }

        return mb_strtolower(trim($name)) . '|' . $base['kind'] . '|' . $base['unit'];
    }
}
