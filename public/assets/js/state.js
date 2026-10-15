/**
 * 全局状态。单独放一个模块，避免视图和外壳互相 import 成环。
 */

export const state = {
    meta: null,
    user: null,
    canWrite: false,
    isAdmin: false,
};

export function setMeta(meta) {
    state.meta = meta;
    state.user = meta.user;
    state.canWrite = !!meta.canWrite;
    state.isAdmin = !!meta.isAdmin;
}

export function tagOptions() {
    const tags = (state.meta && state.meta.tags) || [];

    return tags.filter((t) => t.kind === 'tag');
}

export function cuisineOptions() {
    return (state.meta && state.meta.cuisines) || [];
}

export function unitGroups() {
    return (state.meta && state.meta.units) || [];
}

export function slots() {
    return (state.meta && state.meta.slots) || [
        { key: 'breakfast', label: '早餐' },
        { key: 'lunch', label: '午餐' },
        { key: 'dinner', label: '晚餐' },
        { key: 'snack', label: '加餐' },
    ];
}

export function difficulties() {
    return (state.meta && state.meta.difficulties) || ['简单', '中等', '复杂'];
}

export function slotLabel(key) {
    const s = slots().filter((x) => x.key === key)[0];

    return s ? s.label : key;
}

/** 单位类别，用于判断能不能做乘法缩放 */
export function unitKind(unit) {
    const groups = unitGroups();

    for (const g of groups) {
        if (g.units.indexOf(unit) >= 0) {
            return g.kind;
        }
    }

    return 'other';
}

/** 把「数量 + 单位」按倍数缩放后重新排版 */
export function scaleQty(amount, unit, factor) {
    if (amount === null || amount === undefined || !isFinite(Number(amount))) {
        return null;
    }

    const k = unitKind(unit);

    if (k === 'fuzzy' || k === 'other') {
        return null;
    }

    const v = Number(amount) * factor;

    return { amount: Math.round(v * 100) / 100, unit: unit || '' };
}
