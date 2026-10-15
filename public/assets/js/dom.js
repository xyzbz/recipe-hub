/**
 * DOM 小工具。
 *
 * 模板用 html`` 标签函数，插值默认转义——这样写大段结构也不用担心 XSS，
 * 只有明确想插 HTML 时才套 raw()。
 */

export function esc(v) {
    if (v === null || v === undefined || v === false) {
        return '';
    }

    return String(v)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

export function raw(value) {
    return { __raw: true, value: value === null || value === undefined ? '' : String(value) };
}

/**
 * html`` 的返回值。
 *
 * 继承 String，是为了它跟普通字符串在任何场合都能互换：
 * .replace() / .slice() / .length / [].join() / innerHTML = 全都照旧。
 * 多出来的 __raw 标记只解决一件事——**嵌套的 html`` 不再被外层二次转义**。
 *
 * 这里踩过一次坑：之前 html`` 返回的是普通字符串，跟用户数据长得一模一样，
 * 外层 html`` 没法分辨，只能一律 esc()。于是
 *
 *     ${cond ? html`<span class="pill">${x}</span>` : raw('')}
 *
 * 这种写法会把 `<span class="pill">` 整段转义，页面上直接显示出
 * `<span class="pill">…</span>` 这样的字面量文本。全项目一共 39 处这种写法。
 *
 * 现在的分工很清楚：
 *   html``  → 作者写的模板，安全，可嵌套
 *   raw()   → 手工拼接的 HTML 片段，安全，可嵌套
 *   其它一切 → 一律转义（用户数据走这条）
 */
class Markup extends String {
    get __raw() {
        return true;
    }

    get value() {
        return this.toString();
    }
}

export function html(strings, ...values) {
    let out = strings[0];

    for (let i = 0; i < values.length; i++) {
        const v = values[i];

        if (Array.isArray(v)) {
            out += v.map((x) => (x && x.__raw ? x.value : esc(x))).join('');
        } else if (v && v.__raw) {
            out += v.value;
        } else {
            out += esc(v);
        }

        out += strings[i + 1];
    }

    return new Markup(out);
}

export function mount(node, markup) {
    node.innerHTML = markup;
    return node;
}

export function $(sel, root) {
    return (root || document).querySelector(sel);
}

export function $$(sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
}

/** 事件委托：在容器上挂一次，管住整块动态内容 */
export function delegate(root, eventName, selector, handler) {
    root.addEventListener(eventName, (e) => {
        const target = e.target.closest(selector);

        if (target && root.contains(target)) {
            handler(e, target);
        }
    });
}

export function on(root, eventName, selector, handler) {
    delegate(root, eventName, selector, handler);
}

// ---------------------------------------------------------------- 时间与数字

export function fmtNum(n) {
    if (n === null || n === undefined || n === '') {
        return '';
    }

    const v = Number(n);

    if (!isFinite(v)) {
        return String(n);
    }

    if (Math.abs(v - Math.round(v)) < 0.001) {
        return String(Math.round(v));
    }

    return String(Math.round(v * 100) / 100);
}

export function fmtDuration(min) {
    const m = Number(min) || 0;

    if (m <= 0) {
        return '';
    }

    if (m < 60) {
        return m + ' 分钟';
    }

    const h = Math.floor(m / 60);
    const r = m % 60;

    if (r === 0) {
        return h + ' 小时';
    }

    return h + ' 小时 ' + r + ' 分';
}

/**
 * 封面图的 CSS —— 卡片 / 详情 / 编辑预览都用它。
 *
 * 这里记一个踩过的坑：这个值最终要塞进 `style="..."` 属性里，
 * **绝对不能带双引号**。早先写的是 `url("…")`，又用 raw() 插值（不转义），
 * 于是属性被第一个双引号截断，浏览器拿到的是残缺的 `background-image:url(`，
 * 整条样式作废——表现是详情页和后台列表封面一片空白，而游客页（那边没走 raw）
 * 却好着，特别像「图挂了」。统一不包引号，从根上避免。
 */
export function coverStyle(r) {
    const url = (r && (r.cover_thumb || r.cover_url)) || '';

    if (!url) {
        return '';
    }

    // 路径都是站点自己生成的（年/月/16位hex.webp），这里再把可能截断属性
    // 的字符洗一遍，多一层保险
    const safe = String(url).replace(/["'\\()<>\s]/g, '');

    return safe ? 'background-image:url(' + safe + ')' : '';
}

export function fmtDate(ts) {
    if (!ts) {
        return '';
    }

    const d = typeof ts === 'number' ? new Date(ts * 1000) : new Date(ts);

    if (isNaN(d.getTime())) {
        return String(ts);
    }

    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
}

export function fmtDateTime(ts) {
    if (!ts) {
        return '';
    }

    const d = new Date(Number(ts) * 1000);

    if (isNaN(d.getTime())) {
        return '';
    }

    return fmtDate(ts) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
}

export function fmtAgo(ts) {
    const t = Number(ts);

    if (!t) {
        return '还没动过';
    }

    const diff = Date.now() / 1000 - t;

    if (diff < 60) {
        return '刚刚';
    }

    if (diff < 3600) {
        return Math.floor(diff / 60) + ' 分钟前';
    }

    if (diff < 86400) {
        return Math.floor(diff / 3600) + ' 小时前';
    }

    if (diff < 86400 * 30) {
        return Math.floor(diff / 86400) + ' 天前';
    }

    return fmtDate(t);
}

export function today() {
    const d = new Date();

    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
}

export function addDays(dateStr, n) {
    const d = new Date(dateStr + 'T00:00:00');
    d.setDate(d.getDate() + n);

    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
}

export function weekStart(dateStr) {
    const d = dateStr ? new Date(dateStr + 'T00:00:00') : new Date();
    const n = d.getDay() === 0 ? 7 : d.getDay();
    d.setDate(d.getDate() - (n - 1));

    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
}

const WEEK_CN = ['周日', '周一', '周二', '周三', '周四', '周五', '周六'];

export function weekdayOf(dateStr) {
    const d = new Date(dateStr + 'T00:00:00');

    return isNaN(d.getTime()) ? '' : WEEK_CN[d.getDay()];
}

export function pad(n) {
    return (n < 10 ? '0' : '') + n;
}

export function debounce(fn, ms) {
    let t = 0;

    return function () {
        const args = arguments;
        clearTimeout(t);
        t = setTimeout(() => fn.apply(null, args), ms || 250);
    };
}

// ---------------------------------------------------------------- 提示与弹窗

function host() {
    let h = document.getElementById('rh-overlays');

    if (!h) {
        h = document.createElement('div');
        h.id = 'rh-overlays';
        document.body.appendChild(h);
    }

    return h;
}

let toastTimer = 0;

export function toast(message, type = 'info', ms = 2600) {
    const h = host();
    const node = document.createElement('div');
    node.className = 'toast toast-' + type;
    node.textContent = message;
    h.appendChild(node);

    requestAnimationFrame(() => node.classList.add('in'));

    clearTimeout(toastTimer);

    setTimeout(() => {
        node.classList.remove('in');
        setTimeout(() => node.remove(), 260);
    }, ms);
}

export function toastError(err) {
    const msg = err && err.message ? err.message : String(err);
    toast(msg, 'error', 3600);
}

/** 确认框，返回 Promise<boolean> */
export function confirmBox(opts) {
    const o = Object.assign(
        { title: '确认操作', message: '', okText: '确定', cancelText: '取消', danger: false },
        opts || {}
    );

    return new Promise((resolve) => {
        const h = host();
        const wrap = document.createElement('div');
        wrap.className = 'modal-mask';
        wrap.innerHTML =
            '<div class="modal" role="dialog" aria-modal="true">' +
            '<div class="modal-head"><h3>' +
            esc(o.title) +
            '</h3></div>' +
            '<div class="modal-body">' +
            (o.message ? '<p>' + esc(o.message) + '</p>' : '') +
            '</div>' +
            '<div class="modal-foot">' +
            '<button class="btn" data-x="cancel">' +
            esc(o.cancelText) +
            '</button>' +
            '<button class="btn ' +
            (o.danger ? 'btn-danger' : 'btn-primary') +
            '" data-x="ok">' +
            esc(o.okText) +
            '</button>' +
            '</div></div>';

        h.appendChild(wrap);
        requestAnimationFrame(() => wrap.classList.add('in'));

        const close = (val) => {
            wrap.classList.remove('in');
            setTimeout(() => wrap.remove(), 180);
            resolve(val);
        };

        wrap.querySelector('[data-x="ok"]').addEventListener('click', () => close(true));
        wrap.querySelector('[data-x="cancel"]').addEventListener('click', () => close(false));
        wrap.addEventListener('click', (e) => {
            if (e.target === wrap) {
                close(false);
            }
        });

        if (o.bodyHtml) {
            wrap.querySelector('.modal-body').innerHTML = o.bodyHtml;
        }

        wrap.querySelector('[data-x="ok"]').focus();
    });
}

/** 通用表单弹窗，返回数据对象或 null */
export function formBox(opts) {
    const o = Object.assign({ title: '', fields: [], okText: '保存' }, opts || {});

    return new Promise((resolve) => {
        const h = host();
        const wrap = document.createElement('div');
        wrap.className = 'modal-mask';

        const fieldsHtml = o.fields
            .map((f) => {
                const id = 'f-' + f.name;
                const common =
                    'id="' +
                    id +
                    '" name="' +
                    esc(f.name) +
                    '" ' +
                    (f.required ? 'required ' : '') +
                    (f.placeholder ? 'placeholder="' + esc(f.placeholder) + '" ' : '');
                let input;

                if (f.type === 'textarea') {
                    input = '<textarea ' + common + ' rows="' + (f.rows || 3) + '">' + esc(f.value || '') + '</textarea>';
                } else if (f.type === 'select') {
                    input =
                        '<select ' +
                        common +
                        '>' +
                        (f.options || [])
                            .map(
                                (op) =>
                                    '<option value="' +
                                    esc(op.value) +
                                    '"' +
                                    (String(op.value) === String(f.value) ? ' selected' : '') +
                                    '>' +
                                    esc(op.label) +
                                    '</option>'
                            )
                            .join('') +
                        '</select>';
                } else {
                    input =
                        '<input type="' +
                        esc(f.type || 'text') +
                        '" ' +
                        common +
                        ' value="' +
                        esc(f.value || '') +
                        '">';
                }

                return (
                    '<label class="field"><span>' +
                    esc(f.label) +
                    '</span>' +
                    input +
                    (f.hint ? '<em class="hint">' + esc(f.hint) + '</em>' : '') +
                    '</label>'
                );
            })
            .join('');

        wrap.innerHTML =
            '<div class="modal" role="dialog" aria-modal="true">' +
            '<form><div class="modal-head"><h3>' +
            esc(o.title) +
            '</h3></div>' +
            '<div class="modal-body">' +
            fieldsHtml +
            '</div>' +
            '<div class="modal-foot"><button type="button" class="btn" data-x="cancel">取消</button>' +
            '<button type="submit" class="btn btn-primary">' +
            esc(o.okText) +
            '</button></div></form></div>';

        h.appendChild(wrap);
        requestAnimationFrame(() => wrap.classList.add('in'));

        const form = wrap.querySelector('form');
        const close = (val) => {
            wrap.classList.remove('in');
            setTimeout(() => wrap.remove(), 180);
            resolve(val);
        };

        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const fd = new FormData(form);
            const out = {};

            o.fields.forEach((f) => {
                out[f.name] = fd.get(f.name);
            });

            close(out);
        });

        wrap.querySelector('[data-x="cancel"]').addEventListener('click', () => close(null));
        wrap.addEventListener('click', (e) => {
            if (e.target === wrap) {
                close(null);
            }
        });

        const first = form.querySelector('input, textarea, select');

        if (first) {
            setTimeout(() => first.focus(), 60);
        }
    });
}
