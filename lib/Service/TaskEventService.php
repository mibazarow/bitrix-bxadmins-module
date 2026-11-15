<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;
use Mibazarow\Bxadmins\Model\TaskEventTable;
use Mibazarow\Bxadmins\Model\TaskTable;

/**
 * Журнал событий задачи: замена Push&Pull.
 *
 * События пишутся в БД, браузер читает их через SSE (admin/stream.php).
 * Журнал заодно даёт replay после обрыва связи и перезагрузки вкладки.
 */
class TaskEventService
{
    /** События завершённой задачи храним недолго — только для переподключения. */
    private const FINISHED_TTL_MINUTES = 15;

    /** На случай задачи, которая так и не завершилась. */
    private const MAX_AGE_DAYS = 1;

    /**
     * @param array{task_id?:int,chat_id?:int,type?:string} $params
     */
    public static function append(int $userId, array $params): void
    {
        $taskId = (int)($params['task_id'] ?? 0);
        $chatId = (int)($params['chat_id'] ?? 0);
        $type = (string)($params['type'] ?? '');

        if ($userId <= 0 || $taskId <= 0 || $chatId <= 0 || $type === '') {
            throw new \InvalidArgumentException('Событие задачи без user_id/task_id/chat_id/type.');
        }

        TaskEventTable::add(array(
            'USER_ID' => $userId,
            'TASK_ID' => $taskId,
            'CHAT_ID' => $chatId,
            'TYPE' => $type,
            'PAYLOAD' => Json::encode($params),
            'CREATED_AT' => new DateTime(),
        ));
    }

    /**
     * События пользователя после курсора. Порядок — по возрастанию ID.
     *
     * @return array<int,array{id:int,payload:string}>
     */
    public static function since(int $userId, int $afterId, int $limit = 200): array
    {
        $rows = TaskEventTable::getList(array(
            'select' => array('ID', 'PAYLOAD'),
            'filter' => array(
                '=USER_ID' => $userId,
                '>ID' => $afterId,
            ),
            'order' => array('ID' => 'ASC'),
            'limit' => max(1, min(500, $limit)),
        ))->fetchAll();

        return array_map(static function (array $row): array {
            return array(
                'id' => (int)$row['ID'],
                'payload' => (string)$row['PAYLOAD'],
            );
        }, $rows);
    }

    /** Последний ID пользователя: с него начинаем, если клиент пришёл без курсора. */
    public static function latestId(int $userId): int
    {
        $row = TaskEventTable::getList(array(
            'select' => array('ID'),
            'filter' => array('=USER_ID' => $userId),
            'order' => array('ID' => 'DESC'),
            'limit' => 1,
        ))->fetch();

        return $row ? (int)$row['ID'] : 0;
    }

    /**
     * Удаляет события завершённых задач и всё, что старше суток.
     * Вызывается из RetentionService (суточный агент).
     */
    public static function prune(): int
    {
        $deleted = 0;

        $finishedBefore = DateTime::createFromPhp(
            new \DateTime('-' . self::FINISHED_TTL_MINUTES . ' minutes')
        );

        $taskIds = array_column(TaskTable::getList(array(
            'select' => array('ID'),
            'filter' => array(
                '@STATUS' => array(
                    TaskService::STATUS_DONE,
                    TaskService::STATUS_FAILED,
                    TaskService::STATUS_CANCELLED,
                ),
                '<FINISHED_AT' => $finishedBefore,
            ),
            'limit' => 500,
        ))->fetchAll(), 'ID');

        $taskIds = array_values(array_filter(array_map('intval', $taskIds)));
        if ($taskIds !== array()) {
            $deleted += (int)TaskEventTable::getCount(array('@TASK_ID' => $taskIds));
            TaskEventTable::deleteByFilter(array('@TASK_ID' => $taskIds));
        }

        $oldBefore = DateTime::createFromPhp(new \DateTime('-' . self::MAX_AGE_DAYS . ' days'));
        $deleted += (int)TaskEventTable::getCount(array('<CREATED_AT' => $oldBefore));
        TaskEventTable::deleteByFilter(array('<CREATED_AT' => $oldBefore));

        return $deleted;
    }
}
