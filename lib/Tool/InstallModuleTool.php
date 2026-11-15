<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\ModuleService;

/** Установка модуля штатным установщиком Битрикса. */
class InstallModuleTool extends AbstractChangeTool
{
    public const DOMAIN = 'security';

    public static function getName(): string
    {
        return 'install_module';
    }

    public static function needsSqlDump(): bool
    {
        return true;
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'install_module',
            'description' => 'Устанавливает модуль Битрикса (например catalog, sale, iblock). Модуль должен быть уже загружен на диск в /bitrix/modules или /local/modules.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'module_id' => array('type' => 'string', 'description' => 'ID модуля, например catalog'),
                ),
                'required' => array('module_id'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $moduleId = strtolower(trim((string)($args['module_id'] ?? '')));

        if ($moduleId === '' || !preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)?$/', $moduleId)) {
            throw new \InvalidArgumentException('Некорректный ID модуля.');
        }

        $info = ModuleService::info($moduleId);

        if ($info === null) {
            throw new \InvalidArgumentException('Модуль "' . $moduleId . '" не найден в /bitrix/modules или /local/modules.');
        }

        if (!$info['installer']) {
            throw new \InvalidArgumentException('У модуля "' . $moduleId . '" нет установщика.');
        }

        if (ModuleService::isInstalled($moduleId)) {
            throw new \InvalidArgumentException('Модуль "' . $moduleId . '" уже установлен.');
        }

        $missing = ModuleService::requires($moduleId);

        return array(
            'summary' => 'Установка модуля ' . $moduleId,
            'payload' => array('args' => array('module_id' => $moduleId)),
            'preview' => 'Будет установлен модуль «' . $info['name'] . '» (' . $moduleId
                . ($info['version'] !== '' ? ', версия ' . $info['version'] : '') . ').'
                . ($missing !== array() ? "\n\nВнимание: модуль требует " . implode(', ', $missing) . ' — установка не пройдёт.' : '')
                . "\n\nПеред установкой создаётся SQL-дамп базы.",
        );
    }

    public static function apply(array $payload): array
    {
        $moduleId = (string)($payload['args']['module_id'] ?? '');

        ModuleService::install($moduleId);

        return array(
            'module_id' => $moduleId,
            'installed' => ModuleService::isInstalled($moduleId),
        );
    }

    public static function rollback(array $payload): void
    {
        $moduleId = (string)($payload['args']['module_id'] ?? '');

        if ($moduleId !== '' && ModuleService::isInstalled($moduleId)) {
            ModuleService::uninstall($moduleId);
        }
    }
}
