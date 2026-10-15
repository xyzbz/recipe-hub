import { api, qs } from '../api.js';
import { html, raw, esc, $, $$, toast, toastError, confirmBox, formBox, today, addDays, weekStart, weekdayOf, pad } from '../dom.js';
import { slots, slotLabel } from '../state.js';

/** 选菜谱的弹窗：带搜索，也支持只写个标题（比如「外卖」） */
function pickRecipe() {
    return new Promise((resolve) => {
        let host = document.getElementById('rh-overlays');

        if (!host) {
            host = document.createElement('div');
            host.id = 'rh-overlays';
            document.body.appendChild(host);
        }

        const wrap = document.createElement('div');
        wrap.className = 'modal-mask';
        wrap.innerHTML = html`
        <div class="modal modal-wide" role="dialog" aria-modal="true">
            <div class="modal-head"><h3>往这餐加点什么</h3></div>
            <div class="modal-body">
                <input type="search" id="pick-q" placeholder="搜菜名、食材…" autocomplete="off">
                <div id="pick-results" class="pick-list"></div>
                <div class="pick-custom">
                    <input type="text" id="pick-title" placeholder="或者手写一个，比如「下馆子」">
                    <button class="btn btn-sm" id="pick-title-ok" type="button">用这个</button>
                </div>
            </div>
            <div class="modal-foot"><button class="btn" id="pick-cancel" type="button">取消</button></div>
        </div>`;

        host.appendChild(wrap);
        requestAnimationFrame(() => wrap.classList.add('in'));

        const close = (val) => {
            wrap.classList.remove('in');
            setTimeout(() => wrap.remove(), 180);
            resolve(val);
        };

        const input = wrap.querySelector('#pick-q');
        const results = wrap.querySelector('#pick-results');

        async function search() {
            const q = input.value.trim();

            try {
                const res = await api.get('/api/recipes' + qs({ q: q, per: 20, sort: q ? 'name' : 'updated' }));
                const items = res.items || [];

                if (!items.length) {
                    results.innerHTML = '<p class="dim">没找到，换个词或者手写一个。</p>';
                    return;
                }

                results.innerHTML = items
                    .map(
                        (r) =>
                            '<button type="button" class="pick-item" data-id="' +
                            r.id +
                            '" data-name="' +
                            esc(r.name) +
                            '">' +
                            '<span class="pick-name">' +
                            esc(r.name) +
                            '</span>' +
                            '<span class="pick-meta dim">' +
                            esc(r.cuisine || '') +
                            (r.total_min ? ' · ' + r.total_min + ' 分钟' : '') +
                            '</span></button>'
                    )
                    .join('');

                $$('.pick-item', results).forEach((b) => {
                    b.addEventListener('click', () =>
                        close({ recipe_id: Number(b.getAttribute('data-id')), title: b.getAttribute('data-name') })
                    );
                });
            } catch (e) {
                results.innerHTML = '<p class="dim">' + esc(e.message) + '</p>';
            }
        }

        let tmr = 0;

        input.addEventListener('input', () => {
            clearTimeout(tmr);
            tmr = setTimeout(search, 240);
        });

        wrap.querySelector('#pick-title-ok').addEventListener('click', () => {
            const t = wrap.querySelector('#pick-title').value.trim();

            if (t) {
                close({ recipe_id: 0, title: t });
            }
        });

        wrap.querySelector('#pick-cancel').addEventListener('click', () => close(null));
        wrap.addEventListener('click', (e) => {
            if (e.target === wrap) {
                close(null);
            }
        });

        search();
        setTimeout(() => input.focus(), 60);
    });
}

