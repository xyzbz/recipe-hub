/**
 * 回收站。
 *
 * 删掉的菜谱先到这里待着，数据还在（配料、步骤、标签、图片都在），
 * 点「恢复」就原样回去。只有点「彻底删除」才真的抹掉。
 */

import { api } from '../api.js';
import { html, raw, esc, toast, toastError, confirmBox, fmtAgo, fmtDateTime } from '../dom.js';

export async function renderTrash(root) {
    root.innerHTML = html`
    <div class="page-head">
        <h2 class="page-title">回收站</h2>
        <div class="page-actions">
            <a class="btn btn-sm" href="#/recipes">← 回菜谱</a>
        </div>
    </div>

    <div id="trash-body" class="dim">正在翻回收站…</div>`;

    const box = document.getElementById('trash-body');

    let items = [];

    try {
        items = (await api.get('/api/recipes/trash')).items || [];
    } catch (e) {
        box.innerHTML = html`<div class="empty"><p>加载失败：${e.message}</p></div>`;
        return;
    }

    // 点击事件只绑一次：挂在容器上做事件委托，列表重绘不会叠加监听
    box.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-act]');

        if (!btn) {
            return;
        }

        const id  = Number(btn.closest('.trash-item').dataset.id);
        const act = btn.dataset.act;

        if (act === 'restore') {
            try {
                await api.post('/api/recipes/trash/' + id + '/restore');
                toast('已恢复，回到菜谱列表了', 'ok');
                items = items.filter((x) => Number(x.id) !== id);
                draw();
            } catch (err) {
                toastError(err);
            }

            return;
        }

        const okd = await confirmBox({
            title:     '彻底删除？',
            message:   '这条菜谱连同它的配料、步骤、图片一起消失，捞不回来了。确定吗？',
            okText:    '彻底删除',
            cancelText: '留着',
            danger:    true,
        });

        if (!okd) {
            return;
        }

        try {
            await api.del('/api/recipes/trash/' + id + '/purge');
            toast('已经彻底删掉', 'ok');
            items = items.filter((x) => Number(x.id) !== id);
            draw();
        } catch (err) {
            toastError(err);
        }
    });

    draw();

    function draw() {
        if (!items.length) {
            box.innerHTML = html`
            <div class="empty">
                <p>回收站是空的。</p>
                <p class="dim">删掉的菜谱会先躺在这儿，想删彻底再来一趟。</p>
            </div>`;
            return;
        }

        box.innerHTML = html`
        <p class="dim trash-tip">这里的东西还能捞回来，恢复之后跟删之前一模一样。</p>
        <ul class="trash-list">
            ${raw(items.map((r) => {
                const cover = r.cover_url
                    ? '<img class="trash-cover" src="' + esc(r.cover_url) + '" alt="" loading="lazy">'
                    : '<span class="trash-cover trash-cover-fallback">' + esc((r.name || '?').slice(0, 1)) + '</span>';

                return html`
            <li class="trash-item" data-id="${r.id}">
                ${raw(cover)}
                <span class="trash-main">
                    <strong>${r.name}</strong>
                    <em class="dim">
                        ${r.owner_name ? esc(r.owner_name) + ' · ' : ''}
                        删于 ${fmtDateTime(r.deleted_at)}（${fmtAgo(r.deleted_at)}）
                    </em>
                </span>
                <span class="trash-ops">
                    <button class="btn btn-sm" data-act="restore" type="button">恢复</button>
                    <button class="btn btn-sm btn-danger" data-act="purge" type="button">彻底删除</button>
                </span>
            </li>`;
            }).join(''))}
        </ul>`;
    }
}
