<?php

declare(strict_types=1);

/**
 * SSE-поток событий задачи для админского чата.
 *
 * Заменяет Push&Pull: читает журнал b_mibazarow_bxadmins_task_event и отдаёт
 * события владельцу. Одно соединение живёт не дольше 25 секунд; EventSource
 * сам переподключается с Last-Event-ID, поэтому обрыв и таймаут не теряют события.
 */

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('NO_AGENT_CHECK', true);
define('STOP_STATISTICS', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Mibazarow\Bxadmins\Service\Logger;
use Mibazarow\Bxadmins\Service\TaskEventService;

global $USER;

$fail = static function (int $status, string $message): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(array('ok' => false, 'error' => $message), JSON_UNESCAPED_UNICODE);
    exit;
};

if (!$USER || !$USER->IsAdmin()) {
    $fail(403, 'Доступ запрещён.');
}

if (!Loader::includeModule('mibazarow.bxadmins')) {
    $fail(500, 'Модуль BXADMIN-S не установлен.');
}

$userId = (int)$USER->GetID();
$headerCursor = $_SERVER['HTTP_LAST_EVENT_ID'] ?? null;
$queryCursor = $_GET['last_id'] ?? null;
$hasCursor = $headerCursor !== null || $queryCursor !== null;
$rawLastId = $headerCursor ?? $queryCursor ?? 0;
$lastId = is_scalar($rawLastId) ? max(0, (int)$rawLastId) : 0;

try {
    // Первое подключение (без курсора) начинаем с конца журнала: старые задачи
    // не должны проигрываться при открытии страницы. Переподключение с
    // Last-Event-ID: 0 — это законный курсор, с него читаем дальше.
    if (!$hasCursor) {
        $lastId = TaskEventService::latestId($userId);
    }
} catch (\Throwable $e) {
    Logger::error('stream: ' . $e->getMessage(), array('user_id' => $userId));
    $fail(500, 'Журнал событий недоступен.');
}

// Иначе висящий поток заблокирует остальные AJAX-запросы вкладки.
if (session_status() === PHP_SESSION_ACTIVE) {
    @session_write_close();
}

header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Accel-Buffering: no');
header('X-Content-Type-Options: nosniff');

@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
@set_time_limit(0);

while (ob_get_level() > 0) {
    ob_end_clean();
}

echo "retry: 1500\n\n";
// Курсор до первого события: если связь оборвётся до task_event,
// EventSource запомнит этот id и на переподключении не потеряет события.
echo 'id: ' . $lastId . "\n";
echo "event: cursor\n";
echo "data: {}\n\n";
@ob_flush();
flush();

$started = microtime(true);
$lastPing = $started;
$maxSeconds = 25;

while (microtime(true) - $started < $maxSeconds) {
    if (connection_aborted()) {
        break;
    }

    try {
        $events = TaskEventService::since($userId, $lastId, 200);
    } catch (\Throwable $e) {
        Logger::error('stream: ' . $e->getMessage(), array('user_id' => $userId));
        break;
    }

    if ($events !== array()) {
        foreach ($events as $event) {
            $lastId = (int)$event['id'];

            echo 'id: ' . $lastId . "\n";
            echo "event: task_event\n";
            echo 'data: ' . $event['payload'] . "\n\n";
        }

        @ob_flush();
        flush();

        // Если накопилось больше лимита, следующий SELECT продолжит сразу.
        continue;
    }

    if (microtime(true) - $lastPing >= 10) {
        echo ": ping\n\n";
        @ob_flush();
        flush();
        $lastPing = microtime(true);
    }

    usleep(400000);
}
