/**
 * 主题：跟随系统 / 浅色 / 深色。
 *
 * 存在 localStorage 里，所以换台手机、隔几个月回来还是那个色调。
 * 选择在系统跟着走的模式下，系统切主题时会实时跟上。
 */

const KEY = 'rh-theme';

function media() {
    return window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
}

/** localStorage 在某些隐私模式下会抛异常，别让它把整个应用带崩 */
function read() {
    try {
        return localStorage.getItem(KEY) || 'system';
    } catch (e) {
        return 'system';
    }
}

function write(mode) {
    try {
        if (mode === 'system') {
            localStorage.removeItem(KEY);
        } else {
            localStorage.setItem(KEY, mode);
        }
    } catch (e) {
        /* 存不了就算了，不影响这次显示 */
    }
}

export function themeMode() {
    return read();
}

export function setThemeMode(mode) {
    write(mode === 'dark' || mode === 'light' ? mode : 'system');
    applyTheme();
}

export function isDark() {
    return document.documentElement.getAttribute('data-theme') === 'dark';
}

export function applyTheme() {
    const mode = read();
    const m    = media();
    const dark = mode === 'dark' || (mode === 'system' && !!(m && m.matches));

    document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
}

/** 循环切换：系统 → 浅 → 深 → 系统。放在侧边栏那个小按钮上正好 */
export function cycleTheme() {
    const order = ['system', 'light', 'dark'];
    const next  = order[(order.indexOf(read()) + 1) % order.length];

    setThemeMode(next);

    return next;
}

const m = media();

if (m && m.addEventListener) {
    m.addEventListener('change', () => {
        if (read() === 'system') {
            applyTheme();
        }
    });
}

applyTheme();
