#!/usr/bin/env node
'use strict';

/**
 * BXADMIN-S: демон выполнения задач агента.
 *
 * Держит долгий цикл «LLM → инструмент → LLM» вне PHP, умеет прервать запрос
 * к модели, а инструменты исполняет PHP через внутренний HMAC-эндпоинт.
 * Прогресс уходит в браузер через SSE-журнал (публикует PHP).
 *
 * Запуск:
 *   SITE_URL=https://camouf.ru SECRET=... node daemon/bxadmins-daemon.js
 *
 * Секрет — опция модуля `worker_secret` (Настройки → BXADMIN-S, либо через CLI:
 *   php -r '...Config::workerSecret()').
 * Внешних зависимостей нет: только встроенные модули Node 18+.
 */

const http = require('node:http');
const https = require('node:https');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const browser = require('./browser');

const SITE_URL = (process.env.SITE_URL || 'https://camouf.ru').replace(/\/+$/, '');
// Соединяемся всегда с локальным веб-сервером: так REMOTE_ADDR = 127.0.0.1,
// а Host-заголовок направляет запрос на нужный виртуальный хост.
const WORKER_HOST = process.env.WORKER_HOST || '127.0.0.1';
const SECRET = process.env.SECRET || '';
const POLL_MS = Number(process.env.POLL_MS || 1000);
// Холостой опрос замедляется до этого предела: 10 воркеров не должны
// круглосуточно дёргать PHP по разу в секунду.
const POLL_MAX_MS = Number(process.env.POLL_MAX_MS || 5000);
// Таймаут — не на весь ответ, а на паузу без данных: длинные ответы печатаются
// дольше минуты, но не должны обрываться, пока провайдер присылает куски.
const LLM_TIMEOUT_MS = Number(process.env.LLM_TIMEOUT_MS || 180000);
const WORKER_PATH = process.env.WORKER_PATH || '/local/modules/mibazarow.bxadmins/admin/worker.php';
// Порт локального HTTP-API для браузерных задач (PHP ходит сюда).
const BROWSER_PORT = Number(process.env.BROWSER_PORT || 8899);

if (!SECRET) {
    console.error('SECRET is required (module option worker_secret)');
    process.exit(1);
}

/*
 * Единственный экземпляр. Демон поднимают и cron-агент, и человек — два процесса
 * конфликтуют за порт браузерного API и дублируют задачи. Блокировка по PID-файлу
 * (каталог модуля, вне docroot) снимает гонку: второй запуск просто выходит.
 */
const LOCK_PATH = process.env.LOCK_PATH || path.join(__dirname, '.daemon.lock');

function pidAlive(pid) {
    if (!pid || pid <= 0) {
        return false;
    }

    try {
        process.kill(pid, 0);

        return true;
    } catch (e) {
        return e.code === 'EPERM';
    }
}

function acquireLock() {
    for (let attempt = 0; attempt < 2; attempt++) {
        try {
            const fd = fs.openSync(LOCK_PATH, 'wx');
            fs.writeSync(fd, String(process.pid));
            fs.closeSync(fd);

            return true;
        } catch (e) {
            if (e.code !== 'EEXIST') {
                // Нет прав на файл — не мешаем работать.
                return true;
            }

            let owner = 0;
            try {
                owner = parseInt(fs.readFileSync(LOCK_PATH, 'utf8'), 10) || 0;
            } catch (readError) {
                owner = 0;
            }

            if (pidAlive(owner)) {
                return false;
            }

            // Владелец умер, файл остался — забираем блокировку.
            try {
                fs.unlinkSync(LOCK_PATH);
            } catch (unlinkError) {
                return true;
            }
        }
    }

    return false;
}

function releaseLock() {
    try {
        const owner = parseInt(fs.readFileSync(LOCK_PATH, 'utf8'), 10) || 0;
        if (owner === process.pid) {
            fs.unlinkSync(LOCK_PATH);
        }
    } catch (e) {
        // Файла уже нет — ничего не делаем.
    }
}

if (!acquireLock()) {
    console.error('another daemon instance is running, exiting');
    process.exit(0);
}

