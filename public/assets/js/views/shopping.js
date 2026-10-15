import { api, qs, download } from '../api.js';
import { html, raw, esc, $, $$, toast, toastError, confirmBox, formBox, fmtAgo, today, addDays } from '../dom.js';
import { unitGroups } from '../state.js';

function unitChoices() {
    const out = [{ value: '', label: '—' }];

    unitGroups().forEach((g) => {
        g.units.forEach((u) => out.push({ value: u, label: g.label + ' · ' + u }));
    });

    return out;
}

// ==================================================================== 清单列表

export async function renderShoppingList(root) {
    root.innerHTML = html`
    <div class="page-head">
        <h2 class="page-title">采购清单</h2>
        <div class="page-actions">
            <button class="btn btn-primary btn-sm" id="btn-new-list" type="button">+ 新建清单</button>
        </div>
    </div>
    <div id="lists" class="results"></div>`;

    const box = document.getElementById('lists');

    async function load() {
        box.classList.add('loading');

        try {
            const lists = await api.get('/api/shopping');

            if (!lists.length) {
                box.innerHTML = html`<div class="empty">
                    <p class="empty-mark">🛒</p>
                    <p>还没有清单。</p>
                    <p class="dim">去<a href="#/plan">膳食计划</a>排好一周，一键就能生成；也可以直接新建一份手动写。</p>
                </div>`;
                return;
            }

            box.innerHTML = html`<div class="rows-list">
                ${raw(
                    lists
                        .map((l) => {
                            const pct = l.item_count ? Math.round((l.checked_count / l.item_count) * 100) : 0;

                            return `
                    <div class="row-item${l.status === 'done' ? ' muted' : ''}">
                        <div class="row-main">
                            <a class="row-title" href="#/shopping/${l.id}">${esc(l.name)}</a>
                            <span class="row-qty dim">${l.checked_count}/${l.item_count} 已买</span>
                        </div>
                        <div class="row-meta">
                            <div class="progress"><span style="width:${pct}%"></span></div>
                            <span class="dim">${fmtAgo(l.updated_at)}更新</span>
                            ${l.status === 'done' ? '<span class="badge ok">已结束</span>' : ''}
                        </div>
                        <div class="row-tools">
                            <a class="btn btn-sm" href="#/shopping/${l.id}">打开</a>
                            <button class="icon-btn danger" data-del="${l.id}" title="删除">✕</button>
                        </div>
                    </div>`;
                        })
                        .join('')
                )}
            </div>`;

            $$('[data-del]', box).forEach((b) => {
                b.addEventListener('click', async () => {
                    const ok = await confirmBox({ title: '删除清单', message: '这份清单和里面的条目都会没。', okText: '删除', danger: true });

                    if (!ok) {
                        return;
                    }

                    try {
                        await api.del('/api/shopping/' + b.getAttribute('data-del'));
                        toast('已删除', 'ok');
                        load();
                    } catch (e) {
                        toastError(e);
                    }
                });
            });
        } catch (e) {
            box.innerHTML = html`<div class="empty"><p>${e.message}</p></div>`;
        } finally {
            box.classList.remove('loading');
        }
    }

    document.getElementById('btn-new-list').addEventListener('click', async () => {
        const f = await formBox({
            title: '新建清单',
            okText: '建',
            fields: [{ name: 'name', label: '名字', value: '采购清单 ' + today() }],
        });

        if (!f) {
            return;
        }

        try {
            const res = await api.post('/api/shopping', { name: f.name });
            location.hash = '#/shopping/' + res.id;
        } catch (e) {
            toastError(e);
        }
    });

    load();
}

// ==================================================================== 清单详情

