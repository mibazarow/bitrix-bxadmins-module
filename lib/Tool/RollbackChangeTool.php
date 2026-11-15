<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;
use Mibazarow\Bxadmins\Model\ChangeTable;
use Mibazarow\Bxadmins\Service\ChangeService;

/**
 * Откат выполненного изменения по его ID: файл возвращается из снапшота,
 * данные — через обработчик инструмента.
 */
class RollbackChangeTool extends AbstractChangeTool
{
    public const DOMAIN = 'core';

    public static function getName(): string
    {
        return 'rollback_change';
    }

    public static function needsSqlDump(): bool
    {
        // Откат возвращает прежнее состояние — отдельный дамп перед ним не нужен.
        return false;
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'rollback_change',
            'description' => 'Откатывает выполненное изменение по change_id (см. list_changes). Файлы возвращаются из снапшота, данные — через обработчик изменения.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'change_id' => array('type' => 'integer', 'description' => 'ID изменения из list_changes'),
                ),
                'required' => array('change_id'),
            ),
        );
    }

    public static function preview(array $args, array $context = array()): array
    {
        $changeId = (int)($args['change_id'] ?? 0);
        if ($changeId <= 0) {
            throw new \InvalidArgumentException('Параметр change_id обязателен.');
        }

        if (!Loader::includeModule('mibazarow.bxadmins')) {
            throw new \RuntimeException('Модуль недоступен.');
        }

        $change = ChangeTable::getById($changeId)->fetch();
        if (!$change) {
            throw new \InvalidArgumentException('Изменение #' . $changeId . ' не найдено.');
        }

        if ((string)$change['STATUS'] !== ChangeService::STATUS_EXECUTED) {
            throw new \InvalidArgumentException(
                'Откатить можно только выполненное изменение. У #' . $changeId
                . ' статус «' . (string)$change['STATUS'] . '».'
            );
        }

        return array(
            'summary' => 'Откат изменения #' . $changeId . ': ' . (string)$change['SUMMARY'],
            'payload' => array('args' => array('change_id' => $changeId)),
            'preview' => 'Будет отменено изменение #' . $changeId . ' — ' . (string)$change['SUMMARY']
                . ' (инструмент ' . (string)$change['TOOL'] . ', выполнено ' . (string)$change['CREATED_AT'] . ').'
                . ' Файлы вернутся из снапшота, данные — к прежним значениям.',
        );
    }

    public static function apply(array $payload): array
    {
        $changeId = (int)($payload['args']['change_id'] ?? 0);
        // Действующий пользователь приходит из контекста (payload['user_id']),
        // а не из аргументов инструмента.
        $userId = (int)($payload['user_id'] ?? 0);

        $result = ChangeService::rollback($changeId, $userId);

        if (empty($result['ok'])) {
            throw new \RuntimeException((string)($result['error'] ?? 'Не удалось откатить изменение.'));
        }

        return array('change_id' => $changeId, 'status' => (string)$result['status']);
    }

    public static function rollback(array $payload): void
    {
        throw new \RuntimeException('Повторный откат отменённого изменения не выполняется.');
    }
}
