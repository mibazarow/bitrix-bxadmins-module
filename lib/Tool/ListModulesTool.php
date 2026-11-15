<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Mibazarow\Bxadmins\Service\CustomModuleService;

/**
 * Установленные модули с версиями, путями и признаком владения агентом.
 */
class ListModulesTool extends AbstractTool
{
    public const DOMAIN = 'core';

    private const LIMIT = 300;

    public static function getName(): string
    {
        return 'list_modules';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_modules',
            'description' => 'Список установленных модулей сайта: ID, версия, путь, происхождение (kernel / local / agent) и признак owned — модуль создан агентом и доступен ему для правки. Опционально filter — подстрока ID; owned_only — только модули агента.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'filter' => array(
                        'type' => 'string',
                        'description' => 'Необязательная подстрока ID модуля (например "iblock" или "acme").',
                    ),
                    'owned_only' => array(
                        'type' => 'boolean',
                        'description' => 'Показать только модули, созданные агентом.',
                    ),
                ),
            ),
        );
    }

    /** @return array{count:int,truncated:bool,modules:array} */
    public static function execute(array $args, array $context = array()): array
    {
        $filter = trim((string)($args['filter'] ?? ''));
        $ownedOnly = ($args['owned_only'] ?? false) === true;
        $documentRoot = Loader::getDocumentRoot();
        $modules = array();
        $seen = array();

        foreach (ModuleManager::getInstalledModules() as $moduleRow) {
            $moduleId = (string)($moduleRow['ID'] ?? '');

            if ($filter !== '' && stripos($moduleId, $filter) === false) {
                continue;
            }

            $owned = CustomModuleService::isOwned($moduleId);
            if ($ownedOnly && !$owned) {
                continue;
            }

            $path = self::modulePath($documentRoot, $moduleId);
            $seen[$moduleId] = true;

            $modules[] = array(
                'id' => $moduleId,
                'version' => (string)ModuleManager::getVersion($moduleId),
                'path' => $path,
                'origin' => $owned ? 'agent' : (str_starts_with($path, '/local/') ? 'local' : 'kernel'),
                'owned' => $owned,
                'installed' => true,
            );

            if (count($modules) >= self::LIMIT) {
                break;
            }
        }

        // Созданные, но ещё не установленные модули агента тоже важны: их можно
        // доработать и установить, а без этой строки они пропадают из выдачи.
        if (count($modules) < self::LIMIT) {
            foreach (CustomModuleService::all() as $custom) {
                $moduleId = $custom['module_id'];

                if (isset($seen[$moduleId])) {
                    continue;
                }

                if ($filter !== '' && stripos($moduleId, $filter) === false) {
                    continue;
                }

                $path = self::modulePath($documentRoot, $moduleId);
                if ($path === '') {
                    continue;
                }

                $modules[] = array(
                    'id' => $moduleId,
                    'version' => (string)ModuleManager::getVersion($moduleId),
                    'path' => $path,
                    'origin' => 'agent',
                    'owned' => true,
                    'installed' => false,
                );

                if (count($modules) >= self::LIMIT) {
                    break;
                }
            }
        }

        usort($modules, static fn (array $left, array $right): int => strcmp($left['id'], $right['id']));

        return array(
            'count' => count($modules),
            'truncated' => count($modules) >= self::LIMIT,
            'modules' => $modules,
        );
    }

    private static function modulePath(string $documentRoot, string $moduleId): string
    {
        if ($moduleId !== '' && is_dir($documentRoot . '/local/modules/' . $moduleId)) {
            return '/local/modules/' . $moduleId;
        }

        if ($moduleId !== '' && is_dir($documentRoot . '/bitrix/modules/' . $moduleId)) {
            return '/bitrix/modules/' . $moduleId;
        }

        return '';
    }
}
