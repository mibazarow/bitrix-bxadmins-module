<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\Application;
use Bitrix\Main\ModuleManager;

/**
 * Метаданные модулей: что установлено, какие данные будут затронуты,
 * кто зависит от модуля и от кого зависит он сам.
 */
class ModuleService
{
    /** Модули, которые нельзя удалять через агента. */
    public const PROTECTED = array('main', 'mibazarow.bxadmins');

    public static function isInstalled(string $moduleId): bool
    {
        return ModuleManager::isModuleInstalled($moduleId);
    }

    /** Каталог модуля в /bitrix/modules или /local/modules. */
    public static function path(string $moduleId): ?string
    {
        $root = Application::getDocumentRoot();

        foreach (array('/local/modules/', '/bitrix/modules/') as $base) {
            $dir = $root . $base . $moduleId;
            if (is_dir($dir)) {
                return $dir;
            }
        }

        return null;
    }

    /**
     * Название, версия, описание, наличие установщика.
     *
     * @return array{id:string,name:string,description:string,version:string,version_date:string,path:string,installer:bool}|null
     */
    public static function info(string $moduleId): ?array
    {
        $dir = self::path($moduleId);
        if ($dir === null) {
            return null;
        }

        $installer = is_file($dir . '/install/index.php');
        $version = '';
        $versionDate = '';

        if (is_file($dir . '/install/version.php')) {
            $arModuleVersion = array();
            include $dir . '/install/version.php';

            if (is_array($arModuleVersion)) {
                $version = (string)($arModuleVersion['VERSION'] ?? '');
                $versionDate = (string)($arModuleVersion['VERSION_DATE'] ?? '');
            }
        }

        $name = $moduleId;
        $description = '';

        if ($installer) {
            $className = str_replace('.', '_', $moduleId);

            if (!class_exists($className)) {
                @include_once $dir . '/install/index.php';
            }

            if (class_exists($className)) {
                try {
                    $object = new $className();
                    $name = trim((string)($object->MODULE_NAME ?? '')) ?: $moduleId;
                    $description = trim((string)($object->MODULE_DESCRIPTION ?? ''));
                } catch (\Throwable $e) {
                    // Конструктор модуля может зависеть от контекста — оставляем ID.
                }
            }
        }

        return array(
            'id' => $moduleId,
            'name' => $name,
            'description' => mb_substr($description, 0, 300),
            'version' => $version,
            'version_date' => $versionDate,
            'path' => $dir,
            'installer' => $installer,
        );
    }

    /**
     * Таблицы, которые модуль, скорее всего, создал (по префиксу b_<id>).
     * Оценка: имена таблиц не всегда совпадают с ID модуля.
     *
     * @return array{count:int,rows:int,tables:string[]}
     */
    public static function tables(string $moduleId): array
    {
        $connection = Application::getConnection();
        $like = 'b_' . str_replace('.', '_', $moduleId) . '%';
        $rows = array();
        $totalRows = 0;

        $quoted = "'" . $connection->getSqlHelper()->forSql($like) . "'";

        foreach ($connection->query(
            'SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ' . $quoted
        )->fetchAll() as $row) {
            $rows[] = (string)$row['TABLE_NAME'];
            $totalRows += (int)$row['TABLE_ROWS'];
        }

        return array('count' => count($rows), 'rows' => $totalRows, 'tables' => $rows);
    }

