(function () {
    'use strict';

    function postJson(url) {
        return fetch(url, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: '{}'})
            .then(function (response) {
                return response.json().catch(function () {
                    throw new Error('сервер вернул не JSON (нужен повторный вход?)');
                });
            });
    }

    function bindTestButton(buttonId, resultId, action, fillOk) {
        var button = document.getElementById(buttonId);
        var result = document.getElementById(resultId);
        if (!button || !result) {
            return;
        }

        button.addEventListener('click', function () {
            button.disabled = true;
            var original = button.textContent;
            button.textContent = button.getAttribute('data-label-running');
            result.hidden = false;
            result.className = 'adm-info-message';
            result.textContent = '';

            var url = button.getAttribute('data-ajax')
                + '?action=' + encodeURIComponent(action)
                + '&sessid=' + encodeURIComponent(button.getAttribute('data-sessid'));

            postJson(url)
                .then(function (data) {
                    if (data.ok) {
                        result.textContent = fillOk(button, data);
                    } else {
                        result.textContent = button.getAttribute('data-fail-template')
                            .replace('#ERROR#', data.error || 'неизвестная ошибка');
                        result.className = 'adm-info-message bxadmins-result-error';
                    }
                })
                .catch(function (e) {
                    result.textContent = button.getAttribute('data-fail-template')
                        .replace('#ERROR#', String(e.message || e));
                    result.className = 'adm-info-message bxadmins-result-error';
                })
                .finally(function () {
                    button.disabled = false;
                    button.textContent = original;
                });
        });
    }



    function profilesRoot() {
        return document.querySelector('.bxadmins-profiles');
    }

    function profilePost(action, payload) {
        var root = profilesRoot();
        var url = root.getAttribute('data-ajax')
            + '?action=' + encodeURIComponent(action)
            + '&sessid=' + encodeURIComponent(root.getAttribute('data-sessid'));

        return fetch(url, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload || {})
        }).then(function (response) {
            return response.json().catch(function () {
                throw new Error('сервер вернул не JSON (нужен повторный вход?)');
            });
        });
    }

    function profileFormReset() {
        var form = document.getElementById('bxadmins-profile-form');
        if (!form) {
            return;
        }

        document.getElementById('profile_id').value = '0';
        document.getElementById('profile_name').value = '';
        document.getElementById('llm_endpoint').value = '';
        document.getElementById('llm_api_key').value = '';
        document.getElementById('llm_model').value = '';
        document.getElementById('context_window').value = '240000';
        setReasoning('low');

        var title = document.getElementById('bxadmins-profile-form-title');
        if (title) {
            title.textContent = title.getAttribute('data-add-label') || title.textContent;
        }

        var reset = document.getElementById('bxadmins-profile-reset');
        if (reset) {
            reset.hidden = true;
        }
    }


    /** Кнопки уровня размышлений: активная — битриксовая зелёная (adm-btn-save). */
    function setReasoning(value) {
        if (value === 'medium' || !value) {
            value = 'low';
        }

        var hidden = document.getElementById('llm_reasoning');
        if (hidden) {
            hidden.value = value;
        }

        var group = document.getElementById('llm_reasoning_group');
        if (!group) {
            return;
        }

        Array.prototype.forEach.call(group.querySelectorAll('[data-reasoning]'), function (button) {
            button.classList.toggle('adm-btn-save', button.getAttribute('data-reasoning') === value);
        });
    }

    /**
     * Нижняя кнопка «Сохранить» — это форма настроек вкладок. Если в форме
     * подключения что-то заполнено, сначала сохраняем провайдера, потом настройки:
     * иначе владелец заполняет подключение, жмёт нижнюю кнопку и ничего не меняется.
     */
    function bindSettingsSubmit() {
        var form = document.forms['bxadmins_settings_form'];
        if (!form) {
            return;
        }

        form.addEventListener('submit', function (event) {
            var name = document.getElementById('profile_name');
            var endpoint = document.getElementById('llm_endpoint');
            var model = document.getElementById('llm_model');

            if (!name || !endpoint || !model) {
                return;
            }

            if (name.value.trim() === '' && endpoint.value.trim() === '' && model.value.trim() === '') {
                return;
            }

            event.preventDefault();

            var status = document.getElementById('bxadmins-profile-status');
            if (status) {
                status.textContent = 'Сохраняю подключение…';
                status.className = 'bxadmins-profile-status';
            }

            profilePost('llm_profile_save', {
                profile_id: (document.getElementById('profile_id') || {}).value || '0',
                name: name.value,
                endpoint: endpoint.value,
                api_key: (document.getElementById('llm_api_key') || {}).value || '',
                model: model.value,
                reasoning: (document.getElementById('llm_reasoning') || {}).value || 'low',
                context_window: (document.getElementById('context_window') || {}).value || '240000'
            }).then(function (data) {
                if (!data.ok) {
                    if (status) {
                        status.textContent = data.error || 'Не удалось сохранить подключение.';
                        status.className = 'bxadmins-profile-status bxadmins-result-error';
                    }
                    return;
                }

                form.submit();
            }).catch(function (e) {
                if (status) {
                    status.textContent = String(e.message || e);
                    status.className = 'bxadmins-profile-status bxadmins-result-error';
                }
            });
        });
    }

    function bindReasoning() {
        var group = document.getElementById('llm_reasoning_group');
        if (!group) {
            return;
        }

        group.addEventListener('click', function (event) {
            var button = event.target.closest('[data-reasoning]');
            if (button) {
                setReasoning(button.getAttribute('data-reasoning'));
            }
        });

        setReasoning((document.getElementById('llm_reasoning') || {}).value || 'low');
    }

    function initMcp() {
        var root = document.querySelector('.bxadmins-mcp');
        if (!root) {
            return;
        }

        var status = document.getElementById('bxadmins-mcp-status');

        function post(action, payload) {
            var url = root.getAttribute('data-ajax')
                + '?action=' + encodeURIComponent(action)
                + '&sessid=' + encodeURIComponent(root.getAttribute('data-sessid'));

            return fetch(url, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(payload || {})
            }).then(function (response) {
                return response.json().catch(function () {
                    throw new Error('сервер вернул не JSON (нужен повторный вход?)');
                });
            });
        }

        function say(text, isError) {
            if (!status) {
                return;
            }
            status.textContent = text;
            status.className = 'bxadmins-profile-status' + (isError ? ' bxadmins-result-error' : '');
        }

        root.addEventListener('click', function (event) {
            var refresh = event.target.closest('.bxadmins-mcp-refresh');
            if (refresh) {
                refresh.disabled = true;
                say('Загружаю инструменты…', false);
                post('mcp_refresh', {server_id: refresh.getAttribute('data-id')})
                    .then(function (data) {
                        if (!data.ok) {
                            say(data.error || 'Не удалось загрузить инструменты.', true);
                            return;
                        }
                        window.location.reload();
                    })
                    .catch(function (e) { say(String(e.message || e), true); })
                    .finally(function () { refresh.disabled = false; });
                return;
            }

            var del = event.target.closest('.bxadmins-mcp-delete');
            if (del) {
                if (!window.confirm(root.getAttribute('data-confirm') || 'Удалить MCP-сервер?')) {
                    return;
                }
                post('mcp_delete', {server_id: del.getAttribute('data-id'), confirm: 'Y'})
                    .then(function (data) {
                        if (!data.ok) {
                            say(data.error || 'Не удалось удалить.', true);
                            return;
                        }
                        window.location.reload();
                    })
                    .catch(function (e) { say(String(e.message || e), true); });
            }
        });

        root.addEventListener('change', function (event) {
            var toolToggle = event.target.closest('.bxadmins-mcp-tool-toggle');
            if (toolToggle) {
                post('mcp_tool_toggle', {
                    server_id: toolToggle.getAttribute('data-server'),
                    tool: toolToggle.getAttribute('data-tool'),
                    disabled: toolToggle.checked ? 'N' : 'Y'
                }).then(function (data) {
                    if (!data.ok) {
                        say(data.error || 'Не удалось переключить инструмент.', true);
                        toolToggle.checked = !toolToggle.checked;
                    }
                }).catch(function (e) {
                    say(String(e.message || e), true);
                    toolToggle.checked = !toolToggle.checked;
                });
                return;
            }

            var toggle = event.target.closest('.bxadmins-mcp-toggle');
            if (!toggle) {
                return;
            }
            post('mcp_toggle', {
                server_id: toggle.value,
                active: toggle.checked ? 'Y' : 'N'
            }).then(function (data) {
                if (!data.ok) {
                    say(data.error || 'Не удалось переключить.', true);
                    toggle.checked = !toggle.checked;
                }
            }).catch(function (e) {
                say(String(e.message || e), true);
                toggle.checked = !toggle.checked;
            });
        });

        var test = document.getElementById('bxadmins-mcp-test');
        if (test) {
            test.addEventListener('click', function () {
                test.disabled = true;
                say('Проверяю…', false);
                post('mcp_test', {
                    url: document.getElementById('mcp_url').value,
                    token: document.getElementById('mcp_token').value
                }).then(function (data) {
                    if (!data.ok) {
                        say(data.error || 'Не удалось подключиться.', true);
                        return;
                    }
                    var t = data.test || {};
                    say('OK: ' + (t.server || 'сервер') + ', инструментов ' + (t.count || 0)
                        + ', ' + (t.elapsed_ms || 0) + ' мс', false);
                }).catch(function (e) {
                    say(String(e.message || e), true);
                }).finally(function () {
                    test.disabled = false;
                });
            });
        }

        var save = document.getElementById('bxadmins-mcp-save');
        if (save) {
            save.addEventListener('click', function () {
                save.disabled = true;
                say('Сохраняю…', false);
                post('mcp_save', {
                    name: document.getElementById('mcp_name').value,
                    url: document.getElementById('mcp_url').value,
                    token: document.getElementById('mcp_token').value,
                    active: true
                }).then(function (data) {
                    if (!data.ok) {
                        say(data.error || 'Не удалось сохранить.', true);
                        return;
                    }
                    if (data.warning) {
                        say('Сохранено, но инструменты не загрузились: ' + data.warning, true);
                        return;
                    }
                    var tools = data.tools || {};
                    say('Сохранено. Инструментов: ' + (tools.count || 0), false);
                    window.setTimeout(function () { window.location.reload(); }, 800);
                }).catch(function (e) {
                    say(String(e.message || e), true);
                }).finally(function () {
                    save.disabled = false;
                });
            });
        }
    }

    function initProfiles() {
        var root = profilesRoot();
        if (!root) {
            return;
        }

        root.addEventListener('click', function (event) {
            var edit = event.target.closest('.bxadmins-profile-edit');
            if (edit) {
                document.getElementById('profile_id').value = edit.getAttribute('data-id');
                document.getElementById('profile_name').value = edit.getAttribute('data-name') || '';
                document.getElementById('llm_endpoint').value = edit.getAttribute('data-endpoint') || '';
                document.getElementById('llm_api_key').value = '';
                document.getElementById('llm_model').value = edit.getAttribute('data-model') || '';
                setReasoning(edit.getAttribute('data-reasoning') || 'low');
                document.getElementById('context_window').value = edit.getAttribute('data-context') || '240000';

                var reset = document.getElementById('bxadmins-profile-reset');
                if (reset) {
                    reset.hidden = false;
                }

                document.getElementById('bxadmins-profile-form').scrollIntoView({behavior: 'smooth', block: 'center'});
                return;
            }

            var del = event.target.closest('.bxadmins-profile-delete');
            if (del) {
                if (!window.confirm(root.getAttribute('data-confirm') || 'Удалить подключение?')) {
                    return;
                }
                profilePost('llm_profile_delete', {
                    profile_id: del.getAttribute('data-id'),
                    confirm: 'Y'
                }).then(function (data) {
                    if (!data.ok) {
                        window.alert(data.error || 'Не удалось удалить подключение.');
                        return;
                    }
                    window.location.reload();
                }).catch(function (e) {
                    window.alert(String(e.message || e));
                });
            }
        });

        root.addEventListener('change', function (event) {
            var radio = event.target.closest('.bxadmins-profile-activate');
            if (!radio) {
                return;
            }
            profilePost('llm_profile_activate', {profile_id: radio.value})
                .then(function (data) {
                    if (!data.ok) {
                        window.alert(data.error || 'Не удалось переключить подключение.');
                        return;
                    }
                    window.location.reload();
                })
                .catch(function (e) {
                    window.alert(String(e.message || e));
                });
        });

        var save = document.getElementById('bxadmins-profile-save');
        if (save) {
            save.addEventListener('click', function () {
                var status = document.getElementById('bxadmins-profile-status');
                save.disabled = true;
                if (status) {
                    status.textContent = '';
                }

                profilePost('llm_profile_save', {
                    profile_id: document.getElementById('profile_id').value,
                    name: document.getElementById('profile_name').value,
                    endpoint: document.getElementById('llm_endpoint').value,
                    api_key: document.getElementById('llm_api_key').value,
                    model: document.getElementById('llm_model').value,
                    reasoning: document.getElementById('llm_reasoning').value,
                    context_window: document.getElementById('context_window').value
                }).then(function (data) {
                    if (!data.ok) {
                        if (status) {
                            status.textContent = data.error || 'Не удалось сохранить.';
                            status.className = 'bxadmins-profile-status bxadmins-result-error';
                        }
                        return;
                    }
                    window.location.reload();
                }).catch(function (e) {
                    if (status) {
                        status.textContent = String(e.message || e);
                        status.className = 'bxadmins-profile-status bxadmins-result-error';
                    }
                }).finally(function () {
                    save.disabled = false;
                });
            });
        }

        var reset = document.getElementById('bxadmins-profile-reset');
        if (reset) {
            reset.addEventListener('click', profileFormReset);
        }
    }

    function loadModels() {
        var button = document.getElementById('bxadmins-load-models');
        var field = document.getElementById('bxadmins-model-field');
        var note = document.getElementById('bxadmins-models-note');
        var endpoint = document.getElementById('llm_endpoint');
        var apiKey = document.getElementById('llm_api_key');
        if (!button || !field || !note || !endpoint) {
            return;
        }

        var idleLabel = button.getAttribute('data-label-idle');
        button.disabled = true;
        button.textContent = button.getAttribute('data-label-loading');
        note.textContent = '';

        var url = button.getAttribute('data-ajax')
            + '?action=llm_models'
            + '&sessid=' + encodeURIComponent(button.getAttribute('data-sessid'));

        fetch(url, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                endpoint: endpoint.value,
                api_key: apiKey ? apiKey.value : ''
            })
        })
            .then(function (response) {
                return response.json().catch(function () {
                    throw new Error('сервер вернул не JSON (нужен повторный вход?)');
                });
            })
            .then(function (data) {
                if (!data.ok) {
                    note.textContent = data.error || '';
                    return;
                }

                var models = data.models || [];
                var current = document.getElementById('llm_model');
                var currentValue = current ? current.value : '';

                if (!models.length) {
                    // Провайдер не вернул список — оставляем поле ввода.
                    note.textContent = button.getAttribute('data-empty-label') || '';
                    return;
                }

                var select = document.createElement('select');
                select.id = 'llm_model';
                select.name = 'llm_model';
                select.className = 'adm-select bxadmins-input-wide';

                models.forEach(function (model) {
                    var option = document.createElement('option');
                    option.value = model;
                    option.textContent = model;
                    if (model === currentValue) {
                        option.selected = true;
                    }
                    select.appendChild(option);
                });

                field.innerHTML = '';
                field.appendChild(select);
            })
            .catch(function (e) {
                note.textContent = String(e.message || e);
            })
            .finally(function () {
                button.disabled = false;
                button.textContent = idleLabel;
            });
    }

    function initModels() {
        var button = document.getElementById('bxadmins-load-models');
        if (!button) {
            return;
        }

        button.addEventListener('click', loadModels);

        // «На лету»: как только endpoint и ключ заполнены — подтягиваем список.
        var endpoint = document.getElementById('llm_endpoint');
        var apiKey = document.getElementById('llm_api_key');
        var auto = function () {
            if (endpoint && endpoint.value.trim() !== '' && apiKey && apiKey.value.trim() !== '') {
                loadModels();
            }
        };
        if (endpoint) { endpoint.addEventListener('change', auto); }
        if (apiKey) { apiKey.addEventListener('change', auto); }
    }

    function initSkills() {
        var root = document.querySelector('.bxadmins-skills');
        if (!root) {
            return;
        }

        var status = document.getElementById('bxadmins-skills-status');
        var filter = document.getElementById('bxadmins-skill-filter');
        var showDeleted = document.getElementById('bxadmins-skill-show-deleted');

        function post(action, payload) {
            var url = root.getAttribute('data-ajax')
                + '?action=' + encodeURIComponent(action)
                + '&sessid=' + encodeURIComponent(root.getAttribute('data-sessid'));

            return fetch(url, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(payload || {})
            }).then(function (response) {
                return response.json().catch(function () {
                    throw new Error('сервер вернул не JSON (нужен повторный вход?)');
                });
            });
        }

        function say(text, isError) {
            if (!status) {
                return;
            }
            status.textContent = text;
            status.className = 'bxadmins-profile-status' + (isError ? ' bxadmins-result-error' : '');
        }

        function applyFilter() {
            var needle = filter ? String(filter.value || '').trim().toLowerCase() : '';
            var deletedAllowed = showDeleted ? showDeleted.checked : false;

            root.querySelectorAll('.bxadmins-skill-row').forEach(function (row) {
                var isDeleted = row.getAttribute('data-deleted') === 'Y';
                var match = needle === '' || String(row.getAttribute('data-search') || '').indexOf(needle) !== -1;
                var visible = match && (deletedAllowed || !isDeleted);
                row.hidden = !visible;

                var editor = root.querySelector('.bxadmins-skill-editor[data-editor="' + row.querySelector('.bxadmins-skill-toggle').value + '"]');
                if (editor && !visible) {
                    editor.hidden = true;
                }
            });
        }

        function download(name, text) {
            var blob = new Blob([text], {type: 'text/markdown;charset=utf-8'});
            var link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = String(name || 'skill').replace(/[\\/:*?"<>|]+/g, '_') + '.md';
            document.body.appendChild(link);
            link.click();
            setTimeout(function () {
                URL.revokeObjectURL(link.href);
                link.remove();
            }, 1000);
        }

        function renderHistory(id, versions) {
            var box = root.querySelector('.bxadmins-skill-history-list[data-history="' + id + '"]');
            if (!box) {
                return;
            }

            box.textContent = '';
            if (!versions || !versions.length) {
                box.textContent = 'Истории пока нет.';
                return;
            }

            var list = document.createElement('div');
            versions.forEach(function (version) {
                var row = document.createElement('div');
                row.className = 'bxadmins-skill-history-row';

                var label = document.createElement('span');
                label.textContent = (version.created_at || '') + ' — ' + (version.name || '') + ' (#' + version.id + ')';
                row.appendChild(label);

                var restore = document.createElement('button');
                restore.type = 'button';
                restore.className = 'adm-btn bxadmins-skill-version-restore';
                restore.setAttribute('data-version', String(version.id));
                restore.textContent = 'Восстановить';
                row.appendChild(restore);

                list.appendChild(row);
            });
            box.appendChild(list);
        }

        function openEditor(id, open) {
            var editor = root.querySelector('.bxadmins-skill-editor[data-editor="' + id + '"]');
            if (editor) {
                editor.hidden = !open;
            }
        }

        if (filter) {
            filter.addEventListener('input', applyFilter);
        }
        if (showDeleted) {
            showDeleted.addEventListener('change', applyFilter);
        }
        applyFilter();

        // The settings page is one big form; Enter inside skill fields must not submit it.
        root.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && event.target && event.target.tagName === 'INPUT') {
                event.preventDefault();
            }
        });

        root.addEventListener('change', function (event) {
            var toggle = event.target.closest('.bxadmins-skill-toggle');
            if (!toggle) {
                return;
            }

            post('skill_toggle', {
                skill_id: toggle.value,
                enabled: toggle.checked ? 'Y' : 'N'
            }).then(function (data) {
                if (!data.ok) {
                    say(data.error || 'Не удалось переключить скилл.', true);
                    toggle.checked = !toggle.checked;
                    return;
                }
                var row = toggle.closest('.bxadmins-skill-row');
                if (row) {
                    row.classList.toggle('bxadmins-skill-disabled', !toggle.checked);
                }
            }).catch(function (e) {
                say(String(e.message || e), true);
                toggle.checked = !toggle.checked;
            });
        });

        root.addEventListener('click', function (event) {
            var save = event.target.closest('.bxadmins-skill-save');
            if (save) {
                var form = save.closest('.bxadmins-skill-form');
                if (!form) {
                    return;
                }

                function field(name) {
                    var input = form.querySelector('[name="' + name + '"]');
                    if (!input) {
                        return '';
                    }
                    if (input.type === 'checkbox') {
                        return input.checked;
                    }
                    return input.value;
                }

                post('skill_save', {
                    skill_id: form.getAttribute('data-id'),
                    name: field('name'),
                    description: field('description'),
                    tags: field('tags'),
                    when_to_use: field('when_to_use'),
                    priority: field('priority') || 100,
                    enabled: !!field('enabled'),
                    instructions: field('instructions')
                }).then(function (result) {
                    if (!result.ok) {
                        say(result.error || 'Не удалось сохранить скилл.', true);
                        return;
                    }
                    say('Сохранено.', false);
                    window.location.reload();
                }).catch(function (e) { say(String(e.message || e), true); });
                return;
            }

            var edit = event.target.closest('.bxadmins-skill-edit');
            if (edit) {
                var id = edit.getAttribute('data-id');
                var editor = root.querySelector('.bxadmins-skill-editor[data-editor="' + id + '"]');
                openEditor(id, !editor || editor.hidden);
                return;
            }

            var cancel = event.target.closest('.bxadmins-skill-cancel');
            if (cancel) {
                var form = cancel.closest('.bxadmins-skill-form');
                if (form) {
                    openEditor(form.getAttribute('data-id'), false);
                }
                return;
            }

            var del = event.target.closest('.bxadmins-skill-delete');
            if (del) {
                if (!window.confirm(root.getAttribute('data-confirm') || 'Удалить скилл?')) {
                    return;
                }
                post('skill_delete', {skill_id: del.getAttribute('data-id'), confirm: 'Y'})
                    .then(function (data) {
                        if (!data.ok) {
                            say(data.error || 'Не удалось удалить скилл.', true);
                            return;
                        }
                        window.location.reload();
                    })
                    .catch(function (e) { say(String(e.message || e), true); });
                return;
            }

            var restore = event.target.closest('.bxadmins-skill-restore');
            if (restore) {
                post('skill_restore', {skill_id: restore.getAttribute('data-id')})
                    .then(function (data) {
                        if (!data.ok) {
                            say(data.error || 'Не удалось восстановить скилл.', true);
                            return;
                        }
                        window.location.reload();
                    })
                    .catch(function (e) { say(String(e.message || e), true); });
                return;
            }

            var purge = event.target.closest('.bxadmins-skill-purge');
            if (purge) {
                if (!window.confirm(root.getAttribute('data-purge-confirm') || 'Удалить навсегда?')) {
                    return;
                }
                post('skill_purge', {skill_id: purge.getAttribute('data-id'), confirm: 'Y'})
                    .then(function (data) {
                        if (!data.ok) {
                            say(data.error || 'Не удалось удалить скилл.', true);
                            return;
                        }
                        window.location.reload();
                    })
                    .catch(function (e) { say(String(e.message || e), true); });
                return;
            }

            var exp = event.target.closest('.bxadmins-skill-export');
            if (exp) {
                post('skill_export', {skill_id: exp.getAttribute('data-id')})
                    .then(function (data) {
                        if (!data.ok) {
                            say(data.error || 'Не удалось экспортировать скилл.', true);
                            return;
                        }
                        download(exp.getAttribute('data-name'), data.markdown);
                    })
                    .catch(function (e) { say(String(e.message || e), true); });
                return;
            }

            var history = event.target.closest('.bxadmins-skill-history');
            if (history) {
                post('skill_versions', {skill_id: history.getAttribute('data-id')})
                    .then(function (data) {
                        if (!data.ok) {
                            say(data.error || 'Не удалось загрузить историю.', true);
                            return;
                        }
                        renderHistory(history.getAttribute('data-id'), data.versions || []);
                    })
                    .catch(function (e) { say(String(e.message || e), true); });
                return;
            }

            var versionRestore = event.target.closest('.bxadmins-skill-version-restore');
            if (versionRestore) {
                post('skill_version_restore', {version_id: versionRestore.getAttribute('data-version')})
                    .then(function (data) {
                        if (!data.ok) {
                            say(data.error || 'Не удалось восстановить версию.', true);
                            return;
                        }
                        window.location.reload();
                    })
                    .catch(function (e) { say(String(e.message || e), true); });
            }
        });

        var importInput = document.getElementById('bxadmins-skill-import');
        if (importInput) {
            importInput.addEventListener('change', function () {
                var file = importInput.files && importInput.files[0];
                if (!file) {
                    return;
                }

                var reader = new FileReader();
                reader.onload = function () {
                    post('skill_import', {markdown: String(reader.result || '')})
                        .then(function (data) {
                            if (!data.ok) {
                                say(data.error || 'Не удалось импортировать скилл.', true);
                                return;
                            }
                            window.location.reload();
                        })
                        .catch(function (e) { say(String(e.message || e), true); });
                };
                reader.readAsText(file, 'utf-8');
                importInput.value = '';
            });
        }
    }

    function init() {
        bindTestButton('bxadmins-test-llm', 'bxadmins-test-llm-result', 'test_llm', function (button, data) {
            return button.getAttribute('data-ok-template')
                .replace('#COUNT#', data.count)
                .replace('#MODELS#', (data.models || []).join(', '));
        });

        initMcp();
        initSkills();
        initProfiles();
        bindReasoning();
        bindSettingsSubmit();
        initModels();

        bindTestButton('bxadmins-test-embedding', 'bxadmins-test-embedding-result', 'test_embedding', function (button, data) {
            return button.getAttribute('data-ok-template')
                .replace('#MODEL#', data.test.service_model || data.test.model)
                .replace('#DIM#', data.test.dimension)
                .replace('#MS#', data.test.elapsed_ms);
        });
    }

    // Скрипт подключается в <head> — ждём готовности DOM.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