process.on('exit', releaseLock);
process.on('SIGINT', () => process.exit(0));
process.on('SIGTERM', () => process.exit(0));

function log(...args) {
    console.log(new Date().toISOString(), ...args);
}

const RETRYABLE_ACTIONS = ['claim', 'config', 'alternatives', 'context', 'finish', 'cancelled'];

function worker(action, payload) {
    return workerOnce(action, payload).catch((error) => {
        const message = String(error && error.message || error);
        const transport = /non-JSON|ECONNRESET|socket hang up|worker timeout|EPIPE/.test(message);

        // Повторяем только транспортные сбои и только для безопасных действий:
        // повторный вызов 'tool' мог бы применить изменение дважды.
        if (!transport || RETRYABLE_ACTIONS.indexOf(action) === -1) {
            throw error;
        }

        log('worker ' + action + ' retry after: ' + message);

        return new Promise((resolve) => setTimeout(resolve, 800)).then(() => workerOnce(action, payload));
    });
}

function workerOnce(action, payload) {
    const body = Object.assign({ action }, payload || {});
    const raw = JSON.stringify(body);
    const timestamp = String(Math.floor(Date.now() / 1000));
    const signature = crypto.createHmac('sha256', SECRET).update(timestamp + '.' + raw).digest('hex');

    return new Promise((resolve, reject) => {
        const url = new URL(SITE_URL + WORKER_PATH);
        const transport = url.protocol === 'https:' ? https : http;
        const data = Buffer.from(raw);

        const request = transport.request({
            hostname: WORKER_HOST,
            servername: url.hostname,
            port: url.port || (url.protocol === 'https:' ? 443 : 80),
            path: url.pathname,
            method: 'POST',
            headers: {
                'Host': url.host,
                'Content-Type': 'application/json',
                'Content-Length': data.length,
                'X-Bxadmins-Timestamp': timestamp,
                'X-Bxadmins-Signature': signature
            }
        }, (response) => {
            let text = '';
            response.setEncoding('utf8');
            response.on('data', (chunk) => { text += chunk; });
            response.on('end', () => {
                try {
                    resolve({ status: response.statusCode, body: text ? JSON.parse(text) : {} });
                } catch (e) {
                    reject(new Error('worker returned non-JSON (HTTP ' + response.statusCode + ')'));
                }
            });
        });

        request.on('error', reject);
        request.setTimeout(60000, () => request.destroy(new Error('worker timeout')));
        request.write(data);
        request.end();
    });
}

const UNAVAILABLE_STATUSES = [401, 402, 403, 404, 429, 500, 502, 503, 504];

function unavailable(message) {
    const error = new Error(message);
    error.unavailable = true;

    return error;
}

