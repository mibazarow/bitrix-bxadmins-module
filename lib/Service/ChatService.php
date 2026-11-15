<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\Type\DateTime;
use Mibazarow\Bxadmins\Model\ChangeTable;
use Mibazarow\Bxadmins\Config;
use Mibazarow\Bxadmins\Model\ChatTable;
use Mibazarow\Bxadmins\Model\ScheduleTable;
use Mibazarow\Bxadmins\Model\MessageTable;
use Mibazarow\Bxadmins\Model\TaskEventTable;
use Mibazarow\Bxadmins\Model\TaskTable;

/**
 * Чат с моделью: история, tool calling, сохранение сообщений и изменений.
 */
class ChatService
{
    private const MAX_TEXT = 20000;
    private const TITLE_LENGTH = 60;
    /** Доля контекстного окна, после которой включается сжатие. */
    private const COMPACT_THRESHOLD = 0.85;
    /** Сколько последних сообщений всегда оставляем дословно. */
    private const COMPACT_KEEP = 20;
    private const COMPACT_TRANSCRIPT_LIMIT = 60000;
    private const COMPACT_MESSAGE_LIMIT = 2000;

    /** Создаёт чат; пустой заголовок заменяется на «Новый чат». */
    public static function createChat(int $userId, string $title = ''): int
    {
        $title = trim($title);

        $result = ChatTable::add(array(
            'TITLE' => $title !== '' ? mb_substr($title, 0, 255) : 'Новый чат',
            'USER_ID' => $userId,
            'CREATED_AT' => new DateTime(),
            'UPDATED_AT' => new DateTime(),
        ));

        if (!$result->isSuccess()) {
            throw new \RuntimeException('Не удалось создать чат.');
        }

        return (int)$result->getId();
    }

    /** Чаты пользователя: закреплённые сверху, внутри группы — свежие сначала. */
    public static function listChats(int $userId): array
    {
        $chats = array();

        $rows = ChatTable::getList(array(
            'select' => array('ID', 'TITLE', 'PINNED', 'UPDATED_AT'),
            'filter' => array('=USER_ID' => $userId),
            'order' => array('PINNED' => 'DESC', 'UPDATED_AT' => 'DESC', 'ID' => 'DESC'),
            'limit' => 100,
        ))->fetchAll();

        // Активные задачи по чатам: в списке видно, где сейчас идёт работа.
        $active = array();
        foreach (TaskTable::getList(array(
            'select' => array('CHAT_ID', 'STATUS'),
            'filter' => array('=USER_ID' => $userId, '@STATUS' => array('queued', 'running')),
            'limit' => 500,
        ))->fetchAll() as $task) {
            $chatId = (int)$task['CHAT_ID'];
            $status = (string)$task['STATUS'];

            // «выполняется» важнее «в очереди»: показываем самый активный статус.
            if (!isset($active[$chatId]) || $status === 'running') {
                $active[$chatId] = $status;
            }
        }

        // Чаты задач по расписанию: их полезно отличать от обычных бесед.
        $scheduled = array();
        foreach (ScheduleTable::getList(array(
            'select' => array('CHAT_ID'),
            'filter' => array('=USER_ID' => $userId),
            'limit' => 500,
        ))->fetchAll() as $row) {
            $scheduled[(int)$row['CHAT_ID']] = true;
        }

        foreach ($rows as $row) {
            $chatId = (int)$row['ID'];

            $chats[] = array(
                'id' => $chatId,
                'title' => (string)$row['TITLE'],
                'pinned' => (string)($row['PINNED'] ?? 'N') === 'Y',
                'updated_at' => $row['UPDATED_AT'] instanceof DateTime
                    ? $row['UPDATED_AT']->format('Y-m-d H:i:s')
                    : (string)$row['UPDATED_AT'],
                'busy' => $active[$chatId] ?? '',
                'schedule' => isset($scheduled[$chatId]),
            );
        }

        return $chats;
    }

    /** Переименовывает чат; пустое имя отклоняется. */
    /** Проверка владельца для админских действий, которым нужен только доступ. */
    public static function assertChatOwned(int $chatId, int $userId): void
    {
        self::assertChatOwner($chatId, $userId);
    }

