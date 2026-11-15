<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\Loader;
use Mibazarow\Bxadmins\Config;
use Mibazarow\Bxadmins\Model\ChangeTable;

/**
 * SQL-дампы БД в ZIP: создание, список, удаление и ротация. Каталог — bitrix/backup/bx_admin_s_bacaps/.
 */
class BackupService
{
    public const DIR = '/bitrix/backup/bx_admin_s_bacaps';

    private const DUMP_SUFFIX = '.sql';
    private const ARCHIVE_SUFFIX = '.sql.zip';

    /**
     * Служебные таблицы модуля. В точку отката сайта они не входят: иначе вместе
     * с данными откатывались бы очередь задач, переписка и история изменений,
     * а текущий запрос к демону терял бы свою задачу.
     */
    private const MODULE_TABLES = array(
        'b_mibazarow_bxadmins_chat',
        'b_mibazarow_bxadmins_message',
        'b_mibazarow_bxadmins_index',
        'b_mibazarow_bxadmins_change',
        'b_mibazarow_bxadmins_task',
        'b_mibazarow_bxadmins_task_event',
        'b_mibazarow_bxadmins_llm_profile',
        'b_mibazarow_bxadmins_mcp_server',
        'b_mibazarow_bxadmins_schedule',
        'b_mibazarow_bxadmins_memory',
        'b_mibazarow_bxadmins_skill',
    );

    /**
     * Запрет HTTP-доступа к каталогу дампов.
     * Apache 2.4 — Require all denied; 2.2 — Order/Deny; плюс запрет листинга.
     */
    private const HTACCESS_RULES = "# BXADMIN-S: SQL-дампы не должны отдаваться по HTTP.\n"
        . "<IfModule mod_authz_core.c>\n"
        . "    Require all denied\n"
        . "</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n"
        . "    Order allow,deny\n"
        . "    Deny from all\n"
        . "</IfModule>\n"
        . "Options -Indexes\n";

    /**
     * SQL-дамп БД в ZIP.
     *
     * Легаси-исключение: дамп делается через внешний бинарник mysqldump —
     * D7-средств для последовательного дампа всей БД (с рутинами/событиями) в ядре нет.
     * Каталог bitrix/backup/bx_admin_s_bacaps/ — единственное разрешённое место
     * записи внутри bitrix/ (служебный каталог бэкапов модуля).
     *
     * @param bool $withModuleTables false — не включать служебные таблицы модуля
     *                               (дамп для отката данных сайта)
     * @return array{file:string,size:int,created_at:string}
     * @throws \RuntimeException безопасное сообщение без паролей
     */
    public static function create(bool $withModuleTables = true): array
    {
        if (!class_exists('\ZipArchive')) {
            throw new \RuntimeException('Класс ZipArchive недоступен в PHP — включите расширение zip, бэкап невозможен.');
        }

        $dir = self::ensureDir();

        $config = \Bitrix\Main\Application::getConnection()->getConfiguration();
        $dbName = (string)($config['database'] ?? '');
        $login = (string)($config['login'] ?? '');
        $password = (string)($config['password'] ?? '');
        $host = (string)($config['host'] ?? 'localhost');

        if ($dbName === '' || $login === '') {
            throw new \RuntimeException('Не удалось получить параметры подключения к БД из настроек Битрикса.');
        }

        $mysqldump = self::findMysqldump();

        $stamp = date('d-m-Y_H-i');
        $plainPath = $dir . '/bazarow_' . $stamp . self::DUMP_SUFFIX;
        $tempPath = $plainPath . '.part';

        $descriptors = array(
            0 => array('pipe', 'r'),
            1 => array('file', $tempPath, 'w'),
            2 => array('pipe', 'w'),
        );

        // Пароль передаётся только через окружение MYSQL_PWD — в командной строке он засветился бы в ps.
        $env = getenv();
        if (!is_array($env)) {
            $env = array();
        }
        $env['MYSQL_PWD'] = $password;
        $command = self::buildDumpCommand($mysqldump, $host, $login, $dbName, $withModuleTables);

        $process = @proc_open($command, $descriptors, $pipes, null, $env);
        if (!is_resource($process)) {
            @unlink($tempPath);
            throw new \RuntimeException('Не удалось запустить mysqldump: ' . $mysqldump);
        }

        fclose($pipes[0]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0 || !is_file($tempPath) || filesize($tempPath) <= 0) {
            @unlink($tempPath);
            Logger::error('Backup: mysqldump завершился с кодом ' . $exitCode, array(
                'stderr' => mb_substr($stderr, 0, 500),
            ));
            throw new \RuntimeException('mysqldump завершился с ошибкой (код ' . $exitCode . ').');
        }

        rename($tempPath, $plainPath);

        try {
            $archivePath = self::zipDump($plainPath, $stamp, $dir);
        } finally {
            // Чистый .sql не оставляем ни при каком исходе — финальный артефакт только ZIP.
            @unlink($plainPath);
        }

        self::prune();

        clearstatcache(true, $archivePath);

        return array(
            'file' => basename($archivePath),
            'size' => (int)filesize($archivePath),
            'created_at' => date('Y-m-d H:i:s', filemtime($archivePath)),
        );
    }