async function callLlm(config, messages, tools, signal, onDelta, onReasoning) {
    const base = (config.endpoint || '').replace(/\/+$/, '');
    const payload = {
        model: config.model,
        messages,
        temperature: 0.2,
        stream: true
    };

    if (config.reasoning && config.reasoning !== 'none') {
        payload.reasoning_effort = config.reasoning;
    }

    if (tools && tools.length) {
        payload.tools = tools;
        payload.tool_choice = 'auto';
    }

    const send = () => fetch(base + '/chat/completions', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer ' + (config.api_key || '')
        },
        body: JSON.stringify(payload),
        signal
    });

    let response;
    try {
        response = await send();

        // Некоторые провайдеры не знают reasoning_effort: один повтор без него.
        if (!response.ok && [400, 422].includes(response.status) && payload.reasoning_effort) {
            const probe = await response.clone().text();
            if (/reasoning|effort|unsupported|unknown|invalid/i.test(probe)
                && !/image|vision|context|token/i.test(probe)) {
                delete payload.reasoning_effort;
                response = await send();
            }
        }
    } catch (e) {
        // Таймаут (AbortError) или сетевая ошибка — провайдер не ответил.
        if (e.name === 'AbortError') {
            throw unavailable('Провайдер не ответил: данных нет дольше '
                + Math.round(LLM_TIMEOUT_MS / 1000) + ' секунд.');
        }
        const code = e.cause && e.cause.code ? ' (' + e.cause.code + ')' : '';
        throw unavailable('Провайдер недоступен: соединение не установлено' + code + '.');
    }

    if (!response.ok) {
        const text = await response.text();
        const lower = text.toLowerCase();

        if ([400, 422, 500].includes(response.status)
            && /image|vision|multimodal|image_url/i.test(lower)
            && !/context|token/i.test(lower)) {
            const visionError = new Error('Модель не поддерживает изображения.');
            visionError.vision = true;
            throw visionError;
        }

        if ([400, 413, 422].includes(response.status) && (lower.includes('context') || lower.includes('token'))) {
            throw new Error('Превышено контекстное окно модели. Уменьшите окно в настройках или начните новый чат.');
        }

        if (UNAVAILABLE_STATUSES.includes(response.status)) {
            let reason = 'Провайдер вернул ошибку (HTTP ' + response.status + ').';
            if (lower.includes('balance') || lower.includes('quota') || lower.includes('insufficient')) {
                reason = 'Провайдер сообщает о недостаточном балансе или исчерпанной квоте (HTTP ' + response.status + ').';
            } else if (response.status === 401 || response.status === 403) {
                reason = 'Провайдер отклонил ключ доступа (HTTP ' + response.status + ').';
            } else if (response.status === 429) {
                reason = 'Провайдер ограничил частоту запросов (HTTP 429).';
            }
            throw unavailable(reason);
        }

        throw new Error('LLM вернул ошибку (HTTP ' + response.status + ').');
    }

    // Провайдер может не поддержать stream — тогда ответ придёт целым JSON.
    const contentType = String(response.headers.get('content-type') || '');
    if (!contentType.includes('event-stream')) {
        const data = JSON.parse(await response.text());
        const message = data && data.choices && data.choices[0] ? data.choices[0].message : null;
        if (!message) {
            throw new Error('LLM вернул ответ без choices[0].message.');
        }
        const rawContent = String(message.content || '');
        const cleanContent = stripThink(rawContent);
        const rawReasoning = String(message.reasoning_content || '');

        if (onReasoning && (rawReasoning !== '' || rawContent !== cleanContent)) {
            onReasoning(rawReasoning !== '' ? rawReasoning : rawContent);
        }

        message.content = cleanContent;

        if (onDelta && cleanContent !== '') {
            onDelta(cleanContent);
        }

        return message;
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder('utf-8');
    let buffer = '';
    let content = '';
    let reasoning = '';
    const toolCalls = [];
    let finishReason = null;

    // Размышления модели не должны попадать в ответ. Провайдеры отдают их либо
    // отдельным полем reasoning_content, либо тегами в контенте.
    // Только явные теги размышлений. Тройные кавычки — это Markdown-код,
    // их нельзя принимать за размышления, иначе ответ теряет блоки кода.
    const THINK_TAGS = [
        ['<thinking>', '</thinking>'],
        ['<thought>', '</thought>']
    ];
    let inThink = false;
    let thinkClose = '';

    const emitReason = (text) => {
        if (!text) {
            return;
        }
        reasoning += text;
        if (onReasoning) {
            onReasoning(text);
        }
    };

    const emitContent = (text) => {
        if (!text) {
            return;
        }
        content += text;
        if (onDelta) {
            onDelta(text);
        }
    };

    const consume = (text) => {
        let rest = String(text || '');
        let guard = 0;

        while (rest !== '') {
            // Страховка: битый тег не должен зациклить разбор и повесить демон.
            if (++guard > 10000) {
                emitContent(rest);
                break;
            }
            if (inThink) {
                const index = rest.indexOf(thinkClose);

                if (index === -1) {
                    emitReason(rest);
                    rest = '';
                } else {
                    emitReason(rest.slice(0, index));
                    rest = rest.slice(index + thinkClose.length);
                    inThink = false;
                    thinkClose = '';
                }

                continue;
            }

            let best = null;
            for (const [open, close] of THINK_TAGS) {
                const index = rest.indexOf(open);
                if (index !== -1 && (best === null || index < best.index)) {
                    best = {index: index, open: open, close: close};
                }
            }

            if (best === null) {
                emitContent(rest);
                rest = '';
                continue;
            }

            if (best.index > 0) {
                emitContent(rest.slice(0, best.index));
            }

            inThink = true;
            thinkClose = best.close;
            rest = rest.slice(best.index + best.open.length);
        }
    };

    for (;;) {
        const { done, value } = await reader.read();
        if (done) {
            break;
        }

        buffer += decoder.decode(value, { stream: true });
        const lines = buffer.split('\n');
        buffer = lines.pop() || '';

        for (const rawLine of lines) {
            const line = rawLine.trim();
            if (!line.startsWith('data:')) {
                continue;
            }

            const chunk = line.slice(5).trim();
            if (chunk === '' || chunk === '[DONE]') {
                continue;
            }

            let parsed;
            try {
                parsed = JSON.parse(chunk);
            } catch (e) {
                continue;
            }

            const choice = parsed.choices && parsed.choices[0];
            if (!choice) {
                continue;
            }

            if (choice.finish_reason) {
                finishReason = choice.finish_reason;
            }

            const delta = choice.delta || {};

            if (typeof delta.reasoning_content === 'string' && delta.reasoning_content !== '') {
                emitReason(delta.reasoning_content);
            }
            if (typeof delta.reasoning === 'string' && delta.reasoning !== '') {
                emitReason(delta.reasoning);
            }

            if (typeof delta.content === 'string' && delta.content !== '') {
                consume(delta.content);
            }

            if (Array.isArray(delta.tool_calls)) {
                for (const call of delta.tool_calls) {
                    const index = Number(call.index || 0);
                    if (!toolCalls[index]) {
                        toolCalls[index] = { id: '', type: 'function', function: { name: '', arguments: '' } };
                    }
                    if (call.id) {
                        toolCalls[index].id = call.id;
                    }
                    if (call.function) {
                        if (call.function.name) {
                            toolCalls[index].function.name += call.function.name;
                        }
                        if (call.function.arguments) {
                            toolCalls[index].function.arguments += call.function.arguments;
                        }
                    }
                }
            }
        }
    }

    const message = { role: 'assistant', content };

    if (reasoning !== '') {
        message.reasoning = reasoning;
    }

    if (toolCalls.length) {
        message.tool_calls = toolCalls.filter(Boolean);
    }

    if (finishReason) {
        message.finish_reason = finishReason;
    }

    return message;
}

