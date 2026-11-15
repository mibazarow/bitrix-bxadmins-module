<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\BackupService;

/** Создание SQL-дампа базы. */
class CreateBackupTool extends AbstractTool
{
    public const DOMAIN = 'security';

    public static function getName(): string
    {
        return 'create_backup';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'create_backup',
            'description' => 'Создаёт сжатый SQL-дамп базы в bitrix/backup/bx_admin_s_bacaps/ и возвращает имя файла.',
            'parameters' => array(
                'type' => 'object',
                'properties' => new \stdClass(),
            ),
        );
    }

    /** @return array{file:string,size:int}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        try {
            $backup = BackupService::create();

            return array('file' => (string)$backup['file'], 'size' => (int)$backup['size']);
        } catch (\Throwable $e) {
            return array('error' => $e->getMessage());
        }
    }
}