    /**
     * Архивы каталога бэкапов, свежие сначала.
     *
     * @return array<int, array{file:string,size:int,mtime:int}>
     */
    public static function list(): array
    {
        try {
            self::ensureDir();
        } catch (\Throwable $e) {
            Logger::error('Backup: не удалось подготовить каталог дампов: ' . $e->getMessage());
        }

        $dir = self::dirPath();
        $items = array();

        foreach (glob($dir . '/*' . self::ARCHIVE_SUFFIX) ?: array() as $path) {
            if (!is_file($path)) {
                continue;
            }
            $items[] = array(
                'file' => basename($path),
                'size' => (int)filesize($path),
                'mtime' => (int)filemtime($path),
            );
        }

        usort($items, static function (array $a, array $b): int {
            return $b['mtime'] <=> $a['mtime'];
        });

        return $items;
    }

    /**
     * Восстанавливает базу из архива. Крайне опасная операция: текущие данные
     * заменяются содержимым дампа.
     *
     * Перед восстановлением создаётся страховочный дамп текущего состояния —
     * без него ошибка в дампе означала бы потерю сайта.
     *
     * @return array{file:string,safety_backup:string,elapsed:int}
     */
    public static function restore(string $file, bool $withModuleTables = true): array
    {
        $name = basename($file);
        if ($name === '' || !str_ends_with($name, self::ARCHIVE_SUFFIX)) {
            throw new \RuntimeException('Восстановить можно только архив .sql.zip из каталога бэкапов.');
        }

        $archivePath = self::dirPath() . '/' . $name;
        if (!is_file($archivePath)) {
            throw new \RuntimeException('Архив не найден: ' . $name);
        }

        if (!class_exists('\ZipArchive')) {
            throw new \RuntimeException('Расширение zip недоступно в PHP — восстановление невозможно.');
        }

        $mysql = BinaryFinder::find('mysql');
        if ($mysql === null) {
            throw new \RuntimeException('Клиент mysql не найден на сервере — восстановление невозможно.');
        }

        $config = \Bitrix\Main\Application::getConnection()->getConfiguration();
        $dbName = (string)($config['database'] ?? '');
        $login = (string)($config['login'] ?? '');
        $password = (string)($config['password'] ?? '');
        $host = (string)($config['host'] ?? 'localhost');

        if ($dbName === '' || $login === '') {
            throw new \RuntimeException('Не удалось получить параметры подключения к БД.');
        }

        // Страховочный дамп: без него ошибка в архиве уничтожит сайт.
        // Состав тот же, что и у восстанавливаемого дампа.
        $safety = self::create($withModuleTables);

        $zip = new \ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new \RuntimeException('Не удалось открыть архив ' . $name . '.');
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'bxadmins_restore_');
        if ($tempPath === false) {
            $zip->close();
            throw new \RuntimeException('Не удалось создать временный файл.');
        }