async function publish(task, type, extra) {
    await worker('publish', {
        task_id: task.id,
        command: 'task_event',
        params: Object.assign({ task_id: task.id, chat_id: task.chat_id, type }, extra || {})
    });
}

/**
 * Короткий заголовок чата: «Проверка цен», «Меню на главной».
 * Считается один раз, после первого ответа. Эвристика из PHP уже дала
 * запасной вариант, модель его улучшает.
 */
async function maybeNameChat(task, config) {
    try {
        const check = await worker('chat_title_need', { task_id: task.id, chat_id: task.chat_id });
        const body = check && check.body;

        if (!body || !body.need) {
            return;
        }

        const response = await callLlm(
            config,
            [
                {
                    role: 'system',
                    content: 'Create a very short title for a chat with the site assistant: '
                        + '2–4 words, in Russian, no quotes, no trailing period. '
                        + 'Reply with the title only, without explanations.'
                },
                { role: 'user', content: String(body.text || '').slice(0, 500) }
            ],
            [],
            new AbortController().signal,
            () => {}
        );

        const title = String(response.content || '').trim();
        if (title !== '') {
            await worker('chat_title', { task_id: task.id, chat_id: task.chat_id, title });
        }
    } catch (e) {
        // Заголовок — украшение: сбой не должен влиять на задачу.
        log('chat title failed:', e.message);
    }
}