    public static function renameChat(int $chatId, int $userId, string $title): string
    {
        self::assertChatOwner($chatId, $userId);

        $title = trim($title);
        if ($title === '') {
            throw new \InvalidArgumentException('Название чата не может быть пустым.');
        }

        $title = mb_substr($title, 0, 255);

        ChatTable::update($chatId, array(
            'TITLE' => $title,
            'UPDATED_AT' => new DateTime(),
        ));

        return $title;
    }

    /**
     * Удаляет чат вместе с сообщениями и связанными изменениями.
     * Если к чату привязана задача по расписанию — удаляется и она:
     * осиротевшая задача без чата выполняться не сможет.
     *
     * @return array|null удалённая задача (для отчёта в интерфейсе)
     */
    public static function deleteChat(int $chatId, int $userId): ?array
    {
        self::assertChatOwner($chatId, $userId);

        // Сначала задача: её удаление не трогает чат (withChat = false),
        // иначе получим двойное удаление в одном вызове.
        $schedule = ScheduleService::forChat($chatId);
        if ($schedule !== null) {
            ScheduleService::delete((int)$schedule['id'], false);
        }

        $attachments = array();
        foreach (MessageTable::getList(array(
            'select' => array('ATTACHMENTS'),
            'filter' => array('=CHAT_ID' => $chatId),
        ))->fetchAll() as $row) {
            foreach (self::decodeAttachments((string)($row['ATTACHMENTS'] ?? '')) as $meta) {
                $attachments[] = $meta;
            }
        }
        // Вложения ждущих задач тоже наши файлы — их нужно убрать.
        foreach (TaskTable::getList(array(
            'select' => array('ID', 'STATUS', 'ATTACHMENTS'),
            'filter' => array('=CHAT_ID' => $chatId),
        ))->fetchAll() as $task) {
            foreach (self::decodeAttachments((string)($task['ATTACHMENTS'] ?? '')) as $meta) {
                $attachments[] = $meta;
            }

            if ((string)$task['STATUS'] === TaskService::STATUS_RUNNING) {
                TaskTable::update((int)$task['ID'], array('CANCEL' => 'Y'));
            }
        }
        TaskTable::deleteByFilter(array('=CHAT_ID' => $chatId));
        TaskEventTable::deleteByFilter(array('=CHAT_ID' => $chatId));

        AttachmentService::deleteFiles($attachments);

        MessageTable::deleteByFilter(array('=CHAT_ID' => $chatId));
        ChangeTable::deleteByFilter(array('=CHAT_ID' => $chatId));
        ChatTable::delete($chatId);

        return $schedule;
    }

    /** Закрепляет или открепляет чат. */
    public static function setPinned(int $chatId, int $userId, bool $pinned): bool
    {
        self::assertChatOwner($chatId, $userId);

        ChatTable::update($chatId, array('PINNED' => $pinned ? 'Y' : 'N'));

        return $pinned;
    }

    /**
     * @throws \RuntimeException при отсутствии чата или чужом владельце
     */
    public static function getMessages(int $chatId, int $userId): array
    {
        self::assertChatOwner($chatId, $userId);

        $messages = array();

        $rows = MessageTable::getList(array(
            'select' => array('ROLE', 'CONTENT', 'TOOL_NAME', 'TOOL_ARGS', 'IS_ERROR', 'ATTACHMENTS', 'CREATED_AT'),
            'filter' => array('=CHAT_ID' => $chatId),
            'order' => array('ID' => 'ASC'),
            'limit' => 500,
        ))->fetchAll();

        foreach ($rows as $row) {
            $messages[] = array(
                'role' => (string)$row['ROLE'],
                'content' => (string)($row['CONTENT'] ?? ''),
                'tool_name' => (string)($row['TOOL_NAME'] ?? ''),
                'tool_args' => (string)($row['TOOL_ARGS'] ?? ''),
                'is_error' => (string)($row['IS_ERROR'] ?? 'N') === 'Y',
                'attachments' => self::decodeAttachments((string)($row['ATTACHMENTS'] ?? '')),
                'created_at' => $row['CREATED_AT'] instanceof DateTime
                    ? $row['CREATED_AT']->format('Y-m-d H:i:s')
                    : (string)$row['CREATED_AT'],
            );
        }

        return $messages;
    }

