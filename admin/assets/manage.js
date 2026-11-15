(function () {
    'use strict';

    function initIndex() {
        var root = document.querySelector('.bxadmins-index');
        if (!root) {
            return;
        }

        var ajaxUrl = root.getAttribute('data-ajax');
        var sessid = root.getAttribute('data-sessid');
        var labels = {
            clearConfirm: root.getAttribute('data-clear-confirm'),
            clearOk: root.getAttribute('data-clear-ok'),
            batchTemplate: root.getAttribute('data-batch-template'),
            batchDone: root.getAttribute('data-batch-done'),
            alreadyComplete: root.getAttribute('data-already-complete'),
            errorsTemplate: root.getAttribute('data-errors-template'),
            embedOk: root.getAttribute('data-embed-ok'),
            embedFail: root.getAttribute('data-embed-fail'),
            failTemplate: root.getAttribute('data-fail-template'),
            none: root.getAttribute('data-none'),
            startConfirm: root.getAttribute('data-start-confirm'),
            startOk: root.getAttribute('data-start-ok'),
            stateDone: root.getAttribute('data-state-done'),
            stateRunning: root.getAttribute('data-state-running')
        };

        var batchButton = document.getElementById('bxadmins-btn-index-batch');
        var startButton = document.getElementById('bxadmins-btn-index-start');
        var clearButton = document.getElementById('bxadmins-btn-index-clear');
        var embedButton = document.getElementById('bxadmins-btn-test-embed');
        var reindexCheckbox = document.getElementById('bxadmins-batch-reindex');
        var resultEl = document.getElementById('bxadmins-index-result');

        var stateEl = document.getElementById('bxadmins-index-state');
        var chunksEl = document.getElementById('bxadmins-index-chunks');
        var chunksTotalEl = document.getElementById('bxadmins-index-chunks-total');
        var cursorEl = document.getElementById('bxadmins-index-cursor');
        var lastRunEl = document.getElementById('bxadmins-index-lastrun');
        var modelEl = document.getElementById('bxadmins-index-model');
        var startedEl = document.getElementById('bxadmins-index-started');
        var byTypeEl = document.getElementById('bxadmins-index-bytype');
        var lastErrorEl = document.getElementById('bxadmins-index-lasterror');
        var fillEl = document.getElementById('bxadmins-index-fill');
        var progressLabelEl = document.getElementById('bxadmins-index-progress-label');

        var buttons = [batchButton, startButton, clearButton, embedButton];
        var running = false;

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

        function setRunning(flag) {
            running = flag;
            buttons.forEach(function (button) {
                if (button) {
                    button.disabled = flag;
                }
            });
        }

        function showResult(text, isError) {
            resultEl.hidden = false;
            resultEl.textContent = text;
            resultEl.className = 'adm-info-message' + (isError ? ' bxadmins-result-error' : '');
        }

        function renderStatus(status) {
            if (stateEl) {
                stateEl.textContent = status.complete ? labels.stateDone : labels.stateRunning;
            }
            var shownOffset = status.complete ? status.total : status.offset;
            chunksEl.textContent = status.rows;
            chunksTotalEl.textContent = status.chunks;
            cursorEl.textContent = shownOffset + ' / ' + status.total;
            lastRunEl.textContent = status.last_run || labels.none;
            modelEl.textContent = status.embedding_model || labels.none;
            lastErrorEl.textContent = status.last_error || labels.none;
            if (startedEl) {
                startedEl.textContent = status.started_at || labels.none;
            }

            var parts = [];
            for (var type in status.by_type) {
                if (Object.prototype.hasOwnProperty.call(status.by_type, type)) {
                    parts.push(type + ': ' + status.by_type[type]);
                }
            }
            byTypeEl.textContent = parts.join(' · ');

            if (status.complete) {
                fillEl.style.width = '100%';
                progressLabelEl.textContent = status.total + ' / ' + status.total + ' (100%)';
            } else if (status.progress_percent !== null && status.progress_percent !== undefined) {
                fillEl.style.width = status.progress_percent + '%';
                progressLabelEl.textContent = status.offset + ' / ' + status.total
                    + ' (' + status.progress_percent + '%)';
            } else if (status.total > 0) {
                var percent = Math.min(100, Math.round(status.offset / status.total * 100));
                fillEl.style.width = percent + '%';
                progressLabelEl.textContent = status.offset + ' / ' + status.total + ' (' + percent + '%)';
            } else {
                fillEl.style.width = '0%';
                progressLabelEl.textContent = status.chunks;
            }
        }

        function refreshStatus() {
            return api('index_status').then(function (data) {
                if (data.ok) {
                    renderStatus(data.status);
                }
            }).catch(function () {
                // Статус справочный — ошибка не критична.
            });
        }

        batchButton.addEventListener('click', function () {
            if (running) {
                return;
            }
            setRunning(true);
            resultEl.hidden = true;

            var reindex = reindexCheckbox && reindexCheckbox.checked ? 'Y' : 'N';

            api('index_batch', {limit: 10, reindex: reindex}).then(function (data) {
                if (!data.ok) {
                    showResult(labels.failTemplate.replace('#ERROR#', data.error || '?'), true);
                    return;
                }

                if (data.busy) {
                    showResult('Индексация уже выполняется другим процессом. Попробуйте через минуту.', false);
                    return;
                }

                if (data.already_complete) {
                    showResult(labels.alreadyComplete.replace(/^\s+/, ''), false);
                    return;
                }

                var text = labels.batchTemplate
                    .replace('#PROCESSED#', data.processed)
                    .replace('#SKIPPED#', data.skipped)
                    .replace('#OFFSET#', data.offset)
                    .replace('#TOTAL#', data.total);

                if (data.done) {
                    text += labels.batchDone;
                }

                if (data.errors && data.errors.length > 0) {
                    text += labels.errorsTemplate.replace('#COUNT#', data.errors.length);
                }

                showResult(text, false);
                return refreshStatus();
            }).catch(function (e) {
                showResult(labels.failTemplate.replace('#ERROR#', String(e.message || e)), true);
            }).finally(function () {
                setRunning(false);
            });
        });

        if (startButton) {
            startButton.addEventListener('click', function () {
                if (running || !window.confirm(labels.startConfirm)) {
                    return;
                }
                setRunning(true);
                resultEl.hidden = true;

                api('index_start').then(function (data) {
                    if (!data.ok) {
                        showResult(labels.failTemplate.replace('#ERROR#', data.error || '?'), true);
                        return;
                    }

                    showResult(labels.startOk, false);
                    if (data.status) {
                        renderStatus(data.status);
                    } else {
                        return refreshStatus();
                    }
                }).catch(function (e) {
                    showResult(labels.failTemplate.replace('#ERROR#', String(e.message || e)), true);
                }).finally(function () {
                    setRunning(false);
                });
            });
        }

        clearButton.addEventListener('click', function () {
            if (running || !window.confirm(labels.clearConfirm)) {
                return;
            }
            setRunning(true);
            resultEl.hidden = true;

            api('index_clear', {confirm: 'Y'}).then(function (data) {
                if (!data.ok) {
                    showResult(labels.failTemplate.replace('#ERROR#', data.error || '?'), true);
                    return;
                }

                showResult(labels.clearOk, false);
                return refreshStatus();
            }).catch(function (e) {
                showResult(labels.failTemplate.replace('#ERROR#', String(e.message || e)), true);
            }).finally(function () {
                setRunning(false);
            });
        });

        embedButton.addEventListener('click', function () {
            if (running) {
                return;
            }
            setRunning(true);
            resultEl.hidden = true;

            api('test_embedding').then(function (data) {
                if (data.ok) {
                    showResult(labels.embedOk
                        .replace('#MODEL#', data.test.service_model || data.test.model)
                        .replace('#DIM#', data.test.dimension)
                        .replace('#MS#', data.test.elapsed_ms), false);
                } else {
                    showResult(labels.embedFail.replace('#ERROR#', data.error || '?'), true);
                }
            }).catch(function (e) {
                showResult(labels.embedFail.replace('#ERROR#', String(e.message || e)), true);
            }).finally(function () {
                setRunning(false);
            });
        });
    }

    function initBackups() {
        var root = document.querySelector('.bxadmins-backups');
        if (!root) {
            return;
        }

        var ajaxUrl = root.getAttribute('data-ajax');
        var sessid = root.getAttribute('data-sessid');
        var labels = {
            created: root.getAttribute('data-created-template'),
            deleted: root.getAttribute('data-deleted-template'),
            deleteConfirm: root.getAttribute('data-delete-confirm'),
            deleteLabel: root.getAttribute('data-delete-label'),
            failTemplate: root.getAttribute('data-fail-template'),
            none: root.getAttribute('data-none')
        };

        var createButton = document.getElementById('bxadmins-btn-backup-create');
        var listEl = document.getElementById('bxadmins-backup-list');
        var emptyEl = document.getElementById('bxadmins-backup-empty');
        var resultEl = document.getElementById('bxadmins-backup-result');

        if (!createButton || !listEl || !resultEl) {
            return;
        }

        var createLabel = createButton.textContent;
        var running = false;

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

        function setRunning(flag) {
            running = flag;
            createButton.disabled = flag;
            listEl.querySelectorAll('button').forEach(function (button) {
                button.disabled = flag;
            });
        }

        function showResult(text, isError) {
            resultEl.hidden = false;
            resultEl.textContent = text;
            resultEl.className = 'adm-info-message' + (isError ? ' bxadmins-result-error' : '');
        }

        function formatSize(bytes) {
            if (bytes >= 1048576) {
                return (bytes / 1048576).toFixed(2).replace('.', ',') + ' МБ';
            }
            if (bytes >= 1024) {
                return (bytes / 1024).toFixed(1).replace('.', ',') + ' КБ';
            }

            return bytes + ' Б';
        }

        function formatDate(timestamp) {
            var date = new Date(timestamp * 1000);
            var pad = function (value) {
                return value < 10 ? '0' + value : String(value);
            };

            return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate())
                + ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes());
        }

        function cell(text) {
            var td = document.createElement('td');
            td.className = 'adm-list-table-cell';
            td.textContent = text;
            return td;
        }

        function renderList(items) {
            while (listEl.firstChild) {
                listEl.removeChild(listEl.firstChild);
            }

            items.forEach(function (item) {
                var tr = document.createElement('tr');
                tr.className = 'adm-list-table-row';
                tr.setAttribute('data-file', item.file);

                var fileCell = document.createElement('td');
                fileCell.className = 'adm-list-table-cell';
                var code = document.createElement('code');
                code.textContent = item.file;
                fileCell.appendChild(code);

                var actionsCell = document.createElement('td');
                actionsCell.className = 'adm-list-table-cell';
                var deleteButton = document.createElement('button');
                deleteButton.type = 'button';
                deleteButton.className = 'adm-btn bxadmins-backup-delete';
                deleteButton.setAttribute('data-file', item.file);
                deleteButton.textContent = labels.deleteLabel || 'Удалить';
                actionsCell.appendChild(deleteButton);

                tr.appendChild(fileCell);
                tr.appendChild(cell(formatSize(item.size)));
                tr.appendChild(cell(formatDate(item.mtime)));
                tr.appendChild(actionsCell);
                listEl.appendChild(tr);
            });

            emptyEl.hidden = items.length > 0;
        }

        function refreshList() {
            return api('backup_list').then(function (data) {
                if (!data.ok) {
                    throw new Error(data.error || 'Не удалось получить список бэкапов.');
                }

                renderList(data.backups || []);
            });
        }

        createButton.addEventListener('click', function () {
            if (running) {
                return;
            }
            setRunning(true);
            resultEl.hidden = true;
            createButton.textContent = 'Создаю бэкап…';

            api('backup_create').then(function (data) {
                if (!data.ok) {
                    showResult(labels.failTemplate.replace('#ERROR#', data.error || '?'), true);
                    return;
                }

                showResult(labels.created
                    .replace('#FILE#', data.file)
                    .replace('#SIZE#', formatSize(data.size)), false);

                return refreshList();
            }).catch(function (e) {
                showResult(labels.failTemplate.replace('#ERROR#', String(e.message || e)), true);
            }).finally(function () {
                createButton.textContent = createLabel;
                setRunning(false);
            });
        });

        listEl.addEventListener('click', function (event) {
            var target = event.target;
            if (running || !target || !target.classList.contains('bxadmins-backup-delete')) {
                return;
            }

            var file = target.getAttribute('data-file') || '';
            if (!window.confirm(labels.deleteConfirm.replace('#FILE#', file))) {
                return;
            }

            setRunning(true);
            resultEl.hidden = true;

            api('backup_delete', {file: file, confirm: 'Y'}).then(function (data) {
                if (!data.ok) {
                    showResult(labels.failTemplate.replace('#ERROR#', data.error || '?'), true);
                    return;
                }

                showResult(labels.deleted.replace('#FILE#', file), false);
                return refreshList();
            }).catch(function (e) {
                showResult(labels.failTemplate.replace('#ERROR#', String(e.message || e)), true);
            }).finally(function () {
                setRunning(false);
            });
        });
    }

    function initChanges() {
        var root = document.querySelector('.bxadmins-changes');
        if (!root) {
            return;
        }

        var ajaxUrl = root.getAttribute('data-ajax');
        var sessid = root.getAttribute('data-sessid');
        var listEl = document.getElementById('bxadmins-change-list');
        var emptyEl = document.getElementById('bxadmins-change-empty');
        var resultEl = document.getElementById('bxadmins-change-result');

        if (!listEl || !resultEl) {
            return;
        }

        var labels = {
            rollbackLabel: root.getAttribute('data-rollback-label') || 'Откатить',
            rollbackConfirm: root.getAttribute('data-rollback-confirm') || 'Откатить изменение #ID#?',
            rollbackOk: root.getAttribute('data-rollback-ok') || 'Изменение #ID# откачено.',
            restoreSiteLabel: root.getAttribute('data-restore-site-label') || 'Вернуть сайт сюда',
            restoreSiteConfirm: root.getAttribute('data-restore-site-confirm') || 'Вернуть весь сайт к состоянию перед изменением #ID#?',
            restoreSiteOk: root.getAttribute('data-restore-site-ok') || 'Сайт возвращён к состоянию перед изменением #ID#.',
            failTemplate: root.getAttribute('data-fail-template') || 'Ошибка: #ERROR#',
            none: root.getAttribute('data-none') || '—',
            statusPending: root.getAttribute('data-status-pending') || 'ожидает',
            statusExecuted: root.getAttribute('data-status-executed') || 'выполнено',
            statusRejected: root.getAttribute('data-status-rejected') || 'отклонено',
            statusFailed: root.getAttribute('data-status-failed') || 'ошибка',
            statusRolledBack: root.getAttribute('data-status-rolled_back') || 'откачено'
        };

        var statusLabels = {
            pending: labels.statusPending,
            executed: labels.statusExecuted,
            rejected: labels.statusRejected,
            failed: labels.statusFailed,
            rolled_back: labels.statusRolledBack
        };

        var running = false;

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

        function setRunning(flag) {
            running = flag;
            listEl.querySelectorAll('button').forEach(function (button) {
                button.disabled = flag;
            });
        }

        function showResult(text, isError) {
            resultEl.hidden = false;
            resultEl.textContent = text;
            resultEl.className = 'adm-info-message' + (isError ? ' bxadmins-result-error' : '');
        }

        function cell(text) {
            var td = document.createElement('td');
            td.className = 'adm-list-table-cell';
            td.textContent = text;
            return td;
        }

        function renderList(items) {
            while (listEl.firstChild) {
                listEl.removeChild(listEl.firstChild);
            }

            items.forEach(function (item) {
                var tr = document.createElement('tr');
                tr.className = 'adm-list-table-row';
                tr.setAttribute('data-change-id', String(item.id));

                var toolCell = document.createElement('td');
                toolCell.className = 'adm-list-table-cell';
                var toolCode = document.createElement('code');
                toolCode.textContent = item.tool;
                toolCell.appendChild(toolCode);

                var statusCell = document.createElement('td');
                statusCell.className = 'adm-list-table-cell';
                var statusSpan = document.createElement('span');
                statusSpan.className = 'bxadmins-change-status bxadmins-change-status-' + item.status;
                statusSpan.textContent = statusLabels[item.status] || item.status;
                statusCell.appendChild(statusSpan);

                var backupCell = document.createElement('td');
                backupCell.className = 'adm-list-table-cell';
                if (item.backup_file) {
                    var backupCode = document.createElement('code');
                    backupCode.textContent = item.backup_file;
                    backupCell.appendChild(backupCode);
                } else {
                    backupCell.textContent = labels.none;
                }

                var actionsCell = document.createElement('td');
                actionsCell.className = 'adm-list-table-cell';
                if (item.status === 'executed') {
                    var rollbackButton = document.createElement('button');
                    rollbackButton.type = 'button';
                    rollbackButton.className = 'adm-btn bxadmins-change-rollback';
                    rollbackButton.setAttribute('data-change-id', String(item.id));
                    rollbackButton.textContent = labels.rollbackLabel;
                    actionsCell.appendChild(rollbackButton);

                    var restoreButton = document.createElement('button');
                    restoreButton.type = 'button';
                    restoreButton.className = 'adm-btn bxadmins-change-restore-site';
                    restoreButton.setAttribute('data-change-id', String(item.id));
                    restoreButton.textContent = labels.restoreSiteLabel;
                    actionsCell.appendChild(restoreButton);
                } else {
                    actionsCell.textContent = labels.none;
                }

                tr.appendChild(cell(item.created_at || labels.none));
                tr.appendChild(toolCell);
                tr.appendChild(cell(item.summary || ''));
                tr.appendChild(statusCell);
                tr.appendChild(backupCell);
                tr.appendChild(actionsCell);
                listEl.appendChild(tr);
            });

            if (emptyEl) {
                emptyEl.className = 'adm-info-message' + (items.length > 0 ? ' bxadmins-hidden' : '');
            }
        }

        function loadList() {
            return api('change_list', {limit: 20}).then(function (data) {
                if (!data.ok) {
                    throw new Error(data.error || 'Не удалось получить список изменений.');
                }

                renderList(data.changes || []);
            });
        }

        listEl.addEventListener('click', function (event) {
            var target = event.target;
            if (running || !target) {
                return;
            }

            var isRollback = target.classList.contains('bxadmins-change-rollback');
            var isRestoreSite = target.classList.contains('bxadmins-change-restore-site');
            if (!isRollback && !isRestoreSite) {
                return;
            }

            var changeId = parseInt(target.getAttribute('data-change-id'), 10);
            if (!changeId) {
                return;
            }

            var question = isRestoreSite
                ? labels.restoreSiteConfirm.replace('#ID#', String(changeId))
                : labels.rollbackConfirm.replace('#ID#', String(changeId));

            if (!window.confirm(question)) {
                return;
            }

            setRunning(true);
            resultEl.hidden = true;

            var action = isRestoreSite ? 'change_restore_site' : 'change_rollback';
            var okTemplate = isRestoreSite ? labels.restoreSiteOk : labels.rollbackOk;

            api(action, {change_id: changeId}).then(function (data) {
                if (!data.ok) {
                    showResult(labels.failTemplate.replace('#ERROR#', data.error || '?'), true);
                    return;
                }

                showResult(okTemplate.replace('#ID#', String(changeId)), false);

                // После отката сайта база возвращается к прошлому состоянию — перезагружаем страницу.
                if (isRestoreSite) {
                    window.setTimeout(function () { window.location.reload(); }, 800);
                    return null;
                }

                return loadList();
            }).catch(function (e) {
                showResult(labels.failTemplate.replace('#ERROR#', String(e.message || e)), true);
            }).finally(function () {
                setRunning(false);
            });
        });

        loadList().catch(function (e) {
            showResult(labels.failTemplate.replace('#ERROR#', String(e.message || e)), true);
        });
    }

    function initSchedules() {
        var root = document.querySelector('.bxadmins-schedules');
        if (!root) {
            return;
        }

        var ajaxUrl = root.getAttribute('data-ajax');
        var sessid = root.getAttribute('data-sessid');
        var chatUrl = root.getAttribute('data-chat-url') || '/bitrix/admin/mibazarow_bxadmins_chat.php';
        var listEl = document.getElementById('bxadmins-sched-list');
        var emptyEl = document.getElementById('bxadmins-sched-empty');
        var resultEl = document.getElementById('bxadmins-sched-result');

        if (!listEl || !resultEl) {
            return;
        }

        var labels = {
            deleteConfirm: root.getAttribute('data-delete-confirm') || 'Удалить задачу «#TITLE#»?',
            failTemplate: root.getAttribute('data-fail-template') || 'Ошибка: #ERROR#',
            edit: 'Изменить',
            del: 'Удалить',
            next: 'Следующий запуск',
            runs: 'Запусков',
            last: 'Последний'
        };

        var running = false;

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

        function showResult(text, isError) {
            resultEl.textContent = text;
            resultEl.className = 'adm-info-message' + (isError ? ' bxadmins-result-error' : '');
        }

        function hideResult() {
            resultEl.className = 'adm-info-message bxadmins-hidden';
            resultEl.textContent = '';
        }

        function el(tag, className, text) {
            var node = document.createElement(tag);
            if (className) {
                node.className = className;
            }
            if (text !== undefined && text !== null) {
                node.textContent = String(text);
            }

            return node;
        }

        function renderCard(item) {
            var card = el('div', 'bxadmins-sched' + (item.active ? '' : ' bxadmins-sched-off'));
            card.setAttribute('data-id', String(item.id));

            var main = el('div', 'bxadmins-sched-main');

            var head = el('div', 'bxadmins-sched-head');
            head.appendChild(el('span', 'bxadmins-sched-title', item.title));
            head.appendChild(el('span', 'bxadmins-sched-badge', item.interval_text));
            main.appendChild(head);

            var desc = item.prompt || '';
            main.appendChild(el('div', 'bxadmins-sched-desc', desc.length > 220 ? desc.slice(0, 220) + '…' : desc));

            var meta = el('div', 'bxadmins-sched-meta');
            meta.appendChild(document.createTextNode(labels.next + ': '));
            meta.appendChild(el('b', '', item.next_run));
            meta.appendChild(document.createTextNode(' · ' + labels.runs + ': '));
            meta.appendChild(el('b', '', String(item.runs)));
            if (item.last_status) {
                meta.appendChild(document.createTextNode(' · ' + labels.last + ': '));
                meta.appendChild(el('b', 'bxadmins-sched-status-' + item.last_status, item.last_status));
                meta.appendChild(el('span', 'bxadmins-sched-when', ' (' + item.last_run + ')'));
            }
            main.appendChild(meta);

            var actions = el('div', 'bxadmins-sched-actions');

            // Кнопка, а не checkbox: админка Битрикса подменяет input[type=checkbox] своей галкой.
            var toggle = el('button', 'bxadmins-switch' + (item.active ? ' bxadmins-switch-on' : ''));
            toggle.type = 'button';
            toggle.setAttribute('role', 'switch');
            toggle.setAttribute('aria-checked', item.active ? 'true' : 'false');
            toggle.setAttribute('data-active', item.active ? 'Y' : 'N');
            var track = el('span', 'bxadmins-switch-track');
            track.appendChild(el('span', 'bxadmins-switch-knob'));
            toggle.appendChild(track);
            actions.appendChild(toggle);

            var edit = el('a', 'adm-btn bxadmins-sched-edit', labels.edit);
            edit.href = chatUrl + '&schedule=' + encodeURIComponent(item.id);
            actions.appendChild(edit);

            var del = el('button', 'adm-btn bxadmins-sched-delete', labels.del);
            del.type = 'button';
            del.setAttribute('data-id', String(item.id));
            del.setAttribute('data-title', item.title);
            actions.appendChild(del);

            card.appendChild(main);
            card.appendChild(actions);

            return card;
        }

        function renderList(items) {
            while (listEl.firstChild) {
                listEl.removeChild(listEl.firstChild);
            }

            items.forEach(function (item) {
                listEl.appendChild(renderCard(item));
            });

            if (emptyEl) {
                emptyEl.hidden = items.length > 0;
            }
        }

        function setRunning(flag) {
            running = flag;
            root.querySelectorAll('button, input').forEach(function (node) {
                node.disabled = flag;
            });
        }

        function applyResult(data, okText) {
            if (!data.ok) {
                showResult(labels.failTemplate.replace('#ERROR#', data.error || '?'), true);
                return;
            }

            renderList(data.schedules || []);
            if (okText) {
                showResult(okText, false);
            }
        }

        listEl.addEventListener('click', function (event) {
            var toggle = event.target.closest('.bxadmins-switch');
            if (!running && toggle) {
                var toggleCard = toggle.closest('.bxadmins-sched');
                var toggleId = toggleCard ? parseInt(toggleCard.getAttribute('data-id'), 10) : 0;

                if (!toggleId) {
                    return;
                }

                setRunning(true);
                hideResult();

                api('schedule_toggle', {schedule_id: toggleId}).then(function (data) {
                    applyResult(data, '');
                }).catch(function (e) {
                    showResult(labels.failTemplate.replace('#ERROR#', String(e.message || e)), true);
                }).finally(function () {
                    setRunning(false);
                });

                return;
            }

            var button = event.target.closest('.bxadmins-sched-delete');
            if (running || !button) {
                return;
            }

            var id = parseInt(button.getAttribute('data-id'), 10);
            var title = button.getAttribute('data-title') || '';
            if (!id) {
                return;
            }

            if (!window.confirm(labels.deleteConfirm.replace('#TITLE#', title))) {
                return;
            }

            setRunning(true);
            hideResult();

            api('schedule_delete', {schedule_id: id, confirm: 'Y'}).then(function (data) {
                applyResult(data, '');
            }).catch(function (e) {
                showResult(labels.failTemplate.replace('#ERROR#', String(e.message || e)), true);
            }).finally(function () {
                setRunning(false);
            });
        });
    }

    // Скрипт подключается в <head> — ждём готовности DOM.
    function init() {
        initIndex();
        initBackups();
        initChanges();
        initSchedules();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