async function processTask(task, workerIndex) {
    log('task', task.id, 'started (chat', task.chat_id + ', worker ' + workerIndex + ')');

    const configResponse = await worker('config', {});
    const config = configResponse.body && configResponse.body.llm;

    if (!config || !config.configured) {
        await worker('message', { task_id: task.id, role: 'assistant', content: 'Модель не подключена.', is_error: true });
        await publish(task, 'error', { message: 'Модель не подключена.' });
        await worker('finish', { task_id: task.id, status: 'failed', error: 'Модель не подключена.' });
        return;
    }

    const contextResponse = await worker('context', { task_id: task.id });
    const messages = contextResponse.body.messages || [];
    const tools = contextResponse.body.tools || [];

    let partial = '';

    for (let round = 0; round < (config.max_rounds || 6); round++) {
        const cancelCheck = await worker('cancelled', { task_id: task.id });
        if (cancelCheck.body && cancelCheck.body.cancelled) {
            await publish(task, 'stopped', { partial });
            await worker('finish', {
                task_id: task.id,
                status: 'cancelled',
                result: partial || 'Задача остановлена пользователем.'
            });
            log('task', task.id, 'cancelled');
            return;
        }

        const controller = new AbortController();
        let timer = null;
        let cancelledByUser = false;
        const armTimer = () => {
            if (timer) {
                clearTimeout(timer);
            }
            timer = setTimeout(() => controller.abort(), LLM_TIMEOUT_MS);
        };
        armTimer();

        // Пока модель думает, раз в пару секунд спрашиваем, не нажал ли пользователь
        // «Стоп». Раньше отмена проверялась только между шагами, и кнопка «не работала».
        const cancelPoll = setInterval(async () => {
            if (cancelledByUser) {
                return;
            }
            const response = await worker('cancelled', { task_id: task.id }).catch(() => null);
            if (response && response.body && response.body.cancelled) {
                cancelledByUser = true;
                controller.abort();
            }
        }, 2500);

        // Дельты уходят в браузер пачками, чтобы не забивать журнал мелкими событиями.
        let pendingDelta = '';
        let lastFlush = 0;
        const flushDelta = async (force) => {
            if (pendingDelta === '') {
                return;
            }
            const now = Date.now();
            if (!force && now - lastFlush < 400) {
                return;
            }
            const chunk = pendingDelta;
            pendingDelta = '';
            lastFlush = now;
            await publish(task, 'delta', { content: chunk });
        };

        // Черновик ответа в задаче: страховка, если SSE-события не доходят.
        let draft = '';
        let lastDraft = 0;
        const saveDraft = async (force) => {
            const now = Date.now();
            if (!force && now - lastDraft < 1200) {
                return;
            }
            lastDraft = now;
            await worker('progress', { task_id: task.id, text: draft }).catch(() => {});
        };

        // Точный статус: запрос к модели уже ушёл (в отличие от ожидания в очереди).
        await publish(task, 'thinking', { round: round + 1 });

        let message = null;
        let lastError = null;
        let visionRetried = false;
        let pendingReasoning = '';
        let lastReasoning = 0;

        const flushReasoning = async (force) => {
            if (pendingReasoning === '') {
                return;
            }
            const now = Date.now();
            if (!force && now - lastReasoning < 500) {
                return;
            }
            const chunk = pendingReasoning;
            pendingReasoning = '';
            lastReasoning = now;
            await publish(task, 'reasoning', {content: chunk});
        };

        const onReasoning = (text) => {
            const value = String(text || '');
            if (value === '') {
                return;
            }
            pendingReasoning += value;
            flushReasoning(false).catch(() => {});
        };

        try {
            for (let attempt = 0; attempt < 3 && message === null; attempt++) {
                try {
                    message = await callLlm(config, messages, tools, controller.signal, (text) => {
                        // Любой пришедший кусок — признак живого соединения: продлеваем ожидание.
                        armTimer();
                        pendingDelta += text;
                        draft += text;
                        flushDelta(false).catch(() => {});
                        saveDraft(false).catch(() => {});
                    }, (text) => {
                        armTimer();
                        onReasoning(text);
                    });
                } catch (e) {
                    lastError = e;

                    if (cancelledByUser) {
                        break;
                    }

                    // Модель без vision: повторяем без картинок и честно предупреждаем её.
                    if (e.vision && !visionRetried) {
                        visionRetried = true;
                        stripImages(messages);
                        log('task', task.id, 'vision unsupported, retry without images');
                        armTimer();
                        continue;
                    }

                    // Один повтор — только если модель не выдала ни одного символа:
                    // значит, соединение зависло, а не генерация длинная.
                    const retriable = /aborted|timeout|fetch failed|ECONNRESET|socket hang up|EAI_AGAIN|ENOTFOUND/i
                        .test(String(e.message || ''));
                    if (!retriable || attempt === 1 || draft !== '') {
                        break;
                    }

                    log('task', task.id, 'retry after timeout');
                    armTimer();
                }
            }
        } finally {
            clearInterval(cancelPoll);
            clearTimeout(timer);
            await flushDelta(true).catch(() => {});
            await flushReasoning(true).catch(() => {});
            await saveDraft(true).catch(() => {});
        }

        if (cancelledByUser) {
            // Сохраняем только текст текущего шага: прошлые шаги уже в истории.
            if (draft.trim() !== '') {
                await worker('message', {
                    task_id: task.id,
                    role: 'assistant',
                    content: draft
                }).catch(() => {});
            }

            await publish(task, 'stopped', { partial });
            await worker('finish', {
                task_id: task.id,
                status: 'cancelled',
                result: partial || 'Задача остановлена пользователем.'
            });
            log('task', task.id, 'cancelled');
            return;
        }

        if (message === null) {
            throw lastError || new Error('Модель не ответила');
        }

        const toolCalls = Array.isArray(message.tool_calls) ? message.tool_calls : [];

        if (!toolCalls.length) {
            const content = String(message.content || '');
            await worker('message', { task_id: task.id, role: 'assistant', content });
            await publish(task, 'done', { content });
            await worker('finish', { task_id: task.id, status: 'done', result: content });
            log('task', task.id, 'done');
            await maybeNameChat(task, config);
            return;
        }

        // Сохраняем assistant-сообщение с tool_calls, чтобы история восстановилась.
        await worker('message', {
            task_id: task.id,
            role: 'assistant',
            content: String(message.content || ''),
            tool_args: JSON.stringify(toolCalls)
        });

        messages.push({
            role: 'assistant',
            content: String(message.content || ''),
            tool_calls: toolCalls
        });

        draft = '';
        await saveDraft(true).catch(() => {});

        for (const call of toolCalls) {
            const name = call.function ? call.function.name : '';
            let args = {};
            try {
                args = JSON.parse((call.function && call.function.arguments) || '{}');
            } catch (e) {
                args = {};
            }

            await publish(task, 'tool_start', { name, args });

            const result = await worker('tool', { task_id: task.id, name, args });
            const payload = result.body && result.body.result ? result.body.result : { error: 'Инструмент не ответил.' };
            const content = JSON.stringify(payload);

            await worker('message', {
                task_id: task.id,
                role: 'tool',
                content,
                tool_name: name,
                tool_call_id: call.id || ''
            });

            messages.push({ role: 'tool', tool_call_id: call.id || '', name, content });

            await publish(task, 'tool_done', { name, result: payload });
            partial = (partial ? partial + '\n' : '') + name + ': ' + (payload.error ? 'ошибка' : 'ок');
        }
    }

    // Шаги кончились: просим модель коротко отчитаться без инструментов,
    // чтобы пользователь получил внятный ответ, а не техническую ошибку.
    const limitNote = 'Лимит шагов исчерпан. Ответь пользователю без инструментов: коротко — что '
        + 'удалось выяснить, что осталось и какой один вопрос нужно уточнить, чтобы продолжить.';

    let summaryText = '';
    try {
        const finalMessage = await callLlm(
            config,
            messages.concat([{ role: 'user', content: limitNote }]),
            [],
            new AbortController().signal,
            () => {}
        );
        summaryText = String(finalMessage.content || '').trim();
    } catch (e) {
        log('task', task.id, 'summary failed: ' + String(e && e.message || e));
    }

    if (!summaryText) {
        summaryText = 'Не успел закончить: задача оказалась длиннее, чем шагов в одном заходе. '
            + 'Уточните, что сделать в первую очередь, — продолжу с этого места.';
    }

    await worker('message', { task_id: task.id, role: 'assistant', content: summaryText });
    await publish(task, 'done', { content: summaryText });
    await worker('finish', { task_id: task.id, status: 'done', result: summaryText });
    log('task', task.id, 'done (step limit, summarized)');
    await maybeNameChat(task, config);
}

