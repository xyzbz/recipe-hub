import { api, qs, download } from '../api.js';
import { html, raw, esc, $, $$, toast, toastError, confirmBox, formBox, fmtDateTime, fmtAgo } from '../dom.js';
import { state } from '../state.js';

const ACTION_LABEL = {
    login: '登录',
    login_fail: '登录失败',
    logout: '退出',
    register: '注册',
    user_create: '新建用户',
    user_update: '改用户',
    user_delete: '删用户',
    user_reset_pw: '重置密码',
    password_change: '改密码',
    recipe_create: '新建菜谱',
    recipe_update: '改菜谱',
    recipe_delete: '删菜谱',
    recipe_import: '导入菜谱',
    recipe_export: '导出菜谱',
    upload: '上传图片',
    ingredient_create: '加食材',
    ingredient_delete: '删食材',
    ingredient_merge: '合并食材',
    pantry_add: '库存入库',
    pantry_remove: '库存移除',
    plan_add: '排计划',
    plan_copy: '复制计划',
    plan_template_save: '存周模板',
    plan_template_apply: '套周模板',
    shopping_create: '建清单',
    shopping_delete: '删清单',
    shopping_from_plan: '按计划生成清单',
    backup_create: '创建备份',
    backup_download: '下载备份',
    backup_delete: '删备份',
    backup_restore: '恢复备份',
    notify_channel_save: '保存推送渠道',
    notify_channel_remove: '删推送渠道',
    notify_schedule_save: '改推送时间',
    notify_manual: '手动推送',
    notify_fail: '推送失败',
    settings_save: '改设置',
    maintenance: '维护',
    account_export: '导出账号数据',
    account_import: '导回个人数据',
};

