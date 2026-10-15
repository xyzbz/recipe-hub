import { api, qs } from '../api.js';
import { html, raw, esc, $, $$, toast, toastError, confirmBox, formBox, fmtDate, fmtAgo, fmtDuration } from '../dom.js';
import { unitGroups, state } from '../state.js';

function unitOpts(value) {
    let opts = '<option value="">—</option>';

    unitGroups().forEach((g) => {
        opts += '<optgroup label="' + esc(g.label) + '">';

        g.units.forEach((u) => {
            opts +=
                '<option value="' +
                esc(u) +
                '"' +
                (String(u) === String(value) ? ' selected' : '') +
                '>' +
                esc(u) +
                '</option>';
        });

        opts += '</optgroup>';
    });

    return opts;
}

export async function renderPantry(root) {
    let q = '';
    let location_ = '';
    let expiring = false;

    root.innerHTML = html`
    <div class="page-head">
        <h2 class="page-title">家里有什么</h2>
        <div class="page-actions">
            <button class="btn btn-sm" id="btn-suggest" type="button">能做啥菜</button>
            <button class="btn btn-primary btn-sm" id="btn-add" type="button">+ 加食材</button>
        </div>
    </div>

    <div class="stat-row" id="stat-row"></div>

    <div class="filters">
        <div class="filter-row">
            <input type="search" id="p-q" class="grow" placeholder="找食材…">
            <select id="p-loc">
                <option value="">全部位置</option>
                ${raw(
                    ((state.meta && state.meta.pantryLocations) || [])
                        .map((l) => '<option value="' + esc(l) + '">' + esc(l) + '</option>')
                        .join('')
                )}
            </select>
            <label class="check inline"><input type="checkbox" id="p-exp"><span>只看快过期的</span></label>
        </div>
    </div>

    <div id="suggest-box" hidden></div>
    <div id="pantry-list" class="results"></div>`;

    const listBox = document.getElementById('pantry-list');
    const statRow = document.getElementById('stat-row');

    async function load() {
        listBox.classList.add('loading');

        try {
            const res = await api.get('/api/pantry' + qs({ q: q, location: location_, expiring: expiring, expiring_days: 7 }));
            renderStats(res.stats);
            renderList(res.items);
        } catch (e) {
            listBox.innerHTML = html`<div class="empty"><p>${e.message}</p></div>`;
        } finally {
            listBox.classList.remove('loading');
        }
    }

    function renderStats(s) {
        statRow.innerHTML = html`
        <div class="stat"><em>在库食材</em><strong>${s.total}</strong></div>
        <div class="stat${s.soon ? ' warn' : ''}"><em>3 天内到期</em><strong>${s.soon}</strong></div>
        <div class="stat${s.expired ? ' danger' : ''}"><em>已经过期</em><strong>${s.expired}</strong></div>
        <div class="stat"><em>记了保质期</em><strong>${s.with_exp}</strong></div>`;
    }

    function renderList(items) {
        if (!items.length) {
            listBox.innerHTML = html`<div class="empty">
                <p class="empty-mark">🧺</p>
                <p>库存是空的。</p>
                <p class="dim">记一记家里有什么，就能反查「这些能做啥菜」。</p>
            </div>`;
            return;
        }

        const rows = items
            .map((i) => {
                const badge =
                    i.state === 'expired'
                        ? '<span class="badge danger">过期 ' + Math.abs(i.days_left) + ' 天</span>'
                        : i.state === 'soon'
                        ? '<span class="badge warn">' + (i.days_left === 0 ? '今天到期' : i.days_left + ' 天后到期') + '</span>'
                        : i.state === 'fresh'
                        ? '<span class="badge ok">' + i.days_left + ' 天后到期</span>'
                        : '';

                return html`
                <div class="row-item${i.state === 'expired' ? ' expired' : i.state === 'soon' ? ' soon' : ''}" data-id="${i.id}">
                    <div class="row-main">
                        <span class="row-title">${i.name}</span>
                        <span class="row-qty">${i.display || '—'}</span>
                    </div>
                    <div class="row-meta">
                        <span class="dim">${i.location}</span>
                        ${raw(badge)}
                        ${i.bought_at ? html`<span class="dim">${fmtDate(i.bought_at)} 买的</span>` : raw('')}
                        ${i.note ? html`<span class="dim">${i.note}</span>` : raw('')}
                    </div>
                    <div class="row-tools">
                        <button class="icon-btn" data-act="minus" title="用掉一些">−</button>
                        <button class="icon-btn" data-act="plus" title="补货">＋</button>
                        <button class="btn btn-sm btn-ghost" data-act="edit">改</button>
                        <button class="icon-btn danger" data-act="del" title="删除">✕</button>
                    </div>
                </div>`;
            })
            .join('');

        listBox.innerHTML = html`<div class="rows-list">${raw(rows)}</div>`;
    }

    // ---- 交互

    const qEl = document.getElementById('p-q');
    let tmr = 0;

    qEl.addEventListener('input', () => {
        clearTimeout(tmr);
        tmr = setTimeout(() => {
            q = qEl.value.trim();
            load();
        }, 260);
    });

    document.getElementById('p-loc').addEventListener('change', (e) => {
        location_ = e.target.value;
        load();
    });

    document.getElementById('p-exp').addEventListener('change', (e) => {
        expiring = e.target.checked;
        load();
    });

    listBox.addEventListener('click', async (e) => {
        const row = e.target.closest('[data-id]');

        if (!row) {
            return;
        }

        const id = Number(row.getAttribute('data-id'));
        const act = (e.target.closest('[data-act]') || {}).getAttribute
            ? e.target.closest('[data-act]').getAttribute('data-act')
            : '';

        if (!act) {
            return;
        }

        try {
            if (act === 'del') {
                const ok = await confirmBox({ title: '从库存里删掉', message: '这条会被移除。', okText: '删掉', danger: true });

                if (!ok) {
                    return;
                }

                await api.del('/api/pantry/' + id);
                toast('已删除', 'ok');
                load();
                return;
            }

            if (act === 'minus' || act === 'plus') {
                const item = row.querySelector('.row-qty');
                let delta = 0;

                if (act === 'plus') {
                    const f = await formBox({
                        title: '补货',
                        okText: '加上',
                        fields: [{ name: 'n', label: '加多少', type: 'number', value: '1', step: '0.1', required: true }],
                    });

                    if (!f) {
                        return;
                    }

                    delta = Number(f.n) || 0;
                } else {
                    const f = await formBox({
                        title: '用掉一些',
                        okText: '扣掉',
                        fields: [{ name: 'n', label: '用掉多少', type: 'number', value: '1', step: '0.1', required: true }],
                    });

                    if (!f) {
                        return;
                    }

                    delta = -(Number(f.n) || 0);
                }

                const res = await api.post('/api/pantry/' + id + '/adjust', { delta: delta });
                toast(res.removed ? '用完了，这条已移除' : '现在还有 ' + res.amount, 'ok');
                load();
                return;
            }

            if (act === 'edit') {
                const list = await api.get('/api/pantry');
                const item = (list.items || []).filter((x) => x.id === id)[0];

                if (!item) {
                    return;
                }

                const f = await formBox({
                    title: '改一下',
                    okText: '保存',
                    fields: [
                        { name: 'name', label: '食材', value: item.name, required: true },
                        { name: 'amount', label: '数量', type: 'number', step: '0.01', value: item.amount === null ? '' : item.amount },
                        { name: 'unit', label: '单位', type: 'select', value: item.unit, options: (function () {
                            const out = [{ value: '', label: '—' }];
                            unitGroups().forEach((g) => g.units.forEach((u) => out.push({ value: u, label: g.label + ' · ' + u })));
                            return out;
                        })() },
                        { name: 'location', label: '放哪', type: 'select', value: item.location, options: ((state.meta && state.meta.pantryLocations) || ['冷藏']).map((l) => ({ value: l, label: l })) },
                        { name: 'expire_at', label: '保质期到', type: 'date', value: item.expire_at ? fmtDate(item.expire_at) : '' },
                        { name: 'note', label: '备注', value: item.note || '' },
                    ],
                });

                if (!f) {
                    return;
                }

                await api.put('/api/pantry/' + id, {
                    name: f.name,
                    amount: f.amount === '' ? null : Number(f.amount),
                    unit: f.unit,
                    location: f.location,
                    expire_at: f.expire_at,
                    note: f.note,
                });

                toast('已保存', 'ok');
                load();
            }
        } catch (err) {
            toastError(err);
        }
    });

    document.getElementById('btn-add').addEventListener('click', async () => {
        const f = await formBox({
            title: '记一样食材',
            okText: '记上',
            fields: [
                { name: 'name', label: '食材', placeholder: '比如：五花肉', required: true },
                { name: 'amount', label: '数量', type: 'number', step: '0.01', placeholder: '不填也行' },
                { name: 'unit', label: '单位', type: 'select', value: '克', options: (function () {
                    const out = [{ value: '', label: '—' }];
                    unitGroups().forEach((g) => g.units.forEach((u) => out.push({ value: u, label: g.label + ' · ' + u })));
                    return out;
                })() },
                { name: 'location', label: '放哪', type: 'select', value: '冷藏', options: ((state.meta && state.meta.pantryLocations) || ['冷藏']).map((l) => ({ value: l, label: l })) },
                { name: 'expire_at', label: '保质期到（可不填）', type: 'date' },
                { name: 'note', label: '备注', placeholder: '比如：开封了' },
            ],
        });

        if (!f) {
            return;
        }

        try {
            await api.post('/api/pantry', {
                name: f.name,
                amount: f.amount === '' ? null : Number(f.amount),
                unit: f.unit,
                location: f.location,
                bought_at: new Date().toISOString().slice(0, 10),
                expire_at: f.expire_at,
                note: f.note,
            });

            toast('记上了', 'ok');
            load();
        } catch (e) {
            toastError(e);
        }
    });

    document.getElementById('btn-suggest').addEventListener('click', async () => {
        const box = document.getElementById('suggest-box');

        if (!box.hidden) {
            box.hidden = true;
            return;
        }

        box.hidden = false;
        box.innerHTML = '<div class="panel loading">正在算…</div>';

        try {
            const res = await api.get('/api/pantry/suggest?limit=24&min_ratio=0.5');
            const items = res.items || [];

            if (!items.length) {
                box.innerHTML = html`<div class="panel"><h3>按库存能做啥</h3>
                    <p class="dim">暂时没算出合适的。库存记得越全，这里越准。</p></div>`;
                return;
            }

            box.innerHTML = html`<div class="panel">
                <div class="panel-head">
                    <h3>按库存能做啥</h3>
                    <button class="btn btn-sm btn-ghost" id="suggest-close" type="button">收起</button>
                </div>
                <p class="dim small">按「命中食材占比」排序。缺的东西列出来了，顺路买一下就行。</p>
                <div class="cards cards-sm">
                    ${raw(
                        items
                            .map(
                                (s) => `
                        <a class="card card-flat" href="#/recipe/${s.id}">
                            <div class="card-body">
                                <h3 class="card-title">${esc(s.name)}</h3>
                                <div class="card-meta">${esc(
                                    s.have_count + '/' + s.need_count + ' 样有' + (s.total_min ? ' · ' + esc(fmtDuration(s.total_min)) : '')
                                )}</div>
                                <div class="mini-list">
                                    ${s.missing.length
                                        ? '<span class="miss">还缺：' + s.missing.map(esc).join('、') + '</span>'
                                        : '<span class="ok">材料齐了</span>'}
                                </div>
                            </div>
                        </a>`
                            )
                            .join('')
                    )}
                </div>
            </div>`;

            document.getElementById('suggest-close').addEventListener('click', () => {
                box.hidden = true;
            });
        } catch (e) {
            box.innerHTML = html`<div class="panel"><p>${e.message}</p></div>`;
        }
    });

    load();
}
