/**
 * 接口封装。
 * 统一处理 CSRF、JSON 编解码、401 跳登录、错误提示。
 */

const boot = window.RH_BOOT || {};

let csrfToken = boot.csrf || '';

export function setCsrf(t) {
    csrfToken = t || '';
}

export function getCsrf() {
    return csrfToken;
}

export const bootInfo = boot;

export class ApiError extends Error {
    constructor(message, status = 0, field = '') {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.field = field;
    }
}

const listeners = {};

export function onApi(evt, fn) {
    (listeners[evt] = listeners[evt] || []).push(fn);
}

function emit(evt, payload) {
    (listeners[evt] || []).forEach((fn) => {
        try {
            fn(payload);
        } catch (e) {
            console.error(e);
        }
    });
}

async function request(method, path, body) {
    const headers = { Accept: 'application/json' };

    if (csrfToken) {
        headers['X-CSRF-Token'] = csrfToken;
    }

    let payload;

    if (body instanceof FormData) {
        payload = body;
    } else if (body !== undefined && body !== null) {
        headers['Content-Type'] = 'application/json';
        payload = JSON.stringify(body);
    }

    let res;

    try {
        res = await fetch(path, {
            method,
            headers,
            body: payload,
            credentials: 'same-origin',
        });
    } catch (e) {
        throw new ApiError('连不上服务器，检查一下网络', 0);
    }

    const ct = res.headers.get('content-type') || '';
    let data = null;

    if (ct.indexOf('json') >= 0) {
        try {
            data = await res.json();
        } catch (e) {
            data = null;
        }
    }

    if (res.status === 401) {
        emit('unauthorized');
        throw new ApiError((data && data.error) || '登录状态已失效', 401);
    }

    if (!res.ok || (data && data.ok === false)) {
        throw new ApiError(
            (data && data.error) || ('请求失败（HTTP ' + res.status + '）'),
            res.status,
            (data && data.field) || ''
        );
    }

    return data ? data.data : null;
}

export const api = {
    get: (p) => request('GET', p),
    post: (p, b) => request('POST', p, b === undefined ? {} : b),
    put: (p, b) => request('PUT', p, b),
    del: (p, b) => request('DELETE', p, b),
};

/** 下载类接口（导出/备份）：直接让浏览器接管 */
export function download(path) {
    const a = document.createElement('a');
    a.href = path;
    a.rel = 'noopener';
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    setTimeout(() => a.remove(), 1000);
}

export function qs(params) {
    const p = new URLSearchParams();

    Object.keys(params || {}).forEach((k) => {
        const v = params[k];

        if (v === undefined || v === null || v === '') {
            return;
        }

        if (Array.isArray(v)) {
            v.forEach((x) => p.append(k + '[]', x));
        } else if (v === true) {
            p.append(k, '1');
        } else if (v !== false) {
            p.append(k, v);
        }
    });

    const s = p.toString();

    return s ? '?' + s : '';
}
