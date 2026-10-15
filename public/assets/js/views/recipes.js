import { api, qs, download } from '../api.js';
import {
    html, raw, esc, $, $$, toast, toastError, confirmBox, formBox,
    fmtDuration, fmtDate, fmtAgo, fmtNum, today, debounce, coverStyle,
} from '../dom.js';
import { state, tagOptions, cuisineOptions, unitGroups, difficulties, slots, slotLabel, unitKind } from '../state.js';

let ingredientCache = null;

async function loadIngredients() {
    if (ingredientCache) {
        return ingredientCache;
    }

    try {
        ingredientCache = await api.get('/api/ingredients' + qs({ limit: 500 }));
    } catch (e) {
        ingredientCache = [];
    }

    return ingredientCache;
}

function datalistHtml() {
    const list = ingredientCache || [];

    return html`<datalist id="ing-names">${raw(
        list.map((i) => '<option value="' + esc(i.name) + '">').join('')
    )}</datalist>`;
}

function unitSelect(name, value, extraClass) {
    const groups = unitGroups();
    let opts = '<option value="">—</option>';

    groups.forEach((g) => {
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

    return html`<select name="${name}" class="${extraClass || ''}">${raw(opts)}</select>`;
}

function metaLine(r) {
    const bits = [];

    if (r.cuisine) {
        bits.push(r.cuisine);
    }

    if (r.difficulty) {
        bits.push(r.difficulty);
    }

    const dur = fmtDuration(r.total_min);

    if (dur) {
        bits.push(dur);
    }

    return bits.join(' · ');
}

// ==================================================================== 列表

export async function renderRecipeList(root, params, query) {
    await loadIngredients();

    const filters = {
        q: query.q || '',
        tags: Array.isArray(query.tags) ? query.tags.slice() : query.tags ? [query.tags] : [],
        tagmode: query.tagmode || 'all',
        cuisine: query.cuisine || '',
        difficulty: query.difficulty || '',
        max_time: query.max_time || '',
        fav: query.fav === '1',
        mine: query.mine === '1',
        sort: query.sort || 'updated',
        page: Number(query.page || 1) || 1,
    };

    const tags = tagOptions().filter((t) => t.count > 0 || filters.tags.indexOf(t.name) >= 0);
    const cuisines = cuisineOptions();

    root.innerHTML = html`
    <div class="page-head">
        <h2 class="page-title">菜谱</h2>
        <div class="page-actions">
            <button class="btn btn-sm" id="btn-random" type="button" title="按当前筛选随机抽一道">🎲 随便来一道</button>
            <button class="btn btn-sm" id="btn-import" type="button">导入</button>
            <button class="btn btn-sm" id="btn-export" type="button">导出</button>
            <a class="btn btn-primary btn-sm" href="#/recipe/new">+ 新菜谱</a>
        </div>
    </div>

    <div class="filters">
        <div class="filter-row">
            <input type="search" id="f-q" class="grow" placeholder="搜菜名、食材、做法…" value="${filters.q}">
            <select id="f-cuisine">
                <option value="">全部菜系</option>
                ${raw(
                    cuisines
                        .map(
                            (c) =>
                                '<option value="' +
                                esc(c.name) +
                                '"' +
                                (c.name === filters.cuisine ? ' selected' : '') +
                                '>' +
                                esc(c.name) +
                                (c.count ? '（' + c.count + '）' : '') +
                                '</option>'
                        )
                        .join('')
                )}
            </select>
            <select id="f-difficulty">
                <option value="">全部难度</option>
                ${raw(
                    difficulties()
                        .map(
                            (d) =>
                                '<option value="' +
                                esc(d) +
                                '"' +
                                (d === filters.difficulty ? ' selected' : '') +
                                '>' +
                                esc(d) +
                                '</option>'
                        )
                        .join('')
                )}
            </select>
            <select id="f-time">
                <option value="">不限时长</option>
                <option value="15"${filters.max_time === '15' ? ' selected' : ''}>15 分钟以内</option>
                <option value="30"${filters.max_time === '30' ? ' selected' : ''}>30 分钟以内</option>
                <option value="60"${filters.max_time === '60' ? ' selected' : ''}>1 小时以内</option>
                <option value="120"${filters.max_time === '120' ? ' selected' : ''}>2 小时以内</option>
            </select>
            <select id="f-sort">
                <option value="updated"${filters.sort === 'updated' ? ' selected' : ''}>最近改动</option>
                <option value="created"${filters.sort === 'created' ? ' selected' : ''}>最新添加</option>
                <option value="name"${filters.sort === 'name' ? ' selected' : ''}>按名字</option>
                <option value="time_asc"${filters.sort === 'time_asc' ? ' selected' : ''}>耗时从短到长</option>
                <option value="time_desc"${filters.sort === 'time_desc' ? ' selected' : ''}>耗时从长到短</option>
            </select>
        </div>

        <div class="filter-row">
            <div class="chips" id="f-tags">
                ${raw(
                    tags
                        .slice(0, 28)
                        .map(
                            (t) =>
                                '<button type="button" class="chip' +
                                (filters.tags.indexOf(t.name) >= 0 ? ' on' : '') +
                                '" data-tag="' +
                                esc(t.name) +
                                '">' +
                                esc(t.name) +
                                '</button>'
                        )
                        .join('')
                )}
            </div>
            <label class="check inline"><input type="checkbox" id="f-fav"${filters.fav ? ' checked' : ''}><span>只看收藏</span></label>
            <label class="check inline"><input type="checkbox" id="f-mine"${filters.mine ? ' checked' : ''}><span>只看我加的</span></label>
        </div>
    </div>

    <div id="results" class="results"></div>`;

    const results = document.getElementById('results');
    const qInput = document.getElementById('f-q');
    let page = filters.page;

    function syncHash() {
        const q = {};

        if (filters.q) {
            q.q = filters.q;
        }

        if (filters.tags.length) {
            q.tags = filters.tags;
        }

        if (filters.tagmode !== 'all') {
            q.tagmode = filters.tagmode;
        }

        if (filters.cuisine) {
            q.cuisine = filters.cuisine;
        }

        if (filters.difficulty) {
            q.difficulty = filters.difficulty;
        }

        if (filters.max_time) {
            q.max_time = filters.max_time;
        }

        if (filters.fav) {
            q.fav = '1';
        }

        if (filters.mine) {
            q.mine = '1';
        }

        if (filters.sort !== 'updated') {
            q.sort = filters.sort;
        }

        if (page > 1) {
            q.page = String(page);
        }

        const search = qs(q);
        history.replaceState(null, '', '#/recipes' + search);
    }

    async function load() {
        syncHash();
        results.classList.add('loading');
        results.innerHTML = '<div class="skeleton-list"></div>';

        try {
            const res = await api.get('/api/recipes' + qs({
                q: filters.q,
                tags: filters.tags,
                tagmode: filters.tagmode,
                cuisine: filters.cuisine,
                difficulty: filters.difficulty,
                max_time: filters.max_time,
                fav: filters.fav,
                mine: filters.mine,
                sort: filters.sort,
                page: page,
                per: 24,
            }));

            renderResults(res);
        } catch (e) {
            results.innerHTML = html`<div class="empty"><p>${e.message}</p></div>`;
        } finally {
            results.classList.remove('loading');
        }
    }

    function renderResults(res) {
        if (!res.items.length) {
            results.innerHTML = html`<div class="empty">
                <p class="empty-mark">🍽</p>
                <p>没找到菜谱。</p>
                <p class="dim">换个关键词，或者 <a href="#/recipe/new">新建一道</a>。</p>
            </div>`;
            return;
        }

        const cards = res.items
            .map(
                (r) => html`
            <div class="card-wrap">
                <a class="card" href="#/recipe/${r.id}">
                    <div class="card-cover" style="${raw(coverStyle(r))}">
                        ${r.cover_url ? raw('') : html`<span class="card-cover-fallback">${(r.name || '?').slice(0, 1)}</span>`}
                        ${r.total_min ? html`<span class="card-time">${fmtDuration(r.total_min)}</span>` : raw('')}
                    </div>
                    <div class="card-body">
                        <h3 class="card-title">${r.name}</h3>
                        <div class="card-meta">${metaLine(r) || '未填信息'}</div>
                        <div class="card-tags">
                            ${raw(
                                (r.tags || [])
                                    .filter((t) => t.kind === 'tag')
                                    .slice(0, 3)
                                    .map((t) => '<span class="tag-mini">' + esc(t.name) + '</span>')
                                    .join('')
                            )}
                        </div>
                    </div>
                </a>
                <button class="card-fav${r.is_fav ? ' on' : ''}" data-fav="${r.id}" type="button" title="收藏">★</button>
            </div>`
            )
            .join('');

        let pager = '';

        if (res.pages > 1) {
            pager = '<div class="pager">';

            if (res.page > 1) {
                pager += '<button class="btn btn-sm" data-page="' + (res.page - 1) + '">上一页</button>';
            }

            pager += '<span class="dim">第 ' + res.page + ' / ' + res.pages + ' 页 · 共 ' + res.total + ' 道</span>';

            if (res.page < res.pages) {
                pager += '<button class="btn btn-sm" data-page="' + (res.page + 1) + '">下一页</button>';
            }

            pager += '</div>';
        } else {
            pager = '<div class="pager"><span class="dim">共 ' + res.total + ' 道菜</span></div>';
        }

        results.innerHTML = html`<div class="cards">${raw(cards)}</div>${raw(pager)}`;

        $$('[data-page]', results).forEach((b) => {
            b.addEventListener('click', () => {
                page = Number(b.getAttribute('data-page'));
                load();
                window.scrollTo(0, 0);
            });
        });

        $$('[data-fav]', results).forEach((b) => {
            b.addEventListener('click', async (e) => {
                e.preventDefault();
                e.stopPropagation();

                try {
                    const res2 = await api.post('/api/recipes/' + b.getAttribute('data-fav') + '/favorite');
                    b.classList.toggle('on', !!res2.is_fav);

                    if (filters.fav) {
                        load();
                    }
                } catch (err) {
                    toastError(err);
                }
            });
        });

        // 空状态里的链接也要保持筛选，这里不处理，直接跳列表首页
    }

    // ---- 筛选交互

    let tmr = 0;

    qInput.addEventListener('input', () => {
        clearTimeout(tmr);
        tmr = setTimeout(() => {
            filters.q = qInput.value.trim();
            page = 1;
            load();
        }, 260);
    });

    document.getElementById('f-cuisine').addEventListener('change', (e) => {
        filters.cuisine = e.target.value;
        page = 1;
        load();
    });

    document.getElementById('f-difficulty').addEventListener('change', (e) => {
        filters.difficulty = e.target.value;
        page = 1;
        load();
    });

    document.getElementById('f-time').addEventListener('change', (e) => {
        filters.max_time = e.target.value;
        page = 1;
        load();
    });

    document.getElementById('f-sort').addEventListener('change', (e) => {
        filters.sort = e.target.value;
        page = 1;
        load();
    });

    document.getElementById('f-fav').addEventListener('change', (e) => {
        filters.fav = e.target.checked;
        page = 1;
        load();
    });

    document.getElementById('f-mine').addEventListener('change', (e) => {
        filters.mine = e.target.checked;
        page = 1;
        load();
    });

    document.getElementById('f-tags').addEventListener('click', (e) => {
        const chip = e.target.closest('[data-tag]');

        if (!chip) {
            return;
        }

        const name = chip.getAttribute('data-tag');
        const i = filters.tags.indexOf(name);

        if (i >= 0) {
            filters.tags.splice(i, 1);
        } else {
            filters.tags.push(name);
        }

        chip.classList.toggle('on', i < 0);
        page = 1;
        load();
    });

    document.getElementById('btn-export').addEventListener('click', () => {
        download('/api/recipes/export?all=1');
        toast('正在导出全部菜谱', 'ok');
    });

    document.getElementById('btn-import').addEventListener('click', () => openImport(load));

    // 「随便来一道」：沿用当前筛选（菜系 / 难度 / 时长 / 点亮的标签），
    // 抽不到就如实回一句，不塞别的糊弄人。
    document.getElementById('btn-random').addEventListener('click', async (e) => {
        const btn = e.target;
        const val = (id) => {
            const el = document.getElementById(id);
            return el ? String(el.value || '').trim() : '';
        };

        const tags = $$('#f-tags .chip.on').map((b) => b.dataset.tag);

        btn.disabled = true;
        btn.textContent = '翻翻…';

        try {
            const res = await api.get('/api/recipes/random' + qs({
                cuisine: val('f-cuisine'),
                difficulty: val('f-difficulty'),
                max_time: Number(val('f-time')) || 0,
                tags: tags,
                fav: document.getElementById('f-fav') && document.getElementById('f-fav').checked ? 1 : 0,
                mine: document.getElementById('f-mine') && document.getElementById('f-mine').checked ? 1 : 0,
            }));

            location.hash = '#/recipe/' + res.id;
        } catch (err) {
            toastError(err);
        } finally {
            btn.disabled = false;
            btn.textContent = '🎲 随便来一道';
        }
    });

    load();
}

// ==================================================================== 导入

async function openImport(onDone) {
    const f = await formBox({
        title: '导入菜谱',
        okText: '开始导入',
        fields: [
            {
                name: 'paste',
                label: '粘贴 JSON',
                type: 'textarea',
                rows: 6,
                placeholder: '把导出的 JSON 粘这里；也认 Mealie 那种格式',
            },
            { name: 'overwrite', label: '同名菜谱', type: 'select', value: '0', options: [
                { value: '0', label: '跳过不导入' },
                { value: '1', label: '覆盖已有的' },
            ] },
        ],
    });

    if (!f) {
        return;
    }

    const text = (f.paste || '').trim();

    if (text === '') {
        toast('至少要粘贴点内容，或者用「批量导入」选文件', 'warn');
        return;
    }

    try {
        const res = await api.post('/api/recipes/import', { text: text, overwrite: f.overwrite === '1' });
        toast('导入完成：新增 ' + res.created + ' 道，跳过 ' + res.skipped + ' 道', 'ok');
        onDone();
    } catch (e) {
        toastError(e);
    }
}

// ==================================================================== 详情

export async function renderRecipeDetail(root, params) {
    await loadIngredients();

    let r;

    try {
        r = await api.get('/api/recipes/' + params.id);
    } catch (e) {
        root.innerHTML = html`<div class="empty"><p>${e.message}</p><p class="dim"><a href="#/recipes">回列表</a></p></div>`;
        return;
    }

    let factor = 1;
    const baseServings = Number(r.servings) || 0;

    root.innerHTML = html`
    ${raw(datalistHtml())}
    <div class="detail-head">
        <a class="back" href="#/recipes">← 回列表</a>
        <div class="detail-actions">
            <button class="btn btn-sm" id="d-fav" type="button">${r.is_fav ? '★ 已收藏' : '☆ 收藏'}</button>
            <button class="btn btn-sm btn-primary" id="d-cook" type="button">✓ 做过了</button>
            <button class="btn btn-sm" id="d-shop" type="button">加进采购</button>
            <button class="btn btn-sm" id="d-plan" type="button">排进计划</button>
            ${r.visibility === 'private'
                ? raw('')
                : html`<button class="btn btn-sm" id="d-share" type="button">分享</button>`}
            <button class="btn btn-sm" id="d-print" type="button">打印</button>
            <button class="btn btn-sm" id="d-dup" type="button">复制</button>
            <a class="btn btn-primary btn-sm" href="#/recipe/${r.id}/edit">编辑</a>
        </div>
    </div>

    <div class="detail">
        <div class="detail-cover" style="${raw(coverStyle(r))}">
            ${r.cover_url ? raw('') : html`<span class="detail-cover-fallback">${(r.name || '?').slice(0, 1)}</span>`}
        </div>

        <div class="detail-main">
            <h1 class="detail-title">${r.name}</h1>
            <div class="detail-meta">
                ${r.cuisine ? html`<span class="pill">${r.cuisine}</span>` : raw('')}
                ${r.difficulty ? html`<span class="pill">${r.difficulty}</span>` : raw('')}
                ${r.total_min ? html`<span class="pill">${fmtDuration(r.total_min)}</span>` : raw('')}
                ${r.servings_text || r.servings ? html`<span class="pill">${r.servings_text || r.servings + ' 人份'}</span>` : raw('')}
                ${r.visibility === 'private' ? html`<span class="pill pill-mute">私有</span>` : raw('')}
            </div>

            ${r.description ? html`<p class="detail-desc">${r.description}</p>` : raw('')}

            <div class="detail-tags">
                ${raw((r.tags || []).map((t) => '<span class="tag-mini">' + esc(t.name) + '</span>').join(''))}
            </div>

            <div class="detail-times">
                ${r.prep_min ? html`<span>准备 ${fmtDuration(r.prep_min)}</span>` : raw('')}
                ${r.cook_min ? html`<span>烹饪 ${fmtDuration(r.cook_min)}</span>` : raw('')}
                ${r.total_min ? html`<span>合计 ${fmtDuration(r.total_min)}</span>` : raw('')}
            </div>
        </div>
    </div>

    <div class="cols">
        <section class="panel">
            <div class="panel-head">
                <h3>用料</h3>
                ${baseServings > 0
                    ? html`<div class="servings-scaler">
                          <label>按 <input type="number" id="sv" min="1" max="99" step="1" value="${baseServings}"> 人份</label>
                          <button class="btn btn-sm btn-ghost" id="sv-reset" type="button">还原</button>
                      </div>`
                    : raw('')}
            </div>
            <ul class="ing-list" id="ing-list"></ul>
        </section>

        <section class="panel">
            <h3>做法</h3>
            <ol class="step-list">
                ${raw(
                    (r.steps || [])
                        .map(
                            (s, i) => html`
                    <li class="step">
                        <div class="step-no">${i + 1}</div>
                        <div class="step-body">
                            ${s.image_url ? html`<img class="step-img" src="${s.image_url}" alt="" loading="lazy">` : raw('')}
                            <p class="step-text">${s.text}</p>
                            ${s.duration_min ? html`<span class="dim step-dur">约 ${fmtDuration(s.duration_min)}</span>` : raw('')}
                        </div>
                    </li>`
                        )
                        .join('') || '<li class="dim">还没写步骤。</li>'
                )}
            </ol>
        </section>
    </div>

    ${r.tips || r.taboo || r.storage || r.leftover
        ? html`<div class="cols">
              ${r.tips ? html`<section class="panel note"><h3>小贴士</h3><p>${r.tips}</p></section>` : raw('')}
              ${r.taboo ? html`<section class="panel note"><h3>忌口提醒</h3><p>${r.taboo}</p></section>` : raw('')}
              ${r.storage ? html`<section class="panel note"><h3>储存</h3><p>${r.storage}</p></section>` : raw('')}
              ${r.leftover ? html`<section class="panel note"><h3>剩菜怎么吃</h3><p>${r.leftover}</p></section>` : raw('')}
          </div>`
        : raw('')}

    ${Object.keys(r.nutrition_raw || {}).length
        ? html`<section class="panel">
              <h3>营养（每份）</h3>
              <div class="nutri">
                  ${raw(
                      Object.keys(r.nutrition_raw)
                          .map(
                              (k) =>
                                  '<span class="nutri-item"><em>' +
                                  esc(nutriLabel(k)) +
                                  '</em><strong>' +
                                  esc(fmtNum(r.nutrition_raw[k])) +
                                  '</strong></span>'
                          )
                          .join('')
                  )}
              </div>
          </section>`
        : raw('')}

    <section class="panel" id="cook-panel">
        <div class="panel-head">
            <h3>做过的记录</h3>
            <span class="dim cook-sum" id="cook-sum"></span>
        </div>
        <ul class="comment-list" id="cook-list"><li class="dim">正在看…</li></ul>
    </section>

    <section class="panel" id="comment-panel">
        <div class="panel-head">
            <h3>家人留言</h3>
            <span class="dim">谁都能留，家里人都看得见</span>
        </div>
        <ul class="comment-list" id="comment-list"><li class="dim">正在看…</li></ul>
        <form class="comment-form" id="comment-form">
            <textarea id="comment-text" rows="2" maxlength="500" placeholder="这次少放了盐更好 / 孩子爱吃 / 下次试试加木耳…"></textarea>
            <div class="row-end">
                <button class="btn btn-primary btn-sm" type="submit">发出去</button>
            </div>
        </form>
    </section>

    <div class="detail-foot dim">
        最后改动 ${fmtAgo(r.updated_at)}${r.owner_display || r.owner_name ? ' · ' + esc(r.owner_display || r.owner_name) : ''}
    </div>

    <div class="detail-danger">
        <button class="btn btn-danger btn-sm" id="d-del" type="button">删除这道菜</button>
    </div>`;

    // ---- 用料（带份数缩放）

    function renderIngredients() {
        const list = document.getElementById('ing-list');
        const items = r.ingredients || [];

        if (!items.length) {
            list.innerHTML = '<li class="dim">还没写用料。</li>';
            return;
        }

        list.innerHTML = items
            .map((i) => {
                const kind = unitKind(i.unit);
                let qty = i.display;

                if (factor !== 1 && i.amount !== null && i.amount !== undefined && kind !== 'fuzzy' && kind !== 'other') {
                    const v = Math.round(Number(i.amount) * factor * 100) / 100;
                    qty = fmtNum(v) + (i.unit ? ' ' + i.unit : '');
                }

                return html`<li class="ing${i.optional ? ' optional' : ''}">
                    <span class="ing-name">${i.name}${i.optional ? html`<em class="dim">（可选）</em>` : raw('')}</span>
                    <span class="ing-qty">${qty}</span>
                    ${i.note ? html`<span class="ing-note dim">${i.note}</span>` : raw('')}
                </li>`;
            })
            .join('');
    }

    renderIngredients();

    const sv = document.getElementById('sv');

    if (sv) {
        sv.addEventListener('input', () => {
            const v = Number(sv.value);

            factor = v > 0 && baseServings > 0 ? v / baseServings : 1;
            renderIngredients();
        });

        document.getElementById('sv-reset').addEventListener('click', () => {
            sv.value = baseServings;
            factor = 1;
            renderIngredients();
        });
    }

    // ---- 操作

    document.getElementById('d-fav').addEventListener('click', async (e) => {
        try {
            const res = await api.post('/api/recipes/' + r.id + '/favorite');
            e.target.textContent = res.is_fav ? '★ 已收藏' : '☆ 收藏';
            e.target.classList.toggle('btn-primary', !!res.is_fav);
        } catch (err) {
            toastError(err);
        }
    });

    document.getElementById('d-dup').addEventListener('click', async () => {
        try {
            const res = await api.post('/api/recipes/' + r.id + '/duplicate');
            toast('复制好了', 'ok');
            location.hash = '#/recipe/' + res.id + '/edit';
        } catch (e) {
            toastError(e);
        }
    });

    document.getElementById('d-shop').addEventListener('click', () => addToShopping([r.id]));
    document.getElementById('d-plan').addEventListener('click', () => addToPlan(r));

    // 分享：就是这条菜的地址。站点开了「游客浏览」对方不登录也能打开；
    // 没开的话对方会看到登录页——按钮提示里讲清楚这点。
    const btnShare = document.getElementById('d-share');

    if (btnShare) {
        btnShare.addEventListener('click', async () => {
            const url = location.origin + '/#/recipe/' + r.id;

            try {
                await navigator.clipboard.writeText(url);
                toast(
                    state.meta && state.meta.publicBrowse
                        ? '链接已复制，对方不用登录就能看'
                        : '链接已复制（对方需要登录；在管理后台开「游客浏览」可不登录）',
                    'ok'
                );
            } catch (err) {
                toast('复制失败，地址栏的链接就是它', 'warn');
            }
        });
    }

    document.getElementById('d-print').addEventListener('click', () => window.print());

    document.getElementById('d-del').addEventListener('click', async () => {
        const ok = await confirmBox({
            title: '删除菜谱',
            message: '「' + r.name + '」会先放进回收站，配料、步骤、图片都还在，随时能从回收站恢复。想彻底删掉，去回收站再来一次。',
            okText: '放进回收站',
            danger: true,
        });

        if (!ok) {
            return;
        }

        try {
            await api.del('/api/recipes/' + r.id);
            toast('已放进回收站，随时能捞回来', 'ok');
            location.hash = '#/recipes';
        } catch (e) {
            toastError(e);
        }
    });

    // ---- 下厨记录 & 家人留言

    const cookSum = document.getElementById('cook-sum');
    const cookList = document.getElementById('cook-list');
    const cmtList = document.getElementById('comment-list');
    const cmtForm = document.getElementById('comment-form');
    const cmtText = document.getElementById('comment-text');

    async function drawCooks(summary, items) {
        if (summary) {
            cookSum.textContent = summary.cook_count
                ? '做过 ' + summary.cook_count + ' 次，上次 ' + fmtAgo(summary.last_cook_at)
                : '还没记过';
        }

        if (!items || !items.length) {
            cookList.innerHTML = '<li class="dim">还没做过这道菜（或者没记）。</li>';
            return;
        }

        // 手工拼 HTML：每个字段都过 esc，跟 html`` 的转义强度一致
        cookList.innerHTML = items
            .map(
                (c) =>
                    '<li class="comment-item">' +
                    '<span class="comment-meta">' +
                    '<strong>' + esc(c.user_name || '某人') + '</strong>' +
                    ' · ' + esc(fmtAgo(c.created_at)) +
                    '</span>' +
                    '<span class="comment-text">' +
                    esc(c.servings ? '按 ' + fmtNum(c.servings) + ' 人份做了一次' : '做了一次') +
                    '</span>' +
                    '</li>'
            )
            .join('');
    }

    async function loadCooks() {
        try {
            const res = await api.get('/api/recipes/' + r.id + '/cooks' + qs({ limit: 5 }));
            await drawCooks(res.summary, res.items);
        } catch (e) {
            cookList.innerHTML = '<li class="dim">记录加载不出来：' + esc(e.message) + '</li>';
        }
    }

    async function drawComments(items) {
        if (!items.length) {
            cmtList.innerHTML = '<li class="dim">还没有人留言。做过之后觉得啥可以改进？写一句给下次的自己。</li>';
            return;
        }

        const rows = items
            .map(
                (c) =>
                    '<li class="comment-item">' +
                    '<span class="comment-meta">' +
                    '<strong>' + esc(c.user_name || '某人') + '</strong>' +
                    ' · ' + esc(fmtAgo(c.created_at)) +
                    (c.user_id === (state.user && state.user.id)
                        ? ' · <button class="link btn-del-cmt" data-id="' + Number(c.id) + '">删</button>'
                        : '') +
                    '</span>' +
                    '<p class="comment-text">' + esc(c.text) + '</p>' +
                    '</li>'
            )
            .join('');

        cmtList.innerHTML = rows;
    }

    async function loadComments() {
        try {
            const res = await api.get('/api/recipes/' + r.id + '/comments');
            await drawComments(res.items || []);
        } catch (e) {
            cmtList.innerHTML = '<li class="dim">留言加载不出来：' + esc(e.message) + '</li>';
        }
    }

    cmtList.addEventListener('click', async (e) => {
        const btn = e.target.closest('.btn-del-cmt');

        if (!btn) {
            return;
        }

        try {
            await api.del('/api/comments/' + btn.dataset.id);
            toast('删掉了', 'ok');
            loadComments();
        } catch (err) {
            toastError(err);
        }
    });

    cmtForm.addEventListener('submit', async (e) => {
        e.preventDefault();

        const text = cmtText.value.trim();

        if (!text) {
            return;
        }

        cmtText.disabled = true;

        try {
            await api.post('/api/recipes/' + r.id + '/comments', { text: text });
            cmtText.value = '';
            toast('留言已发布', 'ok');
            loadComments();
        } catch (err) {
            toastError(err);
        } finally {
            cmtText.disabled = false;
        }
    });

    document.getElementById('d-cook').addEventListener('click', async (e) => {
        e.target.disabled = true;

        try {
            const res = await api.post('/api/recipes/' + r.id + '/cooks', {
                servings: Number(document.getElementById('sv') ? document.getElementById('sv').value : 0) || 0,
            });

            await drawCooks(res.summary, null);
            loadCooks();
            toast('记下了，这道菜又 +1 次', 'ok');
        } catch (err) {
            toastError(err);
        } finally {
            e.target.disabled = false;
        }
    });

    loadCooks();
    loadComments();
}

function nutriLabel(k) {
    const map = {
        calories: '热量 kcal',
        protein: '蛋白质 g',
        fat: '脂肪 g',
        carbs: '碳水 g',
        fiber: '膳食纤维 g',
        sodium: '钠 mg',
        sugar: '糖 g',
        calcium: '钙 mg',
    };

    return map[k] || k;
}

// ==================================================================== 编辑器

function blankRecipe() {
    return {
        id: 0,
        name: '',
        description: '',
        cover: '',
        cover_url: '',
        cover_thumb: '',
        servings: 2,
        servings_text: '',
        prep_min: 0,
        cook_min: 0,
        total_min: 0,
        difficulty: '简单',
        cuisine: '家常菜',
        tips: '',
        taboo: '',
        storage: '',
        leftover: '',
        nutrition: {},
        visibility: 'public',
        tags: [],
        ingredients: [],
        steps: [],
    };
}

export async function renderRecipeEditor(root, params) {
    await loadIngredients();

    const isNew = !params.id;
    let r = isNew ? blankRecipe() : await api.get('/api/recipes/' + params.id);

    if (isNew) {
        r.ingredients = [{ name: '', amount: null, unit: '克', qty_text: '', note: '', optional: 0 }];
        r.steps = [{ text: '', image: '', duration_min: 0 }];
    }

    const allTags = tagOptions();

    root.innerHTML = html`
    ${raw(datalistHtml())}
    <div class="page-head">
        <h2 class="page-title">${isNew ? '新菜谱' : '编辑：' + r.name}</h2>
        <div class="page-actions">
            <a class="btn btn-sm" href="${isNew ? '#/recipes' : '#/recipe/' + r.id}">取消</a>
            <button class="btn btn-primary btn-sm" id="btn-save" type="button">保存</button>
        </div>
    </div>

    <form id="recipe-form" class="editor" autocomplete="off">
        <section class="panel">
            <h3>基本信息</h3>

            <div class="editor-top">
                <div class="cover-picker">
                    <div class="cover-preview" id="cover-preview" style="${raw(coverStyle(r))}">
                        ${r.cover_url || r.cover ? raw('') : html`<span class="dim">还没有封面</span>`}
                    </div>
                    <label class="btn btn-sm">
                        选封面
                        <input type="file" id="cover-file" accept="image/*" hidden>
                    </label>
                    <button class="btn btn-sm btn-ghost" type="button" id="cover-clear">去掉</button>
                    <input type="hidden" name="cover" value="${r.cover || ''}">
                </div>

                <div class="editor-fields">
                    <label class="field">
                        <span>菜名 *</span>
                        <input type="text" name="name" required maxlength="80" value="${r.name}" placeholder="比如：红烧肉">
                    </label>
                    <label class="field">
                        <span>简介</span>
                        <textarea name="description" rows="2" maxlength="1000" placeholder="一句话说说这道菜">${r.description || ''}</textarea>
                    </label>
                    <div class="field-row">
                        <label class="field">
                            <span>菜系</span>
                            <input type="text" name="cuisine" list="cuisine-list" value="${r.cuisine || ''}" maxlength="16">
                            <datalist id="cuisine-list">
                                ${raw(cuisineOptions().map((c) => '<option value="' + esc(c.name) + '">').join(''))}
                            </datalist>
                        </label>
                        <label class="field">
                            <span>难度</span>
                            <select name="difficulty">
                                ${raw(
                                    difficulties()
                                        .map(
                                            (d) =>
                                                '<option value="' +
                                                esc(d) +
                                                '"' +
                                                (d === r.difficulty ? ' selected' : '') +
                                                '>' +
                                                esc(d) +
                                                '</option>'
                                        )
                                        .join('')
                                )}
                            </select>
                        </label>
                        <label class="field">
                            <span>可见性</span>
                            <select name="visibility">
                                <option value="public"${r.visibility !== 'private' ? ' selected' : ''}>家里都能看</option>
                                <option value="private"${r.visibility === 'private' ? ' selected' : ''}>只有我能看</option>
                            </select>
                        </label>
                    </div>
                    <div class="field-row">
                        <label class="field">
                            <span>准备（分钟）</span>
                            <input type="number" name="prep_min" min="0" max="9999" value="${r.prep_min || 0}">
                        </label>
                        <label class="field">
                            <span>烹饪（分钟）</span>
                            <input type="number" name="cook_min" min="0" max="9999" value="${r.cook_min || 0}">
                        </label>
                        <label class="field">
                            <span>份数</span>
                            <input type="number" name="servings" min="0" max="99" step="0.5" value="${r.servings || 0}">
                        </label>
                        <label class="field">
                            <span>份数说明</span>
                            <input type="text" name="servings_text" maxlength="24" value="${r.servings_text || ''}" placeholder="比如 4 人份">
                        </label>
                    </div>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <h3>用料</h3>
                <button class="btn btn-sm" type="button" id="add-ing">+ 加一行</button>
            </div>
            <p class="dim small">数量可以写「500 克」「1 斤」「2 两」，也可以只选「少许 / 适量」——模糊量词在采购清单里不会被合并。</p>
            <div id="ing-rows" class="rows"></div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <h3>做法</h3>
                <button class="btn btn-sm" type="button" id="add-step">+ 加一步</button>
            </div>
            <div id="step-rows" class="rows"></div>
        </section>

        <section class="panel">
            <h3>标签</h3>
            <div class="chips" id="tag-picker">
                ${raw(
                    allTags
                        .map(
                            (t) =>
                                '<button type="button" class="chip' +
                                (r.tags.some((x) => x.name === t.name) ? ' on' : '') +
                                '" data-tag="' +
                                esc(t.name) +
                                '">' +
                                esc(t.name) +
                                '</button>'
                        )
                        .join('')
                )}
            </div>
            <div class="field-row" style="margin-top:10px">
                <label class="field">
                    <span>自定义标签</span>
                    <input type="text" id="tag-custom" placeholder="输入后点右边加号，最多 12 个">
                </label>
                <label class="field" style="flex:0 0 auto">
                    <span>&nbsp;</span>
                    <button class="btn" type="button" id="tag-add">加标签</button>
                </label>
            </div>
        </section>

        <section class="panel">
            <h3>经验与其他</h3>
            <div class="field-row wrap">
                <label class="field">
                    <span>小贴士</span>
                    <textarea name="tips" rows="3" maxlength="2000" placeholder="火候、替代料、容易翻车的地方…">${r.tips || ''}</textarea>
                </label>
                <label class="field">
                    <span>忌口提醒</span>
                    <textarea name="taboo" rows="3" maxlength="600">${r.taboo || ''}</textarea>
                </label>
                <label class="field">
                    <span>储存方式</span>
                    <textarea name="storage" rows="3" maxlength="600">${r.storage || ''}</textarea>
                </label>
                <label class="field">
                    <span>剩菜怎么吃</span>
                    <textarea name="leftover" rows="3" maxlength="600">${r.leftover || ''}</textarea>
                </label>
            </div>
        </section>

        <section class="panel">
            <h3>营养（每份，可留空）</h3>
            <div class="field-row">
                <label class="field"><span>热量 kcal</span><input type="number" step="0.1" min="0" name="n_calories" value="${r.nutrition.calories || ''}"></label>
                <label class="field"><span>蛋白质 g</span><input type="number" step="0.1" min="0" name="n_protein" value="${r.nutrition.protein || ''}"></label>
                <label class="field"><span>脂肪 g</span><input type="number" step="0.1" min="0" name="n_fat" value="${r.nutrition.fat || ''}"></label>
                <label class="field"><span>碳水 g</span><input type="number" step="0.1" min="0" name="n_carbs" value="${r.nutrition.carbs || ''}"></label>
                <label class="field"><span>钠 mg</span><input type="number" step="0.1" min="0" name="n_sodium" value="${r.nutrition.sodium || ''}"></label>
            </div>
        </section>

        <div class="editor-foot">
            <button class="btn btn-primary" type="submit">保存</button>
            ${!isNew ? html`<button class="btn btn-danger" type="button" id="btn-del">删除</button>` : raw('')}
        </div>
    </form>`;

    // ---- 用料行

    let ingredients = (r.ingredients || []).map((i) => ({
        id: i.id || 0,
        name: i.name || '',
        amount: i.amount === null || i.amount === undefined ? '' : i.amount,
        unit: i.unit || '',
        qty_text: i.qty_text || '',
        note: i.note || '',
        optional: i.optional ? 1 : 0,
    }));

    const ingRows = document.getElementById('ing-rows');

    function readIngredients() {
        $$('.ing-row', ingRows).forEach((row, idx) => {
            if (!ingredients[idx]) {
                return;
            }

            ingredients[idx].name = row.querySelector('[data-k="name"]').value;
            ingredients[idx].amount = row.querySelector('[data-k="amount"]').value;
            ingredients[idx].unit = row.querySelector('[data-k="unit"]').value;
            ingredients[idx].note = row.querySelector('[data-k="note"]').value;
            ingredients[idx].optional = row.querySelector('[data-k="optional"]').checked ? 1 : 0;
        });
    }

    function renderIngredients() {
        ingRows.innerHTML = ingredients
            .map(
                (it, idx) => html`
            <div class="ing-row" data-idx="${idx}">
                <input type="text" data-k="name" list="ing-names" value="${it.name}" placeholder="食材" maxlength="40">
                <input type="number" data-k="amount" value="${it.amount}" step="0.01" min="0" placeholder="数量">
                ${raw(unitSelect('unit', it.unit, 'unit-sel').replace('name="unit"', 'data-k="unit"'))}
                <input type="text" data-k="note" value="${it.note}" placeholder="备注，比如切丝" maxlength="80">
                <label class="check tiny" title="可选"><input type="checkbox" data-k="optional"${it.optional ? ' checked' : ''}><span>可选</span></label>
                <button class="icon-btn" type="button" data-move="-1" title="上移">↑</button>
                <button class="icon-btn" type="button" data-move="1" title="下移">↓</button>
                <button class="icon-btn danger" type="button" data-del="1" title="删除">✕</button>
            </div>`
            )
            .join('');

        // unit 选择框是拼出来的，单独挂事件（change 时直接写回数组，不用重排 DOM）
        $$('.ing-row', ingRows).forEach((row, idx) => {
            row.querySelectorAll('input[data-k], select[data-k]').forEach((el) => {
                el.addEventListener('change', () => {
                    const k = el.getAttribute('data-k');
                    ingredients[idx][k] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
                });
            });
        });
    }

    ingRows.addEventListener('click', (e) => {
        const row = e.target.closest('.ing-row');

        if (!row) {
            return;
        }

        const idx = Number(row.getAttribute('data-idx'));
        readIngredients();

        if (e.target.closest('[data-del]')) {
            ingredients.splice(idx, 1);

            if (!ingredients.length) {
                ingredients.push({ name: '', amount: '', unit: '克', note: '', optional: 0 });
            }

            renderIngredients();
            return;
        }

        const mv = e.target.closest('[data-move]');

        if (mv) {
            const dir = Number(mv.getAttribute('data-move'));
            const to = idx + dir;

            if (to < 0 || to >= ingredients.length) {
                return;
            }

            const tmp = ingredients[idx];
            ingredients[idx] = ingredients[to];
            ingredients[to] = tmp;
            renderIngredients();
        }
    });

    document.getElementById('add-ing').addEventListener('click', () => {
        readIngredients();
        ingredients.push({ name: '', amount: '', unit: '克', note: '', optional: 0 });
        renderIngredients();

        const rows = $$('.ing-row', ingRows);
        const last = rows[rows.length - 1];

        if (last) {
            last.querySelector('[data-k="name"]').focus();
        }
    });

    renderIngredients();

    // ---- 步骤行

    let steps = (r.steps || []).map((s) => ({
        id: s.id || 0,
        text: s.text || '',
        image: s.image || '',
        image_url: s.image_url || '',
        duration_min: s.duration_min || 0,
    }));

    const stepRows = document.getElementById('step-rows');

    function readSteps() {
        $$('.step-row', stepRows).forEach((row, idx) => {
            if (!steps[idx]) {
                return;
            }

            steps[idx].text = row.querySelector('[data-k="text"]').value;
            steps[idx].duration_min = row.querySelector('[data-k="duration_min"]').value;
        });
    }

    function renderSteps() {
        stepRows.innerHTML = steps
            .map(
                (s, idx) => html`
            <div class="step-row" data-idx="${idx}">
                <div class="step-no">${idx + 1}</div>
                <div class="step-fields">
                    <textarea data-k="text" rows="2" placeholder="这一步做什么">${s.text}</textarea>
                    <div class="step-tools">
                        <label class="btn btn-sm btn-ghost">
                            配图
                            <input type="file" data-img="${idx}" accept="image/*" hidden>
                        </label>
                        ${s.image_url || s.image ? html`<img class="thumb" src="${s.image_url || '/uploads/' + s.image}" alt="">` : raw('')}
                        <label class="inline-field">约 <input type="number" data-k="duration_min" min="0" max="9999" value="${s.duration_min || 0}"> 分钟</label>
                        <button class="icon-btn" type="button" data-move="-1">↑</button>
                        <button class="icon-btn" type="button" data-move="1">↓</button>
                        <button class="icon-btn danger" type="button" data-del="1">✕</button>
                    </div>
                </div>
            </div>`
            )
            .join('');

        $$('input[data-img]', stepRows).forEach((inp) => {
            inp.addEventListener('change', async () => {
                const idx = Number(inp.getAttribute('data-img'));
                const file = inp.files && inp.files[0];

                if (!file) {
                    return;
                }

                readSteps();

                try {
                    const up = await uploadImage(file);
                    steps[idx].image = up.path;
                    steps[idx].image_url = up.url;
                    renderSteps();
                } catch (e) {
                    toastError(e);
                }
            });
        });
    }

    stepRows.addEventListener('click', (e) => {
        const row = e.target.closest('.step-row');

        if (!row) {
            return;
        }

        const idx = Number(row.getAttribute('data-idx'));
        readSteps();

        if (e.target.closest('[data-del]')) {
            steps.splice(idx, 1);

            if (!steps.length) {
                steps.push({ text: '', image: '', duration_min: 0 });
            }

            renderSteps();
            return;
        }

        const mv = e.target.closest('[data-move]');

        if (mv) {
            const dir = Number(mv.getAttribute('data-move'));
            const to = idx + dir;

            if (to < 0 || to >= steps.length) {
                return;
            }

            const tmp = steps[idx];
            steps[idx] = steps[to];
            steps[to] = tmp;
            renderSteps();
        }
    });

    document.getElementById('add-step').addEventListener('click', () => {
        readSteps();
        steps.push({ text: '', image: '', duration_min: 0 });
        renderSteps();

        const rows = $$('.step-row', stepRows);
        const last = rows[rows.length - 1];

        if (last) {
            last.querySelector('textarea').focus();
        }
    });

    renderSteps();

    // ---- 标签

    const picked = new Set((r.tags || []).map((t) => t.name));

    document.getElementById('tag-picker').addEventListener('click', (e) => {
        const chip = e.target.closest('[data-tag]');

        if (!chip) {
            return;
        }

        const name = chip.getAttribute('data-tag');

        if (picked.has(name)) {
            picked.delete(name);
            chip.classList.remove('on');
        } else {
            picked.add(name);
            chip.classList.add('on');
        }
    });

    document.getElementById('tag-add').addEventListener('click', () => {
        const inp = document.getElementById('tag-custom');
        const v = inp.value.trim();

        if (!v) {
            return;
        }

        picked.add(v);

        const exists = $$('[data-tag]').some((c) => c.getAttribute('data-tag') === v);

        if (!exists) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'chip on';
            btn.setAttribute('data-tag', v);
            btn.textContent = v;
            document.getElementById('tag-picker').appendChild(btn);
        } else {
            $$('[data-tag]').forEach((c) => {
                if (c.getAttribute('data-tag') === v) {
                    c.classList.add('on');
                }
            });
        }

        inp.value = '';
    });

    // ---- 封面

    document.getElementById('cover-file').addEventListener('change', async (e) => {
        const file = e.target.files && e.target.files[0];

        if (!file) {
            return;
        }

        const preview = document.getElementById('cover-preview');
        preview.innerHTML = '<span class="dim">正在压缩上传…</span>';

        try {
            const up = await uploadImage(file);
            document.querySelector('input[name="cover"]').value = up.path;
            preview.style.backgroundImage = 'url(' + up.thumb_url + ')';
            preview.innerHTML = '';
            toast('封面已上传（' + up.human + '）', 'ok');
        } catch (err) {
            toastError(err);
            preview.innerHTML = '<span class="dim">还没有封面</span>';
        }
    });

    document.getElementById('cover-clear').addEventListener('click', () => {
        document.querySelector('input[name="cover"]').value = '';
        const preview = document.getElementById('cover-preview');
        preview.style.backgroundImage = '';
        preview.innerHTML = '<span class="dim">还没有封面</span>';
    });

    // ---- 保存

    async function save() {
        readIngredients();
        readSteps();

        const f = document.getElementById('recipe-form');
        const fd = new FormData(f);
        const get = (k) => (fd.get(k) === null ? '' : String(fd.get(k)).trim());

        const nutrition = {};

        ['calories', 'protein', 'fat', 'carbs', 'sodium'].forEach((k) => {
            const v = get('n_' + k);

            if (v !== '' && isFinite(Number(v))) {
                nutrition[k] = Number(v);
            }
        });

        const payload = {
            name: get('name'),
            description: get('description'),
            cover: get('cover'),
            cuisine: get('cuisine'),
            difficulty: get('difficulty'),
            visibility: get('visibility'),
            prep_min: Number(get('prep_min')) || 0,
            cook_min: Number(get('cook_min')) || 0,
            servings: Number(get('servings')) || 0,
            servings_text: get('servings_text'),
            tips: get('tips'),
            taboo: get('taboo'),
            storage: get('storage'),
            leftover: get('leftover'),
            nutrition: nutrition,
            tags: Array.from(picked),
            ingredients: ingredients
                .filter((i) => String(i.name).trim() !== '')
                .map((i) => ({
                    name: String(i.name).trim(),
                    amount: String(i.amount).trim() === '' ? null : Number(i.amount),
                    unit: i.unit || '',
                    note: i.note || '',
                    optional: i.optional ? 1 : 0,
                })),
            steps: steps
                .filter((s) => String(s.text).trim() !== '' || s.image)
                .map((s) => ({
                    text: String(s.text).trim(),
                    image: s.image || '',
                    duration_min: Number(s.duration_min) || 0,
                })),
        };

        if (payload.name === '') {
            toast('菜名总得有吧', 'warn');
            return;
        }

        if (!payload.ingredients.length) {
            toast('至少写一样用料', 'warn');
            return;
        }

        const btn = document.getElementById('btn-save');
        btn.disabled = true;
        btn.textContent = '保存中…';

        try {
            if (isNew) {
                const res = await api.post('/api/recipes', payload);
                toast('菜谱已保存', 'ok');
                location.hash = '#/recipe/' + res.id;
            } else {
                await api.put('/api/recipes/' + r.id, payload);
                toast('已更新', 'ok');
                location.hash = '#/recipe/' + r.id;
            }
        } catch (e) {
            toastError(e);
            btn.disabled = false;
            btn.textContent = '保存';
        }
    }

    document.getElementById('btn-save').addEventListener('click', save);
    document.getElementById('recipe-form').addEventListener('submit', (e) => {
        e.preventDefault();
        save();
    });

    const del = document.getElementById('btn-del');

    if (del) {
        del.addEventListener('click', async () => {
            const ok = await confirmBox({
                title: '删除菜谱',
                message: '「' + r.name + '」会被删掉，这一步不可撤销。',
                okText: '删除',
                danger: true,
            });

            if (!ok) {
                return;
            }

            try {
                await api.del('/api/recipes/' + r.id);
                toast('已删除', 'ok');
                location.hash = '#/recipes';
            } catch (e) {
                toastError(e);
            }
        });
    }
}

async function uploadImage(file) {
    const fd = new FormData();
    fd.append('file', file);

    return await api.post('/api/upload', fd);
}

// ==================================================================== 快捷操作

export async function addToShopping(recipeIds) {
    let lists = [];

    try {
        lists = await api.get('/api/shopping?open=1');
    } catch (e) {
        lists = [];
    }

    const options = lists.map((l) => ({ value: String(l.id), label: l.name + '（' + l.item_count + ' 项）' }));
    options.push({ value: 'new', label: '＋ 新建一份清单' });

    const f = await formBox({
        title: '加进采购清单',
        okText: '加进去',
        fields: [
            { name: 'list', label: '加到哪份清单', type: 'select', value: options[0].value, options: options },
            { name: 'name', label: '新清单名字（选新建时用）', value: '采购清单 ' + today() },
        ],
    });

    if (!f) {
        return;
    }

    try {
        let listId = Number(f.list);

        if (f.list === 'new' || !listId) {
            const created = await api.post('/api/shopping', { name: f.name || '采购清单 ' + today() });
            listId = created.id;
        }

        const res = await api.post('/api/shopping/' + listId + '/add-recipes', { recipe_ids: recipeIds });
        toast('加好了，清单现在有 ' + res.count + ' 项', 'ok');

        const go = await confirmBox({
            title: '去清单看看？',
            message: '材料已经并进清单，重复的会自动合并（比如「2 斤」和「300 克」会算成一起）。',
            okText: '去看看',
            cancelText: '先不去',
        });

        if (go) {
            location.hash = '#/shopping/' + listId;
        }
    } catch (e) {
        toastError(e);
    }
}

export async function addToPlan(recipe) {
    const f = await formBox({
        title: '排进膳食计划',
        okText: '排进去',
        fields: [
            { name: 'plan_date', label: '哪天', type: 'date', value: today(), required: true },
            {
                name: 'slot',
                label: '哪一餐',
                type: 'select',
                value: 'dinner',
                options: slots().map((s) => ({ value: s.key, label: s.label })),
            },
            { name: 'servings', label: '做几人份', type: 'number', value: recipe.servings || 2 },
        ],
    });

    if (!f) {
        return;
    }

    try {
        await api.post('/api/plan', {
            plan_date: f.plan_date,
            slot: f.slot,
            recipe_id: recipe.id,
            servings: Number(f.servings) || 0,
        });

        toast('已排进 ' + f.plan_date + ' ' + slotLabel(f.slot), 'ok');
    } catch (e) {
        toastError(e);
    }
}