/**
 * Локальный HTTP-API браузера для PHP. Только 127.0.0.1 и HMAC-подпись,
 * тот же секрет, что у worker.php.
 */
function startBrowserApi() {
    const server = http.createServer((request, response) => {
        const reply = (status, data) => {
            response.writeHead(status, { 'Content-Type': 'application/json; charset=UTF-8' });
            response.end(JSON.stringify(data));
        };

        if (request.method !== 'POST') {
            reply(405, { ok: false, error: 'method not allowed' });
            return;
        }

        const remote = String(request.socket.remoteAddress || '');
        if (!['127.0.0.1', '::1', '::ffff:127.0.0.1'].includes(remote)) {
            reply(403, { ok: false, error: 'forbidden' });
            return;
        }

        let raw = '';
        request.setEncoding('utf8');
        request.on('data', (chunk) => {
            raw += chunk;
            if (raw.length > 1e6) {
                request.destroy();
            }
        });

        request.on('end', async () => {
            const timestamp = String(request.headers['x-bxadmins-timestamp'] || '');
            const signature = String(request.headers['x-bxadmins-signature'] || '');

            if (!timestamp || !signature || Math.abs(Math.floor(Date.now() / 1000) - Number(timestamp)) > 120) {
                reply(401, { ok: false, error: 'bad timestamp' });
                return;
            }

            const expected = crypto.createHmac('sha256', SECRET).update(timestamp + '.' + raw).digest('hex');
            if (expected !== signature) {
                reply(401, { ok: false, error: 'bad signature' });
                return;
            }

            let payload;
            try {
                payload = raw ? JSON.parse(raw) : {};
            } catch (e) {
                reply(400, { ok: false, error: 'bad json' });
                return;
            }

            try {
                const action = String(payload.action || '');
                let result;

                switch (action) {
                    case 'screenshot':
                        result = await browser.screenshot(payload);
                        break;
                    case 'inspect':
                        result = await browser.inspect(payload);
                        break;
                    case 'css':
                        result = await browser.computedCss(payload);
                        break;
                    case 'smoke':
                        result = await browser.smoke(payload);
                        break;
                    case 'available':
                        result = { available: browser.available(), binary: browser.CHROME_BIN };
                        break;
                    default:
                        reply(400, { ok: false, error: 'unknown action' });
                        return;
                }

                reply(200, { ok: true, result });
            } catch (e) {
                log('browser error:', e.message);
                reply(500, { ok: false, error: e.message });
            }
        });
    });

    server.on('error', (e) => {
        log('browser api error:', e.message);
    });

    server.listen(BROWSER_PORT, '127.0.0.1', () => {
        log('browser api listening on 127.0.0.1:' + BROWSER_PORT);
    });
}


