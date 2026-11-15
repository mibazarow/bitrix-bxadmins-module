<?php

declare(strict_types=1);

/**
 * Внутренний API для Node-демона: очередь задач, контекст, исполнение инструментов.
 *
 * Доступен только с localhost и только с HMAC-подписью общим секретом модуля.
 * Браузер сюда не ходит — пользовательские команды идут через admin/ajax.php.
 */

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('NO_AGENT_CHECK', true);
define('STOP_STATISTICS', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;
use Mibazarow\Bxadmins\Config;
use Mibazarow\Bxadmins\Model\ChatTable;
use Mibazarow\Bxadmins\Model\TaskTable;
use Mibazarow\Bxadmins\Service\ChatService;
use Mibazarow\Bxadmins\Service\LlmProfileService;
use Mibazarow\Bxadmins\Service\Logger;
use Mibazarow\Bxadmins\Service\ScheduleService;
use Mibazarow\Bxadmins\Service\TaskEventService;
use Mibazarow\Bxadmins\Service\TaskService;
use Mibazarow\Bxadmins\Service\ToolRegistry;

header('Content-Type: application/json; charset=UTF-8');

// Фатальные ошибки (недоступный класс, нехватка памяти) иначе отдают HTML —
// демон не разберёт ответ и потеряет задачу. Буфер превращает их в JSON.
ob_start();
register_shutdown_function(static function (): void {
    $error = error_get_last();

    if ($error === null || !in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
    }

    echo json_encode(array('ok' => false, 'error' => 'fatal: ' . $error['message']));
});

$respond = static function (array $data, int $status = 200): void {
    http_response_code($status);
    echo Json::encode($data);
    exit;
};

// Классы модуля доступны только после includeModule — нужен до Config::workerSecret().
if (!Loader::includeModule('mibazarow.bxadmins')) {
    $respond(array('ok' => false, 'error' => 'module is not installed'), 500);
}

$remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if (!in_array($remote, array('127.0.0.1', '::1'), true)) {
    $respond(array('ok' => false, 'error' => 'forbidden'), 403);
}

// За nginx REMOTE_ADDR всегда 127.0.0.1, поэтому реальный клиент проверяем по X-Real-IP.
$realIp = (string)($_SERVER['HTTP_X_REAL_IP'] ?? '');
if ($realIp !== '' && !in_array($realIp, array('127.0.0.1', '::1'), true)) {
    $respond(array('ok' => false, 'error' => 'forbidden'), 403);
}

$rawBody = (string)file_get_contents('php://input');
$timestamp = (string)($_SERVER['HTTP_X_BXADMINS_TIMESTAMP'] ?? '');
$signature = (string)($_SERVER['HTTP_X_BXADMINS_SIGNATURE'] ?? '');

if ($timestamp === '' || $signature === '' || abs(time() - (int)$timestamp) > 120) {
    $respond(array('ok' => false, 'error' => 'bad timestamp'), 401);
}

$secret = Config::workerSecret();
if ($secret === '') {
    $respond(array('ok' => false, 'error' => 'worker secret is not configured'), 500);
}

$expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
if (!hash_equals($expected, $signature)) {
    $respond(array('ok' => false, 'error' => 'bad signature'), 401);
}

try {
    $payload = $rawBody === '' ? array() : Json::decode($rawBody);
} catch (\Throwable $e) {
    $respond(array('ok' => false, 'error' => 'bad json'), 400);
}

$action = (string)($payload['action'] ?? '');
$taskId = (int)($payload['task_id'] ?? 0);

try {
    switch ($action) {
        case 'claim':
            TaskService::releaseStale();

            $task = TaskService::claim();
            if (!$task) {
                $respond(array('ok' => true, 'task' => null));
            }

            // Сообщение пользователя попадает в историю в момент старта задачи.
            ChatService::startTask($task);

            $chat = ChatTable::getById((int)$task['CHAT_ID'])->fetch();
            $respond(array(
                'ok' => true,
                'task' => array(
                    'id' => (int)$task['ID'],
                    'chat_id' => (int)$task['CHAT_ID'],
                    'user_id' => (int)$task['USER_ID'],
                    'prompt' => (string)$task['PROMPT'],
                    'bypass' => (string)$task['BYPASS'] === 'Y',
                    'chat_title' => (string)($chat['TITLE'] ?? ''),
                ),
            ));
            break;

        case 'config':
            $profileId = Config::llmProfileId();

            $respond(array(
                'ok' => true,
                'llm' => array(
                    'profile_id' => $profileId,
                    'endpoint' => Config::llmEndpoint(),
                    'api_key' => Config::llmApiKey(),
                    'model' => Config::llmModel(),
                    'reasoning' => Config::llmReasoning(),
                    'context_window' => Config::contextWindow(),
                    'max_rounds' => 12,
                    'concurrency' => Config::workerConcurrency(),
                    'configured' => Config::isLlmConfigured(),
                    // Другие сохранённые подключения — для предложения переключиться.
                    'alternatives' => LlmProfileService::alternatives($profileId),
                ),
            ));
            break;

        case 'alternatives':
            $respond(array(
                'ok' => true,
                'alternatives' => LlmProfileService::alternatives(Config::llmProfileId()),
            ));
            break;

        case 'context':
            $task = loadTask($taskId);
            $respond(array(
                'ok' => true,
                'messages' => ChatService::llmContext((int)$task['CHAT_ID']),
                'tools' => ChatService::toolDefinitions(),
            ));
            break;

        case 'tool':
            $task = loadTask($taskId);
            $name = (string)($payload['name'] ?? '');
            $args = is_array($payload['args'] ?? null) ? $payload['args'] : array();

            $result = ToolRegistry::execute($name, $args, array(
                'user_id' => (int)$task['USER_ID'],
                'chat_id' => (int)$task['CHAT_ID'],
                'task_id' => (int)$task['ID'],
                'bypass' => (string)$task['BYPASS'] === 'Y',
            ));

            $respond(array('ok' => true, 'result' => $result));
            break;

        case 'message':
            $task = loadTask($taskId);
            ChatService::appendMessage(
                (int)$task['CHAT_ID'],
                (string)($payload['role'] ?? 'assistant'),
                (string)($payload['content'] ?? ''),
                (string)($payload['tool_name'] ?? ''),
                (string)($payload['tool_call_id'] ?? ''),
                (string)($payload['tool_args'] ?? ''),
                (bool)($payload['is_error'] ?? false)
            );
            $respond(array('ok' => true));
            break;

        case 'chat_title_need':
            loadTask($taskId);
            $hint = \Mibazarow\Bxadmins\Service\ChatService::titleHint((int)($payload['chat_id'] ?? 0));
            $respond(array('ok' => true, 'need' => $hint !== null, 'text' => $hint ?? ''));
            break;

        case 'chat_title':
            loadTask($taskId);
            \Mibazarow\Bxadmins\Service\ChatService::setGeneratedTitle(
                (int)($payload['chat_id'] ?? 0),
                (string)($payload['title'] ?? '')
            );
            $respond(array('ok' => true));
            break;

        case 'progress':
            loadTask($taskId);
            TaskService::setProgress($taskId, (string)($payload['text'] ?? ''));
            $respond(array('ok' => true));
            break;

        case 'finish':
            $finishedTask = loadTask($taskId);
            $status = (string)($payload['status'] ?? TaskService::STATUS_DONE);

            TaskService::finish(
                $taskId,
                $status,
                (string)($payload['result'] ?? ''),
                (string)($payload['error'] ?? '')
            );

            // Если задача была запуском по расписанию — фиксируем итог в реестре,
            // чтобы на странице «Задачи» было видно, чем закончился последний запуск.
            ScheduleService::markResultByChat(
                (int)$finishedTask['CHAT_ID'],
                $status,
                (string)($payload['result'] ?? '') ?: (string)($payload['error'] ?? '')
            );

            $respond(array('ok' => true));
            break;

        case 'cancelled':
            loadTask($taskId);
            $respond(array('ok' => true, 'cancelled' => TaskService::isCancelled($taskId)));
            break;

        case 'publish':
            $task = loadTask($taskId);
            $event = (array)($payload['params'] ?? array());
            $event['task_id'] = $taskId;
            $event['chat_id'] = (int)$task['CHAT_ID'];
            publish((int)$task['USER_ID'], (string)($payload['command'] ?? 'task_event'), $event);
            $respond(array('ok' => true));
            break;

        default:
            $respond(array('ok' => false, 'error' => 'unknown action'), 400);
    }
} catch (\Throwable $e) {
    Logger::error('worker: ' . $e->getMessage(), array('action' => $action, 'task_id' => $taskId));
    $respond(array('ok' => false, 'error' => $e->getMessage()), 500);
}

/** @return array */
function loadTask(int $taskId): array
{
    if ($taskId <= 0) {
        throw new \InvalidArgumentException('task_id обязателен.');
    }

    $task = TaskTable::getById($taskId)->fetch();
    if (!$task) {
        throw new \RuntimeException('Задача не найдена.');
    }

    return $task;
}

/**
 * Публикует событие в журнал для SSE-доставки.
 *
 * Ошибка записи не должна ронять задачу: браузер всё равно получит черновик
 * через task_active. Поэтому здесь только журналируем сбой.
 */
function publish(int $userId, string $command, array $params): void
{
    if ($command !== 'task_event') {
        return;
    }

    try {
        TaskEventService::append($userId, $params);
    } catch (\Throwable $e) {
        Logger::error('worker publish: ' . $e->getMessage(), array(
            'user_id' => $userId,
            'task_id' => (int)($params['task_id'] ?? 0),
            'type' => (string)($params['type'] ?? ''),
        ));
    }
}
