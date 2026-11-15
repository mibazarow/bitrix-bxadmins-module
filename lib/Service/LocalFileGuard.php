<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\Loader;

/**
 * Политика путей для записи файлов внутри /local/.
 * Один источник правды и для инструмента, и для снапшотов.
 */
class LocalFileGuard
{
    private const ALLOWED_EXTENSIONS = array(
        'php', 'css', 'scss', 'js', 'html', 'xml', 'json', 'svg', 'md', 'txt',
        'sql', 'csv', 'yml', 'yaml', 'inc', 'tpl',
    );

    private const MAX_PATH_LENGTH = 512;

    private const FORBIDDEN_PREFIXES = array(
        'local/php_interface/',
        'local/modules/mibazarow.bxadmins/',
    );

    /** Каталоги, которые нельзя менять ни при каких условиях. */
    private const SITE_FORBIDDEN_PREFIXES = array(
        'bitrix/',
        'upload/',
        'local/php_interface/',
        'local/modules/mibazarow.bxadmins/',
    );

    /** Расширения, допустимые для файлов сайта (страницы, меню, стили). */
    private const SITE_EXTENSIONS = array(
        'php', 'css', 'scss', 'js', 'html', 'htm', 'xml', 'json', 'svg', 'md', 'txt', 'csv', 'htaccess',
        'sql', 'yml', 'yaml', 'inc', 'tpl',
    );

    /**
     * Нормализует относительный путь и отсекает выход за пределы проекта.
     *
     * @throws \InvalidArgumentException
     */
    public static function normalize(string $relativePath): string
    {
        $path = trim($relativePath);

        if ($path === '') {
            throw new \InvalidArgumentException('Путь к файлу не указан.');
        }

        if (str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Путь содержит недопустимые символы.');
        }

        if (preg_match('/[\x01-\x1F\x7F]/', $path) === 1) {
            throw new \InvalidArgumentException('Путь содержит управляющие символы.');
        }

        if (str_contains($path, '\\')) {
            throw new \InvalidArgumentException('В пути используйте прямые слэши (/).');
        }

        if (mb_strlen($path, 'UTF-8') > self::MAX_PATH_LENGTH) {
            throw new \InvalidArgumentException('Путь слишком длинный.');
        }

        if (str_starts_with($path, '/') || str_starts_with($path, '~') || preg_match('/^[A-Za-z]:/', $path) === 1) {
            throw new \InvalidArgumentException('Путь должен быть относительным, без ведущего слэша.');
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('Путь содержит недопустимые сегменты (пустой, «.» или «..»).');
            }
        }

