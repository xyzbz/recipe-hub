/**
 * 游客只读浏览。
 *
 * 站点开了「游客浏览」后，未登录访客可以看公开菜谱（列表 + 详情），
 * 这是「分享链接」的载体——把 #/recipe/12 发给家人，对方打开就能看。
 *
 * 界面复用登录态的卡片 / 详情样式，但没有任何写操作入口。
 */

import { api, qs } from '../api.js';
import { html, raw, esc, $, $$, toast, toastError, fmtDuration, coverStyle } from '../dom.js';
import { state } from '../state.js';

// ---------------------------------------------------------------- 列表

export async function renderPublicList(root, params, query) {
    const page = Math.max(1, Number(query.page) || 1);
    const args = {
        q: query.q || '',
        cuisine: query.cuisine || '',
        tags: Array.isArray(query.tags) ? query.tags.slice() : query.tags ? [query.tags] : [],
        page: page,
    };

    root.innerHTML = html`<div class="guest-banner">
        <span>👋 游客模式：这里展示的是本站公开菜谱。${raw(loginLink())}</span>
    </div>
    <div id="pub-results" class="loading"></div>`;

    const box = $('#pub-results', root);

    let res;

    try {
        res = await api.get('/api/public/recipes' + qs(args));
    } catch (e) {
        box.classList.remove('loading');
        box.innerHTML = html`<div class="empty"><p>${e.message}</p></div>`;
        return;
    }

    box.classList.remove('loading');

    const items = res.items || [];

    if (!items.length) {
        box.innerHTML = html`<div class="empty">
            <p>${args.q ? '没搜到相关的菜谱。' : '还没有公开的菜谱。'}</p>
            <p class="dim">换个关键词试试${args.q ? '' : '，或者等主人录几道菜'}。</p>
        </div>`;
        return;
    }

    const cards = items.map(cardMarkup).join('');

    box.innerHTML = html`<div class="cards">${raw(cards)}</div>${raw(pagerMarkup(res, args))}`;

    $$('.pager a', box).forEach((a) => {
        a.addEventListener('click', (e) => {
            e.preventDefault();
            location.hash = '#/recipes' + (a.getAttribute('href') || '');
        });
    });
}

function cardMarkup(r) {
    const bits = [r.cuisine, r.difficulty, fmtDuration(r.total_min)].filter(Boolean);

    return html`
    <div class="card-wrap">
        <a class="card" href="#/recipe/${r.id}">
            <div class="card-cover" style="${coverStyle(r)}">
                ${r.cover_url ? raw('') : html`<span class="card-cover-fallback">${(r.name || '?').slice(0, 1)}</span>`}
                ${r.total_min ? html`<span class="card-time">${fmtDuration(r.total_min)}</span>` : raw('')}
            </div>
            <div class="card-body">
                <h3 class="card-title">${r.name}</h3>
                <div class="card-meta">${bits.join(' · ') || '家常菜'}</div>
                <div class="card-tags">
                    ${raw((r.tags || []).map((t) => '<span class="tag-mini">' + esc(t.name) + '</span>').join(''))}
                </div>
            </div>
        </a>
    </div>`;
}

function pagerMarkup(res, args) {
    if ((res.pages || 1) <= 1) {
        return '';
    }

    let out = '<div class="pager">';

    if ((res.page || 1) > 1) {
        out += '<a href="' + esc(publicHash(Object.assign({}, args, { page: res.page - 1 }))) + '">← 上一页</a>';
    }

    out += '<span class="dim">' + (res.page || 1) + ' / ' + res.pages + '</span>';

    if ((res.page || 1) < (res.pages || 1)) {
        out += '<a href="' + esc(publicHash(Object.assign({}, args, { page: res.page + 1 }))) + '">下一页 →</a>';
    }

    return out + '</div>';
}

function publicHash(args) {
    return qs(args);
}

function loginLink() {
    return ' <a href="#/login">登录</a> 后可以收藏、排计划、生成买菜清单。';
}

// ---------------------------------------------------------------- 详情

export async function renderPublicDetail(root, params) {
    const id = Number(params.id) || 0;

    root.innerHTML = html`<div class="loading-block dim">正在打开菜谱…</div>`;

    let r;

    try {
        r = await api.get('/api/public/recipes/' + id);
    } catch (e) {
        root.innerHTML = html`<div class="empty">
            <p>${e.message}</p>
            <p class="dim">可能这道菜是私有的，或者链接不对。${raw(loginLink())}</p>
            <p><a class="btn btn-sm" href="#/recipes">回公开菜谱</a></p>
        </div>`;
        return;
    }

    root.innerHTML = detailMarkup(r);

    const btnShare = $('#p-share', root);

    if (btnShare) {
        btnShare.addEventListener('click', async () => {
            const url = location.origin + '/#/recipe/' + r.id;

            try {
                await navigator.clipboard.writeText(url);
                toast('链接已复制，发给家人吧', 'ok');
            } catch (err) {
                /* 剪贴板被浏览器拒了（少见），退回老办法 */
                const ta = document.createElement('textarea');
                ta.value = url;
                document.body.appendChild(ta);
                ta.select();

                try {
                    document.execCommand('copy');
                    toast('链接已复制，发给家人吧', 'ok');
                } catch (e2) {
                    toast('复制失败，地址栏的链接就是它', 'warn');
                }

                ta.remove();
            }
        });
    }

    const btnPrint = $('#p-print', root);

    if (btnPrint) {
        btnPrint.addEventListener('click', () => window.print());
    }
}

function detailMarkup(r) {
    const ings = r.ingredients || [];
    const steps = r.steps || [];

    return html`
    <div class="detail-head">
        <a class="back" href="#/recipes">← 回列表</a>
        <div class="detail-actions">
            <button class="btn btn-sm" id="p-share" type="button">复制分享链接</button>
            <button class="btn btn-sm" id="p-print" type="button">打印</button>
            <a class="btn btn-primary btn-sm" href="#/login">登录</a>
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
            <div class="panel-head"><h3>用料</h3></div>
            <ul class="ing-list">
                ${raw(
                    ings
                        .map((i) => '<li>' + esc(i.name) + (i.display ? '<em>' + esc(i.display) + '</em>' : '') + '</li>')
                        .join('') || '<li class="dim">还没写用料。</li>'
                )}
            </ul>
        </section>

        <section class="panel">
            <h3>做法</h3>
            <ol class="step-list">
                ${raw(
                    steps
                        .map(
                            (s, i) => html`
                    <li class="step">
                        <div class="step-no">${i + 1}</div>
                        <div class="step-body">
                            ${s.image_url ? html`<img class="step-img" src="${s.image_url}" alt="" loading="lazy">` : raw('')}
                            <p class="step-text">${s.text}</p>
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

    <div class="detail-foot dim">来自「${(state.meta.site && state.meta.site.siteName) || '家常菜谱'}」的分享</div>`;
}
