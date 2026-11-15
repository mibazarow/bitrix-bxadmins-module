<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Mibazarow\Bxadmins\Service\CustomModuleService;

/**
 * Информация об одном модуле и статистика его каталога lib/.
 */
class ModuleInfoTool extends AbstractTool
{
    public const DOMAIN = 'core';

    private const ID_PATTERN = '/^[A-Za-z0-9_.\-]{2,50}$/';
    private const MAX_FILES = 500;
    private const MAX_FILE_SIZE = 200000;

    public static function getName(): string
    {
        return 'module_info';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'module_info',
            'description' => 'Информация об одном модуле по его ID: версия, установлен ли, путь, происхождение (kernel / local / agent), доступен ли он агенту для правки (owned), наличие каталога lib/ и приблизительное число файлов/классов в нем.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'module_id' => array(
                        'type' => 'string',
                        'description' => 'ID модуля, например "iblock", "sale" или "mibazarow.bxadmins".',
                    ),
                ),
                'required' => array('module_id'),
            ),
        );
    }

    /** @return array{module_id:string,installed:bool,version:string,path:string,origin:string,owned:bool,has_lib:bool,lib_stats:array}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        $moduleId = trim((string)($args['module_id'] ?? ''));

        if ($moduleId === '' || !preg_match(self::ID_PATTERN, $moduleId)) {
            return array('error' => 'Некорректный module_id: разрешены буквы, цифры, "_", "-", "." (2–50 символов).');
        }

        $documentRoot = Loader::getDocumentRoot();
        $localPath = $documentRoot . '/local/modules/' . $moduleId;
        $kernelPath = $documentRoot . '/bitrix/modules/' . $moduleId;

        $path = '';
        if (is_dir($localPath)) {
            $path = '/local/modules/' . $moduleId;
        } elseif (is_dir($kernelPath)) {
            $path = '/bitrix/modules/' . $moduleId;
        }

        if ($path === '' && !ModuleManager::isModuleInstalled($moduleId)) {
            return array('error' => 'Модуль "' . $moduleId . '" не найден на сайте.');
        }

        $libDir = $path !== '' ? $documentRoot . $path . '/lib' : '';
        $hasLib = $libDir !== '' && is_dir($libDir);
        $libStats = $hasLib ? self::scanLib($libDir) : array(
            'php_files' => 0,
            'class_like_count' => 0,
            'scanned_files' => 0,
            'truncated' => false,
        );

        $owned = CustomModuleService::isOwned($moduleId);

        return array(
            'module_id' => $moduleId,
            'installed' => ModuleManager::isModuleInstalled($moduleId),
            'version' => (string)ModuleManager::getVersion($moduleId),
            'path' => $path,
            'origin' => $owned ? 'agent' : (str_starts_with($path, '/local/') ? 'local' : 'kernel'),
            'owned' => $owned,
            'has_lib' => $hasLib,
            'lib_stats' => $libStats,
        );
    }

    private static function scanLib(string $libDir): array
    {
        $phpFiles = 0;
        $classLike = 0;
        $scanned = 0;
        $truncated = false;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($libDir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                $phpFiles++;

                if ($scanned < self::MAX_FILES && $file->getSize() <= self::MAX_FILE_SIZE) {
                    $contents = (string)file_get_contents($file->getPathname());
                    $classLike += preg_match_all('/\b(?:class|interface|trait|enum)\s+[A-Za-z_\\\\]/', $contents);
                    $scanned++;
                }

                if ($scanned >= self::MAX_FILES) {
                    $truncated = true;
                    break;
                }
            }
        } catch (\Throwable $e) {
            // Обход дерева не должен ронять инструмент.
        }

        return array(
            'php_files' => $phpFiles,
            'class_like_count' => $classLike,
            'scanned_files' => $scanned,
            'truncated' => $truncated,
        );
    }
}
