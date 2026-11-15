<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\BackupService;

/** Список SQL-дампов модуля. */
class ListBackupsTool extends AbstractTool
{
    public const DOMAIN = 'security';

    public static function getName(): string
    {
        return 'list_backups';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_backups',
            'description' => 'Список SQL-дампов в bitrix/backup/bx_admin_s_bacaps/: имя, размер, дата. Свежие сверху.',
            'parameters' => array(
                'type' => 'object',
                'properties' => new \stdClass(),
            ),
        );
    }

    /** @return array{count:int,backups:array} */
    public static function execute(array $args, array $context = array()): array
    {
        $items = BackupService::list();
        $backups = array();

        foreach ($items as $item) {
            $backups[] = array(
                'file' => (string)$item['file'],
                'size_mb' => round(((int)$item['size']) / 1048576, 2),
                'date' => date('Y-m-d H:i:s', (int)$item['mtime']),
            );
        }

        return array('count' => count($backups), 'backups' => $backups);
    }
}
