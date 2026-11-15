<?php


declare(strict_types=1);

// JSON-API админки: чат, изменения, индексация, бэкапы, проверки подключений.

const ADMIN_MODULE_NAME = 'mibazarow.bxadmins';

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;
use Mibazarow\Bxadmins\Config;
use Mibazarow\Bxadmins\Service\BackupService;
use Mibazarow\Bxadmins\Service\ChangeService;
use Mibazarow\Bxadmins\Service\ChatService;
use Mibazarow\Bxadmins\Service\EmbeddingClient;
use Mibazarow\Bxadmins\Service\IndexService;
use Mibazarow\Bxadmins\Service\LlmClient;
use Mibazarow\Bxadmins\Service\LlmProfileService;
use Mibazarow\Bxadmins\Service\McpService;
use Mibazarow\Bxadmins\Service\ScheduleService;
use Mibazarow\Bxadmins\Service\SkillService;
use Mibazarow\Bxadmins\Service\Logger;
use Mibazarow\Bxadmins\Service\TaskService;
use Mibazarow\Bxadmins\Tool\RestoreSiteTool;

global $USER;

header('Content-Type: application/json; charset=UTF-8');

try {
    if (!$USER || !$USER->IsAdmin()) {
        throw new \InvalidArgumentException('Доступ запрещён.');
    }

    if (!Loader::includeModule(ADMIN_MODULE_NAME)) {
        throw new \InvalidArgumentException('Модуль BXADMIN-S не установлен.');
    }

    if (!check_bitrix_sessid()) {
        throw new \InvalidArgumentException('Сессия устарела — обновите страницу.');
    }

    $params = array();

    if (isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 131072) {
        throw new \RuntimeException('Слишком большой запрос.');
    }

    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        if (strlen($raw) > 131072) {
            throw new \RuntimeException('Слишком большой запрос.');
        }

        $decoded = null;
        try {
            $decoded = Json::decode($raw);
        } catch (\Throwable $e) {
            $decoded = null;
        }

        if (is_array($decoded)) {
            $params = $decoded;
        }
    }

    $params = array_merge($_GET, $_POST, $params);
    $action = (string)($params['action'] ?? '');
    $userId = (int)$USER->GetID();

    switch ($action) {
        case 'new_chat':
            $response = array('ok' => true, 'chat_id' => ChatService::createChat($userId));
            break;

        case 'chats':
            $response = array('ok' => true, 'chats' => ChatService::listChats($userId));
            break;

        case 'chat_rename':
            $response = array(
                'ok' => true,
                'title' => ChatService::renameChat(
                    (int)($params['chat_id'] ?? 0),
                    $userId,
                    (string)($params['title'] ?? '')
                ),
            );
            break;

        case 'chat_delete_info':
            // Что удалится вместе с чатом: интерфейс показывает это в подтверждении.
            $infoChatId = (int)($params['chat_id'] ?? 0);
            ChatService::assertChatOwned($infoChatId, $userId);
            $schedule = ScheduleService::forChat($infoChatId);

            $response = array(
                'ok' => true,
                'schedule' => $schedule === null ? null : array(
                    'id' => (int)$schedule['id'],
                    'title' => (string)$schedule['title'],
                    'interval_text' => (string)$schedule['interval_text'],
                    'active' => (bool)$schedule['active'],
                ),
            );
            break;

        case 'chat_delete':
            if (($params['confirm'] ?? '') !== 'Y') {
                throw new \InvalidArgumentException('Требуется подтверждение: confirm=Y.');
            }

            $deletedSchedule = ChatService::deleteChat((int)($params['chat_id'] ?? 0), $userId);

            $response = array(
                'ok' => true,
                'deleted_schedule' => $deletedSchedule === null ? null : (string)$deletedSchedule['title'],
            );
            break;

        case 'chat_pin':
            $response = array(
                'ok' => true,
                'pinned' => ChatService::setPinned(
                    (int)($params['chat_id'] ?? 0),
                    $userId,
                    (string)($params['pinned'] ?? 'N') === 'Y'
                ),
            );
            break;

        case 'messages':
            $response = array(
                'ok' => true,
                'messages' => ChatService::getMessages((int)($params['chat_id'] ?? 0), $userId),
            );
            break;

        case 'send':
            if (!IndexService::isComplete()) {
                $response = array('ok' => false, 'error' => 'Идёт полная индексация сайта. Дождитесь завершения.');
                break;
            }
            if (!Config::isLlmConfigured()) {
                $response = array('ok' => false, 'error' => 'Языковая модель не подключена. Укажите endpoint и модель в настройках модуля.');
                break;
            }
            $result = ChatService::send(
                $userId,
                (int)($params['chat_id'] ?? 0),
                (string)($params['text'] ?? ''),
                (string)($params['bypass'] ?? '') === 'Y',
                is_array($params['attachments'] ?? null) ? $params['attachments'] : array()
            );
            $response = array(
                'ok' => $result['ok'],
                'chat_id' => $result['chat_id'],
                'task_id' => $result['task_id'],
            );
            break;

        case 'task_stop':
            $result = TaskService::cancel((int)($params['task_id'] ?? 0), $userId);
            $response = array(
                'ok' => true,
                'cancelled' => $result['cancelled'],
                'deleted' => $result['deleted'],
            );
            break;

        case 'task_active':
            $activeChatId = (int)($params['chat_id'] ?? 0);
            if ($activeChatId > 0) {
                ChatService::assertChatOwned($activeChatId, $userId);
            }
            $response = array(
                'ok' => true,
                'tasks' => TaskService::activeForChat($activeChatId),
            );
            break;

        case 'change_confirm':
            $response = array_merge(
                array('ok' => true),
                ChangeService::confirm((int)($params['change_id'] ?? 0), $userId, true)
            );
            break;

        case 'change_batch_confirm':
            $batchId = (string)($params['batch_id'] ?? '');
            $response = array_merge(array('ok' => true), ChangeService::confirmBatch($batchId, $userId, true));
            break;

        case 'change_batch_reject':
            $rejected = ChangeService::rejectBatch((string)($params['batch_id'] ?? ''), $userId);
            $response = array('ok' => true, 'rejected' => $rejected);
            break;

        case 'change_batch_rollback':
            $response = array_merge(
                array('ok' => true),
                ChangeService::rollbackBatch((string)($params['batch_id'] ?? ''), $userId, true)
            );
            break;

        case 'change_reject':
            ChangeService::reject((int)($params['change_id'] ?? 0), $userId);
            $response = array('ok' => true, 'status' => ChangeService::STATUS_REJECTED);
            break;

        case 'change_rollback':
            $response = array_merge(
                array('ok' => true),
                ChangeService::rollback((int)($params['change_id'] ?? 0), $userId, true)
            );
            break;

        case 'change_restore_site':
            $result = RestoreSiteTool::execute(
                array(
                    'change_id' => (int)($params['change_id'] ?? 0),
                    'confirm_restore' => true,
                ),
                array('user_id' => $userId, 'chat_id' => 0, 'bypass' => true)
            );
            $response = array_merge(array('ok' => !empty($result['executed'])), $result);
            break;

        case 'change_list':
            $chatId = (int)($params['chat_id'] ?? 0);
            $response = array(
                'ok' => true,
                'changes' => $chatId > 0
                    ? ChangeService::listForChat($chatId, $userId)
                    : ChangeService::recent((int)($params['limit'] ?? 20)),
            );
            break;

        case 'mcp_save':
            try {
                $id = McpService::save(
                    (int)($params['server_id'] ?? 0),
                    (string)($params['name'] ?? ''),
                    (string)($params['url'] ?? ''),
                    (string)($params['token'] ?? ''),
                    (bool)($params['active'] ?? true)
                );

                $tools = null;
                try {
                    $tools = McpService::refreshTools($id);
                } catch (\Throwable $e) {
                    // Сохранили, но инструменты не загрузились — покажем причину.
                    $response = array(
                        'ok' => true,
                        'server_id' => $id,
                        'servers' => McpService::list(),
                        'tools' => null,
                        'warning' => $e->getMessage(),
                    );
                    break;
                }

                $response = array('ok' => true, 'server_id' => $id, 'servers' => McpService::list(), 'tools' => $tools);
            } catch (\InvalidArgumentException $e) {
                $response = array('ok' => false, 'error' => $e->getMessage());
            }
            break;

        case 'mcp_test':
            try {
                $response = array('ok' => true, 'test' => McpService::test(
                    (string)($params['url'] ?? ''),
                    (string)($params['token'] ?? '')
                ));
            } catch (\Throwable $e) {
                $response = array('ok' => false, 'error' => $e->getMessage());
            }
            break;

        case 'mcp_tool_toggle':
            try {
                McpService::setToolDisabled(
                    (int)($params['server_id'] ?? 0),
                    (string)($params['tool'] ?? ''),
                    (string)($params['disabled'] ?? 'N') === 'Y'
                );
                $response = array('ok' => true);
            } catch (\InvalidArgumentException $e) {
                $response = array('ok' => false, 'error' => $e->getMessage());
            }
            break;

        case 'mcp_refresh':
            try {
                $response = array(
                    'ok' => true,
                    'tools' => McpService::refreshTools((int)($params['server_id'] ?? 0)),
                    'servers' => McpService::list(),
                );
            } catch (\Throwable $e) {
                $response = array('ok' => false, 'error' => $e->getMessage());
            }
            break;

        case 'mcp_toggle':
            try {
                $serverId = (int)($params['server_id'] ?? 0);
                $active = (string)($params['active'] ?? 'N') === 'Y';
                $server = \Mibazarow\Bxadmins\Model\McpServerTable::getById($serverId)->fetch();
                if (!$server) {
                    throw new \InvalidArgumentException('MCP-сервер не найден.');
                }
                \Mibazarow\Bxadmins\Model\McpServerTable::update($serverId, array('ACTIVE' => $active ? 'Y' : 'N'));
                $response = array('ok' => true, 'servers' => McpService::list());
            } catch (\InvalidArgumentException $e) {
                $response = array('ok' => false, 'error' => $e->getMessage());
            }
            break;

        case 'mcp_delete':
            if (($params['confirm'] ?? '') !== 'Y') {
                throw new \InvalidArgumentException('Требуется подтверждение: confirm=Y.');
            }
            McpService::remove((int)($params['server_id'] ?? 0));
            $response = array('ok' => true, 'servers' => McpService::list());
            break;

        case 'mcp_list':
            $response = array('ok' => true, 'servers' => McpService::list());
            break;

        case 'skills_list':
            $response = array('ok' => true, 'skills' => SkillService::all(false, 200));
            break;

        case 'skill_toggle':
            try {
                SkillService::setEnabled(
                    (int)($params['skill_id'] ?? 0),
                    (string)($params['enabled'] ?? 'N') === 'Y'
                );
                $response = array('ok' => true, 'skills' => SkillService::all(false, 200));
            } catch (\InvalidArgumentException $e) {
                $response = array('ok' => false, 'error' => $e->getMessage());
            }
            break;

        case 'skill_delete':
            if (($params['confirm'] ?? '') !== 'Y') {
                throw new \InvalidArgumentException('Требуется подтверждение: confirm=Y.');
            }
            SkillService::remove((string)(int)($params['skill_id'] ?? 0));
            $response = array('ok' => true, 'skills' => SkillService::catalog(200));
            break;

        case 'skill_save':
            try {
                $id = (int)($params['skill_id'] ?? 0);
                $name = trim((string)($params['name'] ?? ''));

                if ($id > 0) {
                    $current = SkillService::get($id);
                    if (!$current) {
                        throw new \InvalidArgumentException('Скилл не найден.');
                    }
                    if (mb_strtolower($current['name']) !== mb_strtolower($name)) {
                        SkillService::rename($id, $name);
                    }
                }

                SkillService::save(
                    $name,
                    (string)($params['description'] ?? ''),
                    (string)($params['instructions'] ?? ''),
                    !isset($params['enabled']) || (bool)$params['enabled'],
                    array(
                        'tags' => (string)($params['tags'] ?? ''),
                        'when_to_use' => (string)($params['when_to_use'] ?? ''),
                        'priority' => (int)($params['priority'] ?? 100),
                        'created_by' => (int)$USER->GetID(),
                        'source' => 'admin',
                    )
                );

                $response = array('ok' => true, 'skills' => SkillService::catalog(200));
            } catch (\Throwable $e) {
                $response = array('ok' => false, 'error' => $e->getMessage());
            }
            break;

        case 'skill_restore':
            SkillService::restore((string)(int)($params['skill_id'] ?? 0));
            $response = array('ok' => true, 'skills' => SkillService::catalog(200));
            break;

        case 'skill_purge':
            if (($params['confirm'] ?? '') !== 'Y') {
                throw new \InvalidArgumentException('Требуется подтверждение: confirm=Y.');
            }
            SkillService::remove((string)(int)($params['skill_id'] ?? 0), true);
            $response = array('ok' => true, 'skills' => SkillService::catalog(200));
            break;

        case 'skill_export':
            $markdown = SkillService::exportMarkdown((int)($params['skill_id'] ?? 0));
            if ($markdown === null) {
                throw new \InvalidArgumentException('Скилл не найден.');
            }
            $response = array('ok' => true, 'markdown' => $markdown);
            break;

        case 'skill_import':
            try {
                $id = SkillService::importMarkdown((string)($params['markdown'] ?? ''));
                $response = array('ok' => true, 'id' => $id, 'skills' => SkillService::catalog(200));
            } catch (\Throwable $e) {
                $response = array('ok' => false, 'error' => $e->getMessage());
            }
            break;

        case 'skill_versions':
            $versions = array();
            foreach (SkillService::versions((int)($params['skill_id'] ?? 0), 20) as $version) {
                $versions[] = array(
                    'id' => $version['id'],
                    'name' => $version['name'],
                    'enabled' => $version['enabled'],
                    'created_at' => $version['created_at'],
                );
            }
            $response = array('ok' => true, 'versions' => $versions);
            break;

        case 'skill_version_restore':
            $skillId = SkillService::restoreVersion((int)($params['version_id'] ?? 0));
            if ($skillId === null) {
                throw new \InvalidArgumentException('Версия не найдена.');
            }
            $response = array('ok' => true, 'skill_id' => $skillId);
            break;

        case 'llm_profile_save':
            try {
                $profileId = LlmProfileService::save(
                    (int)($params['profile_id'] ?? 0),
                    (string)($params['name'] ?? ''),
                    (string)($params['endpoint'] ?? ''),
                    (string)($params['api_key'] ?? ''),
                    (string)($params['model'] ?? ''),
                    (string)($params['reasoning'] ?? 'medium'),
                    (int)($params['context_window'] ?? 240000)
                );

                $response = array('ok' => true, 'profile_id' => $profileId, 'profiles' => LlmProfileService::list());
            } catch (\InvalidArgumentException $e) {
                $response = array('ok' => false, 'error' => $e->getMessage());
            } catch (\Throwable $e) {
                Logger::error('LLM profile save: ' . $e->getMessage());
                $response = array('ok' => false, 'error' => 'Не удалось сохранить подключение: ' . $e->getMessage());
            }
            break;

        case 'llm_profile_activate':
            try {
                LlmProfileService::activate((int)($params['profile_id'] ?? 0));
                $response = array('ok' => true, 'profiles' => LlmProfileService::list());
            } catch (\InvalidArgumentException $e) {
                $response = array('ok' => false, 'error' => $e->getMessage());
            }
            break;

        case 'llm_profile_delete':
            if (($params['confirm'] ?? '') !== 'Y') {
                throw new \InvalidArgumentException('Требуется подтверждение: confirm=Y.');
            }
            LlmProfileService::remove((int)($params['profile_id'] ?? 0));
            $response = array('ok' => true, 'profiles' => LlmProfileService::list());
            break;

        case 'llm_profile_list':
            $response = array('ok' => true, 'profiles' => LlmProfileService::list());
            break;

        case 'llm_models':
            try {
                // Endpoint и ключ можно передать до сохранения — подтягиваем модели «на лету».
                $models = (new LlmClient(
                    (string)($params['endpoint'] ?? ''),
                    (string)($params['api_key'] ?? '')
                ))->listModels();

                $response = array(
                    'ok' => true,
                    'count' => count($models),
                    'models' => $models,
                );
            } catch (\RuntimeException $e) {
                $response = array('ok' => false, 'error' => $e->getMessage());
            }
            break;

        case 'test_llm':
            try {
                if (!Config::isLlmConfigured()) {
                    throw new \RuntimeException('Модель не подключена: укажите endpoint и название модели и сохраните настройки.');
                }
                $models = (new LlmClient())->listModels();
                $response = array(
                    'ok' => true,
                    'count' => count($models),
                    'models' => array_slice($models, 0, 10),
                );
            } catch (\RuntimeException $e) {
                // Сообщения LlmClient безопасны (без ключа) и понятны владельцу.
                $response = array('ok' => false, 'error' => $e->getMessage());
            }
            break;

        case 'index_batch':
            $limit = (int)($params['limit'] ?? 10);
            $limit = max(1, min(20, $limit));
            $reindex = (string)($params['reindex'] ?? '') === 'Y';
            $response = array('ok' => true) + IndexService::runBatch($limit, $reindex);
            break;

        case 'index_status':
            $response = array('ok' => true, 'status' => IndexService::status());
            break;

        case 'index_start':
            IndexService::startReindex();
            $response = array('ok' => true, 'status' => IndexService::status());
            break;

        case 'index_clear':
            if (($params['confirm'] ?? '') !== 'Y') {
                throw new \InvalidArgumentException('Требуется подтверждение: confirm=Y.');
            }
            IndexService::clear();
            $response = array('ok' => true);
            break;

        case 'test_embedding':
            try {
                $response = array('ok' => true, 'test' => (new EmbeddingClient())->test());
            } catch (\RuntimeException $e) {
                // Сообщения EmbeddingClient безопасны и понятны владельцу.
                $response = array('ok' => false, 'error' => $e->getMessage());
            }
            break;

        case 'backup_create':
            try {
                $response = array('ok' => true) + BackupService::create();
            } catch (\RuntimeException $e) {
                Logger::error('Backup create: ' . $e->getMessage());
                $response = array('ok' => false, 'error' => $e->getMessage());
            }
            break;

        case 'backup_list':
            $response = array(
                'ok' => true,
                'backups' => BackupService::list(),
                'status' => BackupService::status(),
            );
            break;

        case 'schedule_list':
            $response = array('ok' => true, 'schedules' => ScheduleService::list($userId));
            break;

        case 'schedule_info':
            $schedule = ScheduleService::get((int)($params['schedule_id'] ?? 0));

            // Чужие задачи не отдаём: в чате задачи видна её переписка.
            if ($schedule === null || $schedule['user_id'] !== $userId) {
                $response = array('ok' => false, 'error' => 'Задача не найдена.');
                break;
            }

            $response = array('ok' => true, 'schedule' => $schedule);
            break;

        case 'schedule_toggle':
            $scheduleId = (int)($params['schedule_id'] ?? 0);
            $schedule = ScheduleService::get($scheduleId);

            if ($schedule === null || (int)$schedule['user_id'] !== $userId) {
                $response = array('ok' => false, 'error' => 'Задача не найдена.');
                break;
            }

            ScheduleService::update($scheduleId, array('active' => !$schedule['active']));
            $response = array('ok' => true, 'schedules' => ScheduleService::list($userId));
            break;

        case 'schedule_delete':
            if (($params['confirm'] ?? '') !== 'Y') {
                throw new \InvalidArgumentException('Требуется подтверждение: confirm=Y.');
            }

            $deleteScheduleId = (int)($params['schedule_id'] ?? 0);
            $deleteSchedule = ScheduleService::get($deleteScheduleId);
            if ($deleteSchedule === null || (int)$deleteSchedule['user_id'] !== $userId) {
                $response = array('ok' => false, 'error' => 'Задача не найдена.');
                break;
            }

            ScheduleService::delete($deleteScheduleId);
            $response = array('ok' => true, 'schedules' => ScheduleService::list($userId));
            break;

        case 'backup_delete':
            if (($params['confirm'] ?? '') !== 'Y') {
                throw new \InvalidArgumentException('Требуется подтверждение: confirm=Y.');
            }
            try {
                BackupService::delete((string)($params['file'] ?? ''));
                $response = array('ok' => true);
            } catch (\RuntimeException $e) {
                Logger::error('Backup delete: ' . $e->getMessage());
                $response = array('ok' => false, 'error' => $e->getMessage());
            }
            break;

        default:
            $response = array('ok' => false, 'error' => 'Неизвестное действие.');
    }
} catch (\InvalidArgumentException $e) {
    $response = array('ok' => false, 'error' => $e->getMessage());
} catch (\Throwable $e) {
    Logger::error('BXADMIN-S AJAX: ' . $e->getMessage(), array(
        'action' => (string)($_REQUEST['action'] ?? ''),
        'user_id' => (int)($USER ? $USER->GetID() : 0),
    ));
    $response = array('ok' => false, 'error' => 'Внутренняя ошибка. Подробности — в журнале модуля.');
}

echo Json::encode($response);