export async function renderAdmin(root) {
    let tab = 'dashboard';

    root.innerHTML = html`
    <div class="page-head">
        <h2 class="page-title">管理后台</h2>
    </div>
    <div class="tabs" id="admin-tabs">
        <button class="tab on" data-tab="dashboard">总览</button>
        <button class="tab" data-tab="users">用户</button>
        <button class="tab" data-tab="settings">站点设置</button>
        <button class="tab" data-tab="notify">推送通知</button>
        <button class="tab" data-tab="logs">日志</button>
        <button class="tab" data-tab="backups">备份</button>
        <button class="tab" data-tab="system">维护</button>
    </div>
    <div id="admin-body" class="results"></div>`;

    const body = document.getElementById('admin-body');

    document.getElementById('admin-tabs').addEventListener('click', (e) => {
        const t = e.target.closest('[data-tab]');

        if (!t) {
            return;
        }

        tab = t.getAttribute('data-tab');
        $$('.tab', root).forEach((x) => x.classList.toggle('on', x === t));
        load();
    });

    async function load() {
        body.classList.add('loading');
        body.innerHTML = '<div class="skeleton-list"></div>';

        try {
            if (tab === 'dashboard') {
                await renderDashboard();
            } else if (tab === 'users') {
                await renderUsers();
            } else if (tab === 'settings') {
                await renderSettings();
            } else if (tab === 'logs') {
                await renderLogs();
            } else if (tab === 'notify') {
                await renderNotify();
            } else if (tab === 'backups') {
                await renderBackups();
            } else {
                await renderSystem();
            }
        } catch (e) {
            body.innerHTML = html`<div class="empty"><p>${e.message}</p></div>`;
        } finally {
            body.classList.remove('loading');
        }
    }

    // ------------------------------------------------------------ 总览

    async function renderDashboard() {
        const d = await api.get('/api/admin/dashboard');
        const s = d.stats;

        body.innerHTML = html`
        <div class="stat-row">
            <div class="stat"><em>菜谱</em><strong>${s.recipes}</strong></div>
            <div class="stat"><em>食材库</em><strong>${s.ingredients}</strong></div>
            <div class="stat"><em>库存</em><strong>${s.pantry}</strong></div>
            <div class="stat"><em>待办清单</em><strong>${s.shopping_open}</strong></div>
            <div class="stat"><em>用户</em><strong>${s.users}</strong></div>
            <div class="stat"><em>近 7 天改动</em><strong>${s.updated_7d}</strong></div>
        </div>

        <div class="cols">
            <section class="panel">
                <h3>存储占用</h3>
                <ul class="kv">
                    <li><span>数据库</span><strong>${d.storage.db.human}</strong></li>
                    <li><span>图片</span><strong>${d.storage.uploads.human}（${d.storage.uploads.files} 张）</strong></li>
                    <li><span>数据目录</span><strong class="mono">${d.system.data_dir}</strong></li>
                </ul>
            </section>
            <section class="panel">
                <h3>运行环境</h3>
                <ul class="kv">
                    <li><span>应用版本</span><strong>${d.system.version}</strong></li>
                    <li><span>PHP</span><strong>${d.system.php}</strong></li>
                    <li><span>SQLite</span><strong>${d.system.sqlite}</strong></li>
                    <li><span>表结构版本</span><strong>v${d.system.schema}</strong></li>
                    <li><span>时区</span><strong>${d.system.tz}</strong></li>
                </ul>
            </section>
        </div>

        <div class="cols">
            <section class="panel">
                <h3>日志概览</h3>
                <ul class="kv">
                    <li><span>累计日志</span><strong>${d.logs.total}</strong></li>
                    <li><span>今天登录</span><strong>${d.logs.login_today}</strong></li>
                    <li><span>近 7 天登录失败</span><strong>${d.logs.fails_7d}</strong></li>
                    <li><span>上传图片</span><strong>${d.logs.uploads}</strong></li>
                </ul>
            </section>
            <section class="panel">
                <h3>库存提醒</h3>
                <ul class="kv">
                    <li><span>已经过期</span><strong class="${s.pantry_expired ? 'bad' : ''}">${s.pantry_expired}</strong></li>
                    <li><span>3 天内到期</span><strong class="${s.pantry_soon ? 'warn-text' : ''}">${s.pantry_soon}</strong></li>
                </ul>
                <p class="dim small">这些在「家里有什么」页面会高亮显示。</p>
            </section>
        </div>`;
    }

    // ------------------------------------------------------------ 用户

    async function renderUsers() {
        const users = await api.get('/api/admin/users');

        body.innerHTML = html`
        <div class="page-actions" style="margin-bottom:12px">
            <button class="btn btn-primary btn-sm" id="u-add" type="button">+ 新建用户</button>
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>账号</th><th>昵称</th><th>权限</th><th>状态</th><th class="num">菜谱</th><th>最后登录</th><th></th></tr></thead>
            <tbody>
            ${raw(
                users
                    .map(
                        (u) => `
                <tr data-id="${u.id}">
                    <td><strong>${esc(u.username)}</strong></td>
                    <td>${esc(u.display_name || '—')}</td>
                    <td>
                        ${u.role === 'admin' ? '<span class="tag-mini">管理员</span>' : u.can_write ? '<span class="tag-mini muted">读写</span>' : '<span class="tag-mini muted">只读</span>'}
                    </td>
                    <td>${u.is_active ? '<span class="badge ok">启用</span>' : '<span class="badge danger">停用</span>'}</td>
                    <td class="num dim">${u.recipe_count}</td>
                    <td class="dim">${u.last_login_at ? esc(fmtAgo(u.last_login_at)) : '还没登录过'}</td>
                    <td class="num">
                        <button class="icon-btn" data-act="edit" title="改">✎</button>
                        <button class="icon-btn" data-act="pw" title="重置密码">🔑</button>
                        <button class="icon-btn danger" data-act="del" title="删除">✕</button>
                    </td>
                </tr>`
                    )
                    .join('')
            )}
            </tbody></table></div>`;

        body.addEventListener('click', async (e) => {
            const btn = e.target.closest('[data-act]');

            if (!btn) {
                return;
            }

            const tr = btn.closest('[data-id]');
            const id = Number(tr.getAttribute('data-id'));
            const name = tr.querySelector('strong').textContent;
            const act = btn.getAttribute('data-act');

            if (act === 'del') {
                const ok = await confirmBox({
                    title: '删除用户',
                    message: '删掉 ' + name + '。他名下菜谱会保留（归属清空），收藏会一起删。',
                    okText: '删除',
                    danger: true,
                });

                if (!ok) {
                    return;
                }

                try {
                    await api.del('/api/admin/users/' + id);
                    toast('已删除', 'ok');
                    load();
                } catch (err) {
                    toastError(err);
                }

                return;
            }

            if (act === 'pw') {
                const f = await formBox({
                    title: '重置 ' + name + ' 的密码',
                    okText: '重置',
                    fields: [{ name: 'password', label: '新密码', required: true, placeholder: '至少 6 位' }],
                });

                if (!f) {
                    return;
                }

                try {
                    await api.put('/api/admin/users/' + id + '/password', { password: f.password });
                    toast('重置好了，该用户已被踢下线', 'ok');
                } catch (err) {
                    toastError(err);
                }

                return;
            }

            const f = await formBox({
                title: '改 ' + name,
                okText: '保存',
                fields: [
                    { name: 'display_name', label: '昵称', value: tr.children[1].textContent.trim() === '—' ? '' : tr.children[1].textContent.trim() },
                    {
                        name: 'role',
                        label: '角色',
                        type: 'select',
                        value: tr.children[2].textContent.indexOf('管理员') >= 0 ? 'admin' : 'member',
                        options: [
                            { value: 'member', label: '普通成员' },
                            { value: 'admin', label: '管理员' },
                        ],
                    },
                    {
                        name: 'can_write',
                        label: '权限',
                        type: 'select',
                        value: tr.children[2].textContent.indexOf('只读') >= 0 ? '0' : '1',
                        options: [
                            { value: '1', label: '可读可写' },
                            { value: '0', label: '只读（只能看）' },
                        ],
                    },
                    {
                        name: 'is_active',
                        label: '状态',
                        type: 'select',
                        value: tr.children[3].textContent.indexOf('停用') >= 0 ? '0' : '1',
                        options: [
                            { value: '1', label: '启用' },
                            { value: '0', label: '停用（踢下线）' },
                        ],
                    },
                ],
            });

            if (!f) {
                return;
            }

            try {
                await api.put('/api/admin/users/' + id, {
                    display_name: f.display_name,
                    role: f.role,
                    can_write: f.can_write === '1',
                    is_active: f.is_active === '1',
                });

                toast('已保存', 'ok');
                load();
            } catch (err) {
                toastError(err);
            }
        });

        const add = document.getElementById('u-add');

        if (add) {
            add.addEventListener('click', async () => {
                const f = await formBox({
                    title: '新建用户',
                    okText: '创建',
                    fields: [
                        { name: 'username', label: '账号', required: true },
                        { name: 'display_name', label: '昵称' },
                        { name: 'password', label: '密码', required: true, placeholder: '至少 6 位' },
                        {
                            name: 'role',
                            label: '角色',
                            type: 'select',
                            value: 'member',
                            options: [
                                { value: 'member', label: '普通成员' },
                                { value: 'admin', label: '管理员' },
                            ],
                        },
                        {
                            name: 'can_write',
                            label: '权限',
                            type: 'select',
                            value: '1',
                            options: [
                                { value: '1', label: '可读可写' },
                                { value: '0', label: '只读' },
                            ],
                        },
                    ],
                });

                if (!f) {
                    return;
                }

                try {
                    await api.post('/api/admin/users', {
                        username: f.username,
                        display_name: f.display_name,
                        password: f.password,
                        role: f.role,
                        can_write: f.can_write === '1',
                    });

                    toast('创建好了', 'ok');
                    load();
                } catch (err) {
                    toastError(err);
                }
            });
        }
    }

    // ------------------------------------------------------------ 站点设置

    async function renderSettings() {
        const d = await api.get('/api/admin/settings');
        const s = d.settings;

        body.innerHTML = html`
        <div class="cols">
            <section class="panel">
                <h3>站点</h3>
                <form id="site-form" class="stack">
                    <label class="field"><span>站点名称</span><input type="text" name="site_name" maxlength="40" value="${s.site_name}"></label>
                    <label class="field"><span>一句话简介</span><input type="text" name="site_desc" maxlength="120" value="${s.site_desc}"></label>
                    <label class="field">
                        <span>站点地址</span>
                        <input type="text" name="site_url" maxlength="120" placeholder="https://eat.example.com" value="${s.site_url || ''}">
                        <em class="hint">推送消息里的菜谱链接和封面图靠它拼绝对地址；不填就只发纯文本</em>
                    </label>
                    <label class="check">
                        <input type="checkbox" name="notify_rich"${s.notify_rich !== '0' ? ' checked' : ''}>
                        <span>推送带封面图和链接（各渠道按能力发卡片 / 图文 / HTML）</span>
                    </label>
                    <label class="check">
                        <input type="checkbox" name="allow_register"${s.allow_register === '1' ? ' checked' : ''}>
                        <span>允许自助注册（私有实例建议关掉）</span>
                    </label>
                    <label class="check">
                        <input type="checkbox" name="public_browse"${s.public_browse === '1' ? ' checked' : ''}>
                        <span>开放游客浏览公开菜谱（开了之后不登录也能看，分享链接才有效）</span>
                    </label>
                    <button class="btn btn-primary" type="submit">保存</button>
                </form>
            </section>

            <section class="panel">
                <h3>只读配置</h3>
                <p class="dim small">这些在部署时通过环境变量设定，改完重启容器才生效。</p>
                <ul class="kv">
                    <li><span>单张图片上限</span><strong>${d.readonly.uploadMaxMb} MB</strong></li>
                    <li><span>图片最长边</span><strong>${d.readonly.imageMaxWidth} px</strong></li>
                    <li><span>图片质量</span><strong>${d.readonly.imageQuality}</strong></li>
                    <li><span>数据目录</span><strong class="mono">${d.readonly.dataDir}</strong></li>
                </ul>
                <p class="dim small">
                    环境变量：<code>RH_SITE_NAME</code>、<code>RH_ALLOW_REGISTER</code>、
                    <code>RH_UPLOAD_MAX_MB</code>、<code>RH_IMAGE_MAX_WIDTH</code>、<code>RH_DATA_DIR</code>
                </p>
            </section>
        </div>`;

        document.getElementById('site-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const fd = new FormData(e.target);

            try {
                await api.post('/api/admin/settings', {
                    site_name: fd.get('site_name'),
                    site_desc: fd.get('site_desc'),
                    site_url: fd.get('site_url'),
                    notify_rich: fd.get('notify_rich') ? 1 : 0,
                    allow_register: fd.get('allow_register') ? 1 : 0,
                    public_browse: fd.get('public_browse') ? 1 : 0,
                });

                toast('保存了，刷新页面生效', 'ok');
            } catch (err) {
                toastError(err);
            }
        });
    }

    // ------------------------------------------------------------ 日志

    async function renderLogs() {
        const d = await api.get('/api/admin/logs?limit=120');
        const items = d.items || [];

        body.innerHTML = html`
        <div class="page-actions" style="margin-bottom:12px">
            <span class="dim">共 ${d.stats.total} 条日志，显示最近 ${items.length} 条</span>
            <button class="btn btn-sm btn-ghost" id="log-clear" type="button">清空（保留登录失败记录）</button>
        </div>
        ${items.length
            ? html`<div class="table-wrap"><table class="table">
                  <thead><tr><th>时间</th><th>谁</th><th>动作</th><th>对象</th><th>IP</th></tr></thead>
                  <tbody>
                  ${raw(
                      items
                          .map(
                              (l) => `
                      <tr>
                          <td class="dim mono">${esc(fmtDateTime(l.created_at))}</td>
                          <td>${esc(l.username || '—')}</td>
                          <td><span class="tag-mini${l.action === 'login_fail' ? ' danger' : ''}">${esc(ACTION_LABEL[l.action] || l.action)}</span></td>
                          <td class="dim">${esc(l.target || '')}${l.detail ? '<br><span class="small">' + esc(l.detail) + '</span>' : ''}</td>
                          <td class="dim mono small">${esc(l.ip)}</td>
                      </tr>`
                          )
                          .join('')
                  )}
                  </tbody></table></div>`
            : html`<div class="empty"><p>还没有日志。</p></div>`}`;

        const clr = document.getElementById('log-clear');

        if (clr) {
            clr.addEventListener('click', async () => {
                const ok = await confirmBox({ title: '清空日志', message: '只保留登录失败记录（防爆破要用）。', okText: '清空', danger: true });

                if (!ok) {
                    return;
                }

                try {
                    const res = await api.post('/api/admin/logs/clear');
                    toast('清掉了 ' + res.removed + ' 条', 'ok');
                    load();
                } catch (e) {
                    toastError(e);
                }
            });
        }
    }

    // ------------------------------------------------------------ 推送通知

    async function renderNotify() {
        const d = await api.get('/api/admin/notify/channels');
        const types = d.types || {};
        const slots = d.slots || {};
        const sched = d.schedule || {};
        const slotKeys = Object.keys(slots);

        body.innerHTML = html`
        <div class="cols">
            <section class="panel">
                <div class="panel-head">
                    <h3>推送渠道</h3>
                    <button class="btn btn-primary btn-sm" id="nt-new" type="button">新增渠道</button>
                </div>
                <p class="dim small">
                    到点了就把当天这一餐的安排推过去。当天没排菜就不推，不打扰人。
                </p>
                ${d.items.length
                    ? html`<div class="rows-list">
                          ${raw(
                              d.items
                                  .map(
                                      (c) => `
                          <div class="row-item">
                              <div class="row-main">
                                  <span class="row-title">${esc(c.name)}</span>
                                  <span class="tag-mini">${esc(c.typeLabel)}</span>
                              </div>
                              <div class="row-meta"><span class="dim">${c.enabled ? '已启用' : '已停用'}</span></div>
                              <div class="row-tools">
                                  <button class="btn btn-sm" data-nt-test="${c.id}">测试</button>
                                  <button class="btn btn-sm" data-nt-edit="${c.id}">编辑</button>
                                  <button class="icon-btn danger" data-nt-del="${c.id}">✕</button>
                              </div>
                          </div>`
                                  )
                                  .join('')
                          )}
                      </div>`
                    : html`<p class="dim">还没配任何渠道。</p>`}
            </section>

            <section class="panel">
                <h3>推送时间</h3>
                <p class="dim small">北京时间。到点那一分钟推一次，同一餐同一个渠道一天只推一回。</p>
                <div class="rows-list">
                    ${raw(
                        slotKeys
                            .map(
                                (k) => `
                    <div class="row-item">
                        <div class="row-main"><span class="row-title">${esc(slots[k])}</span></div>
                        <div class="row-tools nt-tools">
                            <label class="nt-check"><input type="checkbox" data-nt-on="${esc(k)}"${sched[k] && sched[k].on ? ' checked' : ''}> 启用</label>
                            <input type="time" class="nt-time" data-nt-time="${esc(k)}" value="${esc((sched[k] && sched[k].time) || '08:00')}">
                        </div>
                    </div>`
                            )
                            .join('')
                    )}
                </div>
                <div class="btn-row">
                    <button class="btn btn-primary btn-sm" id="nt-save-sched" type="button">保存时间</button>
                </div>
            </section>
        </div>

        <section class="panel">
            <h3>让它按时跑起来</h3>
            <p class="dim small">
                站点自己不会定时，需要一个每分钟跑一次的 cron（去重在数据库里，跑勤快点也不会重复推）。
            </p>
            <p class="dim small">宝塔面板：计划任务 → 任务类型「Shell 脚本」→ 执行周期「每 1 分钟」→ 脚本内容：</p>
            <pre class="mono small">cd /www/wwwroot/你的站点目录 &amp;&amp; php cli/notify.php</pre>
            <p class="dim small">
                Docker 部署：宿主机 crontab 加一行
                <code>* * * * * cd /opt/recipe-hub &amp;&amp; docker compose exec -T recipe-hub php cli/notify.php</code>
            </p>
            <div class="btn-row">
                <button class="btn btn-sm" id="nt-run" type="button">立即推送今天这一餐</button>
                <select id="nt-run-slot" class="nt-time">
                    ${raw(slotKeys.map((k) => `<option value="${esc(k)}">${esc(slots[k])}</option>`).join(''))}
                </select>
                <button class="btn btn-sm" id="nt-logs" type="button">看推送记录</button>
            </div>
            <div id="nt-log-box"></div>
        </section>`;

        // ---------------------------------------------------------------- 渠道表单

        async function openChannelForm(ch) {
            let type = ch ? ch.type : '';

            if (!type) {
                const pick = await formBox({
                    title: '新增推送渠道',
                    okText: '下一步',
                    fields: [
                        {
                            name: 'type',
                            label: '渠道类型',
                            type: 'select',
                            value: 'wecom_app',
                            options: Object.keys(types).map((k) => ({ value: k, label: types[k].label })),
                        },
                    ],
                });

                if (!pick) {
                    return;
                }

                type = pick.type;
            }

            const meta   = types[type] || { label: type, fields: {} };
            const fields = [
                {
                    name: 'name',
                    label: '渠道名称',
                    value: ch ? ch.name : '',
                    required: true,
                    placeholder: '比如：推到我的微信',
                },
            ];

            Object.keys(meta.fields).forEach((k) => {
                const m = meta.fields[k];

                fields.push({
                    name: 'cfg_' + k,
                    label: m.label + (m.required ? ' *' : ''),
                    type: m.type || 'text',
                    options: m.options || [],
                    value: (ch && ch.config && ch.config[k]) || m.default || '',
                    required: !!m.required,
                    hint: m.hint || (m.secret && ch ? '不改就保持原样（已打码）' : ''),
                });
            });

            const f = await formBox({
                title: (ch ? '编辑渠道 · ' : '新增渠道 · ') + meta.label,
                okText: '保存',
                fields,
            });

            if (!f) {
                return;
            }

            const config = {};

            Object.keys(f).forEach((k) => {
                if (k.indexOf('cfg_') === 0) {
                    config[k.slice(4)] = f[k];
                }
            });

            try {
                await api.post('/api/admin/notify/channels', {
                    id: ch ? ch.id : 0,
                    name: f.name,
                    type,
                    enabled: true,
                    config,
                });

                toast('保存好了', 'ok');
                load();
            } catch (err) {
                toastError(err);
            }
        }

        // ---------------------------------------------------------------- 事件
        // 用 onclick 赋值而不是 addEventListener：这个 tab 会被反复渲染，
        // 累积监听会让一次点击触发好几个请求。

        body.onclick = async (e) => {
            const t = e.target.closest('button');

            if (!t) {
                return;
            }

            if (t.id === 'nt-new') {
                await openChannelForm(null);
                return;
            }

            const editId = t.getAttribute('data-nt-edit');

            if (editId) {
                await openChannelForm(d.items.find((x) => String(x.id) === String(editId)));
                return;
            }

            const testId = t.getAttribute('data-nt-test');

            if (testId) {
                t.disabled = true;

                try {
                    await api.post('/api/admin/notify/channels/test', { id: Number(testId) });
                    toast('发出去了，看看手机', 'ok');
                } catch (err) {
                    toastError(err);
                } finally {
                    t.disabled = false;
                }

                return;
            }

            const delId = t.getAttribute('data-nt-del');

            if (delId) {
                const ch  = d.items.find((x) => String(x.id) === String(delId));
                const ok  = await confirmBox({
                    title: '删掉这个渠道',
                    message: ch ? ch.name : ('#' + delId),
                    okText: '删除',
                    danger: true,
                });

                if (!ok) {
                    return;
                }

                try {
                    await api.del('/api/admin/notify/channels/' + delId);
                    toast('已删除', 'ok');
                    load();
                } catch (err) {
                    toastError(err);
                }

                return;
            }

            if (t.id === 'nt-save-sched') {
                const schedule = {};

                $$('[data-nt-on]', body).forEach((cb) => {
                    const k = cb.getAttribute('data-nt-on');
                    const timeEl = $('[data-nt-time="' + k + '"]', body);
                    schedule[k] = { on: cb.checked, time: timeEl ? timeEl.value : '08:00' };
                });

                try {
                    await api.post('/api/admin/notify/schedule', { schedule });
                    toast('时间存好了', 'ok');
                } catch (err) {
                    toastError(err);
                }

                return;
            }

            if (t.id === 'nt-run') {
                const sel = document.getElementById('nt-run-slot');
                const slot = sel ? sel.value : 'dinner';

                t.disabled = true;

                try {
                    const res = await api.post('/api/admin/notify/run', { slot });
                    toast('推完了：成功 ' + res.sent + '、失败 ' + res.failed, res.failed > 0 ? 'warn' : 'ok');
                } catch (err) {
                    toastError(err);
                } finally {
                    t.disabled = false;
                }

                return;
            }

            if (t.id === 'nt-logs') {
                const box = document.getElementById('nt-log-box');

                try {
                    const res = await api.get('/api/admin/notify/logs?limit=30');

                    box.innerHTML = res.items.length
                        ? html`<div class="rows-list">
                              ${raw(
                                  res.items
                                      .map(
                                          (l) => `
                              <div class="row-item">
                                  <div class="row-main">
                                      <span class="row-title">${esc(l.channel_name || ('渠道 #' + l.channel_id))}</span>
                                      <span class="tag-mini">${l.status === 'ok' ? '成功' : '失败'}</span>
                                  </div>
                                  <div class="row-meta">
                                      <span class="dim">${esc(l.title || '')} · ${esc(fmtDateTime(l.created_at))}</span>
                                      ${l.error ? html`<span class="dim">${esc(l.error)}</span>` : ''}
                                  </div>
                              </div>`
                                      )
                                      .join('')
                              )}
                          </div>`
                        : html`<p class="dim">还没有推送记录。</p>`;
                } catch (err) {
                    toastError(err);
                }
            }
        };
    }

    // ------------------------------------------------------------ 备份 / 恢复
    //
    // 恢复是全站唯一「一步把家底换掉」的操作，所以拆成两段：
    //   体检（inspect）—— 先看清楚这份文件里到底有什么，拿到 token
    //   动手（restore）—— 二次确认后才拿 token 去执行
    // 恢复前程序会自动给现状拍一张 before-restore 快照，留在列表里就是回滚通道。

    async function renderBackups() {
        const d = await api.get('/api/admin/backups');

        body.innerHTML = html`
        <div class="stack">
            <div class="cols">
                <section class="panel">
                    <div class="panel-head">
                        <h3>备份 / 恢复</h3>
                        <button class="btn btn-primary btn-sm" id="bk-new" type="button">立即备份</button>
                    </div>
                    <p class="dim small">
                        一键把<b>整个站</b>打成一份 zip：数据库（账号、菜谱、食材、库存、计划、采购单、设置）
                        加上 <code>data/uploads/</code> 里的<b>全部图片</b>。文件在 <span class="mono">${d.dir}</span>，
                        手建的留最近 10 份。换服务器时把这个包传回来点一下恢复，菜和图片一起回来。
                    </p>
                    ${d.items.length
                        ? html`<div class="rows-list">
                              ${raw(
                                  d.items
                                      .map(
                                          (f) => `
                          <div class="row-item">
                              <div class="row-main">
                                  <span class="row-title mono">${esc(f.file)}</span>
                                  <span class="row-qty dim">${esc(f.human)}</span>
                              </div>
                              <div class="row-meta">
                                  <span class="dim">${esc(fmtDateTime(f.time))}</span>
                                  ${f.kind === 'pre-restore'
                                      ? '<span class="tag-mini">恢复前快照</span>'
                                      : f.pack
                                        ? '<span class="tag-mini">整站包</span>'
                                        : '<span class="tag-mini">仅数据库</span>'}
                              </div>
                              <div class="row-tools">
                                  <button class="btn btn-sm" data-rs="${esc(f.file)}">恢复</button>
                                  <button class="btn btn-sm" data-dl="${esc(f.file)}">下载</button>
                                  <button class="icon-btn danger" data-rm="${esc(f.file)}">✕</button>
                              </div>
                          </div>`
                                      )
                                      .join('')
                              )}
                          </div>`
                        : html`<p class="dim">还没有备份。</p>`}
                </section>

                <section class="panel">
                    <h3>从文件恢复</h3>
                    <div class="drop-zone" id="rs-drop">
                        把备份文件拖进来，或者
                        <button class="link" id="rs-pick" type="button">选择文件</button>
                    </div>
                    <p class="dim small">
                        认本站生成的 <code>recipe-*.zip</code>（整站包，含图片）和老的
                        <code>recipe-*.sqlite</code>（只有数据库）。别的文件会被体检挡下，不会动到现在的数据。
                        整站包外面多套了一层文件夹（解压后重新压过）也没关系，照样认。
                    </p>
                    <div id="rs-detail"></div>
                </section>
            </div>

            <section class="panel">
                <h3>恢复是怎么回事</h3>
                <ol class="steps-text">
                    <li>选中文件先做<b>体检</b>：能不能打开、多少菜谱、哪个版本、图片全不全。</li>
                    <li>你确认之后，程序先把包里的<b>图片并回</b> <code>data/uploads/</code>（同名覆盖，不删任何东西），再整体换库。</li>
                    <li>换库前会自动给现状拍一张快照（<code>before-restore-*.sqlite</code>）；换完自动升级库结构、重连，并<b>保住你的登录状态</b>。</li>
                    <li>恢复错了？列表里那份 <code>before-restore-*</code> 点「恢复」就是一键回滚。</li>
                </ol>
                <p class="dim small">
                    老格式的 <code>recipe-*.sqlite</code> 只含数据库，恢复后图片得服务器上本来就有；
                    新打的整站包图片是齐的，换机器推荐用它。
                </p>
            </section>
        </div>`;

        // ---- 体检结果卡片
        function showResult(name, info) {
            const box = document.getElementById('rs-detail');
            const payload = info.token ? { token: info.token, name: name } : { file: info.file, name: name };

            box.innerHTML = html`
            <div class="rs-card">
                <div class="rs-head">
                    <span class="mono">${esc(name)}</span>
                    <span class="dim">${esc(info.human)}</span>
                </div>
                <div class="stat-row">
                    <div class="stat"><em>菜谱</em><strong>${info.counts.recipes}</strong></div>
                    <div class="stat"><em>食材库</em><strong>${info.counts.ingredients}</strong></div>
                    <div class="stat"><em>账号</em><strong>${info.counts.users}</strong></div>
                    <div class="stat"><em>计划</em><strong>${info.counts.meal_plans}</strong></div>
                </div>
                <p class="dim small">
                    库结构 v${info.schema}${info.created_ts ? ' · 打包于 ' + fmtDateTime(info.created_ts) : ''}
                </p>
                <p class="dim small">
                    ${info.kind === 'site'
                        ? info.missing_images
                            ? '整站包：带 ' + info.packed_images + ' 个图片文件，但库里引用的图有 ' + info.missing_images + ' 张不在包里，恢复后会缺图。'
                            : '整站包：带 ' + info.packed_images + ' 个图片文件，库里引用的图一张不缺。'
                        : '仅数据库：库里引用了 ' + info.images + ' 张图，恢复后要靠服务器上本来就有。'}
                </p>
                ${info.warnings.length
                    ? html`<ul class="steps-text warn">${raw(info.warnings.map((w) => `<li>${esc(w)}</li>`).join(''))}</ul>`
                    : ''}
                <div class="btn-row">
                    <button class="btn btn-danger" id="rs-go" type="button">用这份替换现在的数据</button>
                    <button class="btn" id="rs-cancel" type="button">取消</button>
                </div>
            </div>`;

            document.getElementById('rs-cancel').addEventListener('click', () => {
                box.innerHTML = '';
            });

            document.getElementById('rs-go').addEventListener('click', async () => {
                const go = await confirmBox({
                    title: '确认恢复？',
                    message:
                        '现在的全部数据（菜谱、食材、库存、计划、清单、账号）会被这份备份整个换掉，' +
                        '包里的图片也会并进 data/uploads/（只覆盖同名，不删你现在多出来的图）。' +
                        '动手前会自动留一张当前快照，但这一步仍然不可逆，确定继续吗？',
                    okText: '恢复',
                    cancelText: '再想想',
                    danger: true,
                });

                if (!go) {
                    return;
                }

                const btn = document.getElementById('rs-go');
                btn.disabled = true;
                btn.textContent = '正在恢复…';

                try {
                    const out = await api.post('/api/admin/backups/restore', payload);
                    toast(
                        '恢复完成：' + out.counts.recipes + ' 道菜谱 / ' + out.counts.users + ' 个账号'
                            + (out.images_placed ? ' / 铺回 ' + out.images_placed + ' 张图' : '')
                            + '。正在重新载入…',
                        'ok',
                        4000
                    );

                    setTimeout(() => {
                        if (out.relogin) {
                            location.hash = '#/login';
                        }

                        location.reload();
                    }, 1200);
                } catch (e) {
                    toastError(e);
                    btn.disabled = false;
                    btn.textContent = '用这份替换现在的数据';
                }
            });

            box.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        async function inspectByFile(file) {
            const box = document.getElementById('rs-detail');
            box.innerHTML = html`<p class="dim">正在检查 ${esc(file)}…</p>`;

            try {
                const info = await api.post('/api/admin/backups/inspect', { file: file });
                showResult(file, info);
            } catch (e) {
                toastError(e);
                box.innerHTML = html`<p class="dim">${e.message}</p>`;
            }
        }

        async function inspectUpload(file) {
            const box = document.getElementById('rs-detail');
            box.innerHTML = html`<p class="dim">正在上传并检查 ${esc(file.name)}…</p>`;

            const fd = new FormData();
            fd.append('file', file);

            try {
                const info = await api.post('/api/admin/backups/inspect', fd);
                showResult(file.name, info);
            } catch (e) {
                toastError(e);
                box.innerHTML = html`<p class="dim">${e.message}</p>`;
            }
        }

        const pick = document.getElementById('rs-pick');

        pick.addEventListener('click', (e) => {
            e.preventDefault();

            const inp = document.createElement('input');
            inp.type = 'file';
            inp.accept = '.zip,.sqlite,.db,.bak';
            inp.style.display = 'none';
            document.body.appendChild(inp);

            inp.addEventListener('change', () => {
                const f = inp.files && inp.files[0];
                inp.remove();

                if (f) {
                    inspectUpload(f);
                }
            });

            inp.click();
        });

        // ---- 拖放
        const zone = document.getElementById('rs-drop');

        ['dragenter', 'dragover'].forEach((ev) =>
            zone.addEventListener(ev, (e) => {
                e.preventDefault();
                zone.classList.add('over');
            })
        );

        ['dragleave', 'dragend'].forEach((ev) =>
            zone.addEventListener(ev, () => zone.classList.remove('over'))
        );

        zone.addEventListener('drop', (e) => {
            e.preventDefault();
            zone.classList.remove('over');

            const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];

            if (f) {
                inspectUpload(f);
            }
        });

        document.getElementById('bk-new').addEventListener('click', async () => {
            const btn = document.getElementById('bk-new');
            btn.disabled = true;
            btn.textContent = '备份中…';

            try {
                const res = await api.post('/api/admin/backups');
                toast('备份好了：' + res.file + '（' + res.human + '）', 'ok');
                load();
            } catch (e) {
                toastError(e);
                btn.disabled = false;
                btn.textContent = '立即备份';
            }
        });

        body.addEventListener('click', async (e) => {
            const dl = e.target.closest('[data-dl]');

            if (dl) {
                download('/api/admin/backups/download?file=' + encodeURIComponent(dl.getAttribute('data-dl')));
                return;
            }

            const rs = e.target.closest('[data-rs]');

            if (rs) {
                await inspectByFile(rs.getAttribute('data-rs'));
                return;
            }

            const rm = e.target.closest('[data-rm]');

            if (rm) {
                const ok = await confirmBox({ title: '删掉这份备份', message: rm.getAttribute('data-rm'), okText: '删除', danger: true });

                if (!ok) {
                    return;
                }

                try {
                    await api.post('/api/admin/backups/remove', { file: rm.getAttribute('data-rm') });
                    toast('已删除', 'ok');
                    load();
                } catch (err) {
                    toastError(err);
                }
            }
        });
    }

    // ------------------------------------------------------------ 维护

    async function renderSystem() {
        body.innerHTML = html`
        <div class="cols">
            <section class="panel">
                <h3>维护任务</h3>
                <div class="btn-row">
                    <button class="btn" data-task="refresh_ingredients" type="button">重算食材引用次数</button>
                    <button class="btn" data-task="rebuild_search" type="button">重建全部搜索索引</button>
                    <button class="btn" data-task="vacuum" type="button">压缩数据库（VACUUM）</button>
                    <button class="btn" data-task="prune_logs" type="button">清理 90 天前日志</button>
                    <button class="btn" data-task="seed_demo" type="button">补一份示例菜谱</button>
                </div>
                <p class="dim small">
                    改了食材库之后「重算引用次数」比较有用；导入了大批菜谱之后可以重建一次搜索索引。
                </p>
            </section>

            <section class="panel">
                <h3>使用建议</h3>
                <ul class="steps-text">
                    <li>备份就是 <code>data/backups/</code> 里的那份 zip：一个文件装走数据库 + 全部图片，顺手存一份到网盘就稳了。</li>
                    <li>反代加 HTTPS（Caddy 一行搞定），Cookie 会自动带上 Secure。</li>
                    <li>私有实例建议关掉自助注册，账号由你在这里手工建。</li>
                    <li>想定时备份，加个 cron 跑 <code>cli/backup.php</code>（默认打整站包，<code>--db-only</code> 只要数据库）。</li>
                </ul>
            </section>
        </div>`;

        body.addEventListener('click', async (e) => {
            const b = e.target.closest('[data-task]');

            if (!b) {
                return;
            }

            const task = b.getAttribute('data-task');
            b.disabled = true;

            try {
                const res = await api.post('/api/admin/maintenance', { task: task, days: 90 });
                toast('完成：' + JSON.stringify(res), 'ok');
            } catch (err) {
                toastError(err);
            } finally {
                b.disabled = false;
            }
        });
    }

    load();
}