        return implode('/', $segments);
    }

    /**
     * Проверяет, что файл разрешён к записи инструментом write_local_file.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertWritableLocalPath(string $relativePath): string
    {
        $path = self::normalize($relativePath);

        if (!str_starts_with($path, 'local/')) {
            throw new \InvalidArgumentException('Запись разрешена только внутри каталога local/.');
        }

        $lower = strtolower($path);
        foreach (self::FORBIDDEN_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                throw new \InvalidArgumentException('Этот каталог защищён от изменений: ' . rtrim($prefix, '/') . '.');
            }
        }

        self::assertModuleWritable($path);

        $segments = explode('/', $path);
        array_shift($segments);
        foreach ($segments as $segment) {
            if (str_starts_with($segment, '.')) {
                throw new \InvalidArgumentException('Скрытые файлы и каталоги (начинающиеся с точки) изменять нельзя.');
            }
        }

        $extension = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new \InvalidArgumentException(
                'Недопустимое расширение файла. Разрешены: ' . implode(', ', self::ALLOWED_EXTENSIONS) . '.'
            );
        }

        self::assertInsideLocal($path);

        return $path;
    }

    /**
     * Проверяет путь к файлу сайта: разрешено всё, кроме ядра, upload и
     * local/php_interface. Так агент может создать страницу, меню или .htaccess,
     * но не тронет ядро и защищённые каталоги.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertSitePath(string $relativePath): string
    {
        $path = self::normalize($relativePath);
        $lower = strtolower($path);

        foreach (self::SITE_FORBIDDEN_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                throw new \InvalidArgumentException('Этот каталог защищён от изменений: ' . rtrim($prefix, '/') . '.');
            }
        }

        self::assertModuleWritable($path);

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if (str_starts_with($segment, '.')) {
                // .htaccess и подобные служебные файлы править можно, скрытые каталоги — нет.
                if ($segment !== basename($path)) {
                    throw new \InvalidArgumentException('Скрытые каталоги изменять нельзя.');
                }
            }
        }

        $base = basename($path);
        $extension = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === '' && $base === '.htaccess') {
            $extension = 'htaccess';
        }

        if (!in_array($extension, self::SITE_EXTENSIONS, true)) {
            throw new \InvalidArgumentException(
                'Недопустимое расширение файла. Разрешены: ' . implode(', ', self::SITE_EXTENSIONS) . '.'
            );
        }

        $absolute = self::absolute($path);
        if (is_link($absolute)) {
            throw new \InvalidArgumentException('Символические ссылки изменять нельзя.');
        }

        $root = self::root();
        $probe = $absolute;
        while (!file_exists($probe) && !is_link($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                break;
            }
            $probe = $parent;
        }

        $realProbe = realpath($probe);
        if ($realProbe === false) {
            throw new \InvalidArgumentException('Путь выходит за пределы сайта.');
        }

        // local может быть симлинком на общий каталог — это нормальная схема
        // мультисайта, поэтому разрешаем обе базы: корень сайта и его local.
        $bases = array(realpath($root), realpath($root . '/local'));

        foreach ($bases as $base) {
            if ($base !== false && ($realProbe === $base || str_starts_with($realProbe, $base . '/'))) {
                return $path;
            }
        }

        throw new \InvalidArgumentException('Путь выходит за пределы сайта.');
    }

    /**
     * Проверяет путь для чтения: код ядра и сторонних модулей читать можно,
     * но секреты и дампы базы — нет.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertReadablePath(string $relativePath): string
    {
        $path = self::normalize($relativePath);
        $lower = strtolower($path);
        $segments = explode('/', $lower);

        if (str_starts_with($lower, 'upload/')) {
            throw new \InvalidArgumentException('Каталог upload/ — пользовательские файлы, читать его через агента нельзя.');
        }

        if (str_starts_with($lower, 'bitrix/backup/') || str_starts_with($lower, 'bitrix/managed_cache/')) {
            throw new \InvalidArgumentException('Служебный каталог ядра закрыт для чтения.');
        }

        foreach ($segments as $segment) {
            if ($segment === '.git' || $segment === '.svn' || $segment === '.hg') {
                throw new \InvalidArgumentException('Каталоги системы контроля версий читать нельзя.');
            }
        }

        $base = basename($lower);
        $secretNames = array('.settings.php', '.settings_extra.php', '.env', 'dbconn.php');

        if (in_array($base, $secretNames, true) || str_starts_with($base, '.env.')) {
            throw new \InvalidArgumentException('Файл содержит секреты и закрыт для чтения.');
        }

        $extension = strtolower((string)pathinfo($lower, PATHINFO_EXTENSION));
        if (in_array($extension, array('pem', 'key', 'crt', 'p12', 'pfx'), true)) {
            throw new \InvalidArgumentException('Файлы ключей и сертификатов закрыты для чтения.');
        }

        $absolute = self::absolute($path);
        if (is_link($absolute)) {
            throw new \InvalidArgumentException('Символические ссылки читать нельзя.');
        }

        $probe = $absolute;
        while (!file_exists($probe) && !is_link($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                break;
            }
            $probe = $parent;
        }

        $realProbe = realpath($probe);
        $root = self::root();

        // bitrix и local на мультисайте — симлинки на общий каталог, поэтому
        // разрешаем реальные цели этих каталогов, а не только корень сайта.
        $bases = array(realpath($root), realpath($root . '/local'), realpath($root . '/bitrix'));

        foreach ($bases as $allowedBase) {
            if ($allowedBase !== false && $realProbe !== false
                && ($realProbe === $allowedBase || str_starts_with($realProbe, $allowedBase . '/'))) {
                return $path;
            }
        }

        throw new \InvalidArgumentException('Путь выходит за пределы сайта.');
    }

    /** Абсолютный путь от корня проекта. */
    public static function absolute(string $relativePath): string
    {
        return self::root() . '/' . ltrim($relativePath, '/');
    }

    /** Корень проекта (DOCUMENT_ROOT) без завершающего слэша. */
    public static function root(): string
    {
        $root = rtrim((string)Loader::getDocumentRoot(), '/');

        if ($root === '') {
            throw new \RuntimeException('Не удалось определить корень проекта.');
        }

        return $root;
    }

    /**
     * Менять можно только модули, созданные агентом. Всё остальное в local/modules
     * (ядро, вендор, модули владельца) — только чтение.
     *
     * @throws \InvalidArgumentException
     */
    private static function assertModuleWritable(string $relativePath): void
    {
        $moduleId = CustomModuleService::moduleFromPath($relativePath);

        if ($moduleId === null || CustomModuleService::isOwned($moduleId)) {
            return;
        }

        throw new \InvalidArgumentException(
            'Модуль "' . $moduleId . '" не создан агентом: правка сторонних и системных модулей запрещена. '
            . 'Создайте свой модуль инструментом create_module.'
        );
    }

    /**
     * Защита от симлинков и уже существующих каталогов, ведущих за пределы /local/.
     *
     * @throws \InvalidArgumentException
     */
    private static function assertInsideLocal(string $relativePath): void
    {
        $absolute = self::absolute($relativePath);

        if (is_link($absolute)) {
            throw new \InvalidArgumentException('Символические ссылки изменять нельзя.');
        }

        $probe = $absolute;
        while (!file_exists($probe) && !is_link($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                break;
            }
            $probe = $parent;
        }

        $realProbe = realpath($probe);
        $realLocal = realpath(self::root() . '/local');

        if ($realProbe === false || $realLocal === false
            || ($realProbe !== $realLocal && !str_starts_with($realProbe, $realLocal . '/'))) {
            throw new \InvalidArgumentException('Путь выходит за пределы каталога local/.');
        }

        $protectedDirs = array(
            self::root() . '/local/php_interface',
            self::root() . '/local/modules/mibazarow.bxadmins',
        );

        foreach ($protectedDirs as $protectedDir) {
            $realProtected = realpath($protectedDir);
            if ($realProtected !== false
                && ($realProbe === $realProtected || str_starts_with($realProbe, $realProtected . '/'))) {
                throw new \InvalidArgumentException('Этот каталог защищён от изменений.');
            }
        }
    }
}