/**
 * Убирает картинки из сообщений и предупреждает модель, что vision нет.
 * @param {Array} messages
 */
function stripImages(messages) {
    let note = false;

    for (const message of messages) {
        if (!Array.isArray(message.content)) {
            continue;
        }

        const parts = [];

        for (const part of message.content) {
            if (part && part.type === 'image_url') {
                note = true;
                parts.push({ type: 'text', text: '[изображение недоступно: модель не поддерживает картинки]' });
            } else {
                parts.push(part);
            }
        }

        message.content = parts;
    }

    if (!note) {
        return;
    }

    for (let i = messages.length - 1; i >= 0; i--) {
        const message = messages[i];
        if (message.role !== 'user') {
            continue;
        }

        const warning = '\n\n(Модель не поддерживает изображения. Скажи об этом честно: распознать картинку нельзя, '
            + 'но файл можно использовать как изображение для товара, если это уместно.)';

        if (Array.isArray(message.content)) {
            for (const part of message.content) {
                if (part && part.type === 'text') {
                    part.text = String(part.text || '') + warning;
                    break;
                }
            }
        } else {
            message.content = String(message.content || '') + warning;
        }

        break;
    }
}

/** Убирает размышления из готового текста (теги  и т.п.). */
function stripThink(text) {
    return String(text || '')
        .replace(/<thinking>[\s\S]*?<\/thinking>/gi, '')
        .replace(/<thought>[\s\S]*?<\/thought>/gi, '')
        .replace(/<\/?think(ing)?>/gi, '')
        .replace(/<\/?thought>/gi, '')
        .trim();
}

