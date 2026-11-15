(function () {
    'use strict';

    function init() {
    var root = document.querySelector('.bxadmins-chat');
    if (!root) {
        return;
    }

    var ajaxUrl = root.getAttribute('data-ajax');
    var streamUrl = root.getAttribute('data-stream-url') || '/local/modules/mibazarow.bxadmins/admin/stream.php';
    var sessid = root.getAttribute('data-sessid');
    var listEl = root.querySelector('.bxadmins-chat-list');
    var listEmptyEl = root.querySelector('.bxadmins-chat-list-empty');
    var messagesEl = root.querySelector('.bxadmins-chat-messages');
    var progressEl = root.querySelector('.bxadmins-chat-progress');
    var stepsEl = progressEl ? progressEl.querySelector('.bxadmins-steps') : null;
    var stepsListEl = progressEl ? progressEl.querySelector('.bxadmins-steps-list') : null;
    var stepsCurrentEl = progressEl ? progressEl.querySelector('.bxadmins-steps-current') : null;
    var reasoningEl = progressEl ? progressEl.querySelector('.bxadmins-reasoning') : null;
    var reasoningTextEl = progressEl ? progressEl.querySelector('.bxadmins-reasoning-text') : null;
    var reasoningText = '';
    var thinkingLabel = root.getAttribute('data-label-thinking') || 'думает…';
    var STEPS_REQUEST = root.getAttribute('data-label-request') || 'Запрос к модели';
    var STEPS_ANSWER = root.getAttribute('data-label-answer') || 'Пишу ответ…';
    var QUEUED_LABEL = root.getAttribute('data-label-queued') || 'в очереди';
    var QUEUE_REMOVE = root.getAttribute('data-label-queue-remove') || 'Убрать из очереди';
    var attachInput = root.querySelector('.bxadmins-attach-input');
    var attachBtn = root.querySelector('.bxadmins-attach-btn');
    var attachList = root.querySelector('.bxadmins-attach-list');
    var composerEl = root.querySelector('.bxadmins-chat-composer');
    var UPLOAD_URL = root.getAttribute('data-upload-url') || '';
    var FILE_URL = root.getAttribute('data-file-url') || '';
    var SESSID = root.getAttribute('data-sessid') || '';
    var ATTACH_LIMIT = 5;
    var attachments = [];
    var uploading = 0;
    var textarea = root.querySelector('.bxadmins-chat-composer textarea');
    var sendButton = root.querySelector('.bxadmins-send');
    var newChatButton = root.querySelector('.bxadmins-new-chat');
    var stopButton = root.querySelector('.bxadmins-stop');
    var bypassInput = root.querySelector('.bxadmins-bypass-input');
    var bypassLabel = root.querySelector('.bxadmins-bypass');
    var bypassWarnEl = root.querySelector('.bxadmins-bypass-warn');

    // SSE-поток событий; опрос task_active остаётся страховкой при обрыве.
    var lastEventAt = 0;
    var watchTimer = 0;
    var watchUntil = 0;
    var watchRunningId = 0;
    var lastChatsRefresh = 0;
    var eventStream = null;
    var lastEventId = 0;
    var streamFailures = 0;
    var streamWasOpen = false;
    var streamOpenedAt = 0;
    var streamDisabled = false;

    var ROLE_AGENT = root.getAttribute('data-role-agent') || 'Агент';

    // Скриншоты лежат вне docroot: отдаёт admin/shot.php, доступ — только админу.
    var SHOT_URL = '/local/modules/mibazarow.bxadmins/admin/shot.php?file=';

    // Ответы модели — Markdown. Рендерер экранирует HTML, поэтому innerHTML безопасен.
    var MD = window.BXAdminMarkdown || null;

    function renderMarkdown(el, text) {
        var value = text === null || text === undefined ? '' : String(text);

        if (!MD) {
            el.textContent = value;
            return;
        }

        el.classList.add('bxadmins-md');
        el.innerHTML = MD.render(value);
    }
    var ROLE_YOU = root.getAttribute('data-role-you') || 'Вы';
    var EMPTY_TITLE = root.getAttribute('data-empty-title') || 'Чем помочь?';
    var EMPTY_CHAT = root.getAttribute('data-empty-chat') || 'Чат пуст — напишите сообщение.';
    var EMPTY_NEW = root.getAttribute('data-empty-new') || 'Новый чат — напишите сообщение.';
    var RENAME_PROMPT = root.getAttribute('data-rename-prompt') || 'Новое название чата';
    var DELETE_CONFIRM = root.getAttribute('data-delete-confirm') || 'Удалить чат? Это действие необратимо.';
    var DELETE_WITH_SCHEDULE = root.getAttribute('data-delete-schedule-confirm')
        || 'К этому чату привязана задача по расписанию «#TITLE#» (#INTERVAL#).\n\n'
            + 'Удаляя чат, вы удалите и задачу — она перестанет выполняться. Продолжить?';
    var LABEL_RENAME = root.getAttribute('data-label-rename') || 'Переименовать';
    var LABEL_DELETE = root.getAttribute('data-label-delete') || 'Удалить';
    var LABEL_PIN = root.getAttribute('data-label-pin') || 'Закрепить';
    var LABEL_UNPIN = root.getAttribute('data-label-unpin') || 'Открепить';
    var PROVIDER_DOWN_TITLE = root.getAttribute('data-provider-down') || 'Провайдер недоступен';
    var PROVIDER_DOWN_NO_ALTERNATIVES = root.getAttribute('data-provider-none') || 'Других сохранённых подключений нет. Проверьте провайдера в настройках.';
    var PROVIDER_SWITCH_TO = root.getAttribute('data-provider-switch') || 'Переключиться на #NAME#';
    var PROVIDER_SWITCHED = root.getAttribute('data-provider-switched') || 'Переключено на #NAME#. Отправьте сообщение ещё раз.';
    var suggestions = (root.getAttribute('data-suggestions') || '').split('|').filter(function (text) {
        return text !== '';
    });

    var currentChatId = 0;
    var currentTaskId = 0;
    var pendingOwnMessage = false;
    var waiting = false;
    var changeBusy = false;
    var changeById = {};

    var STATUS_LABELS = {
        pending: 'ожидает подтверждения',
        executed: 'выполнено',
        rejected: 'отклонено',
        failed: 'ошибка',
        rolled_back: 'откачено'
    };

    function api(action, payload) {
        var url = ajaxUrl + '?action=' + encodeURIComponent(action)
            + '&sessid=' + encodeURIComponent(sessid);

        return fetch(url, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload || {})
        }).then(function (response) {
            return response.json().catch(function () {
                throw new Error('Сервер вернул не JSON — вероятно, требуется повторный вход в админку.');
            });
        });
    }

    function clear(el) {
        while (el.firstChild) {
            el.removeChild(el.firstChild);
        }
    }

    function pad2(value) {
        return (value < 10 ? '0' : '') + value;
    }

    // created_at приходит из Битрикса как "Y-m-d H:i:s" в серверной таймзоне — разбираем строку напрямую.
    function parseDate(value) {
        var matched = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(value || ''));
        if (!matched) {
            return null;
        }

        return {
            day: matched[3],
            month: matched[2],
            date: matched[1] + '-' + matched[2] + '-' + matched[3],
            time: matched[4] + ':' + matched[5]
        };
    }

    function todayKey() {
        var now = new Date();
        return now.getFullYear() + '-' + pad2(now.getMonth() + 1) + '-' + pad2(now.getDate());
    }

    function formatTime(value) {
        var parsed = parseDate(value);
        if (!parsed) {
            return '';
        }

        return parsed.date === todayKey() ? parsed.time : parsed.day + '.' + parsed.month + ' ' + parsed.time;
    }

    function formatListDate(value) {
        var parsed = parseDate(value);
        if (!parsed) {
            return '';
        }

        var now = new Date();
        return parsed.date === todayKey()
            ? parsed.time
            : parsed.day + '.' + parsed.month + '.' + now.getFullYear();
    }

    function nowStamp() {
        var now = new Date();
        return now.getFullYear() + '-' + pad2(now.getMonth() + 1) + '-' + pad2(now.getDate())
            + ' ' + pad2(now.getHours()) + ':' + pad2(now.getMinutes()) + ':' + pad2(now.getSeconds());
    }

    /**
     * Переход из «Задач по расписанию»: открываем чат задачи и сразу даём
     * агенту контекст, чтобы он предложил, что именно поменять.
     */
    function maybeEditSchedule() {
        var id = 0;

        try {
            id = parseInt(new URLSearchParams(window.location.search).get('schedule') || '0', 10);
        } catch (e) {
            id = 0;
        }

        if (!id) {
            return Promise.resolve();
        }

        return api('schedule_info', {schedule_id: id}).then(function (data) {
            if (!data.ok || !data.schedule) {
                showError(data.error || 'Задача по расписанию не найдена.');
                return null;
            }

            var schedule = data.schedule;

            currentChatId = schedule.chat_id;
            rememberChat(schedule.chat_id);

            return loadMessages().then(function () {
                textarea.value = 'Задача по расписанию «' + schedule.title + '» (#' + schedule.id + ').\n'
                    + 'Она выполняется ' + schedule.interval_text + '.\n'
                    + 'Что делает при каждом запуске: ' + schedule.prompt + '\n\n'
                    + 'Хочу изменить эту задачу. Предложи варианты, что можно поменять, '
                    + 'и спроси, что именно нужно.';
                textarea.focus();
                send();

                return null;
            });
        }).catch(function (e) {
            showError(String(e.message || e));
        });
    }

    /** chat=<id> в адресе страницы: так чат переживает перезагрузку. */
    function parseChatIdFromHash() {
        var matched = /(?:^|[#&?])chat=(\d+)/.exec(String(window.location.hash || ''));

        return matched ? parseInt(matched[1], 10) : 0;
    }

    function rememberChat(chatId) {
        var hash = '#chat=' + chatId;

        if (window.location.hash !== hash) {
            window.history.replaceState(null, '', hash);
        }
    }

    function setBypass(flag) {
        if (!bypassInput) {
            return;
        }

        bypassInput.checked = flag;
        if (bypassLabel) {
            bypassLabel.classList.toggle('bxadmins-bypass-on', flag);
        }

        if (bypassWarnEl) {
            bypassWarnEl.hidden = !flag;
        }

        try {
            window.localStorage.setItem('bxadmins_bypass', flag ? '1' : '0');
        } catch (e) {
            // localStorage может быть недоступен — режим просто не запомнится.
        }
    }

    function restoreBypass() {
        var stored = '0';
        try {
            stored = window.localStorage.getItem('bxadmins_bypass') || '0';
        } catch (e) {
            stored = '0';
        }

        setBypass(stored === '1');
    }

    function toolResult(message) {
        try {
            var parsed = JSON.parse(message.content || '');
            return parsed && typeof parsed === 'object' ? parsed : null;
        } catch (e) {
            return null;
        }
    }

    function statusLabel(status) {
        return STATUS_LABELS[status] || status || '—';
    }

    function buildMessage(role, content, createdAt, isError, attachments) {
        var isUser = role === 'user';
        var wrap = document.createElement('div');
        wrap.className = 'bxadmins-msg bxadmins-msg-' + (isUser ? 'user' : 'assistant');
        if (isError) {
            wrap.className += ' bxadmins-msg-error';
        }

        var avatar = document.createElement('div');
        avatar.className = 'bxadmins-msg-avatar';
        avatar.setAttribute('aria-hidden', 'true');
        avatar.textContent = isUser ? ROLE_YOU : 'AI';

        var contentEl = document.createElement('div');
        contentEl.className = 'bxadmins-msg-content';

        var meta = document.createElement('div');
        meta.className = 'bxadmins-msg-meta';

        var roleEl = document.createElement('span');
        roleEl.className = 'bxadmins-msg-role';
        roleEl.textContent = isUser ? ROLE_YOU : ROLE_AGENT;
        meta.appendChild(roleEl);

        var timeText = formatTime(createdAt);
        if (timeText) {
            var timeEl = document.createElement('span');
            timeEl.className = 'bxadmins-msg-time';
            timeEl.textContent = timeText;
            meta.appendChild(timeEl);
        }

        var body = document.createElement('div');
        body.className = 'bxadmins-msg-body';

        if (isUser) {
            body.textContent = content || '';
        } else {
            renderMarkdown(body, content || '');
        }

        contentEl.appendChild(meta);
        contentEl.appendChild(body);

        if (isUser && Array.isArray(attachments) && attachments.length > 0) {
            body.appendChild(attachmentStrip(attachments));
        }

        wrap.appendChild(avatar);
        wrap.appendChild(contentEl);

        return wrap;
    }

    function toolArgBlock(label, text) {
        var labelEl = document.createElement('div');
        labelEl.className = 'bxadmins-msg-tool-label';
        labelEl.textContent = label;

        var bodyEl = document.createElement('div');
        bodyEl.className = 'bxadmins-msg-tool-body';
        bodyEl.textContent = text || '';

        var wrap = document.createElement('div');
        wrap.appendChild(labelEl);
        wrap.appendChild(bodyEl);

        return wrap;
    }

    function renderPlainTool(message) {
        var details = document.createElement('details');
        details.className = 'bxadmins-msg bxadmins-msg-tool';

        var summary = document.createElement('summary');
        var summaryName = document.createElement('span');
        summaryName.className = 'bxadmins-msg-tool-name';
        // Человеческое имя вместо технического: run_sql → «Смотрю данные сайта».
        var toolLabel = TOOL_LABELS[message.tool_name];
        summaryName.textContent = toolLabel
            ? toolLabel.replace(/…$/, '')
            : 'инструмент: ' + (message.tool_name || '?');
        summary.appendChild(summaryName);

        var timeText = formatTime(message.created_at);
        if (timeText) {
            var summaryTime = document.createElement('span');
            summaryTime.className = 'bxadmins-msg-time';
            summaryTime.textContent = timeText;
            summary.appendChild(summaryTime);
        }

        details.appendChild(summary);
        details.appendChild(toolArgBlock('аргументы', message.tool_args || ''));
        details.appendChild(toolArgBlock('результат', message.content || ''));

        // Скриншот агента: показываем картинку, а не имя файла.
        var shot = shotFile(message.content || '');
        if (shot) {
            var image = document.createElement('img');
            image.className = 'bxadmins-msg-shot';
            image.loading = 'lazy';
            image.alt = 'скриншот страницы';
            image.title = 'Нажмите, чтобы открыть в полном размере';
            image.src = SHOT_URL + encodeURIComponent(shot);
            image.addEventListener('click', function () {
                window.open(image.src, '_blank', 'noopener');
            });
            details.appendChild(image);
        }

        messagesEl.appendChild(details);
    }

    /** Имя файла скриншота из результата инструмента (null, если это не скриншот). */
    function shotFile(content) {
        var matched = /"file"\s*:\s*"(shot-[A-Za-z0-9._-]+\.png)"/.exec(String(content || ''));

        return matched ? matched[1] : null;
    }

    function actionButton(action, label, save) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'adm-btn bxadmins-change-btn' + (save ? ' adm-btn-save' : '');
        button.setAttribute('data-action', action);
        button.textContent = label;
        return button;
    }

    function buildChangeCard(parsed, change) {
        var status = change ? change.status : 'pending';
        var card = document.createElement('div');
        card.className = 'bxadmins-change-card bxadmins-change-card-' + status;
        card.setAttribute('data-change-id', String(parsed.change_id));

        var title = document.createElement('div');
        title.className = 'bxadmins-change-title';
        title.textContent = status === 'pending'
            ? 'Требуется подтверждение изменения'
            : 'Изменение #' + parsed.change_id + ': ' + statusLabel(status);
        card.appendChild(title);

        var summary = document.createElement('div');
        summary.className = 'bxadmins-change-summary';
        summary.textContent = (change && change.summary) || parsed.summary || '';
        card.appendChild(summary);

        var previewText = (change && change.preview) || parsed.preview || '';
        if (previewText) {
            var preview = document.createElement('div');
            preview.className = 'bxadmins-change-preview bxadmins-md';
            // План изменения тоже в Markdown: в нём бывают `коды` и списки.
            renderMarkdown(preview, previewText);
            card.appendChild(preview);
        }

        var meta = document.createElement('div');
        meta.className = 'bxadmins-change-meta';
        meta.textContent = 'Статус: ' + statusLabel(status);
        if (change && change.backup_file) {
            meta.textContent += ' · SQL-дамп: ' + change.backup_file;
        }
        card.appendChild(meta);

        if (change && change.result && Object.keys(change.result).length > 0) {
            var result = document.createElement('pre');
            result.className = 'bxadmins-change-result';
            result.textContent = JSON.stringify(change.result, null, 2);
            card.appendChild(result);
        }

        var actions = document.createElement('div');
        actions.className = 'bxadmins-change-actions';

        if (status === 'pending') {
            actions.appendChild(actionButton('confirm', 'Подтвердить', true));
            actions.appendChild(actionButton('reject', 'Отклонить', false));
        } else if (status === 'executed') {
            actions.appendChild(actionButton('rollback', 'Откатить', false));
        }

        if (actions.childNodes.length > 0) {
            card.appendChild(actions);
        }

        if (messageHasArgs(parsed)) {
            var details = document.createElement('details');
            details.className = 'bxadmins-change-details';
            var detailsSummary = document.createElement('summary');
            detailsSummary.textContent = 'детали вызова';
            var body = document.createElement('div');
            body.className = 'bxadmins-msg-tool-body';
            body.textContent = parsed.__rawArgs || '';
            details.appendChild(detailsSummary);
            details.appendChild(body);
            card.appendChild(details);
        }

        return card;
    }

    /** Одно подтверждение на всю пачку изменений одной задачи. */
    function changeWord(count) {
        var mod10 = count % 10;
        var mod100 = count % 100;

        if (mod10 === 1 && mod100 !== 11) {
            return 'изменение';
        }
        if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) {
            return 'изменения';
        }

        return 'изменений';
    }

    function groupCards(entries) {
        var batches = {};

        entries.forEach(function (entry) {
            var batchId = entry.change && entry.change.batch_id ? String(entry.change.batch_id) : '';
            if (batchId === '') {
                return;
            }
            if (!batches[batchId]) {
                batches[batchId] = {};
            }
            batches[batchId][entry.parsed.change_id] = entry;
        });

        var renderedBatch = {};
        var result = [];

        entries.forEach(function (entry) {
            var batchId = entry.change && entry.change.batch_id ? String(entry.change.batch_id) : '';

            if (batchId !== '' && Object.keys(batches[batchId]).length > 1) {
                if (renderedBatch[batchId]) {
                    return;
                }
                renderedBatch[batchId] = true;
                result.push(buildBatchCard(batchId, Object.keys(batches[batchId]).map(function (id) {
                    return batches[batchId][id];
                })));
                return;
            }

            result.push(buildChangeCard(entry.parsed, entry.change));
        });

        return result;
    }

    function buildBatchCard(batchId, entries) {
        var changes = [];
        var seen = {};

        entries.forEach(function (entry) {
            if (!seen[entry.parsed.change_id]) {
                seen[entry.parsed.change_id] = true;
                changes.push(entry.change || {id: entry.parsed.change_id, status: 'pending', summary: entry.parsed.summary || ''});
            }
        });

        var pending = 0;
        var executed = 0;
        var rolled = 0;
        var failed = 0;

        changes.forEach(function (change) {
            if (change.status === 'pending') pending++;
            else if (change.status === 'executed') executed++;
            else if (change.status === 'rolled_back') rolled++;
            else if (change.status === 'failed') failed++;
        });

        var status = pending > 0 ? 'pending' : (executed > 0 ? 'executed' : (failed > 0 ? 'failed' : 'rolled_back'));
        var card = document.createElement('div');
        card.className = 'bxadmins-change-card bxadmins-change-card-batch bxadmins-change-card-' + status;
        card.setAttribute('data-batch-id', batchId);
        card.setAttribute('data-count', String(changes.length));

        var title = document.createElement('div');
        title.className = 'bxadmins-change-title';
        if (pending > 0 && executed > 0) {
            title.textContent = 'Частично выполнено: ' + executed + ' из ' + changes.length + ' — осталось подтвердить';
        } else if (pending > 0) {
            title.textContent = 'Требуется подтверждение: ' + changes.length + ' ' + changeWord(changes.length);
        } else if (executed > 0) {
            title.textContent = 'Выполнено: ' + changes.length + ' ' + changeWord(changes.length);
        } else if (failed > 0) {
            title.textContent = 'Ошибка: ' + changes.length + ' ' + changeWord(changes.length);
        } else {
            title.textContent = 'Откат выполнен: ' + changes.length + ' ' + changeWord(changes.length);
        }
        card.appendChild(title);

        var list = document.createElement('ul');
        list.className = 'bxadmins-batch-list';

        changes.slice(0, 8).forEach(function (change) {
            var item = document.createElement('li');
            item.textContent = (change.summary || ('Изменение #' + change.id)) + (change.status !== 'pending' ? ' — ' + statusLabel(change.status) : '');
            list.appendChild(item);
        });

        if (changes.length > 8) {
            var more = document.createElement('li');
            more.textContent = '…и ещё ' + (changes.length - 8);
            list.appendChild(more);
        }
        card.appendChild(list);

        var meta = document.createElement('div');
        meta.className = 'bxadmins-change-meta';
        meta.textContent = 'Изменений в пачке: ' + changes.length
            + (rolled > 0 ? ' · откачено: ' + rolled : '');
        card.appendChild(meta);

        var actions = document.createElement('div');
        actions.className = 'bxadmins-change-actions';

        if (pending > 0) {
            actions.appendChild(actionButton('confirm', 'Подтвердить все', true));
            actions.appendChild(actionButton('reject', 'Отклонить все', false));
        } else if (executed > 0) {
            actions.appendChild(actionButton('rollback', 'Откатить все', false));
        }

        if (actions.childNodes.length > 0) {
            card.appendChild(actions);
        }

        return card;
    }

    function messageHasArgs(parsed) {
        return !!(parsed && parsed.__rawArgs);
    }

    function renderMessage(message) {
        if (message.role === 'status') {
            var status = document.createElement('div');
            status.className = 'bxadmins-change-status' + (message.is_error ? ' bxadmins-change-status-error' : '');
            status.textContent = message.content || '';
            messagesEl.appendChild(status);
            scrollBottom();
            return;
        }

        if (message.role === 'notice') {
            var notice = document.createElement('div');
            notice.className = 'bxadmins-chat-notice';
            notice.textContent = message.content || '';
            messagesEl.appendChild(notice);
            return;
        }

        if (message.role === 'tool') {
            var parsed = toolResult(message);
            if (parsed && parsed.requires_confirmation && parsed.change_id) {
                parsed.__rawArgs = message.tool_args || '';
                messagesEl.appendChild(buildChangeCard(parsed, changeById[String(parsed.change_id)] || null));
                return;
            }

            renderPlainTool(message);
            return;
        }

        if (message.role === 'assistant' && String(message.content || '').trim() === '') {
            return;
        }

        messagesEl.appendChild(buildMessage(message.role, message.content, message.created_at, !!message.is_error, message.attachments));
    }

    function buildEmptyPane(text) {
        var box = document.createElement('div');
        box.className = 'bxadmins-empty bxadmins-chat-empty';

        var mark = document.createElement('div');
        mark.className = 'bxadmins-empty-mark';
        mark.setAttribute('aria-hidden', 'true');
        mark.textContent = 'BX';

        var title = document.createElement('div');
        title.className = 'bxadmins-empty-title';
        title.textContent = EMPTY_TITLE;

        var note = document.createElement('div');
        note.className = 'bxadmins-empty-text';
        note.textContent = text || '';

        box.appendChild(mark);
        box.appendChild(title);
        box.appendChild(note);

        if (suggestions.length > 0) {
            var chips = document.createElement('div');
            chips.className = 'bxadmins-suggestions';

            suggestions.forEach(function (suggestion) {
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'bxadmins-suggestion';
                button.setAttribute('data-text', suggestion);
                button.textContent = suggestion;
                chips.appendChild(button);
            });

            box.appendChild(chips);
        }

        return box;
    }

    function scrollBottom() {
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    // Человеческие подписи вместо технических имён инструментов.
    var TOOL_LABELS = {
        site_info: 'Смотрю настройки сайта…',
        list_iblocks: 'Смотрю инфоблоки…',
        list_iblock_elements: 'Смотрю элементы каталога…',
        list_iblock_sections: 'Смотрю разделы…',
        list_iblock_properties: 'Смотрю свойства…',
        get_iblock_fields: 'Смотрю поля инфоблока…',
        get_iblock_element: 'Открываю элемент…',
        list_orders: 'Смотрю заказы…',
        list_catalog_products: 'Смотрю товары…',
        list_users: 'Смотрю пользователей…',
        list_groups: 'Смотрю группы…',
        list_modules: 'Смотрю модули…',
        list_agents: 'Смотрю агенты…',
        list_changes: 'Смотрю историю изменений…',
        list_backups: 'Смотрю бэкапы…',
        search_knowledge: 'Ищу в базе знаний…',
        read_file: 'Читаю файл…',
        write_site_file: 'Готовлю правку файла…',
        write_local_file: 'Готовлю правку файла…',
        update_site: 'Готовлю настройки сайта…',
        set_menu: 'Готовлю меню…',
        create_iblock_element: 'Готовлю новый элемент…',
        update_iblock_element: 'Готовлю правку элемента…',
        set_element_properties: 'Готовлю свойства…',
        set_element_seo: 'Готовлю SEO…',
        restore_site: 'Откатываю сайт…',
        rollback_change: 'Откатываю изменение…',
        browser_screenshot: 'Открываю страницу в браузере…',
        browser_inspect: 'Смотрю страницу в браузере…',
        browser_css: 'Проверяю стили…',
        check_seo: 'Проверяю SEO…',
        run_sql: 'Смотрю данные сайта…',
        update_catalog_product: 'Готовлю данные товара…',
        set_store_stock: 'Готовлю остаток на складе…',
        update_order: 'Готовлю правку заказа…',
        list_order_statuses: 'Смотрю статусы заказов…',
        update_discount: 'Готовлю правку скидки…',
        set_user_groups: 'Готовлю группы пользователя…',
        set_user_password: 'Готовлю смену пароля…',
        create_highload_block: 'Готовлю справочник…',
        delete_highload_block: 'Удаляю справочник…',
        update_iblock_property: 'Готовлю правку свойства…',
        delete_iblock_property: 'Удаляю свойство…',
        list_redirects: 'Смотрю правила адресов…',
        set_redirect: 'Готовлю редирект…',
        delete_redirect: 'Удаляю редирект…',
        get_robots: 'Смотрю robots.txt…',
        set_robots: 'Готовлю robots.txt…',
        get_menu: 'Смотрю меню…',
        upload_file: 'Загружаю файл…',
        create_site: 'Готовлю новый сайт…',
        list_forms: 'Смотрю веб-формы…',
        get_form_results: 'Смотрю заявки…',
        add_blog_comment: 'Добавляю комментарий в блог…',
        add_event_handler: 'Добавляю обработчик события…',
        add_subscriber: 'Добавляю подписчика…',
        browser_smoke: 'Проверяю страницы в браузере…',
        bulk_update_elements: 'Массово меняю элементы…',
        bulk_update_prices: 'Массово меняю цены…',
        check_links: 'Проверяю ссылки…',
        check_uptime: 'Проверяю доступность сайта…',
        clear_cache: 'Очищаю кеш…',
        create_agent: 'Создаю агента…',
        create_module: 'Создаю модуль…',
        create_backup: 'Делаю бэкап…',
        create_blog_post: 'Пишу пост в блог…',
        create_calendar_event: 'Создаю событие календаря…',
        create_coupon: 'Создаю купон…',
        create_delivery: 'Настраиваю доставку…',
        create_discount: 'Создаю скидку…',
        create_forum: 'Создаю форум…',
        create_forum_post: 'Пишу сообщение на форуме…',
        create_forum_topic: 'Создаю тему форума…',
        create_group: 'Создаю группу пользователей…',
        create_iblock: 'Создаю инфоблок…',
        create_iblock_property: 'Создаю свойство инфоблока…',
        create_iblock_section: 'Создаю раздел инфоблока…',
        create_iblock_type: 'Создаю тип инфоблоков…',
        create_offer: 'Создаю торговое предложение…',
        create_order: 'Создаю заказ…',
        create_pay_system: 'Настраиваю оплату…',
        create_schedule: 'Ставлю задачу по расписанию…',
        create_site_template: 'Создаю шаблон сайта…',
        create_user: 'Создаю пользователя…',
        create_user_field: 'Создаю пользовательское поле…',
        create_web_form: 'Создаю веб-форму…',
        delete_agent: 'Удаляю агента…',
        delete_blog_comment: 'Удаляю комментарий…',
        delete_blog_post: 'Удаляю пост блога…',
        delete_calendar_event: 'Удаляю событие календаря…',
        delete_coupon: 'Удаляю купон…',
        delete_discount: 'Удаляю скидку…',
        delete_forum: 'Удаляю форум…',
        delete_forum_post: 'Удаляю сообщение форума…',
        delete_forum_topic: 'Удаляю тему форума…',
        delete_iblock: 'Удаляю инфоблок…',
        delete_iblock_element: 'Удаляю элемент…',
        delete_iblock_section: 'Удаляю раздел…',
        delete_local_file: 'Удаляю файл…',
        delete_module: 'Удаляю модуль…',
        delete_media_file: 'Удаляю файл медиатеки…',
        delete_schedule: 'Удаляю задачу по расписанию…',
        delete_site_file: 'Удаляю файл сайта…',
        delete_user: 'Удаляю пользователя…',
        download_module: 'Скачиваю модуль…',
        get_entity_fields: 'Смотрю поля сущности…',
        get_module_options: 'Читаю настройки модуля…',
        get_product_prices: 'Смотрю цены товара…',
        get_server_status: 'Проверяю сервер…',
        get_site: 'Смотрю данные сайта…',
        get_store_stock: 'Смотрю остатки на складе…',
        get_traffic_summary: 'Смотрю статистику…',
        import_csv_elements: 'Импортирую CSV…',
        install_module: 'Устанавливаю модуль…',
        list_banners: 'Смотрю баннеры…',
        list_bizproc_templates: 'Смотрю шаблоны бизнес-процессов…',
        list_blog_comments: 'Смотрю комментарии блога…',
        list_blog_posts: 'Смотрю посты блога…',
        list_calendar_events: 'Смотрю календарь…',
        list_components: 'Смотрю компоненты…',
        list_coupons: 'Смотрю купоны…',
        list_courses: 'Смотрю курсы…',
        list_currencies: 'Смотрю валюты…',
        list_deliveries: 'Смотрю доставки…',
        list_discounts: 'Смотрю скидки…',
        list_events: 'Смотрю события…',
        list_forum_posts: 'Смотрю сообщения форума…',
        list_forum_topics: 'Смотрю темы форума…',
        list_forums: 'Смотрю форумы…',
        list_highload_blocks: 'Смотрю справочники…',
        list_landings: 'Смотрю лендинги…',
        list_locations: 'Смотрю местоположения…',
        list_mail_events: 'Смотрю почтовые события…',
        list_mail_templates: 'Смотрю почтовые шаблоны…',
        list_media_files: 'Смотрю медиатеку…',
        list_module_content: 'Смотрю содержимое модуля…',
        list_offers: 'Смотрю торговые предложения…',
        list_pay_systems: 'Смотрю способы оплаты…',
        list_price_types: 'Смотрю типы цен…',
        list_rest_apps: 'Смотрю REST-приложения…',
        list_schedules: 'Смотрю задачи по расписанию…',
        list_sites: 'Смотрю сайты…',
        list_social_groups: 'Смотрю соцгруппы…',
        list_stores: 'Смотрю склады…',
        list_subscribe_rubrics: 'Смотрю рассылки…',
        list_subscriptions: 'Смотрю подписки…',
        list_templates: 'Смотрю шаблоны…',
        list_tickets: 'Смотрю заявки…',
        list_votes: 'Смотрю опросы…',
        module_coverage: 'Проверяю покрытие модулей…',
        module_info: 'Смотрю сведения о модуле…',
        reindex_search: 'Переиндексирую поиск…',
        remove_event_handler: 'Снимаю обработчик события…',
        restore_backup: 'Восстанавливаю из бэкапа…',
        save_mail_template: 'Сохраняю почтовый шаблон…',
        send_test_email: 'Отправляю тестовое письмо…',
        set_module_option: 'Меняю настройку модуля…',
        uninstall_module: 'Удаляю модуль…',
        update_blog_post: 'Правлю пост блога…',
        update_calendar_event: 'Меняю событие календаря…',
        update_forum: 'Меняю форум…',
        update_forum_post: 'Правлю сообщение форума…',
        update_forum_topic: 'Правлю тему форума…',
        update_group: 'Меняю группу…',
        update_iblock: 'Меняю инфоблок…',
        update_iblock_section: 'Правлю раздел…',
        update_offer: 'Правлю торговое предложение…',
        update_order_items: 'Меняю состав заказа…',
        update_order_status: 'Меняю статус заказа…',
        update_product_price: 'Меняю цену товара…',
        update_schedule: 'Меняю задачу по расписанию…',
        update_user: 'Меняю пользователя…',
        update_web_form: 'Правлю веб-форму…',
        upload_backup_s3: 'Отправляю бэкап в S3…'
    };

    // ---------- шаги агента ----------
    // Живая лента: думает / вызывает инструмент / пишет ответ. По умолчанию свёрнута,
    // в свёрнутом виде видно только текущий шаг.

    var steps = [];

    function stepsReset() {
        steps = [];
        reasoningText = '';
        if (stepsListEl) {
            clear(stepsListEl);
        }
        if (stepsEl) {
            stepsEl.open = false;
        }
        if (reasoningTextEl) {
            reasoningTextEl.textContent = '';
        }
        if (reasoningEl) {
            reasoningEl.hidden = true;
            reasoningEl.open = false;
        }
        setThinkingLabel(thinkingLabel);
    }

    function setThinkingLabel(text) {
        if (stepsCurrentEl && text) {
            stepsCurrentEl.textContent = text;
        }
    }

    function stepAdd(kind, label, detail) {
        steps.push({kind: kind, label: label, detail: detail || '', start: Date.now(), end: 0, ok: true});
        renderSteps();
    }

    /** Закрывает последний незавершённый шаг (или последний шаг нужного вида). */
    function stepFinish(kind, ok) {
        for (var i = steps.length - 1; i >= 0; i--) {
            if (steps[i].end === 0 && (!kind || steps[i].kind === kind)) {
                steps[i].end = Date.now();
                steps[i].ok = ok !== false;
                renderSteps();
                return;
            }
        }
    }

    function stepDuration(step) {
        if (!step.start) {
            return '';
        }
        if (step.end === 0) {
            return 'идёт…';
        }

        var seconds = Math.max(0, Math.round((step.end - step.start) / 100) / 10);

        return seconds.toFixed(1) + ' с';
    }

    function renderSteps() {
        if (!stepsListEl) {
            return;
        }

        clear(stepsListEl);

        steps.forEach(function (step) {
            var li = document.createElement('li');
            li.className = 'bxadmins-step bxadmins-step-' + step.kind
                + (step.end === 0 ? ' bxadmins-step-active' : (step.ok ? ' bxadmins-step-done' : ' bxadmins-step-error'));

            var label = document.createElement('span');
            label.className = 'bxadmins-step-label';
            label.textContent = step.label;
            li.appendChild(label);

            var time = document.createElement('span');
            time.className = 'bxadmins-step-time';
            time.textContent = stepDuration(step);
            li.appendChild(time);

            if (step.detail !== '') {
                var detail = document.createElement('div');
                detail.className = 'bxadmins-step-detail';
                detail.textContent = step.detail.length > 600 ? step.detail.slice(0, 600) + '…' : step.detail;
                li.appendChild(detail);
            }

            stepsListEl.appendChild(li);
        });
    }

    function shortArgs(args) {
        try {
            var text = JSON.stringify(args || {});
            return text === '{}' ? '' : text;
        } catch (e) {
            return '';
        }
    }

    function setWaiting(flag) {
        waiting = flag;
        // Поле ввода не блокируем: сообщение можно отправить и встать в очередь.
        progressEl && (progressEl.hidden = !flag);

        if (stopButton) {
            stopButton.hidden = !flag;
        }

        if (!flag) {
            currentTaskId = 0;
            setThinkingLabel(thinkingLabel);
        }
    }

    function showError(text) {
        var message = buildMessage('assistant', text, nowStamp());
        message.className += ' bxadmins-msg-error';
        messagesEl.appendChild(message);
        scrollBottom();
    }

    var ACTION_ICONS = {
        pin: '<svg viewBox="0 0 16 16" width="13" height="13" aria-hidden="true"><path fill="currentColor" d="M9.6 1.2 14.8 6.4l-1.1 1.1-1.4-.4-2.6 2.6.3 2.4-1.1 1.1-2.9-2.9-3.5 3.5-.9-.9 3.5-3.5L2.2 6.5l1.1-1.1 2.4.3 2.6-2.6-.4-1.4z"/></svg>',
        unpin: '<svg viewBox="0 0 16 16" width="13" height="13" aria-hidden="true"><path fill="currentColor" d="M9.6 1.2 14.8 6.4l-1.1 1.1-1.4-.4-2.6 2.6.3 2.4-1.1 1.1-2.9-2.9-3.5 3.5-.9-.9 3.5-3.5L2.2 6.5l1.1-1.1 2.4.3 2.6-2.6-.4-1.4z" opacity=".45"/></svg>',
        rename: '<svg viewBox="0 0 16 16" width="13" height="13" aria-hidden="true"><path fill="currentColor" d="M11.7 1.3a1.6 1.6 0 0 1 2.3 0l.7.7a1.6 1.6 0 0 1 0 2.3l-8.3 8.3-3.5 1.2 1.2-3.5zM10.9 2.9 3.4 10.4l-.5 1.5 1.5-.5 7.5-7.5z"/></svg>',
        delete: '<svg viewBox="0 0 16 16" width="13" height="13" aria-hidden="true"><path fill="currentColor" d="M6 1h4l.5 1H14v1.5H2V2h3.5zM3.5 4.5h9l-.7 9a1.5 1.5 0 0 1-1.5 1.4H5.7a1.5 1.5 0 0 1-1.5-1.4zM6.5 6v7h1V6zm2 0v7h1V6z"/></svg>'
    };

    function chatAction(action, label) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'bxadmins-chat-action';
        button.setAttribute('data-action', action);
        button.setAttribute('title', label);
        button.setAttribute('aria-label', label);
        button.innerHTML = ACTION_ICONS[action] || '';

        return button;
    }

    function chatActionRun(action, chat) {
        if (action === 'pin' || action === 'unpin') {
            api('chat_pin', {chat_id: chat.id, pinned: action === 'pin' ? 'Y' : 'N'})
                .then(function (data) {
                    if (!data.ok) {
                        showError(data.error || 'Не удалось изменить закрепление.');
                        return;
                    }
                    loadChats();
                })
                .catch(function (e) {
                    showError(e.message);
                });
            return;
        }

        if (action === 'rename') {
            var title = window.prompt(RENAME_PROMPT, chat.title || '');
            if (title === null) {
                return;
            }
            title = title.trim();
            if (title === '') {
                return;
            }
            api('chat_rename', {chat_id: chat.id, title: title})
                .then(function (data) {
                    if (!data.ok) {
                        showError(data.error || 'Не удалось переименовать чат.');
                        return;
                    }
                    loadChats();
                })
                .catch(function (e) {
                    showError(e.message);
                });
            return;
        }

        if (action === 'delete') {
            // Сначала узнаём, не привязана ли к чату задача по расписанию:
            // удаление чата удалит и её, и человек должен это видеть заранее.
            api('chat_delete_info', {chat_id: chat.id}).then(function (info) {
                var question = DELETE_CONFIRM;
                var schedule = info && info.schedule;

                if (schedule) {
                    question = DELETE_WITH_SCHEDULE
                        .replace('#TITLE#', schedule.title)
                        .replace('#INTERVAL#', schedule.interval_text);
                }

                if (!window.confirm(question)) {
                    return null;
                }

                return api('chat_delete', {chat_id: chat.id, confirm: 'Y'}).then(function (data) {
                    if (!data.ok) {
                        showError(data.error || 'Не удалось удалить чат.');
                        return;
                    }

                    if (chat.id === currentChatId) {
                        currentChatId = 0;
                        clear(messagesEl);
                        loadChats();
                    } else {
                        loadChats();
                    }
                });
            }).catch(function (e) {
                showError(String(e.message || e));
            });
        }
    }

    function loadChats() {
        return api('chats').then(function (data) {
            if (!data.ok) {
                showError(data.error || 'Не удалось загрузить чаты.');
                return;
            }

            clear(listEl);
            listEmptyEl.hidden = data.chats.length > 0;

            data.chats.forEach(function (chat) {
                var li = document.createElement('li');
                li.className = 'bxadmins-chat-list-item'
                    + (chat.id === currentChatId ? ' bxadmins-active' : '')
                    + (chat.pinned ? ' bxadmins-pinned' : '');

                var title = document.createElement('span');
                title.className = 'bxadmins-chat-list-title';
                title.textContent = chat.title || '';

                // Работающая задача видна прямо в списке: не нужно заходить в чат.
                if (chat.busy === 'running') {
                    li.classList.add('bxadmins-busy');
                    var spinner = document.createElement('span');
                    spinner.className = 'bxadmins-chat-list-spinner';
                    spinner.title = 'В этом чате выполняется задача';
                    li.appendChild(spinner);
                } else if (chat.busy === 'queued') {
                    var queued = document.createElement('span');
                    queued.className = 'bxadmins-chat-list-queued';
                    queued.title = 'Задача в очереди';
                    li.appendChild(queued);
                }

                if (chat.schedule) {
                    var clock = document.createElement('span');
                    clock.className = 'bxadmins-chat-list-schedule';
                    clock.textContent = '⏱';
                    clock.title = 'Чат задачи по расписанию';
                    li.appendChild(clock);
                }

                var date = document.createElement('span');
                date.className = 'bxadmins-chat-list-date';
                date.textContent = formatListDate(chat.updated_at);

                var actions = document.createElement('span');
                actions.className = 'bxadmins-chat-list-actions';
                actions.appendChild(chatAction(chat.pinned ? 'unpin' : 'pin', chat.pinned ? LABEL_UNPIN : LABEL_PIN));
                actions.appendChild(chatAction('rename', LABEL_RENAME));
                actions.appendChild(chatAction('delete', LABEL_DELETE));

                li.appendChild(title);
                li.appendChild(date);
                li.appendChild(actions);

                li.addEventListener('click', function (event) {
                    if (event.target.closest('.bxadmins-chat-action')) {
                        return;
                    }
                    // Другой чат — отдельный поток: сбрасываем состояние прошлого.
                    currentChatId = chat.id;
                    rememberChat(chat.id);
                    currentTaskId = 0;
                    pendingOwnMessage = false;
                    stopWatch();
                    streamReset();
                    stepsReset();
                    setWaiting(false);
                    changeById = {};
                    loadChats();
                    loadMessages();
                });

                actions.addEventListener('click', function (event) {
                    var button = event.target.closest('.bxadmins-chat-action');
                    if (!button) {
                        return;
                    }
                    event.stopPropagation();
                    chatActionRun(button.getAttribute('data-action'), chat);
                });

                listEl.appendChild(li);
            });

            // Занятость текущего чата (в том числе после перезагрузки) — из очереди.
            if (currentChatId) {
                loadQueue();
            }
        });
    }

    function loadMessages() {
        if (!currentChatId) {
            return Promise.resolve();
        }

        return Promise.all([
            api('messages', {chat_id: currentChatId}),
            api('change_list', {chat_id: currentChatId}).catch(function () {
                return {ok: false, changes: []};
            })
        ]).then(function (results) {
            var data = results[0];
            var changeData = results[1];

            if (!data.ok) {
                showError(data.error || 'Не удалось загрузить сообщения.');
                return;
            }

            changeById = {};
            (changeData && changeData.ok ? changeData.changes : []).forEach(function (change) {
                changeById[String(change.id)] = change;
            });

            clear(messagesEl);

            if (data.messages.length === 0) {
                messagesEl.appendChild(buildEmptyPane(EMPTY_CHAT));
                return;
            }

            renderMessageList(data.messages);
            scrollBottom();

            return null;
        }).then(function () {
            return loadQueue();
        });
    }

    /**
     * Сообщения репликами. Если в реплике есть карточка подтверждения,
     * весь текст агента сводится в один ответ, а карточка идёт под ним —
     * иначе кажется, что агент ответил дважды.
     */
    function renderMessageList(list) {
        var i = 0;

        while (i < list.length) {
            var message = list[i];

            if (message.role === 'user' || message.role === 'notice' || message.role === 'status') {
                renderMessage(message);
                i++;
                continue;
            }

            var turn = [];
            while (i < list.length && list[i].role !== 'user' && list[i].role !== 'notice') {
                turn.push(list[i]);
                i++;
            }

            renderTurn(turn);
        }
    }

    function renderTurn(turn) {
        var cards = [];
        var texts = [];
        var tools = [];

        turn.forEach(function (message) {
            if (message.role === 'tool') {
                var parsed = toolResult(message);

                if (parsed && parsed.requires_confirmation && parsed.change_id) {
                    parsed.__rawArgs = message.tool_args || '';
                    cards.push({parsed: parsed, change: changeById[String(parsed.change_id)] || null});
                } else {
                    tools.push(message);
                }

                return;
            }

            if (message.role === 'assistant') {
                if (String(message.content || '').trim() !== '') {
                    texts.push(message);
                }

                return;
            }

            renderMessage(message);
        });

        // Служебные вызовы — свёрнутыми строками над ответом.
        tools.forEach(renderMessage);

        var answer = texts.length > 0
            ? texts.map(function (message) {
                return String(message.content || '');
            }).join('\n\n')
            : '';

        if (cards.length === 0) {
            if (answer !== '') {
                renderMessage({
                    role: 'assistant',
                    content: answer,
                    created_at: texts[texts.length - 1].created_at
                });
            }

            return;
        }

        // Подтверждение — часть того же ответа: карточка живёт внутри пузыря,
        // а не отдельным сообщением под ним.
        var bubble = buildMessage(
            'assistant',
            answer,
            texts.length > 0 ? texts[texts.length - 1].created_at : nowStamp()
        );
        var body = bubble.querySelector('.bxadmins-msg-body');

        groupCards(cards).forEach(function (card) {
            if (body) {
                body.appendChild(card);
            } else {
                bubble.appendChild(card);
            }
        });

        messagesEl.appendChild(bubble);
    }

    /** Очередь чата: сообщения, которые ещё не взяты в работу. */
    function loadQueue() {
        if (!currentChatId) {
            return Promise.resolve([]);
        }

        return api('task_active', {chat_id: currentChatId}).then(function (data) {
            if (!data || !data.ok) {
                return [];
            }

            var tasks = Array.isArray(data.tasks) ? data.tasks : [];
            renderQueue(tasks);

            var running = tasks.filter(function (task) {
                return task.status === 'running';
            });

            if (running.length > 0) {
                currentTaskId = Number(running[0].id) || 0;
                setWaiting(true);
                // Страховка на случай, если SSE-событие потеряется: опрос сам
                // заметит завершение и снимет кнопку «Стоп».
                startWatch();
            } else {
                setWaiting(false);
            }

            return tasks;
        }).catch(function () {
            return [];
        });
    }

    function renderQueue(tasks) {
        Array.prototype.slice.call(messagesEl.querySelectorAll('.bxadmins-msg-queued')).forEach(function (el) {
            if (el.parentNode) {
                el.parentNode.removeChild(el);
            }
        });

        tasks.filter(function (task) {
            return task.status === 'queued';
        }).forEach(function (task) {
            messagesEl.appendChild(buildQueuedMessage(task));
        });

        scrollBottom();
    }

    function buildQueuedMessage(task) {
        var wrap = buildMessage('user', String(task.prompt || ''), task.created_at, false, task.attachments);
        wrap.className += ' bxadmins-msg-queued';

        var bar = document.createElement('div');
        bar.className = 'bxadmins-queued-bar';

        var label = document.createElement('span');
        label.textContent = QUEUED_LABEL;
        bar.appendChild(label);

        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'bxadmins-queued-remove';
        remove.setAttribute('data-task-id', String(task.id));
        remove.setAttribute('title', QUEUE_REMOVE);
        remove.setAttribute('aria-label', QUEUE_REMOVE);
        remove.textContent = '×';
        bar.appendChild(remove);

        wrap.querySelector('.bxadmins-msg-content').appendChild(bar);

        return wrap;
    }

    function setCardBusy(card, flag) {
        changeBusy = flag;
        if (!card) {
            return;
        }

        card.querySelectorAll('.bxadmins-change-btn').forEach(function (button) {
            button.disabled = flag;
        });
    }

    function changeAction(card, action) {
        if (changeBusy) {
            return;
        }

        var changeId = parseInt(card.getAttribute('data-change-id'), 10);
        var batchId = card.getAttribute('data-batch-id') || '';
        var count = parseInt(card.getAttribute('data-count'), 10) || 0;

        if (!changeId && !batchId) {
            return;
        }

        if (action === 'rollback' && !window.confirm(
            batchId
                ? 'Откатить все изменения в этой пачке (' + count + ')? Данные вернутся к состоянию до каждого из них.'
                : 'Откатить изменение #' + changeId + '? Данные вернутся к сохранённому состоянию.'
        )) {
            return;
        }

        if (action === 'restore_site' && !window.confirm(
            'Вернуть ВЕСЬ сайт к состоянию перед изменением #' + changeId + '?\n'
            + 'Файлы и база вернутся к тому моменту, всё сделанное после пропадёт.\n'
            + 'Переписка и история изменений сохранятся. Перед откатом создаётся страховочная точка — откат можно будет отменить.'
        )) {
            return;
        }

        var labels = {
            confirm: batchId ? 'Не удалось подтвердить изменения' : 'Не удалось подтвердить изменение',
            reject: batchId ? 'Не удалось отклонить изменения' : 'Не удалось отклонить изменение',
            rollback: batchId ? 'Не удалось откатить изменения' : 'Не удалось откатить изменение',
            restore_site: 'Не удалось вернуть сайт'
        };

        setCardBusy(card, true);

        // Полный откат делает дамп и разворачивает базу — это десятки секунд.
        // Без индикатора пользователь решит, что кнопка не работает.
        var busyNote = null;
        if (action === 'restore_site' || action === 'confirm' || action === 'rollback') {
            busyNote = document.createElement('div');
            busyNote.className = 'bxadmins-change-busy';
            busyNote.textContent = action === 'restore_site'
                ? 'Откатываю сайт: создаю страховочную точку и возвращаю файлы с базой. Это может занять до минуты…'
                : (batchId
                    ? 'Выполняю ' + count + ' ' + changeWord(count) + ' по очереди — это может занять до минуты…'
                    : 'Выполняю: создаю точку отката и применяю изменение. Это может занять до минуты…');
            card.appendChild(busyNote);
            card.scrollIntoView({block: 'nearest'});
        }

        var apiAction = batchId ? 'change_batch_' + action : 'change_' + action;
        var apiPayload = batchId ? {batch_id: batchId} : {change_id: changeId};

        api(apiAction, apiPayload).then(function (data) {
            if (!data.ok) {
                showError((labels[action] || 'Ошибка') + ': ' + (data.error || 'неизвестная ошибка'));
                return;
            }

            if (action === 'restore_site') {
                window.alert('Сайт возвращён к выбранному состоянию. Страница будет перезагружена.');
                window.location.reload();
                return null;
            }

            return loadMessages();
        }).catch(function (e) {
            showError((labels[action] || 'Ошибка') + ': ' + String(e.message || e));
        }).finally(function () {
            setCardBusy(card, false);
            if (busyNote && busyNote.parentNode) {
                busyNote.parentNode.removeChild(busyNote);
            }
        });
    }

    // ---------- файлы в чате ----------

    function fileUrl(meta) {
        return FILE_URL + '?id=' + encodeURIComponent(meta.id)
            + '&name=' + encodeURIComponent(meta.name || '');
    }

    function attachmentStrip(list) {
        var strip = document.createElement('div');
        strip.className = 'bxadmins-msg-files';

        list.forEach(function (meta) {
            var item = document.createElement('a');
            item.className = 'bxadmins-file' + (meta.image ? ' bxadmins-file-image' : '');
            item.href = fileUrl(meta);
            item.target = '_blank';
            item.rel = 'noopener';
            item.title = (meta.name || 'файл') + (meta.image ? ' — открыть' : ' — скачать');

            if (meta.image) {
                var image = document.createElement('img');
                image.src = fileUrl(meta);
                image.alt = meta.name || 'изображение';
                image.loading = 'lazy';
                item.appendChild(image);
            } else {
                var icon = document.createElement('span');
                icon.className = 'bxadmins-file-icon';
                icon.textContent = 'DOC';
                item.appendChild(icon);
            }

            var name = document.createElement('span');
            name.className = 'bxadmins-file-name';
            name.textContent = meta.name || 'файл';
            item.appendChild(name);

            strip.appendChild(item);
        });

        return strip;
    }

    function renderAttachments() {
        if (!attachList) {
            return;
        }

        clear(attachList);
        attachList.hidden = attachments.length === 0 && uploading === 0;

        attachments.forEach(function (meta, index) {
            var chip = document.createElement('div');
            chip.className = 'bxadmins-attach-chip';

            if (meta.image) {
                var image = document.createElement('img');
                image.src = fileUrl(meta);
                image.alt = meta.name || '';
                chip.appendChild(image);
            } else {
                var icon = document.createElement('span');
                icon.className = 'bxadmins-attach-icon';
                icon.textContent = 'DOC';
                chip.appendChild(icon);
            }

            var name = document.createElement('span');
            name.className = 'bxadmins-attach-name';
            name.textContent = meta.name || 'файл';
            chip.appendChild(name);

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'bxadmins-attach-remove';
            remove.setAttribute('data-index', String(index));
            remove.setAttribute('aria-label', root.getAttribute('data-label-remove-file') || 'Убрать файл');
            remove.textContent = '×';
            chip.appendChild(remove);

            attachList.appendChild(chip);
        });

        if (uploading > 0) {
            var loading = document.createElement('div');
            loading.className = 'bxadmins-attach-chip bxadmins-attach-loading';
            loading.textContent = root.getAttribute('data-label-uploading') || 'Загружаю…';
            attachList.appendChild(loading);
        }
    }

    function uploadFiles(fileList) {
        if (!UPLOAD_URL) {
            return;
        }

        Array.prototype.slice.call(fileList || []).forEach(function (file) {
            if (attachments.length + uploading >= ATTACH_LIMIT) {
                showError('Можно прикрепить не больше ' + ATTACH_LIMIT + ' файлов.');
                return;
            }

            if (file.size > 25 * 1024 * 1024) {
                showError('Файл «' + file.name + '» больше 25 МБ.');
                return;
            }

            uploading++;
            renderAttachments();

            var form = new FormData();
            form.append('file', file, file.name);
            form.append('sessid', SESSID);

            fetch(UPLOAD_URL, {method: 'POST', body: form, credentials: 'same-origin'})
                .then(function (response) {
                    return response.json().catch(function () {
                        return {};
                    });
                })
                .then(function (data) {
                    if (!data || !data.ok || !data.file) {
                        throw new Error((data && data.error) || 'Не удалось загрузить файл.');
                    }

                    attachments.push(data.file);
                })
                .catch(function (e) {
                    showError(e.message || 'Не удалось загрузить файл.');
                })
                .finally(function () {
                    uploading--;
                    renderAttachments();
                });
        });
    }

    function send() {
        var text = textarea.value.trim();
        if (text === '' && attachments.length === 0) {
            return;
        }

        if (uploading > 0) {
            showError('Дождитесь окончания загрузки файлов.');
            return;
        }

        // Поле не блокируется: пока агент работает, сообщение встаёт в очередь.
        textarea.value = '';
        textarea.focus();

        if (messagesEl.querySelector('.bxadmins-empty')) {
            clear(messagesEl);
        }

        api('send', {
            chat_id: currentChatId,
            text: text,
            bypass: bypassInput && bypassInput.checked ? 'Y' : 'N',
            attachments: attachments.slice()
        }).then(function (data) {
            if (data.chat_id) {
                currentChatId = data.chat_id;
                rememberChat(data.chat_id);
            }

            if (!data.ok) {
                showError(data.error || 'Не удалось поставить задачу.');
                return;
            }

            currentTaskId = data.task_id || 0;
            pendingOwnMessage = true;
            attachments = [];
            renderAttachments();
            loadChats();
            loadQueue();
            startWatch();
            // Демон мог взять задачу за доли секунды: обновим очередь ещё раз,
            // чтобы «в очереди» не мелькало у уже запущенной задачи.
            window.setTimeout(loadQueue, 1500);
        }).catch(function (e) {
            showError(String(e.message || e));
            textarea.focus();
        });
    }

    sendButton.addEventListener('click', send);

    if (stopButton) {
        stopButton.addEventListener('click', function () {
            if (!currentTaskId) {
                showError('Сейчас нечего останавливать: задача уже завершилась.');
                return;
            }

            stopButton.disabled = true;
            setThinkingLabel('Останавливаю…');
            // Опроса ждать не нужно: первый тик должен пройти сразу.
            lastEventAt = 0;

            api('task_stop', {task_id: currentTaskId}).then(function (data) {
                if (data && data.ok === false) {
                    showError(data.error || 'Не удалось остановить задачу.');
                    setThinkingLabel(thinkingLabel);
                    return;
                }

                if (data && data.cancelled) {
                    // Модель уже отвечает: демон оборвёт запрос в течение пары секунд.
                    setThinkingLabel('Останавливаю: жду ответа модели…');
                    startWatch();
                }
            }).catch(function (e) {
                showError(String(e.message || e));
                setThinkingLabel(thinkingLabel);
            }).finally(function () {
                stopButton.disabled = false;
            });
        });
    }

    // SSE: прогресс и результат приходят событием, без AJAX-поллинга.
    // Один поток на пользователя; смена чата его не перезапускает.
    function openStream() {
        if (eventStream || streamDisabled) {
            return;
        }
        if (!window.EventSource) {
            streamDisabled = true;
            return;
        }

        var url = streamUrl + (lastEventId > 0 ? '?last_id=' + encodeURIComponent(lastEventId) : '');
        var source = new EventSource(url);
        eventStream = source;

        source.addEventListener('cursor', function (event) {
            var id = Number(event.lastEventId || 0);
            if (id > lastEventId) {
                lastEventId = id;
            }
            streamFailures = 0;
            streamWasOpen = true;
            streamOpenedAt = Date.now();
        });

        source.addEventListener('task_event', function (event) {
            lastEventAt = Date.now();

            var id = Number(event.lastEventId || 0);
            if (id > 0 && id <= lastEventId) {
                return;
            }
            if (id > 0) {
                lastEventId = id;
            }

            var data;
            try {
                data = JSON.parse(event.data);
            } catch (e) {
                return;
            }

            onTaskEvent(data || {});
        });

        source.onopen = function () {
            streamFailures = 0;
            streamWasOpen = true;
            streamOpenedAt = Date.now();
        };

        source.onerror = function () {
            // Штатное закрытие сервером раз в 25 с не считаем сбоем:
            // EventSource переподключится с Last-Event-ID.
            if (streamWasOpen) {
                // Соединение, умершее почти сразу, — это сбой, а не штатный таймаут.
                streamFailures = Date.now() - streamOpenedAt < 1000 ? streamFailures + 1 : 0;
                streamWasOpen = false;
            } else {
                streamFailures++;
            }

            if (streamFailures >= 3) {
                closeStream();
                streamDisabled = true;
                return;
            }

            // Браузер окончательно закрыл поток — дадим startWatch открыть заново.
            if (source.readyState === 2) {
                closeStream();
            }
        };
    }

    function closeStream() {
        if (eventStream) {
            eventStream.close();
            eventStream = null;
        }
        streamWasOpen = false;
    }

    function renderProviderDown(event) {
        var wrap = document.createElement('div');
        wrap.className = 'bxadmins-provider-down';

        var title = document.createElement('div');
        title.className = 'bxadmins-provider-down-title';
        title.textContent = PROVIDER_DOWN_TITLE;
        wrap.appendChild(title);

        var text = document.createElement('div');
        text.className = 'bxadmins-provider-down-text';
        text.textContent = String(event.message || '');
        wrap.appendChild(text);

        var alternatives = Array.isArray(event.alternatives) ? event.alternatives : [];

        if (!alternatives.length) {
            var hint = document.createElement('div');
            hint.className = 'bxadmins-provider-down-hint';
            hint.textContent = PROVIDER_DOWN_NO_ALTERNATIVES;
            wrap.appendChild(hint);
        } else {
            var actions = document.createElement('div');
            actions.className = 'bxadmins-provider-down-actions';

            alternatives.forEach(function (profile) {
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'adm-btn';
                button.textContent = PROVIDER_SWITCH_TO.replace('#NAME#', profile.name || ('#' + profile.id));
                button.addEventListener('click', function () {
                    button.disabled = true;
                    api('llm_profile_activate', {profile_id: profile.id})
                        .then(function (data) {
                            if (!data.ok) {
                                showError(data.error || 'Не удалось переключиться.');
                                button.disabled = false;
                                return;
                            }
                            var done = document.createElement('div');
                            done.className = 'bxadmins-provider-down-hint';
                            done.textContent = PROVIDER_SWITCHED.replace('#NAME#', profile.name || ('#' + profile.id));
                            wrap.appendChild(done);
                        })
                        .catch(function (e) {
                            showError(String(e.message || e));
                            button.disabled = false;
                        });
                });
                actions.appendChild(button);
            });

            wrap.appendChild(actions);
        }

        messagesEl.appendChild(wrap);
        scrollBottom();
    }

    var streamBubble = null;
    var streamBody = null;
    var streamRaw = '';

    function streamStart() {
        if (streamBubble) {
            return;
        }

        streamBubble = buildMessage('assistant', '', nowStamp());
        streamBody = streamBubble.querySelector('.bxadmins-msg-body');
        streamBubble.className += ' bxadmins-msg-streaming';
        messagesEl.appendChild(streamBubble);
        scrollBottom();
    }

    function streamAppend(text) {
        if (!text) {
            return;
        }

        streamStart();
        streamRaw += text;
        // Разметка рендерится на лету: незакрытый блок кода просто дорисовывается.
        renderMarkdown(streamBody, streamRaw);
        scrollBottom();
    }

    function streamReset() {
        if (streamBubble && streamBubble.parentNode) {
            streamBubble.parentNode.removeChild(streamBubble);
        }
        streamBubble = null;
        streamBody = null;
        streamRaw = '';
    }

    /** Оставляет уже напечатанный текст в чате и начинает следующий блок. */
    function streamFreeze() {
        if (streamBubble) {
            streamBubble.classList.remove('bxadmins-msg-streaming');

            if (streamRaw.trim() === '' && streamBubble.parentNode) {
                streamBubble.parentNode.removeChild(streamBubble);
            }
        }

        streamBubble = null;
        streamBody = null;
        streamRaw = '';
    }

    /** Опрашивает состояние задачи, пока SSE молчит. */
    function startWatch() {
        lastEventAt = Date.now();
        openStream();
        watchUntil = Date.now() + 15 * 60 * 1000;

        if (watchTimer) {
            return;
        }

        watchTimer = window.setInterval(function () {
            if (Date.now() > watchUntil) {
                stopWatch();
                return;
            }

            // События приходят — опрос не нужен.
            if (lastEventAt && Date.now() - lastEventAt < 5000) {
                return;
            }

            api('task_active', {chat_id: currentChatId}).then(function (data) {
                if (!data.ok) {
                    return;
                }

                var tasks = Array.isArray(data.tasks) ? data.tasks : [];
                var running = tasks.filter(function (task) {
                    return task.status === 'running';
                });
                var queued = tasks.filter(function (task) {
                    return task.status === 'queued';
                });

                renderQueue(tasks);

                if (running.length === 0 && queued.length === 0) {
                    stopWatch();
                    streamReset();
                    setWaiting(false);
                    stepsReset();
                    lastChatsRefresh = Date.now();

                    return loadMessages().then(function () {
                        return loadChats();
                    });
                }

                var runningId = running.length > 0 ? Number(running[0].id) : 0;
                var startedNew = runningId > 0 && runningId !== watchRunningId;
                watchRunningId = runningId;

                if (runningId > 0) {
                    currentTaskId = runningId;
                    setWaiting(true);

                    // Очередная задача стала работой: её сообщение пора показать в истории.
                    if (startedNew) {
                        lastChatsRefresh = Date.now();

                        return loadMessages().then(function () {
                            return loadChats();
                        });
                    }

                    var draft = String(running[0].result || '');
                    if (draft !== '') {
                        showDraft(draft);
                    }
                } else {
                    // В очереди есть задачи, но ни одна ещё не началась.
                    currentTaskId = 0;
                    setWaiting(false);
                    stepsReset();
                }

                if (Date.now() - lastChatsRefresh > 5000) {
                    lastChatsRefresh = Date.now();
                    loadChats();
                }
            }).catch(function () {
                // Сеть моргнула — попробуем на следующем тике.
            });
        }, 2500);
    }

    /** Черновик ответа из задачи — если SSE-события не доходят. */
    function showDraft(text) {
        if (!streamBody) {
            streamStart();
        }

        if (streamRaw !== text) {
            streamRaw = text;
            renderMarkdown(streamBody, text);
            scrollBottom();
        }
    }

    function stopWatch() {
        if (watchTimer) {
            window.clearInterval(watchTimer);
            watchTimer = 0;
        }
        watchRunningId = 0;
    }

    function onTaskEvent(event) {
        lastEventAt = Date.now();
        var chatId = Number(event.chat_id || 0);
        var type = String(event.type || '');

        // Событие другого чата: обновляем список, чтобы было видно активность.
        if (chatId && chatId !== currentChatId) {
            loadChats();
            return;
        }

        if (type === 'thinking') {
            // Задача стартовала: сообщение пользователя уже в истории, показываем его.
            if (pendingOwnMessage) {
                pendingOwnMessage = false;
                loadMessages();
            }

            // Модель ушла в следующий запрос: закрываем прошлые шаги.
            stepFinish('answer');
            stepFinish('model');
            stepAdd('model', STEPS_REQUEST + (event.round ? ' · шаг ' + event.round : ''));
            setThinkingLabel(thinkingLabel);
            setWaiting(true);
            return;
        }

        if (type === 'reasoning') {
            var reasoningChunk = String(event.content || '');
            if (reasoningChunk !== '') {
                reasoningText = (reasoningText + reasoningChunk).slice(-20000);
                if (reasoningTextEl) {
                    reasoningTextEl.textContent = reasoningText;
                }
                if (reasoningEl) {
                    reasoningEl.hidden = false;
                }
            }
            return;
        }

        if (type === 'delta') {
            var lastStep = steps[steps.length - 1];
            if (!lastStep || lastStep.kind !== 'answer' || lastStep.end !== 0) {
                stepFinish('model');
                stepAdd('answer', STEPS_ANSWER);
            }
            setThinkingLabel(STEPS_ANSWER);
            streamAppend(String(event.content || ''));
            return;
        }

        if (type === 'tool_start') {
            // Перед вызовом инструмента текст модели уже показан — оставляем его и
            // начинаем новый блок. Раньше он исчезал, и это выглядело как мигание.
            streamFreeze();
            stepFinish('answer');
            stepFinish('model');

            var toolLabel = TOOL_LABELS[event.name] || 'инструмент: ' + (event.name || '?');
            stepAdd('tool', toolLabel, shortArgs(event.args));
            setThinkingLabel(toolLabel);
            setWaiting(true);
            return;
        }

        if (type === 'tool_done') {
            // Следующий шаг может быть обычным ответом — возвращаем нейтральную подпись.
            var toolPayload = event.result || {};
            stepFinish('tool', !(toolPayload && toolPayload.error));
            setThinkingLabel(thinkingLabel);
            return;
        }

        if (type === 'provider_down') {
            streamReset();
            setWaiting(false);
            stepsReset();
            renderProviderDown(event);
            loadMessages();
            return;
        }

        if (type === 'done' || type === 'error' || type === 'stopped') {
            streamReset();
            setWaiting(false);
            stepsReset();
            stopWatch();
            currentTaskId = 0;
            loadMessages();
            loadChats().then(function () {
                textarea.focus();
            });
        }
    }

    textarea.addEventListener('keydown', function (event) {
        if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
            event.preventDefault();
            send();
        }
    });

    newChatButton.addEventListener('click', function () {
        api('new_chat').then(function (data) {
            if (!data.ok) {
                showError(data.error || 'Не удалось создать чат.');
                return;
            }

            currentChatId = data.chat_id;
            rememberChat(data.chat_id);
            changeById = {};
            currentTaskId = 0;
            stopWatch();
            streamReset();
            stepsReset();
            setWaiting(false);
            clear(messagesEl);
            messagesEl.appendChild(buildEmptyPane(EMPTY_NEW));
            loadChats();
            loadQueue();
            textarea.focus();
        }).catch(function (e) {
            showError(String(e.message || e));
        });
    });

    // Прикрепление файлов: кнопка, вставка из буфера, перетаскивание.
    if (attachBtn && attachInput) {
        attachBtn.addEventListener('click', function () {
            attachInput.click();
        });

        attachInput.addEventListener('change', function () {
            uploadFiles(attachInput.files);
            attachInput.value = '';
        });
    }

    if (attachList) {
        attachList.addEventListener('click', function (event) {
            var remove = event.target.closest('.bxadmins-attach-remove');
            if (!remove) {
                return;
            }

            attachments.splice(Number(remove.getAttribute('data-index')), 1);
            renderAttachments();
        });
    }

    if (textarea) {
        textarea.addEventListener('paste', function (event) {
            var files = [];
            var items = (event.clipboardData && event.clipboardData.items) || [];

            for (var i = 0; i < items.length; i++) {
                if (items[i].kind === 'file') {
                    var file = items[i].getAsFile();
                    if (file) {
                        files.push(file);
                    }
                }
            }

            if (files.length > 0) {
                event.preventDefault();
                uploadFiles(files);
            }
        });
    }

    if (composerEl) {
        ['dragenter', 'dragover'].forEach(function (name) {
            composerEl.addEventListener(name, function (event) {
                event.preventDefault();
                composerEl.classList.add('bxadmins-composer-drop');
            });
        });

        ['dragleave', 'drop'].forEach(function (name) {
            composerEl.addEventListener(name, function (event) {
                event.preventDefault();
                composerEl.classList.remove('bxadmins-composer-drop');
            });
        });

        composerEl.addEventListener('drop', function (event) {
            if (event.dataTransfer && event.dataTransfer.files.length) {
                uploadFiles(event.dataTransfer.files);
            }
        });
    }

    // Убрать сообщение, которое ещё стоит в очереди.
    messagesEl.addEventListener('click', function (event) {
        var remove = event.target.closest('.bxadmins-queued-remove');
        if (!remove) {
            return;
        }

        remove.disabled = true;
        api('task_stop', {task_id: Number(remove.getAttribute('data-task-id'))})
            .then(function (data) {
                if (data && data.ok === false) {
                    showError(data.error || 'Не удалось убрать задачу из очереди.');
                    return null;
                }

                return loadQueue();
            })
            .then(function () {
                return loadChats();
            })
            .catch(function (e) {
                showError(String(e.message || e));
                remove.disabled = false;
            });
    });

    // Копирование блоков кода: кнопка в шапке блока.
    messagesEl.addEventListener('click', function (event) {
        var copy = event.target.closest('.bxadmins-code-copy');
        if (!copy) {
            return;
        }

        var block = copy.closest('.bxadmins-code');
        var code = block ? block.querySelector('pre') : null;
        if (!code) {
            return;
        }

        var text = code.textContent || '';
        var label = copy.getAttribute('data-copied') || 'Скопировано';

        var done = function () {
            var original = copy.getAttribute('data-original') || copy.textContent;
            copy.setAttribute('data-original', original);
            copy.textContent = label;

            window.setTimeout(function () {
                copy.textContent = original;
            }, 1500);
        };

        var fallback = function () {
            // Резерв: скрытая textarea — работает и там, где Clipboard API запрещён.
            var area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', 'readonly');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();

            try {
                document.execCommand('copy');
                done();
            } catch (e) {
                copy.textContent = 'Не удалось';
            }

            document.body.removeChild(area);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done, fallback);
            return;
        }

        fallback();
    });

    messagesEl.addEventListener('click', function (event) {
        var chip = event.target.closest('.bxadmins-suggestion');
        if (chip) {
            textarea.value = chip.getAttribute('data-text') || '';
            textarea.focus();
            return;
        }

        var button = event.target.closest('.bxadmins-change-btn');
        if (!button) {
            return;
        }

        var card = button.closest('.bxadmins-change-card');
        if (!card) {
            return;
        }

        changeAction(card, button.getAttribute('data-action') || '');
    });

    if (bypassInput) {
        bypassInput.addEventListener('change', function () {
            setBypass(bypassInput.checked);
        });
    }

    restoreBypass();

    // После перезагрузки (в том числе после отката сайта) открываем тот же чат:
    // id берём из адреса, иначе — последний чат из списка.
    currentChatId = parseChatIdFromHash();
    openStream();

    loadChats().then(function () {
        if (currentChatId) {
            return loadMessages();
        }

        return null;
    }).then(function () {
        // Пришли из раздела «Задачи» — сразу подгружаем контекст задачи.
        return maybeEditSchedule();
    }).then(function () {
        textarea.focus();
    });

    window.addEventListener('hashchange', function () {
        var id = parseChatIdFromHash();
        if (id && id !== currentChatId) {
            currentChatId = id;
            currentTaskId = 0;
            pendingOwnMessage = false;
            stopWatch();
            streamReset();
            stepsReset();
            setWaiting(false);
            changeById = {};
            loadChats();
            loadMessages();
        }
    });
    }

    // Скрипт подключается в <head> — ждём готовности DOM.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