    /**
     * Установленные модули, которые подключают этот — удаление сломает их.
     * Проверяются только точки входа: install/index.php и include.php.
     *
     * @return string[]
     */
    public static function dependents(string $moduleId): array
    {
        $result = array();

        foreach (ModuleManager::getInstalledModules() as $module) {
            $id = (string)($module['ID'] ?? '');
            if ($id === '' || $id === $moduleId) {
                continue;
            }

            $dir = self::path($id);
            if ($dir === null) {
                continue;
            }

            foreach (array('/install/index.php', '/include.php') as $file) {
                $path = $dir . $file;
                if (!is_file($path)) {
                    continue;
                }

                $source = (string)@file_get_contents($path);
                if (preg_match('/includeModule\s*\(\s*[\'"]' . preg_quote($moduleId, '/') . '[\'"]/i', $source) === 1) {
                    $result[] = $id;
                    break;
                }
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * Модули, которые нужны этому: ищем includeModule в его установщике.
     *
     * @return string[]
     */
    public static function requires(string $moduleId): array
    {
        $dir = self::path($moduleId);
        if ($dir === null) {
            return array();
        }

        $result = array();

        foreach (array('/install/index.php', '/include.php') as $file) {
            $path = $dir . $file;
            if (!is_file($path)) {
                continue;
            }

            $source = (string)@file_get_contents($path);

            if (preg_match_all('/includeModule\s*\(\s*[\'"]([a-z0-9_.]+)[\'"]/i', $source, $matches) === false) {
                continue;
            }

            foreach ($matches[1] as $dependency) {
                $dependency = strtolower((string)$dependency);
                if ($dependency !== $moduleId && $dependency !== 'main' && !self::isInstalled($dependency)) {
                    $result[] = $dependency;
                }
            }
        }

        return array_values(array_unique($result));
    }

    /** Устанавливает модуль штатным установщиком. */
    public static function install(string $moduleId): void
    {
        $info = self::info($moduleId);

        if ($info === null) {
            throw new \InvalidArgumentException('Модуль "' . $moduleId . '" не найден на диске.');
        }

        if (!$info['installer']) {
            throw new \InvalidArgumentException('У модуля "' . $moduleId . '" нет установщика install/index.php.');
        }

        if (self::isInstalled($moduleId)) {
            throw new \InvalidArgumentException('Модуль "' . $moduleId . '" уже установлен.');
        }

        $missing = self::requires($moduleId);
        if ($missing !== array()) {
            throw new \InvalidArgumentException(
                'Сначала установите зависимые модули: ' . implode(', ', $missing) . '.'
            );
        }

        $className = str_replace('.', '_', $moduleId);
        if (!class_exists($className)) {
            @include_once $info['path'] . '/install/index.php';
        }

        if (!class_exists($className)) {
            throw new \RuntimeException('Не удалось загрузить установщик модуля "' . $moduleId . '".');
        }

        $module = new $className();

        // false — штатный признак ошибки установщика; null считается успехом.
        foreach (array('InstallDB', 'InstallEvents', 'InstallFiles') as $method) {
            if ($module->$method() === false) {
                throw new \RuntimeException(
                    'Установщик модуля "' . $moduleId . '" вернул ошибку на шаге ' . $method . '.'
                );
            }
        }

        if (!ModuleManager::isModuleInstalled($moduleId)) {
            ModuleManager::registerModule($moduleId);
        }

        if (method_exists($module, 'InstallTasks')) {
            $module->InstallTasks();
        }
    }

    /** Удаляет модуль штатным деинсталлятором (с удалением данных). */
    public static function uninstall(string $moduleId): void
    {
        if (in_array($moduleId, self::PROTECTED, true)) {
            throw new \InvalidArgumentException('Модуль "' . $moduleId . '" защищён от удаления.');
        }

        if (!self::isInstalled($moduleId)) {
            throw new \InvalidArgumentException('Модуль "' . $moduleId . '" не установлен.');
        }

        $info = self::info($moduleId);
        if ($info === null || !$info['installer']) {
            throw new \InvalidArgumentException('У модуля "' . $moduleId . '" нет установщика — удаление невозможно.');
        }

        $className = str_replace('.', '_', $moduleId);
        if (!class_exists($className)) {
            @include_once $info['path'] . '/install/index.php';
        }

        if (!class_exists($className)) {
            throw new \RuntimeException('Не удалось загрузить деинсталлятор модуля "' . $moduleId . '".');
        }

        $module = new $className();

        if (method_exists($module, 'DoUninstall')) {
            $module->DoUninstall();
        } else {
            if (method_exists($module, 'UnInstallDB')) {
                $module->UnInstallDB();
            }
            if (method_exists($module, 'UnInstallEvents')) {
                $module->UnInstallEvents();
            }
            if (method_exists($module, 'UnInstallFiles')) {
                $module->UnInstallFiles();
            }
        }

        if (ModuleManager::isModuleInstalled($moduleId)) {
            ModuleManager::unRegisterModule($moduleId);
        }
    }
}
