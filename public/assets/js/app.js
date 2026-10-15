/**
 * 应用外壳：状态、路由、导航。
 *
 * 路由用 hash（#/recipes/12），不依赖服务端重写，部署到哪都不会 404。
 */

import { api, onApi, setCsrf, bootInfo } from './api.js';
import { html, raw, esc, $, mount, toast, toastError, confirmBox } from './dom.js';
import { state, setMeta } from './state.js';
import { applyTheme, cycleTheme, isDark } from './theme.js';
import { renderAuth, renderSetup } from './views/auth.js';
import { renderPublicList, renderPublicDetail } from './views/public.js';
import { renderRecipeList, renderRecipeDetail, renderRecipeEditor } from './views/recipes.js';
import { renderPantry } from './views/pantry.js';
import { renderPlan } from './views/plan.js';
import { renderShoppingList, renderShoppingDetail } from './views/shopping.js';
import { renderIngredients } from './views/ingredients.js';
import { renderTrash } from './views/trash.js';
import { renderSettings } from './views/settings.js';
import { renderAdmin } from './views/admin.js';

export { state };

const appRoot = document.getElementById('app');

// ---------------------------------------------------------------- 路由表

const routes = [
    { path: '/recipes', nav: 'recipes', view: renderRecipeList },
    { path: '/recipe/:id/edit', nav: 'recipes', view: renderRecipeEditor },
    { path: '/recipe/new', nav: 'recipes', view: renderRecipeEditor },
    { path: '/recipe/:id', nav: 'recipes', view: renderRecipeDetail },
    { path: '/ingredients', nav: 'ingredients', view: renderIngredients },
    { path: '/pantry', nav: 'pantry', view: renderPantry },
    { path: '/plan', nav: 'plan', view: renderPlan },
    { path: '/shopping', nav: 'shopping', view: renderShoppingList },
    { path: '/shopping/:id', nav: 'shopping', view: renderShoppingDetail },
    { path: '/settings', nav: 'settings', view: renderSettings },
    { path: '/trash', nav: 'trash', view: renderTrash },
    { path: '/admin', nav: 'admin', view: renderAdmin },
];

const NAV = [
    { key: 'recipes', label: '菜谱', icon: '📖', hash: '#/recipes', mobile: true },
    { key: 'plan', label: '计划', icon: '🗓', hash: '#/plan', mobile: true },
    { key: 'shopping', label: '采购', icon: '🛒', hash: '#/shopping', mobile: true },
    { key: 'pantry', label: '库存', icon: '🧺', hash: '#/pantry', mobile: true },
    { key: 'ingredients', label: '食材库', icon: '🥬', hash: '#/ingredients', mobile: false },
    { key: 'settings', label: '设置', icon: '⚙️', hash: '#/settings', mobile: true },
    { key: 'trash', label: '回收站', icon: '🗑️', hash: '#/trash', mobile: false },
];

let currentRoute = { path: '', params: {}, query: {} };

// ---------------------------------------------------------------- 路由解析

