<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\Type\DateTime;
use Mibazarow\Bxadmins\Model\ChatTable;
use Mibazarow\Bxadmins\Model\ScheduleTable;

/**
 * Задачи по расписанию: реестр, интервалы, запуск по времени.
 *
 * Задача не выполняет код сама — она ставит в очередь обычный запрос к агенту
 * в своём чате. Поэтому всё, что делает расписание, проходит через те же
 * подтверждения, точки отката и историю, что и ручная просьба.
 */
final class ScheduleService
{
    /** Минимальный интервал: чаще раза в 5 минут ставить бессмысленно. */
    public const MIN_INTERVAL = 300;

    /** Максимальный интервал: месяц. */
    public const MAX_INTERVAL = 2592000;

    /** @return array<int,array> */
    public static function list(int $userId = 0): array
    {
        $filter = $userId > 0 ? array('=USER_ID' => $userId) : array();

        $rows = ScheduleTable::getList(array(
            'select' => array(
                'ID', 'CHAT_ID', 'USER_ID', 'TITLE', 'PROMPT', 'INTERVAL', 'ACTIVE',
                'NEXT_RUN', 'LAST_RUN', 'LAST_STATUS', 'LAST_RESULT', 'RUNS',
            ),
            'filter' => $filter,
            'order' => array('ACTIVE' => 'DESC', 'ID' => 'DESC'),
            'limit' => 200,
        ))->fetchAll();

        return array_map(static fn (array $row): array => self::normalize($row), $rows);
    }

    public static function get(int $id): ?array
    {
        $row = $id > 0 ? ScheduleTable::getById($id)->fetch() : false;

        return $row ? self::normalize($row) : null;
    }

    /**
     * Создаёт задачу и отдельный чат для её запусков.
     *
     * @return array{id:int,chat_id:int}
     */
    public static function create(int $userId, string $title, string $prompt, int $interval): array
    {
        $title = trim($title);
        $prompt = trim($prompt);

        if ($title === '' || $prompt === '') {
            throw new \InvalidArgumentException('Нужны название и текст задачи.');
        }

        $interval = self::normalizeInterval($interval);

        // Свой чат: история запусков не смешивается с обычной перепиской.
        $chatId = ChatService::createChat($userId, mb_substr($title, 0, 60));

        $result = ScheduleTable::add(array(
            'CHAT_ID' => $chatId,
            'USER_ID' => $userId,
            'TITLE' => mb_substr($title, 0, 255),
            'PROMPT' => $prompt,
            'INTERVAL' => $interval,
            'ACTIVE' => 'Y',
            'NEXT_RUN' => self::nextRun($interval),
            'RUNS' => 0,
            'CREATED_AT' => new DateTime(),
            'UPDATED_AT' => new DateTime(),
        ));

        if (!$result->isSuccess()) {
            throw new \RuntimeException('Не удалось создать задачу по расписанию.');
        }

        return array('id' => (int)$result->getId(), 'chat_id' => $chatId);
    }

    /**
     * @param array{title?:string,prompt?:string,interval?:int,active?:bool} $fields
     */
    public static function update(int $id, array $fields): void
    {
        $schedule = ScheduleTable::getById($id)->fetch();
        if (!$schedule) {
            throw new \InvalidArgumentException('Задача #' . $id . ' не найдена.');
        }

        $update = array('UPDATED_AT' => new DateTime());

        if (isset($fields['title'])) {
            $title = trim((string)$fields['title']);
            if ($title !== '') {
                $update['TITLE'] = mb_substr($title, 0, 255);
            }
        }

        if (isset($fields['prompt'])) {
            $prompt = trim((string)$fields['prompt']);
            if ($prompt !== '') {
                $update['PROMPT'] = $prompt;
            }
        }

        if (isset($fields['interval'])) {
            $interval = self::normalizeInterval((int)$fields['interval']);
            $update['INTERVAL'] = $interval;
            // Новый интервал отсчитывается от текущего момента: иначе задача
            // может запуститься сразу после правки.
            $update['NEXT_RUN'] = self::nextRun($interval);
        }

        if (array_key_exists('active', $fields)) {
            $active = (bool)$fields['active'];
            $update['ACTIVE'] = $active ? 'Y' : 'N';

            if ($active) {
                // Включили — считаем от сейчас, а не от просроченной даты.
                $update['NEXT_RUN'] = self::nextRun((int)$schedule['INTERVAL']);
            }
        }

        ScheduleTable::update($id, $update);
    }

