import { api, setCsrf } from '../api.js';
import { html, raw, $, toast, toastError } from '../dom.js';
import { state } from '../state.js';

/** 首次安装向导 */
export function renderSetup(root, onDone) {
    root.classList.remove('boot');
    root.innerHTML = html`
    <div class="auth-page">
        <div class="auth-card">
            <div class="auth-brand"><span class="brand-mark">🍳</span></div>
            <h1 class="auth-title">把菜谱本装起来</h1>
            <p class="auth-sub">第一次运行，先建个管理员账号。这些以后都能改。</p>

            <form id="setup-form" class="auth-form">
                <label class="field">
                    <span>这个菜谱本叫什么</span>
                    <input type="text" name="site_name" value="家常菜谱" maxlength="40">
                </label>
                <label class="field">
                    <span>管理员账号</span>
                    <input type="text" name="username" required autocomplete="username" placeholder="2~24 个字符">
                </label>
                <label class="field">
                    <span>密码</span>
                    <input type="password" name="password" required autocomplete="new-password" placeholder="至少 6 位">
                </label>
                <label class="check">
                    <input type="checkbox" name="demo" checked>
                    <span>预置 10 道家常菜谱，方便先看看长什么样</span>
                </label>
                <label class="check">
                    <input type="checkbox" name="allow_register">
                    <span>允许其他人自助注册（私有实例建议先不开）</span>
                </label>
                <button class="btn btn-primary btn-block" type="submit">开始使用</button>
            </form>
            <p class="auth-foot dim">数据存在自己的服务器上，图片也是。</p>
        </div>
    </div>`;

    const form = document.getElementById('setup-form');

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = form.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.textContent = '正在初始化…';

        const fd = new FormData(form);

        try {
            await api.post('/api/setup', {
                site_name: fd.get('site_name'),
                username: fd.get('username'),
                password: fd.get('password'),
                demo: fd.get('demo') ? 1 : 0,
                allow_register: fd.get('allow_register') ? 1 : 0,
            });

            toast('装好了，欢迎', 'ok');
            onDone();
        } catch (err) {
            toastError(err);
            btn.disabled = false;
            btn.textContent = '开始使用';
        }
    });
}

/** 登录 / 注册 */
export function renderAuth(root, onDone) {
    root.classList.remove('boot');

    // 没开注册就别把入口摆出来，点进去只会撞一个 403
    const allowRegister = !!(state.meta && state.meta.site && state.meta.site.allowRegister);

    root.innerHTML = html`
    <div class="auth-page">
        <div class="auth-card">
            <div class="auth-brand"><span class="brand-mark">🍳</span></div>
            <h1 class="auth-title" id="auth-title">打开菜谱本</h1>
            <p class="auth-sub" id="auth-sub">用你的账号登录。</p>

            <form id="auth-form" class="auth-form">
                <label class="field" id="field-display" hidden>
                    <span>昵称（可不填）</span>
                    <input type="text" name="display_name" maxlength="24" autocomplete="nickname">
                </label>
                <label class="field">
                    <span>账号</span>
                    <input type="text" name="username" required autocomplete="username">
                </label>
                <label class="field">
                    <span>密码</span>
                    <input type="password" name="password" required autocomplete="current-password">
                </label>
                <button class="btn btn-primary btn-block" type="submit" id="auth-submit">登录</button>
            </form>

            ${raw(
                allowRegister
                    ? html`<p class="auth-foot">
                <button class="link" id="toggle-mode" type="button">没有账号？注册一个</button>
            </p>`
                    : html`<p class="auth-foot dim">这个实例没有开放自助注册，要账号找管理员开。</p>`
            )}
        </div>
    </div>`;

    let mode = 'login';

    const form = document.getElementById('auth-form');
    const title = document.getElementById('auth-title');
    const sub = document.getElementById('auth-sub');
    const submit = document.getElementById('auth-submit');
    const displayField = document.getElementById('field-display');
    const toggle = document.getElementById('toggle-mode');
    const pwd = form.querySelector('input[name="password"]');

    if (toggle) {
        toggle.addEventListener('click', () => {
            mode = mode === 'login' ? 'register' : 'login';

            if (mode === 'register') {
                title.textContent = '建个账号';
                sub.textContent = '注册后就能开始记菜谱。';
                submit.textContent = '注册并进入';
                displayField.hidden = false;
                pwd.setAttribute('autocomplete', 'new-password');
                toggle.textContent = '已经有账号了，去登录';
            } else {
                title.textContent = '打开菜谱本';
                sub.textContent = '用你的账号登录。';
                submit.textContent = '登录';
                displayField.hidden = true;
                pwd.setAttribute('autocomplete', 'current-password');
                toggle.textContent = '没有账号？注册一个';
            }
        });
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        submit.disabled = true;
        submit.textContent = '请稍候…';

        const fd = new FormData(form);
        const path = mode === 'login' ? '/api/login' : '/api/register';

        try {
            const res = await api.post(path, {
                username: fd.get('username'),
                password: fd.get('password'),
                display_name: fd.get('display_name') || '',
            });

            if (res && res.csrf) {
                setCsrf(res.csrf);
            }

            // 地址还挂在 #/login 的话，外壳接手后会渲染成「页面不存在」——
            // 替换成菜谱列表（replace 不留历史记录）。
            if (location.hash === '#/login' || location.hash === '#/auth') {
                location.replace('#/recipes');
            }

            onDone();
        } catch (err) {
            toastError(err);
            submit.disabled = false;
            submit.textContent = mode === 'login' ? '登录' : '注册并进入';
        }
    });
}
