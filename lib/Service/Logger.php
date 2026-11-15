<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Mibazarow\Bxadmins\Config;

/**
 * Журнал модуля в файл. По ТЗ хранятся только ошибки; в БД логи не пишем,
 * чтобы не раздувать базу сайта.
 */
class Logger
{
    private const FILE_PREFIX = 'bxadmins-';
    private const MAX_LINE = 4000;

    public static function error(string $message, array $context = array()): void
    {
        $days = Config::logRetentionDays();
        if ($days <= 0) {
            return;
        }

        try {
            $dir = Config::logDir();
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                return;
            }

            $line = date('Y-m-d H:i:s') . ' [error] '
                . mb_substr(str_replace(array("\r", "\n"), ' ', self::redact($message)), 0, self::MAX_LINE);

            if ($context !== array()) {
                $json = json_encode(self::redactContext($context), JSON_UNESCAPED_UNICODE);
                if (is_string($json)) {
                    $line .= ' ' . mb_substr($json, 0, self::MAX_LINE);
                }
            }

            @file_put_contents(
                $dir . '/' . self::FILE_PREFIX . date('Y-m-d') . '.log',
                $line . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );

            // Ротация изредка, чтобы не читать каталог на каждую запись.
            if (mt_rand(1, 100) === 1) {
                self::prune($days);
            }
        } catch (\Throwable) {
            // Логирование не должно ронять вызывающий код.
        }
    }

    /**
     * Последние строки журнала, свежие сверху.
     *
     * @return string[]
     */
    public static function tail(int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        $files = self::files();
        $lines = array();

        foreach ($files as $file) {
            $tail = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!is_array($tail)) {
                continue;
            }

            foreach (array_reverse($tail) as $line) {
                $lines[] = $line;
                if (count($lines) >= $limit) {
                    return $lines;
                }
            }
        }

        return $lines;
    }

    /** Удаляет файлы старше срока хранения. */
    public static function prune(int $days): void
    {
        $threshold = time() - max(1, $days) * 86400;

        foreach (self::files() as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $threshold) {
                @unlink($file);
            }
        }
    }

    /** @return string[] файлы журнала, свежие первыми */
    private static function files(): array
    {
        $dir = Config::logDir();
        $files = glob($dir . '/' . self::FILE_PREFIX . '*.log');
        if (!is_array($files)) {
            return array();
        }

        usort($files, static fn(string $a, string $b): int => strcmp($b, $a));

        return $files;
    }

    private const SECRET_HINTS = array(
        'password', 'passwd', 'secret', 'token', 'api_key', 'apikey',
        'private_key', 'credential', 'authorization', 'bearer', 'hash',
    );

    /** Прячет типовые токены/ключи в свободном тексте сообщения. */
    private static function redact(string $text): string
    {
        $text = (string)preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=-]{8,}/i', 'Bearer ***', $text);

        return (string)preg_replace(
            '/\b(sk|pk|ghp|gho|ghs|ghu|github_pat|xox[baprs])[-_][A-Za-z0-9_-]{8,}/i',
            '***',
            $text
        );
    }

    /** Маскирует значения полей с секретными именами (рекурсивно). */
    private static function redactContext(array $context): array
    {
        $result = array();

        foreach ($context as $key => $value) {
            $lower = strtolower((string)$key);
            $isSecret = false;

            foreach (self::SECRET_HINTS as $hint) {
                if (str_contains($lower, $hint)) {
                    $isSecret = true;
                    break;
                }
            }

            if ($isSecret) {
                $result[$key] = $value === null || $value === '' ? $value : '***';
            } elseif (is_array($value)) {
                $result[$key] = self::redactContext($value);
            } elseif (is_string($value)) {
                $result[$key] = self::redact($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
