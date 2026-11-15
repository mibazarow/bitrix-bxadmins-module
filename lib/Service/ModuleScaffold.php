<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\IO\Directory;
use Bitrix\Main\IO\File;

/**
 * Генерация каркаса собственного модуля в /local/modules.
 *
 * Создаётся минимальный, но рабочий модуль Битрикса: установщик, версия,
 * точка подключения namespace, языковые файлы и пустые SQL-файлы под таблицы.
 * Дальше агент наполняет lib/ обычными инструментами записи.
 */
final class ModuleScaffold
{
    public const DEFAULT_VERSION = '1.0.0';

    private const SQL_INSTALL_COMMENT = "-- Таблицы модуля. Добавьте CREATE TABLE ... ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n";
    private const SQL_UNINSTALL_COMMENT = "-- Удаление таблиц модуля. Добавьте DROP TABLE IF EXISTS ...;\n";

    private const MAX_EXTRA_FILES = 40;
    private const MAX_EXTRA_FILE_BYTES = 512000;
    private const MAX_EXTRA_TOTAL_BYTES = 1000000;

    /** Расширения, которые агент может положить в свой модуль при создании. */
    private const EXTRA_EXTENSIONS = array(
        'php', 'css', 'scss', 'js', 'html', 'htm', 'xml', 'json', 'svg', 'md', 'txt',
        'sql', 'csv', 'yml', 'yaml', 'inc', 'tpl',
    );

    /**
     * Полный набор файлов модуля: каркас + SQL владельца + файлы агента.
     *
     * @param array<string,string> $extraFiles путь внутри модуля => содержимое
     * @return array<string,string>
     * @throws \InvalidArgumentException
     */
    public static function prepare(
        string $moduleId,
        string $name,
        string $description,
        string $author,
        array $extraFiles = array(),
        string $installSql = '',
        string $uninstallSql = ''
    ): array {
        $files = self::files($moduleId, $name, $description, $author);

        if (trim($installSql) !== '') {
            $files['install/db/mysql/install.sql'] = self::sqlContent($installSql, 'install_sql');
        }

        if (trim($uninstallSql) !== '') {
            $files['install/db/mysql/uninstall.sql'] = self::sqlContent($uninstallSql, 'uninstall_sql');
        }

        $count = 0;
        $total = 0;

        foreach ($extraFiles as $path => $content) {
            $path = self::normalizeExtraPath((string)$path);

            if (isset($files[$path])) {
                throw new \InvalidArgumentException(
                    'Файл ' . $path . ' создаётся установщиком модуля — его нельзя переопределить.'
                );
            }

            if (!is_string($content)) {
                throw new \InvalidArgumentException('Содержимое файла ' . $path . ' должно быть строкой.');
            }

            if (str_contains($content, "\0") || !mb_check_encoding($content, 'UTF-8')) {
                throw new \InvalidArgumentException('Файл ' . $path . ' должен быть текстом UTF-8 без null-байтов.');
            }

            if (strlen($content) > self::MAX_EXTRA_FILE_BYTES) {
                throw new \InvalidArgumentException('Файл ' . $path . ' больше 500 КБ.');
            }

            if (++$count > self::MAX_EXTRA_FILES) {
                throw new \InvalidArgumentException('За одно создание можно добавить не больше '
                    . self::MAX_EXTRA_FILES . ' файлов.');
            }

            $total += strlen($content);
            if ($total > self::MAX_EXTRA_TOTAL_BYTES) {
                throw new \InvalidArgumentException('Суммарный объём файлов больше 1 МБ — создайте модуль '
                    . 'каркасом и добавьте остальное обычной правкой файлов.');
            }

            $files[$path] = $content;
        }

        return $files;
    }