    /**
     * Удаляет задачу. По умолчанию вместе с её чатом: осиротевший чат
     * (история запусков без самой задачи) только путает.
     *
     * @param bool $withChat false — оставить чат (используется при удалении чата,
     *                       который уже удаляется вызывающим кодом)
     */
    public static function delete(int $id, bool $withChat = true): void
    {
        $schedule = ScheduleTable::getById($id)->fetch();

        ScheduleTable::delete($id);

        if (!$withChat || !$schedule) {
            return;
        }

        $chatId = (int)$schedule['CHAT_ID'];
        if ($chatId > 0) {
            self::deleteChatData($chatId);
        }
    }

    /** Чат задачи по расписанию; null, если чат обычный. */
    public static function forChat(int $chatId): ?array
    {
        $row = $chatId > 0 ? ScheduleTable::getList(array(
            'select' => array('ID', 'CHAT_ID', 'USER_ID', 'TITLE', 'PROMPT', 'INTERVAL', 'ACTIVE'),
            'filter' => array('=CHAT_ID' => $chatId),
            'limit' => 1,
        ))->fetch() : false;

        return $row ? self::normalize($row) : null;
    }

    /** Удаление чата со всем содержимым. */
    public static function deleteChatData(int $chatId): void
    {
        // Вложения сообщений лежат вне docroot — удаляем вместе с чатом.
        $attachments = array();
        foreach (\Mibazarow\Bxadmins\Model\MessageTable::getList(array(
            'select' => array('ATTACHMENTS'),
            'filter' => array('=CHAT_ID' => $chatId),
        ))->fetchAll() as $row) {
            $decoded = json_decode((string)($row['ATTACHMENTS'] ?? ''), true);
            if (is_array($decoded)) {
                $attachments = array_merge($attachments, $decoded);
            }
        }
        // Работающую задачу просим остановиться, её файлы тоже наши.
        foreach (\Mibazarow\Bxadmins\Model\TaskTable::getList(array(
            'select' => array('ID', 'STATUS', 'ATTACHMENTS'),
            'filter' => array('=CHAT_ID' => $chatId),
        ))->fetchAll() as $task) {
            $decoded = json_decode((string)($task['ATTACHMENTS'] ?? ''), true);
            if (is_array($decoded)) {
                $attachments = array_merge($attachments, $decoded);
            }
            if ((string)$task['STATUS'] === \Mibazarow\Bxadmins\Service\TaskService::STATUS_RUNNING) {
                \Mibazarow\Bxadmins\Model\TaskTable::update((int)$task['ID'], array('CANCEL' => 'Y'));
            }
        }

        \Mibazarow\Bxadmins\Service\AttachmentService::deleteFiles($attachments);

        \Mibazarow\Bxadmins\Model\MessageTable::deleteByFilter(array('=CHAT_ID' => $chatId));
        \Mibazarow\Bxadmins\Model\ChangeTable::deleteByFilter(array('=CHAT_ID' => $chatId));
        \Mibazarow\Bxadmins\Model\TaskTable::deleteByFilter(array('=CHAT_ID' => $chatId));
        \Mibazarow\Bxadmins\Model\TaskEventTable::deleteByFilter(array('=CHAT_ID' => $chatId));
        ChatTable::delete($chatId);
    }

    /** Строка целиком — для отката удаления и правки. */
    public static function raw(int $id): ?array
    {
        $row = $id > 0 ? ScheduleTable::getById($id)->fetch() : false;

        return $row ?: null;
    }

    /**
     * Задачи, которым пора запускаться.
     *
     * @return array<int,array>
     */
    public static function due(int $limit = 5): array
    {
        $rows = ScheduleTable::getList(array(
            'select' => array('ID', 'CHAT_ID', 'USER_ID', 'TITLE', 'PROMPT', 'INTERVAL', 'RUNS'),
            'filter' => array('=ACTIVE' => 'Y', '<=NEXT_RUN' => new DateTime()),
            'order' => array('NEXT_RUN' => 'ASC'),
            'limit' => max(1, $limit),
        ))->fetchAll();

        return $rows;
    }