function parseHash() {
    let h = location.hash.replace(/^#/, '');

    if (!h || h === '/') {
        return { path: '/recipes', query: {} };
    }

    const qIdx = h.indexOf('?');
    let query = {};

    if (qIdx >= 0) {
        const sp = new URLSearchParams(h.slice(qIdx + 1));
        query = {};
        sp.forEach((v, k) => {
            if (k.endsWith('[]')) {
                const key = k.slice(0, -2);
                (query[key] = query[key] || []).push(v);
            } else {
                query[k] = v;
            }
        });
        h = h.slice(0, qIdx);
    }

    return { path: '/' + h.replace(/^\/+/, '').replace(/\/+$/, ''), query: query };
}

function matchRoute(path) {
    for (const r of routes) {
        const rp = r.path.split('/').filter(Boolean);
        const pp = path.split('/').filter(Boolean);

        if (rp.length !== pp.length) {
            continue;
        }

        const params = {};
        let ok = true;

        for (let i = 0; i < rp.length; i++) {
            if (rp[i].charAt(0) === ':') {
                params[rp[i].slice(1)] = decodeURIComponent(pp[i]);
            } else if (rp[i] !== pp[i]) {
                ok = false;
                break;
            }
        }

        if (ok) {
            return { route: r, params };
        }
    }

    return null;
}

export function navigate(hash, replace) {
    const target = hash.charAt(0) === '#' ? hash : '#/' + hash.replace(/^\/+/, '');

    if (replace) {
        location.replace(target);
    } else if (location.hash === target) {
        render();
    } else {
        location.hash = target;
    }
}

export function reload() {
    render();
}

// ---------------------------------------------------------------- 渲染

function navItem(item, active) {
    return html`<a class="nav-item${active ? ' active' : ''}" href="${item.hash}">
        <span class="nav-icon">${item.icon}</span><span class="nav-label">${item.label}</span>
    </a>`;
}

function shellMarkup(activeKey) {
    const site = (state.meta && state.meta.site) || {};
    const user = state.user || {};
    const isAdmin = state.isAdmin;

    const sideNav = NAV.map((n) => navItem(n, n.key === activeKey)).join('') +
        (isAdmin ? navItem({ key: 'admin', label: '管理', icon: '🛠', hash: '#/admin' }, activeKey === 'admin') : '');

    const mobileNav = NAV.filter((n) => n.mobile)
        .map((n) => navItem(n, n.key === activeKey))
        .join('');

    return html`
    <div class="shell">
        <aside class="sidebar">
            <div class="brand">
                <span class="brand-mark">🍳</span>
                <span class="brand-text">
                    <strong>${site.siteName || '家常菜谱'}</strong>
                    <em>${shortDesc(site.siteDesc)}</em>
                </span>
            </div>
            <nav class="side-nav">${raw(sideNav)}</nav>
            <div class="side-foot">
                <button class="btn btn-ghost btn-sm side-theme" id="btn-theme" type="button" title="切换深浅色">${isDark() ? '☀️ 浅色' : '🌙 深色'}</button>
                <a class="side-user" href="#/settings">
                    <span class="avatar">${(user.display_name || user.username || '?').slice(0, 1)}</span>
                    <span class="side-user-text">
                        <strong>${user.display_name || user.username}</strong>
                        <em>${user.role === 'admin' ? '管理员' : state.canWrite ? '读写' : '只读'}</em>
                    </span>
                </a>
                <button class="btn btn-ghost btn-sm" id="btn-logout" type="button">退出</button>
            </div>
        </aside>

        <div class="main">
            <header class="topbar">
                <button class="topbar-menu" id="btn-menu" type="button" aria-label="菜单">☰</button>
                <span class="topbar-title">${site.siteName || '家常菜谱'}</span>
                <form class="topbar-search" id="global-search" role="search">
                    <input type="search" name="q" placeholder="搜菜名、食材、做法…" autocomplete="off">
                </form>
                <a class="btn btn-primary btn-sm" href="#/recipe/new" id="btn-new">+ 新菜谱</a>
            </header>
            <main id="view" class="view"></main>
        </div>

        <nav class="tabbar">${raw(mobileNav)}</nav>
        <a class="fab" href="#/recipe/new" aria-label="新建菜谱">＋</a>
    </div>`;
}

function shortDesc(d) {
    return d && d.length > 16 ? d.slice(0, 16) + '…' : d || '自己家的中餐菜谱本';
}

/**
 * 渲染队列：同一时刻只跑一次渲染。
 *
 * 不排队会出事：两次 render 交错时，后一次把整个外壳 `appRoot.innerHTML` 换掉，
 * 前一次还在跑的视图函数手里那个 `#view` 已经脱离文档了，它继续往里写、
 * 并且用 `document.getElementById` 找自己的控件时会拿到 null，
 * 页面就会闪一下「加载失败：Cannot read properties of null」。
 *
 * 触发场景很日常——页面刚启动时 boot() 和 hashchange 会连着各渲染一次，
 * 或者手快点两下导航。
 *
 * 顺带用一个自增序号做「后到者胜」：排在队里但已经被顶掉的那一次直接跳过，
 * 省掉一次白渲染。
 */
let renderSeq = 0;
let renderChain = Promise.resolve();

function render() {
    const seq = ++renderSeq;

    renderChain = renderChain.then(async () => {
        if (seq !== renderSeq) {
            return;
        }

        await doRender();
    });

    return renderChain;
}

async function doRender() {
    if (!state.user) {
        // 站点开了「游客浏览」：不登录也能逛公开菜谱（只读）
        if (state.meta && state.meta.publicBrowse) {
            return doGuestRender();
        }

        return;
    }

    const parsed = parseHash();
    const hit = matchRoute(parsed.path);

    // 已登录的人不该停在登录页（登录成功残留、手动输地址都会走到这）：
    // 路由表里没有 /login，与其渲染「页面不存在」，不如直接送回菜谱列表。
    if (parsed.path === '/login' || parsed.path === '/auth') {
        navigate('/recipes', true);
        return;
    }

    appRoot.classList.remove('boot');
    appRoot.innerHTML = shellMarkup(hit ? hit.route.nav : '');

    bindShell();

    const view = document.getElementById('view');

    if (!hit) {
        view.innerHTML = html`<div class="empty">
            <p>这个页面不存在。</p>
            <p class="dim"><a href="#/recipes">回到菜谱列表</a></p>
        </div>`;
        return;
    }

    currentRoute = { path: parsed.path, params: hit.params, query: parsed.query };

    view.classList.add('loading');

    try {
        await hit.route.view(view, hit.params, parsed.query);
    } catch (e) {
        console.error(e);
        view.innerHTML = html`<div class="empty">
            <p>加载失败：${e.message}</p>
            <p class="dim"><button class="btn btn-sm" id="btn-retry">重试</button></p>
        </div>`;
        const btn = document.getElementById('btn-retry');

        if (btn) {
            btn.addEventListener('click', () => render());
        }
    } finally {
        view.classList.remove('loading');
        window.scrollTo(0, 0);
    }
}

// ---------------------------------------------------------------- 游客模式

function guestShell(activeTitle) {
    const site = (state.meta && state.meta.site) || {};

    return html`
    <div class="guest-shell">
        <header class="guest-top">
            <a class="brand" href="#/recipes">
                <span class="brand-mark">🍳</span>
                <strong class="guest-title">${site.siteName || '家常菜谱'}</strong>
            </a>
            <form class="guest-search" id="guest-search" role="search">
                <input type="search" name="q" placeholder="搜菜名、食材…" autocomplete="off">
            </form>
            <a class="btn btn-primary btn-sm" href="#/login">登录</a>
        </header>
        <main id="view" class="view">${activeTitle}</main>
        <footer class="guest-foot dim">公开菜谱 · 想收藏和排计划就<a href="#/login">登录</a>吧</footer>
    </div>`;
}

async function doGuestRender() {
    const parsed = parseHash();

    // 游客点「登录」→ 完整登录页（复用登录态那套）
    if (parsed.path === '/login' || parsed.path === '/auth') {
        renderAuth(appRoot, () => boot());
        return;
    }

    appRoot.classList.remove('boot');
    appRoot.innerHTML = guestShell('');

    const search = document.getElementById('guest-search');

    if (search) {
        search.addEventListener('submit', (e) => {
            e.preventDefault();
            const q = search.querySelector('input').value.trim();
            navigate('/recipes' + (q ? '?q=' + encodeURIComponent(q) : ''));
        });
    }

    const view = document.getElementById('view');

    let hit = null;
    const params = {};

    if (parsed.path === '/' || parsed.path === '/recipes') {
        hit = renderPublicList;
    } else {
        const m = parsed.path.match(/^\/recipe\/(\d+)$/);

        if (m) {
            params.id = m[1];
            hit = renderPublicDetail;
        }
    }

    if (!hit) {
        // 游客能去的地方只有列表和详情，其他一律送回列表
        navigate('/recipes', true);
        return;
    }

    view.classList.add('loading');

    try {
        await hit(view, params, parsed.query);
    } catch (e) {
        console.error(e);
        view.innerHTML = html`<div class="empty">
            <p>加载失败：${e.message}</p>
            <p class="dim"><button class="btn btn-sm" id="btn-retry">重试</button></p>
        </div>`;
        const btn = document.getElementById('btn-retry');

        if (btn) {
            btn.addEventListener('click', () => render());
        }
    } finally {
        view.classList.remove('loading');
        window.scrollTo(0, 0);
    }
}

function bindShell() {
    const logout = document.getElementById('btn-logout');

    if (logout) {
        logout.addEventListener('click', async () => {
            try {
                await api.post('/api/logout');
            } catch (e) {
                /* 退出失败也无所谓 */
            }

            setCsrf('');
            state.user = null;
            boot();
        });
    }

    const menu = document.getElementById('btn-menu');

    if (menu) {
        menu.addEventListener('click', () => {
            document.body.classList.toggle('sidebar-open');
        });
    }

    document.addEventListener('click', (e) => {
        if (!e.target.closest('.sidebar') && !e.target.closest('#btn-menu')) {
            document.body.classList.remove('sidebar-open');
        }
    });

    const themeBtn = document.getElementById('btn-theme');

    if (themeBtn) {
        themeBtn.addEventListener('click', () => {
            cycleTheme();
            themeBtn.textContent = isDark() ? '☀️ 浅色' : '🌙 深色';
        });
    }

    const search = document.getElementById('global-search');

    if (search) {
        search.addEventListener('submit', (e) => {
            e.preventDefault();
            const q = search.querySelector('input').value.trim();
            navigate('/recipes' + (q ? '?q=' + encodeURIComponent(q) : ''));
        });
    }
}

// ---------------------------------------------------------------- 启动

export async function boot() {
    try {
        const meta = await api.get('/api/bootstrap');
        setMeta(meta);

        if (meta.csrf) {
            setCsrf(meta.csrf);
        }
    } catch (e) {
        appRoot.innerHTML = html`<div class="boot-screen">
            <div class="boot-mark">😵</div>
            <div class="boot-text">打不开：${e.message}</div>
            <button class="btn btn-primary" id="boot-retry">重试</button>
        </div>`;

        const btn = document.getElementById('boot-retry');

        if (btn) {
            btn.addEventListener('click', () => location.reload());
        }

        return;
    }

    if (state.meta.needsSetup) {
        renderSetup(appRoot, () => boot());
        return;
    }

    if (!state.user) {
        if (state.meta.publicBrowse) {
            document.title = (state.meta.site && state.meta.site.siteName) || '家常菜谱';
            render();
            return;
        }

        renderAuth(appRoot, () => boot());
        return;
    }

    document.title = (state.meta.site && state.meta.site.siteName) || bootInfo.siteName || '家常菜谱';
    render();
}

onApi('unauthorized', () => {
    if (state.user) {
        state.user = null;
        toast('登录状态已失效，请重新登录', 'warn');
        boot();
    }
});

window.addEventListener('hashchange', () => render());

boot();