    /**
     * Создаёт каталог и файлы модуля. При ошибке удаляет частично созданное.
     *
     * @param array<string,string> $files путь внутри модуля => содержимое
     * @return string[] относительные пути созданных файлов
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public static function create(string $moduleId, array $files): array
    {
        $moduleId = CustomModuleService::assertId($moduleId);
        $relativeDir = CustomModuleService::relativePath($moduleId);
        $absoluteDir = LocalFileGuard::absolute($relativeDir);

        if (file_exists($absoluteDir) || is_link($absoluteDir)) {
            throw new \InvalidArgumentException(
                'Каталог ' . $relativeDir . ' уже существует: модуль с таким ID уже есть на диске.'
            );
        }

        $created = array();

        try {
            Directory::createDirectory($absoluteDir);

            foreach ($files as $relativeFile => $content) {
                $target = $absoluteDir . '/' . $relativeFile;
                Directory::createDirectory(dirname($target));

                $bytes = File::putFileContents($target, $content);
                if ($bytes === false || (int)$bytes !== strlen($content)) {
                    throw new \RuntimeException('Не удалось записать ' . $relativeDir . '/' . $relativeFile . '.');
                }

                $created[] = $relativeDir . '/' . $relativeFile;
            }

            // Пустой lib/ нужен уже сейчас: Loader::registerNamespace смотрит на каталог,
            // а первые классы агент добавит обычной записью файлов.
            Directory::createDirectory($absoluteDir . '/lib');
        } catch (\Throwable $e) {
            try {
                Directory::deleteDirectory($absoluteDir);
            } catch (\Throwable $cleanupError) {
                Logger::error('ModuleScaffold: не удалось удалить частично созданный модуль', array(
                    'module' => $moduleId,
                ));
            }

            throw $e instanceof \InvalidArgumentException
                ? $e
                : new \RuntimeException('Не удалось создать модуль: ' . $e->getMessage(), 0, $e);
        }

        return $created;
    }

    /**
     * Удаляет каталог модуля. Симлинки и выход за /local/modules запрещены.
     *
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public static function delete(string $moduleId): void
    {
        $moduleId = CustomModuleService::assertId($moduleId);
        $absoluteDir = LocalFileGuard::absolute(CustomModuleService::relativePath($moduleId));

        if (is_link($absoluteDir)) {
            throw new \InvalidArgumentException('Каталог модуля является символической ссылкой — удаление запрещено.');
        }

        $localModules = realpath(LocalFileGuard::root() . '/local/modules');
        $realDir = realpath($absoluteDir);

        if ($localModules === false || $realDir === false || dirname($realDir) !== $localModules) {
            throw new \InvalidArgumentException('Каталог модуля не найден в /local/modules.');
        }

        try {
            Directory::deleteDirectory($absoluteDir);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Не удалось удалить каталог модуля: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Содержимое файлов каркаса: путь внутри модуля => содержимое.
     *
     * @return array<string,string>
     */
    public static function files(
        string $moduleId,
        string $name,
        string $description,
        string $author
    ): array {
        $moduleId = CustomModuleService::assertId($moduleId);
        $name = trim($name) !== '' ? mb_substr(trim($name), 0, 255) : $moduleId;
        $description = mb_substr(trim($description), 0, 1000);
        $author = mb_substr(trim($author), 0, 255);
        $className = str_replace('.', '_', $moduleId);
        $constantPrefix = strtoupper($className);
        $namespace = self::namespaceFor($moduleId);
        $versionDate = date('Y-m-d');

        $replace = static function (string $template) use (
            $moduleId,
            $name,
            $description,
            $author,
            $className,
            $constantPrefix,
            $namespace,
            $versionDate
        ): string {
            return strtr($template, array(
                '{{ID}}' => var_export($moduleId, true),
                '{{NAME}}' => var_export($name, true),
                '{{DESCRIPTION}}' => var_export($description, true),
                '{{AUTHOR}}' => var_export($author, true),
                '{{CLASS}}' => $className,
                '{{CONST}}' => $constantPrefix,
                '{{NAMESPACE}}' => var_export($namespace, true),
                '{{VERSION}}' => var_export(self::DEFAULT_VERSION, true),
                '{{VERSION_DATE}}' => var_export($versionDate, true),
                '{{README_NAME}}' => $name,
                '{{README_DESCRIPTION}}' => $description,
                '{{README_AUTHOR}}' => $author,
            ));
        };

        return array(
            '.description.php' => $replace(self::DESCRIPTION_TEMPLATE),
            'README.md' => $replace(self::README_TEMPLATE),
            'include.php' => $replace(self::INCLUDE_TEMPLATE),
            'install/index.php' => $replace(self::INSTALLER_TEMPLATE),
            'install/version.php' => $replace(self::VERSION_TEMPLATE),
            'install/db/mysql/install.sql' => self::SQL_INSTALL_COMMENT,
            'install/db/mysql/uninstall.sql' => self::SQL_UNINSTALL_COMMENT,
            'lang/ru/install/index.php' => $replace(self::LANG_TEMPLATE),
            'lang/en/install/index.php' => $replace(self::LANG_TEMPLATE),
        );
    }