    /**
     * Ставит запуск в очередь и сдвигает следующее время.
     * Если в чате ещё идёт предыдущий запуск — не дублируем, просто ждём.
     */
    public static function run(array $schedule): bool
    {
        $scheduleId = (int)$schedule['ID'];
        $chatId = (int)$schedule['CHAT_ID'];
        $userId = (int)$schedule['USER_ID'];
        $interval = self::normalizeInterval((int)$schedule['INTERVAL']);

        if (!ChatTable::getById($chatId)->fetch()) {
            // Чат удалили — задача без него бессмысленна, выключаем.
            ScheduleTable::update($scheduleId, array(
                'ACTIVE' => 'N',
                'LAST_STATUS' => 'failed',
                'LAST_RESULT' => 'Чат задачи удалён — расписание выключено.',
                'UPDATED_AT' => new DateTime(),
            ));

            return false;
        }

        $active = TaskService::activeForChat($chatId);
        if ($active !== array()) {
            // Предыдущий запуск ещё идёт: переносим на следующий тик, не копим очередь.
            ScheduleTable::update($scheduleId, array('NEXT_RUN' => self::nextRun($interval)));

            return false;
        }

        $prompt = "Автоматический запуск задачи по расписанию «" . (string)$schedule['TITLE'] . "».\n\n"
            . (string)$schedule['PROMPT'] . "\n\n"
            . 'Выполни задачу и коротко отчитайся: что сделал и что получилось. '
            . 'Если менять ничего не нужно — так и скажи.';

        try {
            TaskService::enqueue($chatId, $userId, $prompt, false);
        } catch (\Throwable $e) {
            Logger::error('Schedule #' . $scheduleId . ': не удалось поставить запуск: ' . $e->getMessage());

            ScheduleTable::update($scheduleId, array(
                'NEXT_RUN' => self::nextRun($interval),
                'LAST_STATUS' => 'failed',
                'LAST_RESULT' => 'Не удалось поставить запуск: ' . mb_substr($e->getMessage(), 0, 300),
                'UPDATED_AT' => new DateTime(),
            ));

            return false;
        }

        ScheduleTable::update($scheduleId, array(
            'NEXT_RUN' => self::nextRun($interval),
            'LAST_RUN' => new DateTime(),
            'LAST_STATUS' => 'queued',
            'RUNS' => (int)$schedule['RUNS'] + 1,
            'UPDATED_AT' => new DateTime(),
        ));

        return true;
    }

    /** Итог запуска: пишется из демона по завершении задачи. */
    public static function markResult(int $scheduleId, string $status, string $result = ''): void
    {
        if ($scheduleId <= 0) {
            return;
        }

        ScheduleTable::update($scheduleId, array(
            'LAST_STATUS' => $status,
            'LAST_RESULT' => mb_substr($result, 0, 2000),
            'UPDATED_AT' => new DateTime(),
        ));
    }

    /**
     * Итог запуска по чату задачи: демон завершает задачу и не знает ID расписания,
     * зато знает чат — по нему и находим запись.
     */
    public static function markResultByChat(int $chatId, string $status, string $result = ''): void
    {
        if ($chatId <= 0) {
            return;
        }

        $row = ScheduleTable::getList(array(
            'select' => array('ID'),
            'filter' => array('=CHAT_ID' => $chatId),
            'limit' => 1,
        ))->fetch();

        if ($row) {
            self::markResult((int)$row['ID'], $status, $result);
        }
    }

    /** Человеческий интервал: «каждые 3 часа», «раз в день». */
    public static function humanInterval(int $seconds): string
    {
        $seconds = self::normalizeInterval($seconds);

        if ($seconds % 604800 === 0) {
            $weeks = intdiv($seconds, 604800);

            return $weeks === 1 ? 'раз в неделю' : 'каждые ' . $weeks . ' нед.';
        }

        if ($seconds % 86400 === 0) {
            $days = intdiv($seconds, 86400);

            return $days === 1 ? 'раз в день' : 'каждые ' . $days . ' дн.';
        }

        if ($seconds % 3600 === 0) {
            $hours = intdiv($seconds, 3600);

            return $hours === 1 ? 'каждый час' : 'каждые ' . $hours . ' ' . self::plural($hours, 'час', 'часа', 'часов');
        }

        $minutes = max(1, intdiv($seconds, 60));

        return $minutes === 1
            ? 'каждую минуту'
            : 'каждые ' . $minutes . ' ' . self::plural($minutes, 'минуту', 'минуты', 'минут');
    }