/** Понятный текст вместо технических ошибок Node/undici. */
function humanError(message) {
    const text = String(message || '');

    if (/operation was aborted|aborted/i.test(text)) {
        return 'Модель не ответила за ' + Math.round(LLM_TIMEOUT_MS / 1000) + ' секунд.'
            + ' Можно повторить задачу, разбить её на части или переключить модель в настройках.';
    }

    if (/fetch failed|ECONNRESET|ENOTFOUND|EAI_AGAIN|socket hang up/i.test(text)) {
        return 'Нет связи с провайдером модели. Проверьте интернет на сервере и повторите.';
    }

    if (/worker timeout|non-JSON/i.test(text)) {
        return 'Сайт не ответил на внутренний запрос. Повторите задачу; если повторяется — проверьте журнал модуля.';
    }

    return text;
}

/**
 * Один рабочий цикл: забирает задачу и выполняет её.
 * Запускается несколько раз параллельно, чтобы задачи в разных чатах
 * не ждали друг друга. Внутри чата по-прежнему строго одна задача —
 * это гарантирует claim() на стороне PHP.
 */
async function loop(workerIndex) {
    let idle = 0;

    for (;;) {
        try {
            const response = await worker('claim', {});
            const task = response.body && response.body.task;

            if (task) {
                idle = 0;

                try {
                    await processTask(task, workerIndex);
                } catch (e) {
                    log('task', task.id, 'failed:', e.message);

                    // Технические тексты Node («This operation was aborted») ничего
                    // не говорят владельцу сайта — переводим в понятное сообщение.
                    const reason = humanError(e.message);
                    try {
                        const alternatives = await worker('alternatives', {}).catch(() => null);
                        const list = (alternatives && alternatives.body && alternatives.body.alternatives) || [];

                        await worker('message', {
                            task_id: task.id,
                            role: 'assistant',
                            content: 'Ошибка: ' + reason,
                            is_error: true
                        });

                        if (e.unavailable) {
                            await publish(task, 'provider_down', { message: reason, alternatives: list });
                        } else {
                            await publish(task, 'error', { message: reason });
                        }

                        await worker('finish', { task_id: task.id, status: 'failed', error: reason });
                    } catch (inner) {
                        log('finish failed:', inner.message);
                    }
                }
                continue;
            }
        } catch (e) {
            log('worker error:', e.message);
        }

        // Пустая очередь или сбой сайта: плавно замедляемся до POLL_MAX_MS,
        // чтобы не держать ядро на холостом опросе. Разброс не даёт всем
        // воркерам просыпаться одновременно.
        idle = Math.min(idle + 1, 6);
        const base = Math.min(POLL_MAX_MS, POLL_MS * idle);
        const delay = Math.round(base * (0.85 + Math.random() * 0.3));
        await new Promise((resolve) => setTimeout(resolve, delay));
    }
}

log('BXADMIN-S daemon started for', SITE_URL);
startBrowserApi();

/*
 * Параллельные рабочие циклы. Сколько — говорит модуль (worker_concurrency):
 * значение читаем один раз на старте, при изменении перезапускается демон.
 */
async function start() {
    // Сколько рабочих циклов — решает модуль; окружение может переопределить.
    let workers = Number(process.env.WORKERS || 0);

    if (!workers) {
        try {
            const response = await worker('config', {});
            const concurrency = Number(response.body && response.body.llm && response.body.llm.concurrency);

            workers = concurrency > 0 ? concurrency : 3;
        } catch (e) {
            workers = 3;
        }
    }

    workers = Math.max(1, Math.min(10, workers));

    for (let index = 1; index <= workers; index++) {
        loop(index).catch((e) => log('worker ' + index + ' crashed:', e.message));
    }

    log('workers started:', workers);
}

start().catch((e) => log('startup failed:', e.message));