    /** @throws \InvalidArgumentException */
    private static function normalizeExtraPath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));

        if ($path === '' || str_starts_with($path, '/')) {
            throw new \InvalidArgumentException('Путь файла в модуле должен быть относительным.');
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('Недопустимый путь файла в модуле: ' . $path);
            }

            if (str_starts_with($segment, '.')) {
                throw new \InvalidArgumentException('Скрытые файлы в модуле создавать нельзя: ' . $path);
            }
        }

        $extension = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, self::EXTRA_EXTENSIONS, true)) {
            throw new \InvalidArgumentException(
                'Недопустимое расширение файла ' . $path . '. Разрешены: '
                . implode(', ', self::EXTRA_EXTENSIONS) . '.'
            );
        }

        return implode('/', $segments);
    }

    /** @throws \InvalidArgumentException */
    private static function sqlContent(string $sql, string $parameter): string
    {
        if (str_contains($sql, "\0") || !mb_check_encoding($sql, 'UTF-8')) {
            throw new \InvalidArgumentException('Параметр ' . $parameter . ' должен быть текстом UTF-8.');
        }

        if (strlen($sql) > 200000) {
            throw new \InvalidArgumentException('Параметр ' . $parameter . ' больше 200 КБ.');
        }

        return rtrim($sql) . "\n";
    }

    /** Acme\Forms для acme.forms. */
    private static function namespaceFor(string $moduleId): string
    {
        $segments = array();

        foreach (preg_split('/[._]/', strtolower($moduleId)) ?: array() as $part) {
            $part = (string)preg_replace('/[^a-z0-9_]/', '', $part);

            if ($part === '') {
                continue;
            }

            if (preg_match('/^[0-9]/', $part) === 1) {
                $part = 'V' . $part;
            }

            $segments[] = ucfirst($part);
        }

        return implode('\\', $segments);
    }

    private const DESCRIPTION_TEMPLATE = <<<'PHP'
<?php

$arDescription = array(
    'NAME' => {{NAME}},
    'DESCRIPTION' => {{DESCRIPTION}},
    'GROUP' => array('ID' => 'other'),
    'SORT' => 500,
);
PHP;

    private const README_TEMPLATE = <<<'MD'
# {{README_NAME}}

{{README_DESCRIPTION}}

Модуль создан агентом BXADMIN-S в `local/modules/{{ID}}`. Агент может его править и удалить;
код ядра и сторонних модулей остаётся только для чтения.

Автор: {{README_AUTHOR}}.
MD;

    private const INCLUDE_TEMPLATE = <<<'PHP'
<?php

declare(strict_types=1);

use Bitrix\Main\Loader;

Loader::registerNamespace({{NAMESPACE}}, __DIR__ . '/lib');
PHP;

    private const INSTALLER_TEMPLATE = <<<'PHP'
<?php

declare(strict_types=1);

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;

Loc::loadMessages(__FILE__);

class {{CLASS}} extends CModule
{
    public $MODULE_ID = {{ID}};
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;
    public $PARTNER_NAME;
    public $PARTNER_URI;

    public function __construct()
    {
        $arModuleVersion = array();
        include __DIR__ . '/version.php';

        $this->MODULE_VERSION = (string)($arModuleVersion['VERSION'] ?? '1.0.0');
        $this->MODULE_VERSION_DATE = (string)($arModuleVersion['VERSION_DATE'] ?? '');
        $this->MODULE_NAME = (string)Loc::getMessage('{{CONST}}_MODULE_NAME');
        $this->MODULE_DESCRIPTION = (string)Loc::getMessage('{{CONST}}_MODULE_DESCRIPTION');
        $this->PARTNER_NAME = (string)Loc::getMessage('{{CONST}}_PARTNER_NAME');
        $this->PARTNER_URI = '';
    }

    public function DoInstall()
    {
        if (!$this->InstallDB()) {
            return false;
        }

        ModuleManager::registerModule($this->MODULE_ID);

        return true;
    }

    public function DoUninstall()
    {
        if (!$this->UnInstallDB()) {
            return false;
        }

        ModuleManager::unRegisterModule($this->MODULE_ID);

        return true;
    }

    public function InstallDB()
    {
        return $this->runSql('install.sql');
    }

    public function UnInstallDB()
    {
        return $this->runSql('uninstall.sql');
    }

    public function InstallEvents()
    {
        return true;
    }

    public function UnInstallEvents()
    {
        return true;
    }

    public function InstallFiles()
    {
        return true;
    }

    public function UnInstallFiles()
    {
        return true;
    }

    /**
     * Легаси-исключение: RunSQLBatch — штатный способ выполнить SQL установщика,
     * D7-аналога для пакетного выполнения DDL нет.
     */
    private function runSql(string $file): bool
    {
        global $APPLICATION, $DB;

        $path = __DIR__ . '/db/mysql/' . $file;
        if (!is_file($path)) {
            return true;
        }

        $sql = (string)file_get_contents($path);
        if (trim((string)preg_replace('~^\s*--.*$~m', '', $sql)) === '') {
            return true;
        }

        $errors = $DB->RunSQLBatch($path);
        if (!empty($errors)) {
            $APPLICATION->ThrowException(implode('', $errors));

            return false;
        }

        return true;
    }
}
PHP;

    private const VERSION_TEMPLATE = <<<'PHP'
<?php

$arModuleVersion = array(
    'VERSION' => {{VERSION}},
    'VERSION_DATE' => {{VERSION_DATE}},
);
PHP;

    private const LANG_TEMPLATE = <<<'PHP'
<?php

$MESS['{{CONST}}_MODULE_NAME'] = {{NAME}};
$MESS['{{CONST}}_MODULE_DESCRIPTION'] = {{DESCRIPTION}};
$MESS['{{CONST}}_PARTNER_NAME'] = {{AUTHOR}};
PHP;
}
