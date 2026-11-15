<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\BinaryFinder;

/**
 * Выполняет PHP-код в контексте Битрикса — последний довод, когда готового
 * инструмента для API нет. Прямые SQL-запросы и оболочка запрещены.
 */
class RunPhpTool extends AbstractChangeTool
{
    public const DOMAIN = 'core';

    private const MAX_CODE = 8000;
    private const MAX_OUTPUT = 20000;
    private const TIMEOUT = 30;

    public static function getName(): string
    {
        return 'run_php';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'run_php',
            'description' => 'Выполняет PHP-код в контексте Битрикса (D7 API), когда для задачи нет готового инструмента. Только с подтверждением владельца. Прямые SQL-запросы, getConnection/queryExecute, shell, eval и запись файлов запрещены — файлы меняются только файловыми инструментами; изменения данных лучше делать штатными инструментами.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'code' => array('type' => 'string', 'description' => 'PHP-код без открывающего <?php. Может использовать \Bitrix\Main\Loader::includeModule и D7 API.'),
                    'description' => array('type' => 'string', 'description' => 'Что делает код — коротко.'),
                ),
                'required' => array('code'),
            ),
        );
    }

    public static function preview(array $args, array $context = array()): array
    {
        $code = self::normalizeCode((string)($args['code'] ?? ''));
        $description = trim((string)($args['description'] ?? ''));

        if ($code === '') {
            throw new \InvalidArgumentException('Нужен PHP-код.');
        }
        if (mb_strlen($code) > self::MAX_CODE) {
            throw new \InvalidArgumentException('Код слишком длинный: максимум ' . self::MAX_CODE . ' символов.');
        }

        self::assertSafe($code);

        return array(
            'summary' => 'PHP-код: ' . ($description !== '' ? $description : mb_substr(preg_replace('/\s+/', ' ', $code), 0, 80)),
            'payload' => array('args' => array('code' => $code, 'description' => $description, 'user_id' => (int)($context['user_id'] ?? 0))),
            'preview' => ($description !== '' ? $description . "\n\n" : '') . $code,
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());
        $code = self::normalizeCode((string)($args['code'] ?? ''));
        $userId = (int)($args['user_id'] ?? 0);

        self::assertSafe($code);

        $root = \Bitrix\Main\Application::getDocumentRoot();
        $file = tempnam(sys_get_temp_dir(), 'bxadmins-php-');
        if ($file === false) {
            throw new \RuntimeException('Не удалось создать временный файл.');
        }

        $host = (string)parse_url(\Mibazarow\Bxadmins\Config::daemonSiteUrl(), PHP_URL_HOST) ?: 'localhost';
        $script = "<?php\ndeclare(strict_types=1);\n"
            . '$_SERVER["DOCUMENT_ROOT"] = ' . var_export($root, true) . ";\n"
            . '$_SERVER["REQUEST_URI"] = "/";' . "\n"
            . '$_SERVER["SERVER_NAME"] = ' . var_export($host, true) . ";\n"
            . '$_SERVER["HTTP_HOST"] = ' . var_export($host, true) . ";\n"
            . "define('NO_KEEP_STATISTIC', true);\n"
            . "define('NOT_CHECK_PERMISSIONS', true);\n"
            . "require " . var_export($root . '/bitrix/modules/main/include/prolog_before.php', true) . ";\n"
            . ($userId > 0
                ? 'global $USER; $USER = new CUser; $USER->Authorize(' . $userId . ");\n"
                : '')
            . "set_exception_handler(static function (\\Throwable \$e): void {\n"
            . "    fwrite(STDERR, get_class(\$e) . ': ' . \$e->getMessage());\n"
            . "    exit(1);\n"
            . "});\n"
            . "register_shutdown_function(static function (): void {\n"
            . "    \$err = error_get_last();\n"
            . "    if (\$err !== null && in_array(\$err['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {\n"
            . "        fwrite(STDERR, 'Fatal: ' . \$err['message']);\n"
            . "    }\n"
            . "});\n"
            . $code . "\n";
        file_put_contents($file, $script);

        try {
            [$exit, $stdout, $stderr] = self::run($file);
        } finally {
            @unlink($file);
        }

        $out = trim($stdout);
        $err = trim($stderr);

        if ($exit !== 0) {
            throw new \RuntimeException(
                'PHP завершился с кодом ' . $exit . '. '
                . mb_substr($err !== '' ? $err : $out, 0, 1000)
            );
        }

        return array(
            'exit_code' => $exit,
            'output' => mb_substr($out, 0, self::MAX_OUTPUT),
            'stderr' => mb_substr($err, 0, 2000),
        );
    }

    public static function rollbackNeedsRestore(): bool
    {
        // Код мог изменить что угодно — откат только через точку отката сайта.
        return true;
    }

    public static function rollback(array $payload): void
    {
        // Откат выполняется через SiteRestoreService (см. rollbackNeedsRestore).
    }

    private static function normalizeCode(string $code): string
    {
        $code = preg_replace('/^\s*<\?php/', '', $code) ?? $code;

        return trim($code);
    }

    private static function assertSafe(string $code): void
    {
        $forbidden = array(
            'getConnection', 'queryExecute', '$DB->', 'CDatabase',
            'shell_exec', 'passthru', 'proc_open', 'popen', 'exec(', 'system(',
            'eval(', 'assert(', 'create_function',
            // Запись файлов — только через write_local_file/write_site_file и модульные
            // инструменты: так работают проверки путей и запрет на чужие модули.
            'file_put_contents', 'fopen(', 'fwrite(', 'fputs(', 'unlink(', 'rename(', 'copy(',
            'mkdir(', 'rmdir(', 'chmod(', 'touch(', 'symlink(', 'link(',
            'File::putFileContents', 'File::deleteFile',
            'Directory::createDirectory', 'Directory::deleteDirectory',
        );

        foreach ($forbidden as $needle) {
            if (str_contains($code, $needle)) {
                throw new \InvalidArgumentException(
                    'В run_php запрещено: ' . $needle . '. SQL-запись, файлы и оболочка — только через штатные инструменты.'
                );
            }
        }

        if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|REPLACE)\s+(INTO|FROM|TABLE|`)/i', $code)) {
            throw new \InvalidArgumentException('SQL-запросы на запись в run_php запрещены.');
        }

        self::assertNonStaticCalls($code);
    }

    /**
     * Классические методы Add/Update у инфоблоков — нестатические. Вызов через
     * :: бросает фатальный Error, который Bitrix показывает как «ошибка скрипта».
     * Ловим это на превью и сразу подсказываем правильную форму.
     */
    private static function assertNonStaticCalls(string $code): void
    {
        $pattern = '/\b(CIBlockSection|CAllIBlockSection|CIBlockElement|CAllIBlockElement|CIBlock|CIBlockType|CIBlockProperty)::(Add|Update)\s*\(/';
        if (preg_match($pattern, $code, $match) !== 1) {
            return;
        }

        $class = str_replace('CAllI', 'CI', $match[1]);
        $method = $match[2];

        throw new \InvalidArgumentException(
            'Метод ' . $match[1] . '::' . $method . '() нестатический. '
            . 'Используй (new ' . $class . '())->' . $method . '(...).'
        );
    }

    /** @return array{0:int,1:string,2:string} */
    /**
     * Путь к PHP CLI. В Apache/mod_php константа PHP_BINARY пустая, поэтому
     * берём PHP_BINDIR/php, а затем ищем бинарник в PATH.
     */
    private static function phpBinary(): string
    {
        $binary = defined('PHP_BINARY') ? (string)PHP_BINARY : '';
        if ($binary !== '' && is_executable($binary)) {
            return $binary;
        }

        $bindir = defined('PHP_BINDIR') ? rtrim((string)PHP_BINDIR, '/') : '';
        if ($bindir !== '' && is_executable($bindir . '/php')) {
            return $bindir . '/php';
        }

        $found = BinaryFinder::find('php');
        if ($found !== null) {
            return $found;
        }

        throw new \RuntimeException('Не удалось найти PHP CLI для run_php.');
    }

    private static function run(string $file): array
    {
        $descriptors = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );

        $process = proc_open(array(self::phpBinary(), $file), $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Не удалось запустить PHP.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $started = microtime(true);

        while (true) {
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);
            $status = proc_get_status($process);

            if (!$status['running']) {
                break;
            }

            if (microtime(true) - $started > self::TIMEOUT) {
                proc_terminate($process, 9);
                break;
            }

            usleep(100000);
        }

        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);

        return array($exit, $stdout, $stderr);
    }
}
