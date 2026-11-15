<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\CustomModuleService;
use Mibazarow\Bxadmins\Service\ModuleScaffold;
use Mibazarow\Bxadmins\Service\ModuleService;

/**
 * Удаление собственного модуля агента: сначала деинсталляция, затем каталог.
 * Сторонние и системные модули удалять нельзя — только читать.
 */
class DeleteModuleTool extends AbstractChangeTool
{
    public const DOMAIN = 'security';

    public static function getName(): string
    {
        return 'delete_module';
    }

    public static function rollbackNeedsRestore(): bool
    {
        // Деинсталляция удаляет таблицы модуля: точечно вернуть их нельзя.
        return true;
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'delete_module',
            'description' => 'Удаляет модуль, который агент создал сам: деинсталлирует его (если установлен) и стирает каталог /local/modules/<id>. Сторонние и системные модули удалять нельзя. Необратимо, требует confirm_destroy=true и подтверждения; откат — восстановление сайта к точке отката.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'module_id' => array(
                        'type' => 'string',
                        'description' => 'ID модуля, созданного агентом, например acme.forms.',
                    ),
                    'confirm_destroy' => array(
                        'type' => 'boolean',
                        'description' => 'Подтверждение удаления кода и данных модуля; без true операция отклоняется.',
                    ),
                ),
                'required' => array('module_id', 'confirm_destroy'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $moduleId = CustomModuleService::assertOwned((string)($args['module_id'] ?? ''));

        if (($args['confirm_destroy'] ?? false) !== true) {
            throw new \InvalidArgumentException(
                'Удаление модуля стирает его код и данные. Передайте confirm_destroy=true, если это осознанное решение.'
            );
        }

        $info = ModuleService::info($moduleId);
        if ($info === null) {
            throw new \InvalidArgumentException('Каталог модуля "' . $moduleId . '" не найден.');
        }

        $installed = ModuleService::isInstalled($moduleId);
        $tables = ModuleService::tables($moduleId);
        $stats = self::stats($moduleId);

        $lines = array();
        $lines[] = 'Будет удалён модуль «' . $info['name'] . '» (' . $moduleId . ').';
        $lines[] = 'Каталог: ' . CustomModuleService::relativePath($moduleId)
            . ', файлов: ' . $stats['files'] . ', объём: ' . self::size($stats['bytes']) . '.';

        if ($installed) {
            $lines[] = 'Модуль установлен: сначала выполнится штатная деинсталляция.';
        } else {
            $lines[] = 'Модуль не установлен: будет удалён только каталог.';
        }

        if ($tables['count'] > 0) {
            $lines[] = 'Таблиц будет затронуто: ' . $tables['count'] . ' (строк ~' . $tables['rows'] . ').';
            $lines[] = 'Таблицы: ' . implode(', ', array_slice($tables['tables'], 0, 12))
                . ($tables['count'] > 12 ? ' и ещё ' . ($tables['count'] - 12) : '') . '.';
        }

        $lines[] = 'Код и данные будут потеряны. Откат — только восстановлением сайта к точке отката.';

        return array(
            'summary' => 'Удаление модуля ' . $moduleId,
            'payload' => array('args' => array('module_id' => $moduleId)),
            'preview' => implode("\n", $lines),
        );
    }

    public static function apply(array $payload): array
    {
        $moduleId = CustomModuleService::assertOwned((string)($payload['args']['module_id'] ?? ''));

        if (ModuleService::info($moduleId) === null) {
            throw new \RuntimeException('Каталог модуля "' . $moduleId . '" не найден — изменение отменено.');
        }

        if (ModuleService::isInstalled($moduleId)) {
            ModuleService::uninstall($moduleId);
        }

        if (ModuleService::path($moduleId) !== null) {
            ModuleScaffold::delete($moduleId);
        }

        CustomModuleService::forget($moduleId);

        return array(
            'module_id' => $moduleId,
            'deleted' => true,
            'installed' => ModuleService::isInstalled($moduleId),
        );
    }

    public static function rollback(array $payload): void
    {
        // Возврат — только восстановлением точки отката сайта.
        throw new \RuntimeException('Удаление модуля откатывается восстановлением сайта к точке отката.');
    }

    /** @return array{files:int,bytes:int} */
    private static function stats(string $moduleId): array
    {
        $dir = ModuleService::path($moduleId);
        if ($dir === null) {
            return array('files' => 0, 'bytes' => 0);
        }

        $files = 0;
        $bytes = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $files++;
                    $bytes += (int)$file->getSize();
                }
            }
        } catch (\Throwable $e) {
            // Статистика не должна мешать предпросмотру.
        }

        return array('files' => $files, 'bytes' => $bytes);
    }

    private static function size(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' Б';
        }

        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' КБ';
        }

        return round($bytes / 1048576, 1) . ' МБ';
    }
}
