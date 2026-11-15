'use strict';

/**
 * BXADMIN-S: headless-браузер через CDP.
 *
 * Запускает chrome-headless-shell и общается с ним по Chrome DevTools Protocol
 * через встроенный в Node WebSocket. Внешних зависимостей нет.
 *
 * Возможности: скриншот (в т.ч. полной страницы), DOM, вычисленные CSS,
 * консоль и сетевые ошибки, дымовой тест страницы.
 */

const { spawn } = require('node:child_process');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const os = require('node:os');

const CHROME_DIR = process.env.CHROME_DIR || '/home/bitrix/bxadmins-data/chrome';
const CHROME_BIN = process.env.CHROME_BIN
    || path.join(CHROME_DIR, 'chrome-headless-shell-linux64', 'chrome-headless-shell');
const CHROME_LIBS = process.env.CHROME_LIBS
    || path.join(CHROME_DIR, 'libs', 'usr', 'lib', 'x86_64-linux-gnu');
const SHOT_DIR = process.env.SHOT_DIR || '/home/bitrix/bxadmins-data/screenshots';
const DEFAULT_TIMEOUT = Number(process.env.BROWSER_TIMEOUT_MS || 45000);

function log(...args) {
    console.log(new Date().toISOString(), '[browser]', ...args);
}


/**
 * Шум, не относящийся к проверяемой странице: 401 на pull.config у гостя
 * (если на сайте включён Pull) и пустые исключения минифицированных
 * скриптов. Иначе отчёт о скриншоте всегда выглядит «сломанным».
 */
const NOISE_PATTERNS = [
    /\/rest\/pull\.config\.get/i,
    /could not read push-server config/i,
    /^\s*s?\s*$/,
];

function isNoise(entry) {
    const text = String((entry && (entry.text || entry.url || entry.error)) || '');

    return NOISE_PATTERNS.some((pattern) => pattern.test(text));
}

function available() {
    return fs.existsSync(CHROME_BIN);
}

/** Одна WebSocket-сессия CDP с ожиданием ответов по id. */
class Cdp {
    constructor(ws) {
        this.ws = ws;
        this.nextId = 0;
        this.pending = new Map();
        this.listeners = [];

        ws.onmessage = (event) => {
            let message;
            try {
                message = JSON.parse(String(event.data));
            } catch (e) {
                return;
            }

            if (message.id && this.pending.has(message.id)) {
                const { resolve, reject } = this.pending.get(message.id);
                this.pending.delete(message.id);

                if (message.error) {
                    reject(new Error(message.error.message || 'CDP error'));
                } else {
                    resolve(message.result || {});
                }
                return;
            }

            for (const listener of this.listeners) {
                try {
                    listener(message);
                } catch (e) {
                    // Слушатель не должен ломать сессию.
                }
            }
        };
    }

    static connect(url) {
        return new Promise((resolve, reject) => {
            const ws = new WebSocket(url);
            const timer = setTimeout(() => reject(new Error('CDP connect timeout')), 15000);

            ws.onopen = () => {
                clearTimeout(timer);
                resolve(new Cdp(ws));
            };
            ws.onerror = () => {
                clearTimeout(timer);
                reject(new Error('CDP connect failed'));
            };
        });
    }

    on(listener) {
        this.listeners.push(listener);
    }

    send(method, params, sessionId) {
        const id = ++this.nextId;
        const payload = { id, method, params: params || {} };
        if (sessionId) {
            payload.sessionId = sessionId;
        }

        return new Promise((resolve, reject) => {
            this.pending.set(id, { resolve, reject });
            const timer = setTimeout(() => {
                if (this.pending.has(id)) {
                    this.pending.delete(id);
                    reject(new Error('CDP timeout: ' + method));
                }
            }, DEFAULT_TIMEOUT);

            const done = (fn) => (value) => {
                clearTimeout(timer);
                fn(value);
            };
            this.pending.set(id, { resolve: done(resolve), reject: done(reject) });

            try {
                this.ws.send(JSON.stringify(payload));
            } catch (e) {
                clearTimeout(timer);
                this.pending.delete(id);
                reject(e);
            }
        });
    }

    close() {
        try {
            this.ws.close();
        } catch (e) {
            // Уже закрыта.
        }
    }
}

function httpJson(port, pathname) {
    return new Promise((resolve, reject) => {
        const request = http.get({ hostname: '127.0.0.1', port, path: pathname, timeout: 10000 }, (response) => {
            let raw = '';
            response.setEncoding('utf8');
            response.on('data', (chunk) => { raw += chunk; });
            response.on('end', () => {
                try {
                    resolve(JSON.parse(raw));
                } catch (e) {
                    reject(new Error('bad JSON from DevTools endpoint'));
                }
            });
        });

        request.on('error', reject);
        request.on('timeout', () => request.destroy(new Error('DevTools endpoint timeout')));
    });
}

