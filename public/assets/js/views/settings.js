import { api } from '../api.js';
import { html, raw, esc, $$, toast, toastError } from '../dom.js';
import { state, unitGroups } from '../state.js';
import { themeMode, setThemeMode } from '../theme.js';

export async function renderSettings(root) {
    const u = state.user || {};
    const site = (state.meta && state.meta.site) || {};

    const unitFlat = [];

    unitGroups().forEach((g) => {
        g.units.forEach((x) => unitFlat.push({ value: x, label: g.label + ' · ' + x }));
    });

    root.innerHTML = html`
    <div class="page-head"><h2 class="page-title">设置</h2></div>

    <div class="cols">
        <section class="panel">
            <h3>我的资料</h3>
            <form id="profile-form" class="stack">
                <label class="field"><span>昵称</span><input type="text" name="display_name" maxlength="24" value="${u.display_name || ''}"></label>
                <label class="field">
                    <span>默认用量单位</span>
                    <select name="unit_pref">
                        ${raw(unitFlat.map((x) => '<option value="' + esc(x.value) + '"' + (x.value === u.unit_pref ? ' selected' : '') + '>' + esc(x.label) + '</option>').join(''))}
                    </select>
                    <em class="hint">采购清单会优先按这个单位显示</em>
                </label>
                <div class="field-row">
                    <label class="field"><span>账号</span><input type="text" value="${u.username}" disabled></label>
                    <label class="field"><span>权限</span><input type="text" value="${u.role === 'admin' ? '管理员' : state.canWrite ? '读写' : '只读'}" disabled></label>
                </div>
                <button class="btn btn-primary" type="submit">保存</button>
            </form>
        </section>

        <section class="panel">
            <h3>改密码</h3>
            <form id="pwd-form" class="stack">
                <label class="field"><span>原密码</span><input type="password" name="old_password" autocomplete="current-password" required></label>
                <label class="field"><span>新密码</span><input type="password" name="new_password" autocomplete="new-password" required placeholder="至少 6 位"></label>
                <label class="field"><span>再输一遍</span><input type="password" name="repeat" autocomplete="new-password" required></label>
                <button class="btn btn-primary" type="submit">改密码</button>
                <em class="hint">改完之后其它设备上的登录会失效，当前这台继续用。</em>
            </form>
        </section>
        <section class="panel">
            <h3>外观</h3>
            <p class="dim small">晚上躺着看菜谱，「深色」刺眼得多。「跟随系统」会随手机/电脑的主题自动切。</p>
            <div class="theme-picker" id="theme-picker">
                ${raw(
                    [
                        ['system', '跟随系统'],
                        ['light', '浅色'],
                        ['dark', '深色'],
                    ]
                        .map(
                            (x) =>
                                '<button type="button" class="chip' +
                                (themeMode() === x[0] ? ' on' : '') +
                                '" data-theme="' +
                                x[0] +
                                '">' +
                                x[1] +
                                '</button>'
                        )
                        .join('')
                )}
            </div>
        </section>
    </div>

    <section class="panel">
        <h3>关于</h3>
        <ul class="kv">
            <li><span>站点</span><strong>${site.siteName || '家常菜谱'}</strong></li>
            <li><span>版本</span><strong>${state.meta && state.meta.version ? state.meta.version : '-'}</strong></li>
            <li><span>菜谱数</span><strong>${state.meta && state.meta.stats ? state.meta.stats.recipes : 0}</strong></li>
            <li><span>食材库</span><strong>${state.meta && state.meta.stats ? state.meta.stats.ingredients : 0}</strong></li>
            <li><span>数据全部存在</span><strong>你自己的服务器上</strong></li>
        </ul>
        ${state.isAdmin
            ? html`<p class="dim small">
                  你是管理员：<a href="#/admin">管理后台 · 备份</a> 里可以一键把<b>整个站</b>（菜谱、食材、库存、计划、
                  采购单、账号，连全部图片）打包成一个文件下载；换服务器时把那个文件传回去点恢复就搬完了。
              </p>`
            : raw('')}
    </section>`;

    document.getElementById('theme-picker').addEventListener('click', (e) => {
        const btn = e.target.closest('[data-theme]');

        if (!btn) {
            return;
        }

        setThemeMode(btn.dataset.theme);
        $$('#theme-picker .chip').forEach((b) => b.classList.toggle('on', b === btn));
        toast('外观已切换', 'ok');
    });

    document.getElementById('profile-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);

        try {
            const res = await api.post('/api/profile', {
                display_name: fd.get('display_name'),
                unit_pref: fd.get('unit_pref'),
            });

            state.user = res.user;

            // 侧边栏（头像 + 名字）是外壳渲染的，不会跟着 state 自动更新。
            // 不补这一下，用户改完昵称会发现左上角还是旧的，以为没保存上。
            const fresh = res.user || {};
            const shown = fresh.display_name || fresh.username || '?';
            const sideName = document.querySelector('.side-user-text strong');
            const sideAvatar = document.querySelector('.side-user .avatar');

            if (sideName) {
                sideName.textContent = shown;
            }

            if (sideAvatar) {
                sideAvatar.textContent = shown.slice(0, 1);
            }

            toast('已保存', 'ok');
        } catch (err) {
            toastError(err);
        }
    });

    document.getElementById('pwd-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);

        if (fd.get('new_password') !== fd.get('repeat')) {
            toast('两次输入的新密码不一样', 'warn');
            return;
        }

        try {
            await api.post('/api/password', {
                old_password: fd.get('old_password'),
                new_password: fd.get('new_password'),
            });

            e.target.reset();
            toast('密码改好了', 'ok');
        } catch (err) {
            toastError(err);
        }
    });
}
