<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Config;

/**
 * Чтение хвоста журналов модуля. Только файлы из Config::logDir(): наружу
 * (nginx/apache/php) инструмент не ходит, чтобы не читать чужие секреты.
 */
class ReadLogTool extends AbstractTool
{
    public const DOMAIN = 'security';

    private const FILES = array(
        'daemon' => 'daemon.log',
        'scrapling' => 'scrapling-mcp.log',
        'module' => '',
    );

    private const MAX_LINES = 500;
    private const MAX_BYTES = 524288;

    public static function getName(): string
    {
        return 'read_log';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'read_log',
            'description' => 'Читает хвост журналов модуля: daemon (демон), module (ошибки модуля за сегодня), scrapling (MCP-скрапер). Можно отфильтровать по подстроке. Только чтение.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'file' => array(
                        'type' => 'string',
                        'enum' => array('daemon', 'module', 'scrapling'),
                        'description' => 'Какой журнал читать, по умолчанию daemon',
                    ),
                    'lines' => array('type' => 'integer', 'description' => 'Сколько последних строк, по умолчанию 100, максимум 500'),
                    'grep' => array('type' => 'string', 'description' => 'Показать только строки, содержащие подстроку (без учёта регистра)'),
                ),
            ),
        );
    }

    /** @return array<string,mixed>|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        $file = strtolower(trim((string)($args['file'] ?? 'daemon')));
        if (!array_key_exists($file, self::FILES)) {
            return array('error' => 'file должен быть daemon, module или scrapling.');
        }

        $lines = max(1, min(self::MAX_LINES, (int)($args['lines'] ?? 100)));
        $grep = trim((string)($args['grep'] ?? ''));
        if (mb_strlen($grep) > 200) {
            return array('error' => 'grep слишком длинный (максимум 200 символов).');
        }

        $path = self::resolve($file);
        if ($path === null) {
            return array('error' => 'Журнал не найден: ' . $file . '.');
        }

        $tail = self::tail($path);
        if ($tail['error'] !== null) {
            return array('error' => $tail['error']);
        }

        $result = $tail['lines'];

        if ($grep !== '') {
            $result = array_values(array_filter($result, static function (string $line) use ($grep): bool {
                return mb_stripos($line, $grep) !== false;
            }));
        }

        $count = count($result);
        if ($count > $lines) {
            $result = array_slice($result, -$lines);
        }

        return array(
            'file' => basename($path),
            'lines' => $result,
            'count' => count($result),
            'truncated' => $tail['truncated'] || $count > $lines,
        );
    }

    private static function resolve(string $file): ?string
    {
        $dir = Config::logDir();
        $name = self::FILES[$file];

        if ($name === '') {
            $latest = null;
            foreach (glob($dir . '/bxadmins-*.log') ?: array() as $candidate) {
                if (!is_file($candidate)) {
                    continue;
                }

                if ($latest === null || filemtime($candidate) > filemtime($latest)) {
                    $latest = $candidate;
                }
            }

            $path = $latest;
        } else {
            $path = $dir . '/' . $name;
        }

        if ($path === null || !is_file($path) || is_link($path)) {
            return null;
        }

        $real = realpath($path);
        $realDir = realpath($dir);

        if ($real === false || $realDir === false || !str_starts_with($real, $realDir . '/')) {
            return null;
        }

        return $real;
    }

    /** @return array{lines:string[],truncated:bool,error:?string} */
    private static function tail(string $path): array
    {
        $size = (int)filesize($path);
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return array('lines' => array(), 'truncated' => false, 'error' => 'Не удалось открыть журнал.');
        }

        $read = min($size, self::MAX_BYTES);
        $truncated = $size > self::MAX_BYTES;

        if ($read > 0) {
            fseek($handle, -$read, SEEK_END);
        }

        $data = $read > 0 ? (string)fread($handle, $read) : '';
        fclose($handle);

        $lines = $data === '' ? array() : explode("\n", rtrim($data, "\n"));

        // Первая строка может быть обрезана посередине — убираем её.
        if ($truncated && $lines !== array()) {
            array_shift($lines);
        }

        return array('lines' => $lines, 'truncated' => $truncated, 'error' => null);
    }
}