export async function renderShoppingDetail(root, params) {
    const listId = Number(params.id);

    root.innerHTML = html`
    <div class="page-head">
        <div>
            <a class="back" href="#/shopping">← 所有清单</a>
            <h2 class="page-title" id="list-title">采购清单</h2>
        </div>
        <div class="page-actions">
            <button class="btn btn-sm" id="btn-from-plan" type="button">从计划补货</button>
            <button class="btn btn-sm" id="btn-tidy" type="button">合并重复</button>
            <button class="btn btn-sm" id="btn-export" type="button">导出文本</button>
            <button class="btn btn-sm btn-ghost" id="btn-more" type="button">更多</button>
        </div>
    </div>
    <div id="list-body" class="results"></div>`;

    const body = document.getElementById('list-body');

    async function load() {
        body.classList.add('loading');

        try {
            const l = await api.get('/api/shopping/' + listId);
            document.getElementById('list-title').textContent = l.name;
            render(l);
        } catch (e) {
            body.innerHTML = html`<div class="empty"><p>${e.message}</p><p class="dim"><a href="#/shopping">回清单列表</a></p></div>`;
        } finally {
            body.classList.remove('loading');
        }
    }

    function render(l) {
        const items = l.items || [];
        const pct = l.item_count ? Math.round((l.checked_count / l.item_count) * 100) : 0;

        // 按食材分类分组，逛菜市场顺路
        const groups = [];
        const seen = {};

        items.forEach((i) => {
            const cat = i.category || '其他';

            if (!seen[cat]) {
                seen[cat] = { name: cat, items: [] };
                groups.push(seen[cat]);
            }

            seen[cat].items.push(i);
        });

        body.innerHTML = html`
        <div class="shop-head">
            <div class="progress big"><span style="width:${pct}%"></span></div>
            <span class="dim">已买 ${l.checked_count} / ${l.item_count}</span>
            <button class="btn btn-sm btn-ghost" id="btn-rename" type="button">改名</button>
        </div>

        <form class="add-row" id="add-form">
            <input type="text" name="name" placeholder="加一样…" required autocomplete="off">
            <input type="number" name="amount" step="0.01" min="0" placeholder="数量" class="narrow">
            <select name="unit" class="narrow">
                ${raw(unitChoices().map((u) => '<option value="' + esc(u.value) + '">' + esc(u.label) + '</option>').join(''))}
            </select>
            <button class="btn btn-sm btn-primary" type="submit">加</button>
        </form>

        ${items.length
            ? html`${raw(
                  groups
                      .map(
                          (g) => `
                <section class="shop-group">
                    <h3 class="shop-group-title">${esc(g.name)}</h3>
                    <ul class="shop-items">
                        ${g.items
                            .map(
                                (i) => `
                            <li class="shop-item${i.checked ? ' checked' : ''}" data-id="${i.id}">
                                <label class="shop-check">
                                    <input type="checkbox" data-toggle="${i.id}"${i.checked ? ' checked' : ''}>
                                    <span class="shop-name">${esc(i.name)}</span>
                                </label>
                                <span class="shop-qty">${esc(i.display)}</span>
                                ${i.sources ? '<span class="shop-src dim">' + esc(i.sources) + '</span>' : ''}
                                ${i.note ? '<span class="shop-src dim">' + esc(i.note) + '</span>' : ''}
                                <button class="icon-btn tiny" data-edit="${i.id}" title="改">✎</button>
                                <button class="icon-btn tiny danger" data-rm="${i.id}" title="删除">✕</button>
                            </li>`
                            )
                            .join('')}
                    </ul>
                </section>`
                      )
                      .join('')
              )}`
            : html`<div class="empty"><p>清单是空的。</p><p class="dim">上面直接加，或者点「从计划补货」按排好的菜单自动生成。</p></div>`}

        <div class="shop-foot">
            <button class="btn btn-sm" id="btn-uncheck" type="button">全部取消勾选</button>
            <button class="btn btn-sm" id="btn-clear-checked" type="button">清掉已买的</button>
            <button class="btn btn-sm" id="btn-to-pantry" type="button">已买的存进库存</button>
        </div>`;

        bind(l);
    }

    function bind(l) {
        const form = document.getElementById('add-form');

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const fd = new FormData(form);

            try {
                await api.post('/api/shopping/' + listId + '/items', {
                    name: fd.get('name'),
                    amount: fd.get('amount') === '' ? null : Number(fd.get('amount')),
                    unit: fd.get('unit'),
                });

                form.reset();
                load();
            } catch (err) {
                toastError(err);
            }
        });

        $$('[data-toggle]', body).forEach((cb) => {
            cb.addEventListener('change', async () => {
                try {
                    await api.put('/api/shopping/items/' + cb.getAttribute('data-toggle') + '/toggle');
                    const li = cb.closest('.shop-item');
                    li.classList.toggle('checked', cb.checked);
                    refreshHead();
                } catch (e) {
                    toastError(e);
                    cb.checked = !cb.checked;
                }
            });
        });

        async function refreshHead() {
            try {
                const fresh = await api.get('/api/shopping/' + listId);
                const bar = body.querySelector('.shop-head');
                const pct = fresh.item_count ? Math.round((fresh.checked_count / fresh.item_count) * 100) : 0;

                if (bar) {
                    bar.querySelector('.progress span').style.width = pct + '%';
                    bar.querySelector('.dim').textContent = '已买 ' + fresh.checked_count + ' / ' + fresh.item_count;
                }
            } catch (e) {
                /* 忽略 */
            }
        }

        $$('[data-rm]', body).forEach((b) => {
            b.addEventListener('click', async () => {
                try {
                    await api.del('/api/shopping/items/' + b.getAttribute('data-rm'));
                    load();
                } catch (e) {
                    toastError(e);
                }
            });
        });

        $$('[data-edit]', body).forEach((b) => {
            b.addEventListener('click', async () => {
                const id = Number(b.getAttribute('data-edit'));
                const cur = (l.items || []).filter((x) => x.id === id)[0];

                if (!cur) {
                    return;
                }

                const f = await formBox({
                    title: '改这一条',
                    okText: '保存',
                    fields: [
                        { name: 'name', label: '食材', value: cur.name, required: true },
                        { name: 'amount', label: '数量', type: 'number', step: '0.01', value: cur.amount === null ? '' : cur.amount },
                        { name: 'unit', label: '单位', type: 'select', value: cur.unit, options: unitChoices() },
                        { name: 'note', label: '备注', value: cur.note || '' },
                    ],
                });

                if (!f) {
                    return;
                }

                try {
                    await api.put('/api/shopping/items/' + id, {
                        name: f.name,
                        amount: f.amount === '' ? null : Number(f.amount),
                        unit: f.unit,
                        note: f.note,
                    });
                    load();
                } catch (e) {
                    toastError(e);
                }
            });
        });

        const rename = document.getElementById('btn-rename');

        if (rename) {
            rename.addEventListener('click', async () => {
                const f = await formBox({
                    title: '清单改名',
                    okText: '改名',
                    fields: [{ name: 'name', label: '名字', value: l.name }],
                });

                if (!f) {
                    return;
                }

                try {
                    await api.put('/api/shopping/' + listId, { name: f.name });
                    load();
                } catch (e) {
                    toastError(e);
                }
            });
        }

        document.getElementById('btn-uncheck').addEventListener('click', async () => {
            try {
                await api.post('/api/shopping/' + listId + '/uncheck-all');
                load();
            } catch (e) {
                toastError(e);
            }
        });

        document.getElementById('btn-clear-checked').addEventListener('click', async () => {
            try {
                const res = await api.post('/api/shopping/' + listId + '/clear-checked');
                toast('清掉了 ' + res.removed + ' 条', 'ok');
                load();
            } catch (e) {
                toastError(e);
            }
        });

        document.getElementById('btn-to-pantry').addEventListener('click', async () => {
            try {
                const res = await api.post('/api/shopping/' + listId + '/to-pantry');
                toast(res.added + ' 样存进库存了', 'ok');
            } catch (e) {
                toastError(e);
            }
        });
    }

    document.getElementById('btn-tidy').addEventListener('click', async () => {
        try {
            const res = await api.post('/api/shopping/' + listId + '/tidy');
            toast('合并完成，现在 ' + res.count + ' 项', 'ok');
            load();
        } catch (e) {
            toastError(e);
        }
    });

    document.getElementById('btn-from-plan').addEventListener('click', async () => {
        const f = await formBox({
            title: '按计划补货',
            okText: '汇总',
            fields: [
                { name: 'from', label: '从', type: 'date', value: today(), required: true },
                { name: 'to', label: '到', type: 'date', value: addDays(today(), 6), required: true },
            ],
        });

        if (!f) {
            return;
        }

        try {
            const preview = await api.get('/api/plan/preview' + qs({ from: f.from, to: f.to }));

            if (!preview.merged || !preview.merged.length) {
                toast('这段时间没排菜', 'warn');
                return;
            }

            const ok = await confirmBox({
                title: '汇总进这份清单',
                message:
                    '会把这 ' + (Number(f.to.replace(/-/g, '')) - Number(f.from.replace(/-/g, '')) + 1) +
                    ' 天的用料合并进当前清单，已经手动加过的条目会保留并一起合并。合并后 ' +
                    preview.merged.length + ' 项。',
                okText: '汇总',
            });

            if (!ok) {
                return;
            }

            const res = await api.post('/api/shopping/' + listId + '/from-plan', { from: f.from, to: f.to });
            toast('好了，' + res.count + ' 项', 'ok');
            load();
        } catch (e) {
            toastError(e);
        }
    });

    document.getElementById('btn-export').addEventListener('click', async () => {
        try {
            const res = await api.get('/api/shopping/' + listId + '/export');
            const text = res.text;

            const ok = await confirmBox({
                title: '导出文本',
                okText: '下载 .txt',
                cancelText: '复制到剪贴板',
                bodyHtml: '<pre class="export-preview">' + esc(text) + '</pre>',
            });

            if (ok) {
                download('/api/shopping/' + listId + '/export?download=1');
                return;
            }

            try {
                await navigator.clipboard.writeText(text);
                toast('已复制到剪贴板', 'ok');
            } catch (e) {
                toast('这个浏览器不让复制，用下载吧', 'warn');
            }
        } catch (e) {
            toastError(e);
        }
    });

    document.getElementById('btn-more').addEventListener('click', async () => {
        const ok = await confirmBox({
            title: '更多操作',
            message: '清空这份清单里的全部条目（清单本身保留）。',
            okText: '清空条目',
            danger: true,
        });

        if (!ok) {
            return;
        }

        try {
            const res = await api.post('/api/shopping/' + listId + '/clear');
            toast('清掉了 ' + res.removed + ' 条', 'ok');
            load();
        } catch (e) {
            toastError(e);
        }
    });

    load();
}