export async function renderPlan(root, params, query) {
    let start = query.start || weekStart(today());

    root.innerHTML = html`
    <div class="page-head">
        <h2 class="page-title">这周吃啥</h2>
        <div class="page-actions">
            <button class="btn btn-sm" id="plan-tpl" type="button">周模板</button>
            <button class="btn btn-sm" id="plan-copy" type="button">复制到下周</button>
            <button class="btn btn-primary btn-sm" id="plan-shop" type="button">生成采购清单</button>
        </div>
    </div>

    <div class="week-bar">
        <button class="btn btn-sm" id="w-prev" type="button">← 上一周</button>
        <span class="week-label" id="w-label"></span>
        <button class="btn btn-sm" id="w-next" type="button">下一周 →</button>
        <button class="btn btn-sm btn-ghost" id="w-now" type="button">回到本周</button>
    </div>

    <div class="week-grid" id="week-grid"></div>

    <div id="tpl-box" hidden></div>`;

    const grid = document.getElementById('week-grid');
    const label = document.getElementById('w-label');

    function rangeOf(s) {
        return { from: s, to: addDays(s, 6) };
    }

    async function load() {
        const { from, to } = rangeOf(start);
        label.textContent = from + ' ~ ' + to;
        history.replaceState(null, '', '#/plan?start=' + start);
        grid.classList.add('loading');

        try {
            const res = await api.get('/api/plan/week' + qs({ start: start }));
            renderGrid(res.days || []);
        } catch (e) {
            grid.innerHTML = html`<div class="empty"><p>${e.message}</p></div>`;
        } finally {
            grid.classList.remove('loading');
        }
    }

    function renderGrid(days) {
        const t = today();

        grid.innerHTML = days
            .map((d) => {
                const isToday = d.date === t;
                const meals = slots()
                    .map((s) => {
                        const items = (d.meals[s.key] || [])
                            .map(
                                (m) => html`
                            <div class="meal-item" draggable="true" data-id="${m.id}">
                                <span class="meal-name">${m.title || '（没名字）'}</span>
                                ${m.recipe_id ? raw('<span class="meal-link">菜谱</span>') : raw('')}
                                ${m.recipe_id ? raw('<button class="icon-btn tiny" data-act="cook" title="做过了，记一笔">✅</button>') : raw('')}
                                <button class="icon-btn tiny" data-act="shuffle" title="换一道（随机换成本周没排过的菜）">🔄</button>
                                <button class="icon-btn tiny" data-act="edit" title="改">⋯</button>
                            </div>`
                            )
                            .join('');

                        return html`
                        <div class="slot" data-date="${d.date}" data-slot="${s.key}">
                            <div class="slot-head">
                                <span>${s.label}</span>
                                <button class="icon-btn tiny" data-act="add" title="加一道">＋</button>
                            </div>
                            <div class="slot-body">${raw(items)}</div>
                        </div>`;
                    })
                    .join('');

                return html`
                <div class="day${isToday ? ' today' : ''}" data-date="${d.date}">
                    <div class="day-head">
                        <strong>${weekdayOf(d.date)}</strong>
                        <span class="dim">${d.date.slice(5)}</span>
                    </div>
                    ${raw(meals)}
                </div>`;
            })
            .join('');

        bindGrid();
    }

    let draggingId = 0;

    function bindGrid() {
        $$('.meal-item', grid).forEach((el) => {
            el.addEventListener('dragstart', (e) => {
                draggingId = Number(el.getAttribute('data-id'));
                el.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';

                try {
                    e.dataTransfer.setData('text/plain', String(draggingId));
                } catch (err) {
                    /* 某些浏览器不让设置，无所谓 */
                }
            });

            el.addEventListener('dragend', () => {
                el.classList.remove('dragging');
                draggingId = 0;
            });
        });

        $$('.slot', grid).forEach((slot) => {
            slot.addEventListener('dragover', (e) => {
                e.preventDefault();
                slot.classList.add('drop-hover');
            });

            slot.addEventListener('dragleave', () => slot.classList.remove('drop-hover'));

            slot.addEventListener('drop', async (e) => {
                e.preventDefault();
                slot.classList.remove('drop-hover');

                const id = draggingId || Number(e.dataTransfer.getData('text/plain'));

                if (!id) {
                    return;
                }

                try {
                    await api.put('/api/plan/' + id + '/move', {
                        plan_date: slot.getAttribute('data-date'),
                        slot: slot.getAttribute('data-slot'),
                    });
                    toast('挪好了', 'ok');
                    load();
                } catch (err) {
                    toastError(err);
                }
            });
        });
    }

    grid.addEventListener('click', async (e) => {
        const addBtn = e.target.closest('[data-act="add"]');

        if (addBtn) {
            const slot = addBtn.closest('.slot');
            const picked = await pickRecipe();

            if (!picked) {
                return;
            }

            try {
                await api.post('/api/plan', {
                    plan_date: slot.getAttribute('data-date'),
                    slot: slot.getAttribute('data-slot'),
                    recipe_id: picked.recipe_id,
                    title: picked.recipe_id ? '' : picked.title,
                    servings: 0,
                });

                toast('排上了', 'ok');
                load();
            } catch (err) {
                toastError(err);
            }

            return;
        }

        // 一键换菜：不想吃这顿了，从「本周还没排过」的菜里随机来一道
        const cookBtn = e.target.closest('[data-act="cook"]');

        if (cookBtn) {
            const item = cookBtn.closest('.meal-item');

            if (!item) {
                return;
            }

            const id = Number(item.getAttribute('data-id'));

            try {
                // 格子上只有 id，具体内容跟「改」那条路一样重新拉一次
                const list = await api.get('/api/plan' + qs(rangeOf(start)));
                const cur = (list.items || []).filter((x) => x.id === id)[0];

                if (!cur || !cur.recipe_id) {
                    return;
                }

                await api.post('/api/recipes/' + cur.recipe_id + '/cooks', {
                    plan_id: id,
                    servings: Number(cur.servings) || 0,
                });

                toast('记下了：' + (cur.title || '这道菜') + ' 又吃过一次', 'ok');
                load();
            } catch (err) {
                toastError(err);
            }

            return;
        }

        const shuffleBtn = e.target.closest('[data-act="shuffle"]');

        if (shuffleBtn) {
            const item = shuffleBtn.closest('.meal-item');

            if (!item) {
                return;
            }

            const id = Number(item.getAttribute('data-id'));

            try {
                const res = await api.post('/api/plan/' + id + '/shuffle');
                toast('换成了「' + (res.recipe && res.recipe.name) + '」', 'ok');
                load();
            } catch (err) {
                toastError(err);
            }

            return;
        }

        const editBtn = e.target.closest('[data-act="edit"]');

        if (editBtn) {
            const item = editBtn.closest('.meal-item');

            if (!item) {
                return;
            }

            const id = Number(item.getAttribute('data-id'));

            try {
                const list = await api.get('/api/plan' + qs(rangeOf(start)));
                const cur = (list.items || []).filter((x) => x.id === id)[0];

                if (!cur) {
                    return;
                }

                const f = await formBox({
                    title: '改这一餐',
                    okText: '保存',
                    fields: [
                        { name: 'plan_date', label: '日期', type: 'date', value: cur.plan_date },
                        {
                            name: 'slot',
                            label: '哪一餐',
                            type: 'select',
                            value: cur.slot,
                            options: slots().map((s) => ({ value: s.key, label: s.label })),
                        },
                        { name: 'title', label: '显示名字（手写的条目用）', value: cur.title || '' },
                        { name: 'servings', label: '做几人份（做采购清单时会按这个放大用量）', type: 'number', step: '0.5', value: cur.servings || 0 },
                        { name: 'note', label: '备注', value: cur.note || '' },
                    ],
                });

                if (f) {
                    await api.put('/api/plan/' + id, {
                        plan_date: f.plan_date,
                        slot: f.slot,
                        title: f.title,
                        servings: Number(f.servings) || 0,
                        note: f.note,
                    });

                    toast('已保存', 'ok');
                    load();
                    return;
                }

                const del = await confirmBox({
                    title: '从计划里去掉',
                    message: '只去掉这一餐的安排，菜谱本身不受影响。',
                    okText: '去掉',
                    danger: true,
                });

                if (del) {
                    await api.del('/api/plan/' + id);
                    toast('已去掉', 'ok');
                    load();
                }
            } catch (err) {
                toastError(err);
            }
        }
    });

    document.getElementById('w-prev').addEventListener('click', () => {
        start = addDays(start, -7);
        load();
    });

    document.getElementById('w-next').addEventListener('click', () => {
        start = addDays(start, 7);
        load();
    });

    document.getElementById('w-now').addEventListener('click', () => {
        start = weekStart(today());
        load();
    });

    document.getElementById('plan-shop').addEventListener('click', async () => {
        const { from, to } = rangeOf(start);

        try {
            const preview = await api.get('/api/plan/preview' + qs({ from: from, to: to }));

            if (!preview.merged || !preview.merged.length) {
                toast('这一周还没排菜，先去排几道', 'warn');
                return;
            }

            const shown = preview.merged
                .slice(0, 20)
                .map((m) => '<li><span>' + esc(m.name) + '</span><em>' + esc(m.display) + '</em></li>')
                .join('');

            const ok = await confirmBox({
                title: '生成采购清单',
                okText: '生成',
                bodyHtml:
                    '<p class="dim">这一周共排了 ' +
                    preview.raw +
                    ' 条用料，合并后 <b>' +
                    preview.merged.length +
                    '</b> 项。按「斤 / 两 / 克」换算过了，重复的已经并在一起。</p>' +
                    '<ul class="preview-list">' +
                    shown +
                    (preview.merged.length > 20 ? '<li class="dim">……还有 ' + (preview.merged.length - 20) + ' 项</li>' : '') +
                    '</ul>',
            });

            if (!ok) {
                return;
            }

            const res = await api.post('/api/plan/to-shopping', {
                from: from,
                to: to,
                name: from + ' 那周的采购',
            });

            toast('清单好了，' + res.count + ' 项', 'ok');
            location.hash = '#/shopping/' + res.list_id;
        } catch (e) {
            toastError(e);
        }
    });

    document.getElementById('plan-copy').addEventListener('click', async () => {
        const { from, to } = rangeOf(start);

        try {
            const n = await api.post('/api/plan/copy', { from: from, to: to, new_start: addDays(start, 7) });
            toast('复制到下周了，' + n.copied + ' 条', 'ok');
            start = addDays(start, 7);
            load();
        } catch (e) {
            toastError(e);
        }
    });

    // ---- 周模板

    document.getElementById('plan-tpl').addEventListener('click', async () => {
        const box = document.getElementById('tpl-box');

        if (!box.hidden) {
            box.hidden = true;
            return;
        }

        box.hidden = false;
        box.innerHTML = '<div class="panel loading">正在读模板…</div>';

        try {
            const list = await api.get('/api/plan/templates');
            const { from, to } = rangeOf(start);

            box.innerHTML = html`
            <div class="panel">
                <div class="panel-head">
                    <h3>周模板</h3>
                    <div>
                        <button class="btn btn-sm" id="tpl-save" type="button">把这一周存成模板</button>
                        <button class="btn btn-sm btn-ghost" id="tpl-close" type="button">收起</button>
                    </div>
                </div>
                ${list.length
                    ? html`<div class="rows-list">
                          ${raw(
                              list
                                  .map(
                                      (t) => `
                          <div class="row-item">
                              <div class="row-main">
                                  <span class="row-title">${esc(t.name)}</span>
                                  <span class="row-qty dim">${t.count} 条</span>
                              </div>
                              <div class="row-tools">
                                  <button class="btn btn-sm" data-apply="${t.id}">套到这一周</button>
                                  <button class="icon-btn danger" data-tpl-del="${t.id}">✕</button>
                              </div>
                          </div>`
                                  )
                                  .join('')
                          )}
                      </div>`
                    : html`<p class="dim">还没有模板。排好一周之后点「把这一周存成模板」，以后一键复用。</p>`}
            </div>`;

            document.getElementById('tpl-close').addEventListener('click', () => {
                box.hidden = true;
            });

            document.getElementById('tpl-save').addEventListener('click', async () => {
                const f = await formBox({
                    title: '存成周模板',
                    okText: '存起来',
                    fields: [{ name: 'name', label: '模板名字', value: '家常一周', required: true }],
                });

                if (!f) {
                    return;
                }

                try {
                    await api.post('/api/plan/templates', { name: f.name, from: from, to: to });
                    toast('存好了', 'ok');
                    document.getElementById('plan-tpl').click();
                    document.getElementById('plan-tpl').click();
                } catch (e) {
                    toastError(e);
                }
            });

            box.addEventListener('click', async (e) => {
                const apply = e.target.closest('[data-apply]');
                const del = e.target.closest('[data-tpl-del]');

                try {
                    if (apply) {
                        const n = await api.post('/api/plan/templates/' + apply.getAttribute('data-apply') + '/apply', {
                            start: start,
                        });
                        toast('套上了，' + n.applied + ' 条', 'ok');
                        load();
                    }

                    if (del) {
                        await api.del('/api/plan/templates/' + del.getAttribute('data-tpl-del'));
                        toast('模板已删', 'ok');
                        document.getElementById('plan-tpl').click();
                        document.getElementById('plan-tpl').click();
                    }
                } catch (err) {
                    toastError(err);
                }
            });
        } catch (e) {
            box.innerHTML = html`<div class="panel"><p>${e.message}</p></div>`;
        }
    });

    load();
}
