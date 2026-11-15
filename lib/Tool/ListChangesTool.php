<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\ChangeService;

/**
 * История изменений сайта. Нужна и для отчёта владельцу, и для отката:
 * агент находит ID нужного изменения и вызывает rollback_change.
 */
class ListChangesTool extends AbstractTool
{
    public const DOMAIN = 'core';

    public static function getName(): string
    {
        return 'list_changes';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_changes',
            'description' => 'История изменений сайта: что меняли, когда, чем закончилось, можно ли откатить. Для отката одного изменения возьми change_id и вызови rollback_change; чтобы вернуть весь сайт к состоянию перед изменением — restore_site.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'limit' => array('type' => 'integer', 'description' => 'Сколько записей показать, по умолчанию 20, максимум 100'),
                    'status' => array(
                        'type' => 'string',
                        'description' => 'Только с этим статусом: pending, executed, rejected, failed, rolled_back',
                    ),
                ),
            ),
        );
    }

    /** @return array{count:int,changes:array}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        $limit = (int)($args['limit'] ?? 20);
        $status = trim((string)($args['status'] ?? ''));

        $changes = array();

        foreach (ChangeService::recent($limit) as $row) {
            if ($status !== '' && $row['status'] !== $status) {
                continue;
            }

            $changes[] = array(
                'change_id' => (int)$row['id'],
                'tool' => (string)$row['tool'],
                'summary' => (string)$row['summary'],
                'status' => (string)$row['status'],
                'created_at' => (string)$row['created_at'],
                // Откатить можно только выполненное изменение.
                'can_rollback' => (string)$row['status'] === ChangeService::STATUS_EXECUTED,
                // Точка отката сайта: git-коммит и/или SQL-дамп сохранены вместе с изменением.
                'can_restore_site' => (string)$row['status'] === ChangeService::STATUS_EXECUTED
                    && (!empty($row['result']['point']) || (string)$row['backup_file'] !== ''),
            );
        }

        return array('count' => count($changes), 'changes' => $changes);
    }
}