    /**
     * Принимает сообщение пользователя и ставит задачу в очередь.
     * Выполняет её Node-демон, поэтому запрос возвращается сразу.
     *
     * @return array{chat_id:int, task_id:int, ok:bool}
     * @throws \InvalidArgumentException на валидации
     */
    public static function send(
        int $userId,
        int $chatId,
        string $text,
        bool $bypass = false,
        array $attachments = array()
    ): array {
        $text = trim($text);
        $attachments = AttachmentService::normalizeList($attachments);

        if ($text === '' && $attachments === array()) {
            throw new \InvalidArgumentException('Сообщение пустое.');
        }

        if (mb_strlen($text) > self::MAX_TEXT) {
            throw new \InvalidArgumentException('Сообщение слишком длинное (максимум ' . self::MAX_TEXT . ' символов).');
        }

        // /skill <name> — explicit skill invocation. The model still loads the
        // body with use_skill, so the prompt stays catalog-only.
        if (preg_match('/^\/skill[ \t]+(.+?)(?:\n([\s\S]*))?$/u', $text, $skillMatch) === 1) {
            $skillName = trim((string)$skillMatch[1]);
            $rest = trim((string)($skillMatch[2] ?? ''));
            $skill = SkillService::find($skillName);

            if ($skill === null) {
                throw new \InvalidArgumentException('Скилл не найден: ' . $skillName);
            }
            if (!$skill['enabled']) {
                throw new \InvalidArgumentException('Скилл «' . $skill['name'] . '» выключен в настройках.');
            }

            $text = 'Apply the skill "' . $skill['name'] . '". Call use_skill("' . $skill['name']
                . '") first and follow the returned instructions.'
                . ($rest !== '' ? "\n\n" . $rest : '');
        }

        if ($chatId > 0) {
            self::assertChatOwner($chatId, $userId);
        } else {
            $titleSource = $text !== '' ? $text : (string)($attachments[0]['name'] ?? 'Файл');
            $chatId = self::createChat($userId, mb_substr($titleSource, 0, self::TITLE_LENGTH));
        }

        // Сообщение попадёт в историю, когда демон возьмёт задачу в работу:
        // до этого оно живёт в очереди и в чате показывается как «в очереди».
        $task = TaskService::enqueue(
            $chatId,
            $userId,
            $text !== '' ? $text : 'Посмотри вложение.',
            $bypass,
            $attachments !== array() ? json_encode($attachments, JSON_UNESCAPED_UNICODE) : ''
        );

        return array('chat_id' => $chatId, 'task_id' => $task['task_id'], 'ok' => true);
    }

    /**
     * Задача взята в работу: сообщение пользователя становится частью истории.
     *
     * @param array $task строка задачи из очереди
     */
    public static function startTask(array $task): void
    {
        $chatId = (int)($task['CHAT_ID'] ?? 0);
        if ($chatId <= 0) {
            return;
        }

        self::addMessage(
            $chatId,
            'user',
            (string)($task['PROMPT'] ?? ''),
            '',
            '',
            '',
            false,
            (string)($task['ATTACHMENTS'] ?? '')
        );

        // Приближаемся к лимиту контекста — сжимаем старую часть диалога в сводку.
        try {
            self::maybeCompact($chatId);
        } catch (\Throwable $e) {
            Logger::error('Компактация чата #' . $chatId . ': ' . $e->getMessage());
        }
    }

