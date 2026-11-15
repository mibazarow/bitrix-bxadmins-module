<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\BackupService;

/**
 * Восстановление базы из SQL-дампа. Самая опасная операция модуля:
 * текущие данные заменяются содержимым архива.
 */
class RestoreBackupTool extends AbstractChangeTool
{
    public const DOMAIN = 'security';

    public static function getName(): string
    {
        return 'restore_backup';
    }

    public static function needsSqlDump(): bool
    {
        // BackupService::restore сам делает страховочный дамп текущего состояния.
        return false;
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'restore_backup',
            'description' => 'Восстанавливает базу из SQL-дампа (см. list_backups). Заменяет ВСЕ текущие данные содержимым архива. Перед восстановлением автоматически создаётся страховочный дамп.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'file' => array('type' => 'string', 'description' => 'Имя архива из list_backups, например bazarow_29-10-2026_15-34.sql.zip'),
                    'confirm_replace' => array(
                        'type' => 'boolean',
                        'description' => 'Подтверждение замены данных; без true операция отклоняется',
                    ),
                ),
                'required' => array('file', 'confirm_replace'),
            ),
        );
    }

    public static function preview(array $args, array $context = array()): array
    {
        $file = basename(trim((string)($args['file'] ?? '')));

        if ($file === '' || !str_ends_with($file, '.sql.zip')) {
            throw new \InvalidArgumentException('Укажите имя архива .sql.zip из списка бэкапов.');
        }

        if (($args['confirm_replace'] ?? false) !== true) {
            throw new \InvalidArgumentException(
                'Восстановление заменит все текущие данные сайта. Передайте confirm_replace=true, если это осознанное решение.'
            );
        }

        $found = null;
        foreach (BackupService::list() as $item) {
            if ($item['file'] === $file) {
                $found = $item;
                break;
            }
        }

        if ($found === null) {
            throw new \InvalidArgumentException('Архив ' . $file . ' не найден в каталоге бэкапов.');
        }

        $date = date('Y-m-d H:i:s', (int)$found['mtime']);
        $size = round(((int)$found['size']) / 1048576, 2);

        return array(
            'summary' => 'Восстановление базы из ' . $file,
            'payload' => array('args' => array('file' => $file)),
            'preview' => 'База будет восстановлена из архива ' . $file . ' (' . $size . ' МБ, создан ' . $date . ').'
                . "\n\nВСЕ данные, появившиеся после этой даты, будут потеряны: товары, заказы, "
                . 'страницы, настройки, пользователи.'
                . "\nПеред восстановлением автоматически создаётся страховочный дамп текущего состояния — "
                . 'из него можно будет вернуться назад.',
        );
    }

    public static function apply(array $payload): array
    {
        $file = (string)($payload['args']['file'] ?? '');

        return BackupService::restore($file);
    }

    public static function rollback(array $payload): void
    {
        $safety = (string)($payload['result']['safety_backup'] ?? '');

        if ($safety === '') {
            throw new \RuntimeException('Страховочный дамп не найден — откат восстановления невозможен.');
        }

        BackupService::restore($safety);
    }
}
