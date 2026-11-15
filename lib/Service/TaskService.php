<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\Type\DateTime;
use Mibazarow\Bxadmins\Model\ChatTable;
use Mibazarow\Bxadmins\Model\TaskTable;

/**
 * Очередь задач агента. Задачи выполняет Node-демон, здесь — состояние и
 * атомарный захват, чтобы две копии демона не взяли одну задачу.
 */
class TaskService
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /** Одновременно у пользователя может идти не больше десяти задач. */
    public const USER_TASK_LIMIT = 10;

    /**
     * Ставит задачу в очередь. Если в чате уже есть активная задача,
     * новая встаёт следом (внутри чата строго последовательно).
     *
     * @return array{task_id:int,chat_id:int}
     */
    public static function enqueue(
        int $chatId,
        int $userId,
        string $prompt,
        bool $bypass = false,
        string $attachments = ''
    ): array
    {
        $chat = ChatTable::getById($chatId)->fetch();
        if (!$chat || (int)$chat['USER_ID'] !== $userId) {
            throw new \RuntimeException('Чат не найден или недоступен.');
        }

        if (self::activeCount($userId) >= self::USER_TASK_LIMIT) {
            throw new \InvalidArgumentException(
                'Достигнут лимит одновременных задач (' . self::USER_TASK_LIMIT . '). Дождитесь завершения текущих.'
            );
        }

        $result = TaskTable::add(array(
            'CHAT_ID' => $chatId,
            'USER_ID' => $userId,
            'STATUS' => self::STATUS_QUEUED,
            'PROMPT' => $prompt,
            'BYPASS' => $bypass ? 'Y' : 'N',
            'CANCEL' => 'N',
            'ATTACHMENTS' => $attachments !== '' ? $attachments : null,
            'CREATED_AT' => new DateTime(),
        ));

        if (!$result->isSuccess()) {
            throw new \RuntimeException('Не удалось поставить задачу в очередь.');
        }

        return array('task_id' => (int)$result->getId(), 'chat_id' => $chatId);
    }

    /**
     * Атомарно забирает следующую задачу: внутри чата — только одна активная.
     * SELECT кандидата + UPDATE с проверкой STATUS защищает от гонки двух демонов.
     *
     * @return array|null
     */
    public static function claim(): ?array
    {
        $connection = \Bitrix\Main\Application::getConnection();
        $table = TaskTable::getTableName();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = $connection->query(
                "SELECT t.ID FROM {$table} t
                  WHERE t.STATUS = 'queued'
                    AND t.CANCEL = 'N'
                    AND NOT EXISTS (
                        SELECT 1 FROM (SELECT CHAT_ID FROM {$table} WHERE STATUS = 'running') r
                         WHERE r.CHAT_ID = t.CHAT_ID
                    )
                  ORDER BY t.ID ASC
                  LIMIT 1"
            )->fetch();

            if (!$candidate) {
                return null;
            }

            $id = (int)$candidate['ID'];
            $connection->queryExecute(
                "UPDATE {$table} SET STATUS = 'running', STARTED_AT = NOW()
                  WHERE ID = {$id} AND STATUS = 'queued'"
            );

            if ($connection->getAffectedRowsCount() === 1) {
                $row = TaskTable::getById($id)->fetch();

                return $row ?: null;
            }
        }

        return null;
    }

    /** Активные задачи пользователя (в очереди или в работе). */
    private static function activeCount(int $userId): int
    {
        return TaskTable::getCount(array(
            '=USER_ID' => $userId,
            '@STATUS' => array(self::STATUS_QUEUED, self::STATUS_RUNNING),
        ));
    }

    /** Запрошена ли отмена задачи. */
    public static function isCancelled(int $taskId): bool
    {
        $row = TaskTable::getById($taskId)->fetch();

        return $row && (string)$row['CANCEL'] === 'Y';
    }

    public static function finish(int $taskId, string $status, string $result = '', string $error = ''): void
    {
        TaskTable::update($taskId, array(
            'STATUS' => $status,
            'RESULT' => $result !== '' ? mb_substr($result, 0, 20000) : null,
            'ERROR' => $error !== '' ? mb_substr($error, 0, 4000) : null,
            'FINISHED_AT' => new DateTime(),
        ));
    }

    /**
     * Активные задачи чата — для индикатора и очереди в интерфейсе.
     *
     * Ключи отдаём в нижнем регистре: ответ уходит в браузер, а остальные
     * ответы API там тоже в нижнем регистре.
     *
     * @return array<int,array{id:int,status:string,prompt:string,result:string,created_at:mixed}>
     */
    public static function activeForChat(int $chatId): array
    {
        $rows = TaskTable::getList(array(
            // RESULT у активной задачи — черновик ответа: так браузер показывает
            // текст по мере генерации, пока SSE-события не доходят.
            'select' => array('ID', 'STATUS', 'CANCEL', 'RESULT', 'PROMPT', 'ATTACHMENTS', 'CREATED_AT'),
            'filter' => array('=CHAT_ID' => $chatId, '@STATUS' => array(self::STATUS_QUEUED, self::STATUS_RUNNING)),
            'order' => array('ID' => 'ASC'),
        ))->fetchAll();

        return array_map(static function (array $row): array {
            return array(
                'id' => (int)$row['ID'],
                'status' => (string)$row['STATUS'],
                'prompt' => (string)($row['PROMPT'] ?? ''),
                'result' => (string)($row['RESULT'] ?? ''),
                'attachments' => json_decode((string)($row['ATTACHMENTS'] ?? ''), true) ?: array(),
                'created_at' => $row['CREATED_AT'] ?? null,
            );
        }, $rows);
    }

    /** Черновик ответа модели: перезаписывается по ходу генерации. */
    public static function setProgress(int $taskId, string $text): void
    {
        if ($taskId <= 0) {
            return;
        }

        TaskTable::update($taskId, array('RESULT' => mb_substr($text, 0, 60000)));
    }

    /** Задачи, зависшие в работе (демон упал) — снимаем блокировку чата. */
    public static function releaseStale(int $minutes = 15): int
    {
        $threshold = DateTime::createFromPhp(new \DateTime('-' . max(1, $minutes) . ' minutes'));

        $rows = TaskTable::getList(array(
            'select' => array('ID'),
            'filter' => array('=STATUS' => self::STATUS_RUNNING, '<STARTED_AT' => $threshold),
            'limit' => 200,
        ))->fetchAll();

        foreach ($rows as $row) {
            self::finish((int)$row['ID'], self::STATUS_FAILED, '', 'Задача прервана: демон не отвечает.');
        }

        return count($rows);
    }

    /**
     * Отменяет задачу: ещё не начавшуюся — удаляет из очереди, идущую — просит
     * остановиться после текущего шага.
     *
     * @return array{deleted:bool,cancelled:bool}
     */
    public static function cancel(int $taskId, int $userId): array
    {
        $row = TaskTable::getById($taskId)->fetch();
        if (!$row || (int)$row['USER_ID'] !== $userId) {
            throw new \RuntimeException('Задача не найдена или недоступна.');
        }

        $status = (string)$row['STATUS'];

        if ($status === self::STATUS_QUEUED) {
            $meta = json_decode((string)($row['ATTACHMENTS'] ?? ''), true);
            if (is_array($meta)) {
                AttachmentService::deleteFiles($meta);
            }

            TaskTable::delete($taskId);

            return array('deleted' => true, 'cancelled' => false);
        }

        if ($status === self::STATUS_RUNNING) {
            TaskTable::update($taskId, array('CANCEL' => 'Y'));

            return array('deleted' => false, 'cancelled' => true);
        }

        return array('deleted' => false, 'cancelled' => false);
    }
}