        $entry = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = (string)$zip->getNameIndex($i);
            if (str_ends_with($entryName, self::DUMP_SUFFIX)) {
                $entry = $entryName;
                break;
            }
        }

        if ($entry === null) {
            $zip->close();
            @unlink($tempPath);
            throw new \RuntimeException('В архиве нет SQL-дампа.');
        }

        $stream = $zip->getStream($entry);
        if (!is_resource($stream)) {
            $zip->close();
            @unlink($tempPath);
            throw new \RuntimeException('Не удалось прочитать SQL-дамп из архива.');
        }

        $out = fopen($tempPath, 'wb');
        if ($out === false) {
            fclose($stream);
            $zip->close();
            @unlink($tempPath);
            throw new \RuntimeException('Не удалось записать временный файл.');
        }

        stream_copy_to_stream($stream, $out);
        fclose($out);
        fclose($stream);
        $zip->close();

        $started = microtime(true);

        $env = getenv();
        if (!is_array($env)) {
            $env = array();
        }
        $env['MYSQL_PWD'] = $password;

        $command = implode(' ', array_map('escapeshellarg', array(
            $mysql,
            '--host=' . $host,
            '--user=' . $login,
            '--default-character-set=utf8mb4',
            $dbName,
        ))) . ' < ' . escapeshellarg($tempPath);

        $descriptors = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );

        $process = @proc_open($command, $descriptors, $pipes, null, $env);

        if (!is_resource($process)) {
            @unlink($tempPath);
            throw new \RuntimeException('Не удалось запустить mysql для восстановления.');
        }

        fclose($pipes[0]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        @unlink($tempPath);

        if ($exitCode !== 0) {
            Logger::error('Backup: восстановление завершилось с кодом ' . $exitCode, array(
                'file' => $name,
                'stderr' => mb_substr($stderr, 0, 500),
            ));

            throw new \RuntimeException(
                'Восстановление завершилось с ошибкой (код ' . $exitCode . ').'
                . ' Страховочный дамп: ' . $safety['file'] . '.'
            );
        }

        return array(
            'file' => $name,
            'safety_backup' => (string)$safety['file'],
            'elapsed' => (int)round(microtime(true) - $started),
        );
    }

    /** Удаляет файл только из каталога бэкапов и только валидный архив. */
    public static function delete(string $file): void
    {
        $name = basename($file);
        if ($name === '' || !str_ends_with($name, self::ARCHIVE_SUFFIX)) {
            throw new \RuntimeException('Можно удалять только архивы .sql.zip из каталога бэкапов.');
        }

        $path = self::dirPath() . '/' . $name;
        if (!is_file($path)) {
            throw new \RuntimeException('Файл не найден: ' . $name);
        }

        if (!@unlink($path)) {
            throw new \RuntimeException('Не удалось удалить файл: ' . $name);
        }
    }

    /**
     * Оставляет N самых свежих архивов (Config::backupKeep()).
     * backup_keep = 0 трактуется как «хранить один последний дамп»: полный ноль
     * означал бы молчаливое удаление только что созданного бэкапа — опасная опечатка.
     */
    private static function prune(): void
    {
        $keep = max(1, Config::backupKeep());
        // Дампы, на которые ссылается история изменений, нужны для отката:
        // удалять их по счётчику нельзя, иначе точка отката останется без базы.
        $referenced = self::referencedFiles(30);

        $newest = 0;
        foreach (self::list() as $item) {
            if ($newest < $keep) {
                $newest++;

                continue;
            }

            if (isset($referenced[$item['file']])) {
                continue;
            }

            @unlink(self::dirPath() . '/' . $item['file']);
        }
    }

    /**
     * Файлы дампов, на которые ссылаются последние изменения.
     *
     * @return array<string,true>
     */
    private static function referencedFiles(int $changes): array
    {
        $rows = ChangeTable::getList(array(
            'select' => array('BACKUP_FILE'),
            'filter' => array('!BACKUP_FILE' => false),
            'order' => array('ID' => 'DESC'),
            'limit' => $changes,
        ))->fetchAll();

        $files = array();
        foreach ($rows as $row) {
            $file = trim((string)($row['BACKUP_FILE'] ?? ''));
            if ($file !== '') {
                $files[$file] = true;
            }
        }

        return $files;
    }

    /** Каталог дампов — нужен инструментам, которые отправляют архив наружу. */
    public static function dirPath(): string
    {
        return Loader::getDocumentRoot() . self::DIR;
    }

    /**
     * Состояние каталога бэкапов для UI (без секретов).
     *
     * @return array{dir:string,path:string,exists:bool,writable:bool,htaccess:bool,http_denied:bool,keep:int}
     */
    public static function status(): array
    {
        try {
            self::ensureDir();
        } catch (\Throwable $e) {
            Logger::error('Backup: не удалось подготовить каталог дампов: ' . $e->getMessage());
        }

        $dir = self::dirPath();
        $htaccessPath = $dir . '/.htaccess';
        $htaccess = is_file($htaccessPath) ? (string)@file_get_contents($htaccessPath) : '';

        return array(
            'dir' => self::DIR,
            'path' => $dir,
            'exists' => is_dir($dir),
            'writable' => is_dir($dir) && is_writable($dir),
            'htaccess' => is_file($htaccessPath),
            'http_denied' => self::containsDenyRule($htaccess),
            'keep' => Config::backupKeep(),
        );
    }

    private static function ensureDir(): string
    {
        $dir = self::dirPath();

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать каталог бэкапов: ' . $dir);
        }

        self::ensureHtaccess($dir);

        return $dir;
    }

    /**
     * Создаёт .htaccess, если его нет или в нём нет запрета.
     * Существующий пользовательский файл с deny-правилом не трогаем вообще.
     */
    private static function ensureHtaccess(string $dir): void
    {
        $path = $dir . '/.htaccess';
        $contents = is_file($path) ? (string)@file_get_contents($path) : '';

        if (self::containsDenyRule($contents)) {
            return;
        }

        $payload = $contents === '' ? self::HTACCESS_RULES : rtrim($contents) . "\n\n" . self::HTACCESS_RULES;

        if (@file_put_contents($path, $payload, LOCK_EX) === false) {
            throw new \RuntimeException('Не удалось записать защитный .htaccess в каталог бэкапов.');
        }
    }

    private static function containsDenyRule(string $contents): bool
    {
        return stripos($contents, 'Require all denied') !== false
            || stripos($contents, 'Deny from all') !== false;
    }

    private static function buildDumpCommand(
        string $mysqldump,
        string $host,
        string $login,
        string $dbName,
        bool $withModuleTables
    ): string {
        $parts = array(
            $mysqldump,
            '--host=' . $host,
            '--user=' . $login,
            '--single-transaction',
            '--quick',
            '--routines',
            '--events',
            '--default-character-set=utf8mb4',
        );

        if (!$withModuleTables) {
            foreach (self::MODULE_TABLES as $table) {
                $parts[] = '--ignore-table=' . $dbName . '.' . $table;
            }
        }

        $parts[] = $dbName;

        return implode(' ', array_map('escapeshellarg', $parts));
    }

    private static function findMysqldump(): string
    {
        // Homebrew ставит mysql@8.4 в keg-only каталог, которого нет в PATH.
        $homebrew = '/opt/homebrew/opt/mysql@8.4/bin/mysqldump';
        $found = is_executable($homebrew) ? $homebrew : BinaryFinder::find('mysqldump');

        if ($found === null) {
            throw new \RuntimeException('mysqldump не найден на сервере — установите клиент MySQL/MariaDB, бэкап невозможен.');
        }

        return $found;
    }

    private static function zipDump(string $plainPath, string $stamp, string $dir): string
    {
        $archivePath = $dir . '/bazarow_' . $stamp . self::ARCHIVE_SUFFIX;
        $counter = 2;
        while (file_exists($archivePath)) {
            $archivePath = $dir . '/bazarow_' . $stamp . '-' . $counter . self::ARCHIVE_SUFFIX;
            $counter++;
        }

        $zip = new \ZipArchive();
        if ($zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            throw new \RuntimeException('Не удалось создать ZIP-архив: ' . basename($archivePath));
        }

        if (!$zip->addFile($plainPath, basename($plainPath))) {
            $zip->close();
            @unlink($archivePath);
            throw new \RuntimeException('Не удалось добавить дамп в ZIP-архив.');
        }

        $zip->setArchiveComment('Created ' . date('Y-m-d H:i:s') . ' by BXADMIN-S');

        if (!$zip->close()) {
            @unlink($archivePath);
            throw new \RuntimeException('Запись ZIP-архива прервалась — архив удалён.');
        }

        return $archivePath;
    }
}