/** Запускает Chrome и возвращает порт отладки. */
function launchChrome() {
    return new Promise((resolve, reject) => {
        if (!available()) {
            reject(new Error('chrome-headless-shell не найден: ' + CHROME_BIN));
            return;
        }

        const userDataDir = fs.mkdtempSync(path.join(os.tmpdir(), 'bxadmins-chrome-'));
        const env = Object.assign({}, process.env);
        env.LD_LIBRARY_PATH = CHROME_LIBS + (env.LD_LIBRARY_PATH ? ':' + env.LD_LIBRARY_PATH : '');

        const child = spawn(CHROME_BIN, [
            '--remote-debugging-port=0',
            '--user-data-dir=' + userDataDir,
            '--no-sandbox',
            '--disable-gpu',
            '--disable-dev-shm-usage',
            '--hide-scrollbars',
            '--mute-audio',
            '--no-first-run',
            '--disable-extensions',
            '--disable-background-networking',
            'about:blank',
        ], { env, stdio: ['ignore', 'pipe', 'pipe'] });

        let settled = false;
        const timer = setTimeout(() => {
            if (!settled) {
                settled = true;
                try { child.kill('SIGKILL'); } catch (e) { /* уже мёртв */ }
                reject(new Error('Chrome не запустился за 30 секунд'));
            }
        }, 30000);

        const onData = (chunk) => {
            const text = String(chunk);
            const match = text.match(/DevTools listening on ws:\/\/127\.0\.0\.1:(\d+)\//);

            if (match && !settled) {
                settled = true;
                clearTimeout(timer);
                resolve({ child, port: Number(match[1]), userDataDir });
            }
        };

        child.stderr.on('data', onData);
        child.stdout.on('data', onData);

        child.on('error', (error) => {
            if (!settled) {
                settled = true;
                clearTimeout(timer);
                reject(error);
            }
        });

        child.on('exit', (code) => {
            if (!settled) {
                settled = true;
                clearTimeout(timer);
                reject(new Error('Chrome завершился с кодом ' + code));
            }
        });
    });
}

function stopChrome(browser) {
    if (!browser) {
        return;
    }

    try {
        browser.child.kill('SIGKILL');
    } catch (e) {
        // Уже завершён.
    }

    try {
        fs.rmSync(browser.userDataDir, { recursive: true, force: true });
    } catch (e) {
        // Каталог мог остаться — не критично.
    }
}

/**
 * Выполняет действие в отдельной странице.
 * @param {(session: string, cdp: Cdp) => Promise<any>} handler
 */
async function withPage(handler) {
    const browser = await launchChrome();

    try {
        const version = await httpJson(browser.port, '/json/version');
        const cdp = await Cdp.connect(version.webSocketDebuggerUrl);

        try {
            const created = await cdp.send('Target.createTarget', { url: 'about:blank' });
            const attached = await cdp.send('Target.attachToTarget', {
                targetId: created.targetId,
                flatten: true,
            });
            const session = attached.sessionId;

            try {
                return await handler(session, cdp);
            } finally {
                try {
                    await cdp.send('Target.closeTarget', { targetId: created.targetId });
                } catch (e) {
                    // Страница могла закрыться сама.
                }
            }
        } finally {
            cdp.close();
        }
    } finally {
        stopChrome(browser);
    }
}

/** Навигация с ожиданием загрузки и сбором консоли/сети. */
async function openPage(session, cdp, url, options) {
    const opts = options || {};
    const consoleMessages = [];
    const failedRequests = [];

    cdp.on((message) => {
        if (message.sessionId !== session) {
            return;
        }

        if (message.method === 'Runtime.consoleAPICalled') {
            const params = message.params || {};
            const text = (params.args || [])
                .map((arg) => {
                    if (arg.value !== undefined) {
                        return String(arg.value);
                    }
                    return arg.description || arg.type || '';
                })
                .join(' ');

            const entry = { level: String(params.type || 'log'), text: text.slice(0, 500) };
            if (!isNoise(entry)) {
                consoleMessages.push(entry);
            }
        }

        if (message.method === 'Runtime.exceptionThrown') {
            const details = (message.params || {}).exceptionDetails || {};
            const description = (details.exception && details.exception.description)
                || details.text
                || 'Исключение';

            const entry = { level: 'error', text: String(description).slice(0, 500) };
            if (!isNoise(entry)) {
                consoleMessages.push(entry);
            }
        }

        if (message.method === 'Log.entryAdded') {
            const entry = (message.params || {}).entry || {};
            if (entry.level === 'error' || entry.level === 'warning') {
                const item = { level: String(entry.level), text: String(entry.text || '').slice(0, 500) };
                if (!isNoise(item)) {
                    consoleMessages.push(item);
                }
            }
        }

        if (message.method === 'Network.loadingFailed') {
            const params = message.params || {};
            if (!params.canceled) {
                const item = {
                    error: String(params.errorText || 'failed'),
                    type: String(params.type || ''),
                };
                if (!isNoise(item)) {
                    failedRequests.push(item);
                }
            }
        }

        if (message.method === 'Network.responseReceived') {
            const response = (message.params || {}).response || {};
            const status = Number(response.status || 0);
            if (status >= 400) {
                const item = {
                    error: 'HTTP ' + status,
                    url: String(response.url || '').slice(0, 300),
                };
                if (!isNoise(item)) {
                    failedRequests.push(item);
                }
            }
        }
    });

    await cdp.send('Page.enable', {}, session);
    await cdp.send('Runtime.enable', {}, session);
    await cdp.send('Log.enable', {}, session);
    await cdp.send('Network.enable', {}, session);

    const width = Math.max(320, Math.min(2560, Number(opts.width) || 1280));
    const height = Math.max(240, Math.min(2000, Number(opts.height) || 900));

    await cdp.send('Emulation.setDeviceMetricsOverride', {
        width,
        height,
        deviceScaleFactor: 1,
        mobile: Boolean(opts.mobile),
    }, session);

    const loaded = new Promise((resolve) => {
        const timer = setTimeout(resolve, DEFAULT_TIMEOUT);
        cdp.on((message) => {
            if (message.sessionId === session && message.method === 'Page.loadEventFired') {
                clearTimeout(timer);
                resolve();
            }
        });
    });

    await cdp.send('Page.navigate', { url }, session);
    await loaded;

    const waitMs = Math.max(0, Math.min(15000, Number(opts.wait_ms) || 0));
    if (waitMs > 0) {
        await new Promise((resolve) => setTimeout(resolve, waitMs));
    }

    return { consoleMessages, failedRequests, width, height };
}

async function evaluate(session, cdp, expression) {
    const result = await cdp.send('Runtime.evaluate', {
        expression,
        returnByValue: true,
        awaitPromise: true,
    }, session);

    if (result.exceptionDetails) {
        const details = result.exceptionDetails;
        throw new Error('JS: ' + ((details.exception && details.exception.description) || details.text));
    }

    return result.result ? result.result.value : null;
}

/** Скриншот страницы. */
async function screenshot(options) {
    const url = String(options.url || '');
    if (url === '') {
        throw new Error('url обязателен');
    }

    return withPage(async (session, cdp) => {
        const page = await openPage(session, cdp, url, options);

        let clip;
        if (options.full_page) {
            const metrics = await cdp.send('Page.getLayoutMetrics', {}, session);
            const size = metrics.cssContentSize || metrics.contentSize || {};
            clip = {
                x: 0,
                y: 0,
                width: Math.max(1, Math.ceil(Number(size.width) || page.width)),
                height: Math.max(1, Math.ceil(Number(size.height) || page.height)),
                scale: 1,
            };
        }

        const shot = await cdp.send('Page.captureScreenshot', {
            format: options.format === 'jpeg' ? 'jpeg' : 'png',
            quality: options.format === 'jpeg' ? 80 : undefined,
            captureBeyondViewport: Boolean(clip),
            clip,
        }, session);

        const buffer = Buffer.from(shot.data, 'base64');

        fs.mkdirSync(SHOT_DIR, { recursive: true });
        const name = 'shot-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8)
            + (options.format === 'jpeg' ? '.jpg' : '.png');
        fs.writeFileSync(path.join(SHOT_DIR, name), buffer);

        return {
            file: name,
            width: clip ? clip.width : page.width,
            height: clip ? clip.height : page.height,
            bytes: buffer.length,
            console: page.consoleMessages.filter((m) => m.level === 'error').slice(0, 10),
            failed_requests: page.failedRequests.slice(0, 10),
        };
    });
}

/** DOM: структура страницы и метаданные. */
async function inspect(options) {
    const url = String(options.url || '');
    if (url === '') {
        throw new Error('url обязателен');
    }

    return withPage(async (session, cdp) => {
        const page = await openPage(session, cdp, url, options);

        const data = await evaluate(session, cdp, `(() => {
            const pick = (selector) => document.querySelector(selector);
            const text = (el) => el ? (el.textContent || '').trim().replace(/\\s+/g, ' ') : '';

            const headings = Array.from(document.querySelectorAll('h1,h2,h3'))
                .slice(0, 40)
                .map((el) => ({ tag: el.tagName.toLowerCase(), text: text(el).slice(0, 160) }));

            const images = Array.from(document.images || []).slice(0, 60).map((img) => ({
                src: img.currentSrc || img.src,
                alt: img.alt || '',
                width: img.naturalWidth,
                height: img.naturalHeight,
                broken: img.complete && img.naturalWidth === 0,
            }));

            const links = Array.from(document.links || []).slice(0, 100).map((a) => ({
                href: a.href,
                text: text(a).slice(0, 80),
            }));

            return {
                title: document.title,
                url: location.href,
                lang: document.documentElement.lang || '',
                meta_description: (pick('meta[name="description"]') || {}).content || '',
                meta_robots: (pick('meta[name="robots"]') || {}).content || '',
                canonical: (pick('link[rel="canonical"]') || {}).href || '',
                h1: text(pick('h1')),
                headings,
                forms: Array.from(document.forms || []).map((form) => ({
                    action: form.action,
                    method: (form.method || 'get').toUpperCase(),
                    fields: Array.from(form.elements || []).filter((el) => el.name).map((el) => el.name).slice(0, 30),
                })),
                images,
                links,
                body_text_length: (document.body ? document.body.innerText || '' : '').length,
                scroll_height: document.documentElement.scrollHeight,
            };
        })()`);

        return Object.assign({}, data, {
            console: page.consoleMessages.slice(0, 30),
            failed_requests: page.failedRequests.slice(0, 20),
        });
    });
}

/** Вычисленные CSS-стили элементов по селектору. */
async function computedCss(options) {
    const url = String(options.url || '');
    const selector = String(options.selector || '');
    if (url === '' || selector === '') {
        throw new Error('url и selector обязательны');
    }

    return withPage(async (session, cdp) => {
        const page = await openPage(session, cdp, url, options);

        const data = await evaluate(session, cdp, `(() => {
            const nodes = Array.from(document.querySelectorAll(${JSON.stringify(selector)})).slice(0, 20);
            const props = ${JSON.stringify(options.properties || [])};

            const wanted = props.length ? props : [
                'display','position','width','height','margin','padding','color',
                'background-color','font-family','font-size','font-weight','line-height',
                'border','border-radius','box-shadow','z-index','opacity','flex-direction','gap'
            ];

            return nodes.map((el) => {
                const style = getComputedStyle(el);
                const box = el.getBoundingClientRect();
                const values = {};
                wanted.forEach((name) => { values[name] = style.getPropertyValue(name); });

                return {
                    tag: el.tagName.toLowerCase(),
                    classes: el.className && typeof el.className === 'string' ? el.className : '',
                    text: (el.textContent || '').trim().slice(0, 80),
                    rect: { x: Math.round(box.x), y: Math.round(box.y), width: Math.round(box.width), height: Math.round(box.height) },
                    styles: values,
                };
            });
        })()`);

        return { selector, count: data.length, elements: data, console: page.consoleMessages.slice(0, 15) };
    });
}

/** Дымовой тест: страница открывается, ошибок нет, ключевые элементы на месте. */
async function smoke(options) {
    const url = String(options.url || '');
    if (url === '') {
        throw new Error('url обязателен');
    }

    return withPage(async (session, cdp) => {
        const page = await openPage(session, cdp, url, options);

        const data = await evaluate(session, cdp, `(() => {
            const brokenImages = Array.from(document.images || []).filter((img) => img.complete && img.naturalWidth === 0).length;
            return {
                title: document.title,
                url: location.href,
                body_length: document.body ? (document.body.innerText || '').length : 0,
                images: (document.images || []).length,
                broken_images: brokenImages,
                links: (document.links || []).length,
                forms: (document.forms || []).length,
            };
        })()`);

        const errors = page.consoleMessages.filter((m) => m.level === 'error');
        const problems = [];

        if (data.body_length < 50) {
            problems.push('Страница почти пустая (' + data.body_length + ' символов текста).');
        }
        if (data.broken_images > 0) {
            problems.push('Битых картинок: ' + data.broken_images + '.');
        }
        if (errors.length > 0) {
            problems.push('Ошибок в консоли: ' + errors.length + '.');
        }
        if (page.failedRequests.length > 0) {
            problems.push('Неудачных запросов: ' + page.failedRequests.length + '.');
        }

        return Object.assign({}, data, {
            ok: problems.length === 0,
            problems,
            console: errors.slice(0, 10),
            failed_requests: page.failedRequests.slice(0, 10),
        });
    });
}

module.exports = { available, screenshot, inspect, computedCss, smoke, CHROME_BIN, SHOT_DIR };
