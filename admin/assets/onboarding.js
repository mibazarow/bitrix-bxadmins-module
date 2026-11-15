(function () {
    'use strict';

    var BATCH_LIMIT = 5;
    var POLL_INTERVAL = 4500;

    function init() {
        var root = document.querySelector('.bxadmins-lock');
        if (!root) {
            return;
        }

        var ajaxUrl = root.getAttribute('data-ajax');
        var sessid = root.getAttribute('data-sessid');
        var fill = document.getElementById('bxadmins-lock-fill');
        var label = document.getElementById('bxadmins-lock-label');
        if (!ajaxUrl || !fill || !label) {
            return;
        }

        var percent = root.getAttribute('data-percent');
        var total = parseInt(root.getAttribute('data-total') || '0', 10);
        var offset = parseInt(root.getAttribute('data-offset') || '0', 10);
        var busy = false;
        var stopped = false;

        if (percent !== null && percent !== '') {
            fill.style.width = percent + '%';
            fill.classList.remove('bxadmins-progress-indeterminate');
        }

        function url(action, extra) {
            var result = ajaxUrl + '?action=' + encodeURIComponent(action)
                + '&sessid=' + encodeURIComponent(sessid);
            return extra ? result + '&' + extra : result;
        }

        function applyStatus(status) {
            total = status.total || total;
            offset = status.offset || 0;

            if (status.progress_percent !== null && total > 0) {
                fill.classList.remove('bxadmins-progress-indeterminate');
                fill.style.width = status.progress_percent + '%';
                label.textContent = offset + ' / ' + total + ' (' + status.progress_percent + '%)';
            }
        }

        function tick() {
            if (busy || stopped) {
                return;
            }
            busy = true;

            // Индексацию двигаем сами: агенты Битрикса могут быть отключены (main.check_agents=N).
            fetch(url('index_batch', 'limit=' + BATCH_LIMIT + '&reindex=Y'))
                .then(function (response) {
                    return response.json();
                })
                .catch(function () {
                    return null; // временная ошибка — не мешаем следующему тику
                })
                .then(function () {
                    return fetch(url('index_status'));
                })
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    if (!data || !data.ok || !data.status) {
                        return;
                    }

                    if (data.status.complete) {
                        stopped = true;
                        window.location.reload();
                        return;
                    }

                    applyStatus(data.status);
                })
                .catch(function () {
                    // Сеть/сервер недоступны — просто ждём следующий интервал.
                })
                .then(function () {
                    busy = false;
                });
        }

        tick();
        var timer = setInterval(tick, POLL_INTERVAL);

        window.addEventListener('beforeunload', function () {
            stopped = true;
            clearInterval(timer);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
