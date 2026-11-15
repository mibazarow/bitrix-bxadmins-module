<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\ModuleService;

/**
 * Удаление модуля. Необратимо: удаляются таблицы и данные модуля,
 * поэтому требует отдельного подтверждения и SQL-дампа.
 */
class UninstallModuleTool extends AbstractChangeTool
{
    public const DOMAIN = 'security';

    public static function getName(): string
    {
        return 'uninstall_module';
    }

    public static function needsSqlDump(): bool
    {
        return true;
    }

    public static function rollbackNeedsRestore(): bool
    {
        // Деинсталляция удаляет таблицы и файлы модуля: точечно восстановить нельзя.
        return true;
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'uninstall_module',
            'description' => 'Удаляет модуль Битрикса вместе с его таблицами и данными. Необратимо. Требует подтверждения пользователя и создаёт SQL-дамп.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'module_id' => array('type' => 'string', 'description' => 'ID модуля, например catalog'),
                    'confirm_destroy' => array(
                        'type' => 'boolean',
                        'description' => 'Подтверждение удаления данных модуля; без true операция отклоняется',
                    ),
                ),
                'required' => array('module_id', 'confirm_destroy'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $moduleId = strtolower(trim((string)($args['module_id'] ?? '')));

        if ($moduleId === '' || !preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)?$/', $moduleId)) {
            throw new \InvalidArgumentException('Некорректный ID модуля.');
        }

        if (($args['confirm_destroy'] ?? false) !== true) {
            throw new \InvalidArgumentException(
                'Удаление модуля уничтожает его данные. Передайте confirm_destroy=true, если это осознанное решение.'
            );
        }

        if (in_array($moduleId, ModuleService::PROTECTED, true)) {
            throw new \InvalidArgumentException('Модуль "' . $moduleId . '" защищён от удаления.');
        }

        if (!ModuleService::isInstalled($moduleId)) {
            throw new \InvalidArgumentException('Модуль "' . $moduleId . '" не установлен.');
        }

        $info = ModuleService::info($moduleId);
        $tables = ModuleService::tables($moduleId);
        $dependents = ModuleService::dependents($moduleId);

        $lines = array();
        $lines[] = 'Будет удалён модуль «' . ($info['name'] ?? $moduleId) . '» (' . $moduleId . ').';

        if ($tables['count'] > 0) {
            $lines[] = 'Таблиц будет затронуто: ' . $tables['count'] . ', записей: ' . $tables['rows'] . '.';
            $lines[] = 'Таблицы: ' . implode(', ', array_slice($tables['tables'], 0, 12))
                . ($tables['count'] > 12 ? ' и ещё ' . ($tables['count'] - 12) : '') . '.';
        } else {
            $lines[] = 'Таблиц с префиксом b_' . str_replace('.', '_', $moduleId) . ' не найдено.';
        }

        if ($dependents !== array()) {
            $lines[] = 'ВНИМАНИЕ: от этого модуля зависят установленные модули: ' . implode(', ', $dependents)
                . '. Они могут перестать работать.';
        }

        $lines[] = 'Данные будут потеряны безвозвратно. Откат — только восстановлением SQL-дампа.';

        return array(
            'summary' => 'Удаление модуля ' . $moduleId,
            'payload' => array('args' => array('module_id' => $moduleId)),
            'preview' => implode("\n", $lines),
        );
    }

    public static function apply(array $payload): array
    {
        $moduleId = (string)($payload['args']['module_id'] ?? '');

        ModuleService::uninstall($moduleId);

        return array(
            'module_id' => $moduleId,
            'installed' => ModuleService::isInstalled($moduleId),
        );
    }

    public static function rollback(array $payload): void
    {
        // Возврат — только восстановлением SQL-дампа, созданного перед удалением.
        throw new \RuntimeException('Удаление модуля откатывается восстановлением SQL-дампа.');
    }
}
