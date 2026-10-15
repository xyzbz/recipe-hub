import { api, qs } from '../api.js';
import { html, raw, esc, $, $$, toast, toastError, confirmBox, formBox } from '../dom.js';
import { state } from '../state.js';

export async function renderIngredients(root, params, query) {
    let q = query.q || '';
    let category = query.category || '';

    const cats = ((state.meta && state.meta.ingredientCategories) || []).map((c) =>
        typeof c === 'string' ? { name: c, count: 0 } : { name: c.name, count: c.count || 0 }
    );

    root.innerHTML = html`
    <div class="page-head">
        <h2 class="page-title">食材库</h2>
        <div class="page-actions">
            <button class="btn btn-sm" id="btn-merge" type="button">合并两条</button>
            <button class="btn btn-primary btn-sm" id="btn-add" type="button">+ 加食材</button>
        </div>
    </div>

    <p class="dim small">
        这里放的是「标准名 + 别名」。填菜谱时写别名（酱油 / 豉油）也能自动对上生抽，
        采购清单才不会给你列出四种酱油。
    </p>

    <div class="filters">
        <div class="filter-row">
            <input type="search" id="i-q" class="grow" placeholder="搜食材名或别名…" value="${q}">
        </div>
        <div class="filter-row">
            <div class="chips">
                <button type="button" class="chip${category === '' ? ' on' : ''}" data-cat="">全部</button>
                ${raw(
                    cats
                        .map(
                            (c) =>
                                '<button type="button" class="chip' +
                                (c.name === category ? ' on' : '') +
                                '" data-cat="' +
                                esc(c.name) +
                                '">' +
                                esc(c.name) +
                                (c.count ? '<span class="chip-num">' + c.count + '</span>' : '') +
                                '</button>'
                        )
                        .join('')
                )}
            </div>
        </div>
    </div>

    <div id="ing-list" class="results"></div>`;

    const listBox = document.getElementById('ing-list');

    async function load() {
        listBox.classList.add('loading');
        history.replaceState(null, '', '#/ingredients' + qs({ q: q, category: category }));

        try {
            const items = await api.get('/api/ingredients' + qs({ q: q, category: category, limit: 400 }));

            if (!items.length) {
                listBox.innerHTML = html`<div class="empty"><p>没找到。</p></div>`;
                return;
            }

            listBox.innerHTML = html`<div class="table-wrap"><table class="table">
                <thead><tr><th>名称</th><th>分类</th><th>别名</th><th class="num">用过</th><th></th></tr></thead>
                <tbody>
                ${raw(
                    items
                        .map(
                            (i) => `
                    <tr data-id="${i.id}">
                        <td>
                            <strong>${esc(i.name)}</strong>
                            ${i.is_builtin ? '<span class="tag-mini">预置</span>' : '<span class="tag-mini muted">自建</span>'}
                        </td>
                        <td class="dim">${esc(i.category)}</td>
                        <td class="dim">${esc((i.alias_list || []).join('、')) || '—'}</td>
                        <td class="num dim">${i.used_count}</td>
                        <td class="num">
                            <button class="icon-btn" data-act="edit">✎</button>
                            <button class="icon-btn danger" data-act="del">✕</button>
                        </td>
                    </tr>`
                        )
                        .join('')
                )}
                </tbody></table></div>
            <p class="dim small">共 ${items.length} 条</p>`;
        } catch (e) {
            listBox.innerHTML = html`<div class="empty"><p>${e.message}</p></div>`;
        } finally {
            listBox.classList.remove('loading');
        }
    }

    const qEl = document.getElementById('i-q');
    let tmr = 0;

    qEl.addEventListener('input', () => {
        clearTimeout(tmr);
        tmr = setTimeout(() => {
            q = qEl.value.trim();
            load();
        }, 260);
    });

    root.querySelector('.chips').addEventListener('click', (e) => {
        const c = e.target.closest('[data-cat]');

        if (!c) {
            return;
        }

        category = c.getAttribute('data-cat');
        $$('.chip', root).forEach((x) => x.classList.toggle('on', x === c));
        load();
    });

    listBox.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-act]');

        if (!btn) {
            return;
        }

        const tr = btn.closest('[data-id]');
        const id = Number(tr.getAttribute('data-id'));
        const name = tr.querySelector('strong').textContent;

        if (btn.getAttribute('data-act') === 'del') {
            const ok = await confirmBox({
                title: '删除食材',
                message: '「' + name + '」会从食材库删掉。菜谱里已经写了这个食材的行会保留，只是不再和食材库关联。',
                okText: '删除',
                danger: true,
            });

            if (!ok) {
                return;
            }

            try {
                await api.del('/api/ingredients/' + id);
                toast('已删除', 'ok');
                load();
            } catch (err) {
                toastError(err);
            }

            return;
        }

        const f = await formBox({
            title: '改食材',
            okText: '保存',
            fields: [
                { name: 'name', label: '标准名', value: name, required: true },
                {
                    name: 'category',
                    label: '分类',
                    type: 'select',
                    value: tr.children[1].textContent.trim(),
                    options: cats.map((c) => ({ value: c.name, label: c.name })),
                },
                {
                    name: 'aliases',
                    label: '别名（逗号分隔）',
                    value: tr.children[2].textContent.trim() === '—' ? '' : tr.children[2].textContent.trim(),
                    hint: '比如「酱油,豉油,味极鲜」',
                },
            ],
        });

        if (!f) {
            return;
        }

        try {
            await api.put('/api/ingredients/' + id, f);
            toast('已保存', 'ok');
            load();
        } catch (err) {
            toastError(err);
        }
    });

    document.getElementById('btn-add').addEventListener('click', async () => {
        const f = await formBox({
            title: '加一条食材',
            okText: '加',
            fields: [
                { name: 'name', label: '标准名', placeholder: '比如：香茅', required: true },
                { name: 'category', label: '分类', type: 'select', value: '其他', options: cats.map((c) => ({ value: c.name, label: c.name })) },
                { name: 'aliases', label: '别名（逗号分隔）', placeholder: '柠檬草、香茅草' },
            ],
        });

        if (!f) {
            return;
        }

        try {
            await api.post('/api/ingredients', f);
            toast('加好了', 'ok');
            load();
        } catch (e) {
            toastError(e);
        }
    });

    document.getElementById('btn-merge').addEventListener('click', async () => {
        const items = await api.get('/api/ingredients' + qs({ limit: 500 }));
        const opts = items.map((i) => ({ value: String(i.id), label: i.name + '（' + i.category + '）' }));

        const f = await formBox({
            title: '合并两条食材',
            okText: '合并',
            fields: [
                { name: 'from', label: '把这条并掉', type: 'select', options: opts, hint: '这一条会消失，名字自动变成别名' },
                { name: 'to', label: '并进这一条', type: 'select', options: opts },
            ],
        });

        if (!f || f.from === f.to) {
            return;
        }

        try {
            const res = await api.post('/api/ingredients/merge', { from: Number(f.from), to: Number(f.to) });
            toast('合并完成，改了 ' + res.recipes + ' 处菜谱引用', 'ok');
            load();
        } catch (e) {
            toastError(e);
        }
    });

    load();
}