    /**
     * Сжимает старую часть диалога в сводку, когда контекст приближается к лимиту.
     * Последние COMPACT_KEEP сообщений всегда остаются дословно.
     */
    private static function maybeCompact(int $chatId): void
    {
        $chat = ChatTable::getById($chatId)->fetch();
        if (!$chat) {
            return;
        }

        $summaryUpTo = (int)($chat['SUMMARY_UP_TO_ID'] ?? 0);
        $filter = array('=CHAT_ID' => $chatId, '!==ROLE' => 'notice');
        if ($summaryUpTo > 0) {
            $filter['>ID'] = $summaryUpTo;
        }

        $rows = MessageTable::getList(array(
            'select' => array('ID', 'ROLE', 'CONTENT', 'TOOL_NAME', 'TOOL_ARGS'),
            'filter' => $filter,
            'order' => array('ID' => 'ASC'),
            'limit' => 1000,
        ))->fetchAll();

        $estimated = self::estimateTokens(self::systemPrompt()) + self::estimateRowsTokens($rows);
        $limit = (int)floor(Config::contextWindow() * self::COMPACT_THRESHOLD);

        if ($estimated < $limit) {
            return;
        }

        if (count($rows) <= self::COMPACT_KEEP) {
            return;
        }

        $older = array_slice($rows, 0, count($rows) - self::COMPACT_KEEP);
        $cutId = (int)end($older)['ID'];

        $transcript = '';
        foreach ($older as $row) {
            $line = self::transcriptLine($row);
            if ($line === '') {
                continue;
            }
            if (mb_strlen($transcript) + mb_strlen($line) > self::COMPACT_TRANSCRIPT_LIMIT) {
                break;
            }
            $transcript .= $line . "\n";
        }

        if (trim($transcript) === '') {
            return;
        }

        $previous = trim((string)($chat['SUMMARY'] ?? ''));
        $loadedSkills = self::loadedSkills($older);
        $prompt = 'Compress the dialogue fragment into a short summary in Russian: what the user asked, '
            . 'what the agent did, and which decisions and facts matter for continuing. No fluff, up to 400 words.' . "\n\n"
            . ($loadedSkills !== array()
                ? 'Skills loaded during this fragment: ' . implode(', ', $loadedSkills)
                    . ". Keep their names in the summary so they can be reloaded with use_skill if needed.\n\n"
                : '')
            . ($previous !== '' ? "Previously compacted:\n" . $previous . "\n\n" : '')
            . "Fragment:\n" . $transcript;

        $message = (new LlmClient())->chat(array(
            array('role' => 'system', 'content' => 'You compress dialogue history without losing facts or decisions.'),
            array('role' => 'user', 'content' => $prompt),
        ));

        $summary = trim((string)($message['content'] ?? ''));
        if ($summary === '') {
            return;
        }

        ChatTable::update($chatId, array(
            'SUMMARY' => mb_substr($summary, 0, 20000),
            'SUMMARY_UP_TO_ID' => $cutId,
        ));

        self::addMessage($chatId, 'notice', 'Контекст сжат: старая часть диалога заменена краткой сводкой.');
    }

