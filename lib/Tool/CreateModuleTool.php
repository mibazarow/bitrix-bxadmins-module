<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\CustomModuleService;
use Mibazarow\Bxadmins\Service\ModuleScaffold;
use Mibazarow\Bxadmins\Service\ModuleService;

/**
 * Создание собственного модуля в /local/modules.
 * Модуль ядра и вендора не создаётся: такие ID запрещены.
 */
class CreateModuleTool extends AbstractChangeTool
{
    public const DOMAIN = 'security';

    public static function getName(): string
    {
        return 'create_module';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'create_module',
            'description' => 'Создаёт собственный модуль Битрикса в /local/modules/<vendor>.<name>: установщик, версия, include.php, языковые файлы, SQL-файлы и каталог lib/. Можно сразу передать код (files), SQL установки и удаления. Только для модулей, которые пишет сам агент; ID вида bitrix.* и main запрещены. Создание не устанавливает модуль — для этого вызови install_module. Требует подтверждения.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'module_id' => array(
                        'type' => 'string',
                        'description' => 'ID модуля в формате vendor.name, строчными буквами: acme.forms.',
                    ),
                    'name' => array(
                        'type' => 'string',
                        'description' => 'Название модуля в админке. Если не указано — ID.',
                    ),
                    'description' => array(
                        'type' => 'string',
                        'description' => 'Короткое описание модуля для админки.',
                    ),
                    'author' => array(
                        'type' => 'string',
                        'description' => 'Автор модуля (подпись в админке).',
                    ),
                    'files' => array(
                        'type' => 'object',
                        'description' => 'Необязательные файлы модуля: {"lib/Service/Example.php": "<?php ..."}. Путь внутри модуля; до 40 файлов, 1 МБ суммарно. Файлы каркаса переопределить нельзя.',
                        'additionalProperties' => array('type' => 'string'),
                    ),
                    'install_sql' => array(
                        'type' => 'string',
                        'description' => 'Необязательный SQL установки (CREATE TABLE ...). Попадает в install/db/mysql/install.sql.',
                    ),
                    'uninstall_sql' => array(
                        'type' => 'string',
                        'description' => 'Необязательный SQL деинсталляции (DROP TABLE ...).',
                    ),
                ),
                'required' => array('module_id'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $moduleId = CustomModuleService::assertId((string)($args['module_id'] ?? ''));

        if (ModuleService::path($moduleId) !== null || ModuleService::isInstalled($moduleId)) {
            throw new \InvalidArgumentException(
                'Модуль "' . $moduleId . '" уже есть на диске или установлен — создавать его заново нельзя.'
            );
        }

        $name = trim((string)($args['name'] ?? '')) ?: $moduleId;
        $description = (string)($args['description'] ?? '');
        $author = trim((string)($args['author'] ?? '')) ?: 'Владелец сайта';
        $extraFiles = is_array($args['files'] ?? null) ? $args['files'] : array();
        $installSql = (string)($args['install_sql'] ?? '');
        $uninstallSql = (string)($args['uninstall_sql'] ?? '');

        $files = ModuleScaffold::prepare(
            $moduleId,
            $name,
            $description,
            $author,
            $extraFiles,
            $installSql,
            $uninstallSql
        );

        $lines = array();
        $lines[] = 'Будет создан модуль «' . $name . '» (' . $moduleId . ') в local/modules/.';
        $lines[] = 'Файлы (' . count($files) . '):';
        foreach (array_keys($files) as $index => $file) {
            if ($index >= 30) {
                $lines[] = '• и ещё ' . (count($files) - 30) . ' файл(ов)';
                break;
            }
            $lines[] = '• local/modules/' . $moduleId . '/' . $file;
        }
        $lines[] = '• local/modules/' . $moduleId . '/lib/';
        $lines[] = '';
        $lines[] = 'Модуль появится на диске, но не будет установлен. Установка — отдельным шагом (install_module).';
        $lines[] = 'После создания агент может править и удалять этот модуль; сторонние модули остаются только для чтения.';

        return array(
            'summary' => 'Создание модуля ' . $moduleId,
            'payload' => array(
                'args' => array(
                    'module_id' => $moduleId,
                    'name' => $name,
                    'description' => $description,
                    'author' => $author,
                    'files' => $extraFiles,
                    'install_sql' => $installSql,
                    'uninstall_sql' => $uninstallSql,
                ),
            ),
            'preview' => implode("\n", $lines),
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());
        $moduleId = CustomModuleService::assertId((string)($args['module_id'] ?? ''));
        $userId = (int)($payload['user_id'] ?? 0);

        if (ModuleService::path($moduleId) !== null || ModuleService::isInstalled($moduleId)) {
            throw new \RuntimeException('Модуль "' . $moduleId . '" уже существует — изменение отменено.');
        }

        $files = ModuleScaffold::prepare(
            $moduleId,
            (string)($args['name'] ?? ''),
            (string)($args['description'] ?? ''),
            (string)($args['author'] ?? ''),
            is_array($args['files'] ?? null) ? $args['files'] : array(),
            (string)($args['install_sql'] ?? ''),
            (string)($args['uninstall_sql'] ?? '')
        );

        $created = ModuleScaffold::create($moduleId, $files);

        try {
            CustomModuleService::register($moduleId, (string)($args['name'] ?? $moduleId), $userId);
        } catch (\Throwable $e) {
            // Без записи в реестр модуль остался бы «чужим» и навсегда закрытым для правки.
            try {
                ModuleScaffold::delete($moduleId);
            } catch (\Throwable $cleanupError) {
                // Файлы уже созданы; сообщаем исходную ошибку — её важнее видеть.
            }

            throw $e;
        }

        return array(
            'module_id' => $moduleId,
            'path' => CustomModuleService::relativePath($moduleId),
            'files' => $created,
            'installed' => false,
        );
    }

    public static function rollback(array $payload): void
    {
        $moduleId = strtolower(trim((string)($payload['args']['module_id'] ?? '')));

        if ($moduleId === '') {
            return;
        }

        if (ModuleService::isInstalled($moduleId)) {
            ModuleService::uninstall($moduleId);
        }

        if (ModuleService::path($moduleId) !== null) {
            ModuleScaffold::delete($moduleId);
        }

        CustomModuleService::forget($moduleId);
    }
}