    /** Разбор интервала из слов: «3 часа», «каждые 30 минут», «раз в день». */
    public static function parseInterval(string $text): ?int
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') {
            return null;
        }

        // Уже число секунд.
        if (ctype_digit($text)) {
            return self::normalizeInterval((int)$text);
        }

        $map = array(
            'минут' => 60, 'мин' => 60,
            'час' => 3600,
            'дн' => 86400, 'день' => 86400, 'сут' => 86400,
            'недел' => 604800,
        );

        if (preg_match('/(\d+)\s*([а-я]+)/u', $text, $match) === 1) {
            $amount = max(1, (int)$match[1]);
            $unit = $match[2];

            foreach ($map as $needle => $seconds) {
                if (str_starts_with($unit, $needle)) {
                    return self::normalizeInterval($amount * $seconds);
                }
            }
        }

        if (str_contains($text, 'час')) {
            return 3600;
        }

        if (str_contains($text, 'день') || str_contains($text, 'дн') || str_contains($text, 'сут')) {
            return 86400;
        }

        return null;
    }

    public static function normalizeInterval(int $seconds): int
    {
        if ($seconds <= 0) {
            return 3600;
        }

        return max(self::MIN_INTERVAL, min(self::MAX_INTERVAL, $seconds));
    }

    /** «через 2 часа», «15 минут назад». */
    public static function humanWhen($date): string
    {
        if ($date === null || $date === '') {
            return '—';
        }

        $timestamp = $date instanceof \Bitrix\Main\Type\Date
            ? $date->getTimestamp()
            : (int)strtotime((string)$date);

        if ($timestamp <= 0) {
            return '—';
        }

        $diff = $timestamp - time();
        $past = $diff < 0;
        $diff = abs($diff);

        if ($diff < 60) {
            $text = 'меньше минуты';
        } elseif ($diff < 3600) {
            $minutes = (int)floor($diff / 60);
            $text = $minutes . ' ' . self::plural($minutes, 'минуту', 'минуты', 'минут');
        } elseif ($diff < 86400) {
            $hours = (int)floor($diff / 3600);
            $text = $hours . ' ' . self::plural($hours, 'час', 'часа', 'часов');
        } else {
            $days = (int)floor($diff / 86400);
            $text = $days . ' ' . self::plural($days, 'день', 'дня', 'дней');
        }

        return $past ? $text . ' назад' : 'через ' . $text;
    }

    /** Статус запуска человеческими словами. */
    public static function statusLabel(string $status): string
    {
        $map = array(
            'queued' => 'в очереди',
            'running' => 'выполняется',
            'done' => 'выполнено',
            'failed' => 'ошибка',
            'cancelled' => 'остановлено',
        );

        return $map[$status] ?? $status;
    }

    private static function nextRun(int $interval): DateTime
    {
        return DateTime::createFromPhp(new \DateTime('+' . $interval . ' seconds'));
    }

    private static function plural(int $number, string $one, string $few, string $many): string
    {
        $mod10 = $number % 10;
        $mod100 = $number % 100;

        if ($mod10 === 1 && $mod100 !== 11) {
            return $one;
        }

        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20)) {
            return $few;
        }

        return $many;
    }

    private static function normalize(array $row): array
    {
        $interval = (int)$row['INTERVAL'];

        return array(
            'id' => (int)$row['ID'],
            'chat_id' => (int)$row['CHAT_ID'],
            'user_id' => (int)$row['USER_ID'],
            'title' => (string)$row['TITLE'],
            'prompt' => (string)$row['PROMPT'],
            'interval' => $interval,
            'interval_text' => self::humanInterval($interval),
            'active' => (string)$row['ACTIVE'] === 'Y',
            'next_run' => self::humanWhen($row['NEXT_RUN'] ?? null),
            'last_run' => self::humanWhen($row['LAST_RUN'] ?? null),
            'last_status' => self::statusLabel((string)($row['LAST_STATUS'] ?? '')),
            'last_result' => (string)($row['LAST_RESULT'] ?? ''),
            'runs' => (int)($row['RUNS'] ?? 0),
        );
    }
}