    /** @param array<int,array{ROLE?:string,CONTENT?:string,TOOL_NAME?:string,TOOL_ARGS?:string}> $rows */
    private static function loadedSkills(array $rows): array
    {
        $names = array();

        foreach ($rows as $row) {
            if ((string)($row['ROLE'] ?? '') !== 'tool' || (string)($row['TOOL_NAME'] ?? '') !== 'use_skill') {
                continue;
            }

            $decoded = json_decode((string)($row['CONTENT'] ?? ''), true);
            $name = is_array($decoded) ? trim((string)($decoded['name'] ?? '')) : '';
            if ($name !== '') {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    /** @param array<int,array{ROLE?:string,CONTENT?:string,TOOL_ARGS?:string}> $rows */
    private static function estimateRowsTokens(array $rows): int
    {
        $tokens = 0;

        foreach ($rows as $row) {
            $tokens += 4 + self::estimateTokens((string)($row['CONTENT'] ?? ''));
            $tokens += self::estimateTokens((string)($row['TOOL_ARGS'] ?? ''));
        }

        return $tokens;
    }

    /** Оценка токенов по символам: для русского текста ~3 символа на токен. */
    private static function estimateTokens(string $text): int
    {
        return (int)ceil(mb_strlen($text) / 3);
    }

    /** @param array<int,array{ROLE?:string,CONTENT?:string,TOOL_NAME?:string,TOOL_ARGS?:string}> $rows */
    private static function historyLimit(): int
    {
        return max(10, min(200, (int)floor(Config::contextWindow() / 2000)));
    }

    private static function transcriptLine(array $row): string
    {
        $role = (string)($row['ROLE'] ?? '');
        $content = trim((string)($row['CONTENT'] ?? ''));

        if ($role === 'tool') {
            $content = trim((string)($row['TOOL_ARGS'] ?? ''));
            $role = 'tool';
        }

        if ($content === '') {
            return '';
        }

        if (mb_strlen($content) > self::COMPACT_MESSAGE_LIMIT) {
            $content = mb_substr($content, 0, self::COMPACT_MESSAGE_LIMIT) . '…';
        }

        return $role . ': ' . $content;
    }

    /** @return array<int,array> */
    private static function decodeAttachments(string $json): array
    {
        if (trim($json) === '') {
            return array();
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : array();
    }

    /** @param array{origin?:string,bypass?:bool,user_id?:int,chat_id?:int,chat_title?:string} $task */
    private static function systemPrompt(array $task = array()): string
    {
        return SystemPrompt::build($task);
    }

    /**
     * Что известно о текущей задаче: чат или расписание, bypass, автор.
     *
     * @return array{origin:string,bypass:bool,user_id:int,chat_title:string}
     */
    private static function taskContext(int $chatId): array
    {
        $chat = ChatTable::getById($chatId)->fetch();
        $task = TaskTable::getList(array(
            'select' => array('BYPASS'),
            'filter' => array('=CHAT_ID' => $chatId),
            'order' => array('ID' => 'DESC'),
            'limit' => 1,
        ))->fetch();

        return array(
            'origin' => ScheduleTable::getCount(array('=CHAT_ID' => $chatId)) > 0 ? 'schedule' : 'chat',
            'bypass' => (string)($task['BYPASS'] ?? 'N') === 'Y',
            'user_id' => (int)($chat['USER_ID'] ?? 0),
            'chat_id' => $chatId,
            'chat_title' => (string)($chat['TITLE'] ?? ''),
        );
    }

    /**
     * Хронологическая история последних строк в формате OpenAI:
     * assistant-сообщение с tool_calls восстанавливается по следующим за ним tool-строкам.
     */
    private static function buildLlmHistory(int $chatId): array
    {
        $chat = ChatTable::getById($chatId)->fetch();
        $summary = trim((string)($chat['SUMMARY'] ?? ''));
        $summaryUpTo = (int)($chat['SUMMARY_UP_TO_ID'] ?? 0);

        $rows = self::historyRows($chatId, $summaryUpTo);
        $llm = self::summaryMessage($summary);
        $count = count($rows);

        for ($i = 0; $i < $count; $i++) {
            $role = (string)$rows[$i]['ROLE'];

            if ($role === 'user') {
                $llm[] = self::userMessage($rows[$i]);
                continue;
            }

            if ($role === 'status') {
                // Отчёты об изменении/откате должны быть в контексте агента.
                $llm[] = self::statusMessage($rows[$i]);
                continue;
            }

            if ($role === 'assistant') {
                [$messages, $i] = self::assistantMessages($rows, $i, $count);
                foreach ($messages as $message) {
                    $llm[] = $message;
                }
                continue;
            }

            // Осиротевшая tool-строка на границе среза истории — пропускаем.
        }

        return $llm;
    }

    private static function historyRows(int $chatId, int $summaryUpTo): array
    {
        $filter = array('=CHAT_ID' => $chatId, '=IS_ERROR' => 'N', '!==ROLE' => 'notice');
        if ($summaryUpTo > 0) {
            $filter['>ID'] = $summaryUpTo;
        }

        $rows = MessageTable::getList(array(
            'select' => array('ROLE', 'CONTENT', 'TOOL_NAME', 'TOOL_CALL_ID', 'TOOL_ARGS', 'ATTACHMENTS'),
            'filter' => $filter,
            'order' => array('ID' => 'DESC'),
            // The window matters more than a fixed row count: small models keep
            // fewer messages, long-context models keep more.
            'limit' => self::historyLimit(),
        ))->fetchAll();

        return array_reverse($rows);
    }

    private static function summaryMessage(string $summary): array
    {
        if ($summary === '') {
            return array();
        }

        return array(array(
            'role' => 'system',
            'content' => 'Brief summary of the earlier conversation (context was compacted): ' . $summary,
        ));
    }

    private static function userMessage(array $row): array
    {
        $attachments = self::decodeAttachments((string)($row['ATTACHMENTS'] ?? ''));

        return array(
            'role' => 'user',
            'content' => AttachmentService::buildUserContent(
                (string)($row['CONTENT'] ?? ''),
                $attachments
            ),
        );
    }

    private static function statusMessage(array $row): array
    {
        return array(
            'role' => 'assistant',
            'content' => '[Статус изменения] ' . (string)($row['CONTENT'] ?? ''),
        );
    }

    /**
     * Сообщения assistant вместе с идущими сразу за ним tool-строками.
     *
     * @return array{0: array<int, array>, 1: int} Сообщения и индекс последней потреблённой строки.
     */
    private static function assistantMessages(array $rows, int $i, int $count): array
    {
        $content = (string)($rows[$i]['CONTENT'] ?? '');
        $toolCalls = array();
        $toolMessages = array();

        $j = $i + 1;
        while ($j < $count && $rows[$j]['ROLE'] === 'tool') {
            $callId = (string)($rows[$j]['TOOL_CALL_ID'] ?? '');
            $name = (string)($rows[$j]['TOOL_NAME'] ?? '');
            $args = (string)($rows[$j]['TOOL_ARGS'] ?? '');
            if ($args === '') {
                $args = '{}';
            }

            if ($callId !== '') {
                $toolCalls[] = array(
                    'id' => $callId,
                    'type' => 'function',
                    'function' => array('name' => $name, 'arguments' => $args),
                );
                $toolMessages[] = array(
                    'role' => 'tool',
                    'tool_call_id' => $callId,
                    'name' => $name,
                    'content' => (string)($rows[$j]['CONTENT'] ?? ''),
                );
            }
            $j++;
        }

        $messages = array();
        if ($toolCalls !== array()) {
            $messages[] = array('role' => 'assistant', 'content' => $content, 'tool_calls' => $toolCalls);
            foreach ($toolMessages as $toolMessage) {
                $messages[] = $toolMessage;
            }
        } elseif ($content !== '') {
            $messages[] = array('role' => 'assistant', 'content' => $content);
        }

        return array($messages, $j - 1);
    }

    /**
     * @throws \RuntimeException
     */
    private static function assertChatOwner(int $chatId, int $userId): void
    {
        $chat = ChatTable::getById($chatId)->fetch();

        if (!$chat || (int)$chat['USER_ID'] !== $userId) {
            throw new \RuntimeException('Чат не найден или недоступен.');
        }
    }

    /**
     * Контекст для модели: системный промпт + история с учётом сводки.
     *
     * @return array<int,array{role:string,content:string}>
     */
    public static function llmContext(int $chatId): array
    {
        return array_merge(
            array(array('role' => 'system', 'content' => self::systemPrompt(self::taskContext($chatId)))),
            self::buildLlmHistory($chatId)
        );
    }

    /** Публичная запись сообщения — используется демоном и инструментами. */
    public static function appendMessage(
        int $chatId,
        string $role,
        string $content,
        string $toolName = '',
        string $toolCallId = '',
        string $toolArgs = '',
        bool $isError = false,
        string $attachments = ''
    ): void {
        self::addMessage($chatId, $role, $content, $toolName, $toolCallId, $toolArgs, $isError, $attachments);
        self::touchChat($chatId);
    }

    /** Определения инструментов для модели. */
    public static function toolDefinitions(): array
    {
        return ToolRegistry::definitions();
    }

    private static function addMessage(
        int $chatId,
        string $role,
        string $content,
        string $toolName = '',
        string $toolCallId = '',
        string $toolArgs = '',
        bool $isError = false,
        string $attachments = ''
    ): void {
        if ($role === 'user') {
            self::autoTitle($chatId, $content);
        }

        MessageTable::add(array(
            'CHAT_ID' => $chatId,
            'ROLE' => $role,
            'CONTENT' => Utf8Helper::sanitize($content),
            'TOOL_NAME' => $toolName !== '' ? $toolName : null,
            'TOOL_CALL_ID' => $toolCallId !== '' ? $toolCallId : null,
            'TOOL_ARGS' => $toolArgs !== '' ? Utf8Helper::sanitize($toolArgs) : null,
            'IS_ERROR' => $isError ? 'Y' : 'N',
            'ATTACHMENTS' => $attachments !== '' ? Utf8Helper::sanitize($attachments) : null,
            'CREATED_AT' => new DateTime(),
        ));
    }

    /**
     * Короткий заголовок из первой фразы: 3–5 значимых слов, без разметки.
     * Точный заголовок позже придумывает модель — это запасной вариант.
     */
    public static function shortTitle(string $text): string
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', strip_tags($text)));
        $text = trim((string)preg_replace('/[`*_#>|~\[\]()]+/u', ' ', $text));

        if ($text === '') {
            return '';
        }

        // Служебные слова в начале фразы не несут смысла для заголовка.
        $stop = array('пожалуйста', 'привет', 'слушай', 'так', 'ну', 'мне', 'нам', 'надо', 'нужно', 'хочу', 'давай');
        $words = preg_split('/\s+/u', mb_strtolower($text)) ?: array();
        while ($words !== array() && in_array($words[0], $stop, true)) {
            array_shift($words);
        }

        $words = array_slice($words !== array() ? $words : preg_split('/\s+/u', $text), 0, 5);
        $title = trim(implode(' ', $words));
        $title = mb_substr($title, 0, 48);
        $title = rtrim($title, " .,:;!?—-");

        return $title !== '' ? mb_strtoupper(mb_substr($title, 0, 1)) . mb_substr($title, 1) : '';
    }

    /**
     * Материал для генерации короткого заголовка моделью: первое сообщение
     * пользователя. Возвращает null, если заголовок уже придуман человеком
     * или это не первый обмен репликами.
     */
    public static function titleHint(int $chatId): ?string
    {
        $chat = ChatTable::getById($chatId)->fetch();
        if (!$chat) {
            return null;
        }

        $title = trim((string)$chat['TITLE']);
        $first = MessageTable::getList(array(
            'select' => array('CONTENT'),
            'filter' => array('=CHAT_ID' => $chatId, '=ROLE' => 'user'),
            'order' => array('ID' => 'ASC'),
            'limit' => 1,
        ))->fetch();

        if (!$first) {
            return null;
        }

        $firstText = trim((string)$first['CONTENT']);
        if ($firstText === '') {
            return null;
        }

        // Заголовок считаем «нашим», если он пуст, шаблонный или совпадает
        // с эвристикой из первого сообщения.
        $ours = $title === '' || $title === 'Новый чат' || $title === self::shortTitle($firstText);

        return $ours ? mb_substr($firstText, 0, 500) : null;
    }

    /** Заголовок, придуманный моделью: 2–4 слова, без кавычек и точки. */
    public static function setGeneratedTitle(int $chatId, string $title): void
    {
        $title = trim((string)preg_replace('/\s+/u', ' ', strip_tags($title)));
        $title = trim($title, " \t\n\r\0\x0B\"'«».,:;!?—-");

        if ($title === '') {
            return;
        }

        // Слишком длинное — значит, модель не поняла формат: оставляем эвристику.
        if (mb_strlen($title) > 60) {
            return;
        }

        ChatTable::update($chatId, array(
            'TITLE' => $title,
            'UPDATED_AT' => new DateTime(),
        ));
    }

    private static function touchChat(int $chatId): void
    {
        ChatTable::update($chatId, array('UPDATED_AT' => new DateTime()));
    }

    /**
     * Название чата по первому сообщению: иначе список выглядит как набор
     * одинаковых «Новый чат» и в нём невозможно ориентироваться.
     */
    private static function autoTitle(int $chatId, string $firstMessage): void
    {
        $chat = ChatTable::getList(array(
            'select' => array('ID', 'TITLE'),
            'filter' => array('=ID' => $chatId),
            'limit' => 1,
        ))->fetch();

        if (!$chat) {
            return;
        }

        $current = trim((string)$chat['TITLE']);
        $placeholder = $current === '' || $current === 'Новый чат';

        if (!$placeholder) {
            return;
        }

        $text = self::shortTitle($firstMessage);

        if ($text === '') {
            return;
        }

        ChatTable::update($chatId, array(
            'TITLE' => $text,
            'UPDATED_AT' => new DateTime(),
        ));
    }
}
