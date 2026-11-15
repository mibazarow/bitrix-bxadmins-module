<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\IO\Directory;
use Bitrix\Main\IO\File;
use Mibazarow\Bxadmins\Service\BinaryFinder;
use Mibazarow\Bxadmins\Service\FileSnapshotService;
use Mibazarow\Bxadmins\Service\LocalFileGuard;
use Mibazarow\Bxadmins\Service\Logger;
use Mibazarow\Bxadmins\Service\Markdown;

/**
 * Создание и изменение файла внутри local/: превью, снапшот, откат.
 */
class WriteLocalFileTool extends AbstractChangeTool
{
    public const DOMAIN = 'content';

    public static function needsSqlDump(): bool
    {
        // Файл восстанавливается git-точкой; база не меняется.
        return false;
    }

    private const MAX_CONTENT_BYTES = 512000;
    private const MAX_EXISTING_BYTES = 1048576;
    private const PREVIEW_LIMIT = 19500;
    private const EXCERPT_LIMIT = 4000;

    public static function getName(): string
    {
        return 'write_local_file';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'write_local_file',
            'description' => 'СОЗДАНИЕ или ИЗМЕНЕНИЕ файла внутри /local/ (запись). Без bypass возвращает requires_confirmation с диффом: файл ещё не записан, пользователь должен подтвердить карточку в чате. С bypass запись выполняется сразу после снапшота (SQL-дамп для файловых изменений не создаётся). Запрещены bitrix/, upload/, local/php_interface/, сам модуль mibazarow.bxadmins, сторонние модули в local/modules (править можно только модули, созданные агентом через create_module), скрытые файлы и каталоги. Разрешены расширения: php, css, scss, js, html, xml, json, svg, md, txt, sql, csv, yml, yaml, inc, tpl. Не утверждай, что файл создан или изменён, пока результат не содержит executed=true.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'path' => array(
                        'type' => 'string',
                        'description' => 'Путь относительно корня сайта, обязательно внутри local/ (например: local/templates/mb/style.css). Без ведущего слэша и без «..».',
                    ),
                    'content' => array(
                        'type' => 'string',
                        'description' => 'Полное новое содержимое файла в UTF-8, до 500 КБ.',
                    ),
                ),
                'required' => array('path', 'content'),
            ),
        );
    }

    /**
     * Готовит изменение: проверяет путь, читает текущее содержимое, считает diff.
     * payload: args (path, content) + before (exists, hash).
     */
    public static function preview(array $args): array
    {
        $path = LocalFileGuard::assertWritableLocalPath((string)($args['path'] ?? ''));
        $content = self::content($args['content'] ?? null);
        $absolute = LocalFileGuard::absolute($path);
        $exists = is_file($absolute);

        $before = null;
        $beforeHash = null;

        if ($exists) {
            $size = filesize($absolute);
            if ($size === false || $size > self::MAX_EXISTING_BYTES) {
                throw new \InvalidArgumentException('Текущий файл слишком большой для безопасного предпросмотра (максимум 1 МБ).');
            }

            $before = file_get_contents($absolute);
            if (!is_string($before)) {
                throw new \InvalidArgumentException('Не удалось прочитать текущий файл для предпросмотра.');
            }

            if ($before === $content) {
                throw new \InvalidArgumentException('Содержимое файла не изменилось.');
            }

            $beforeHash = hash('sha256', $before);
        }

        return array(
            'summary' => ($exists ? 'Изменить файл ' : 'Создать файл ') . $path,
            'preview' => self::buildPreview($path, $exists ? $before : null, $content),
            'payload' => array(
                'args' => array('path' => $path, 'content' => $content),
                'before' => array('exists' => $exists, 'hash' => $beforeHash),
            ),
        );
    }

    /**
     * Пишет файл после снапшота. Если файл изменился с момента preview — отказ.
     *
     * @return array{path:string,bytes:int,snapshot:array}
     */
    public static function apply(array $payload): array
    {
        $changeId = (int)($payload['change_id'] ?? 0);
        $path = LocalFileGuard::assertWritableLocalPath((string)($payload['args']['path'] ?? ''));
        $content = self::content($payload['args']['content'] ?? null);
        $before = isset($payload['before']) && is_array($payload['before']) ? $payload['before'] : array();

        $absolute = LocalFileGuard::absolute($path);
        $existsNow = is_file($absolute);
        $expectedExists = !empty($before['exists']);
        $expectedHash = isset($before['hash']) ? (string)$before['hash'] : '';
        $currentHash = $existsNow ? hash_file('sha256', $absolute) : null;

        $unchanged = $existsNow === $expectedExists
            && (!$existsNow || (is_string($currentHash) && $expectedHash !== '' && hash_equals($expectedHash, $currentHash)));

        if (!$unchanged) {
            throw new \InvalidArgumentException('Файл изменился после предпросмотра — сформируйте изменение заново');
        }

        $snapshot = FileSnapshotService::snapshot($path, $changeId);

        $snapshotMatches = ((bool)$snapshot['existed']) === $expectedExists
            && (!$expectedExists || ($expectedHash !== '' && ($snapshot['hash'] ?? '') === $expectedHash));

        if (!$snapshotMatches) {
            throw new \InvalidArgumentException('Файл изменился после предпросмотра — сформируйте изменение заново');
        }

        try {
            Directory::createDirectory(dirname($absolute));

            $bytes = File::putFileContents($absolute, $content);
            if ($bytes === false || (int)$bytes !== strlen($content)) {
                throw new \RuntimeException('Не удалось записать файл полностью.');
            }
        } catch (\Throwable $e) {
            // Частично записанный файл не оставляем: возвращаем снапшот (или удаляем новый файл).
            try {
                FileSnapshotService::restore(array('path' => $path, 'snapshot' => $snapshot));
            } catch (\Throwable $restoreError) {
                Logger::error('WriteLocalFileTool: не удалось отменить частичную запись', array(
                    'path' => $path,
                ));
            }

            throw new \RuntimeException('Не удалось записать файл — изменение отменено.');
        }

        // Отдельный коммит не нужен: точку отката (SQL-дамп + коммит всей структуры)
        // создаёт ChangeService до и после изменения.
        return array(
            'path' => $path,
            'bytes' => (int)$bytes,
            'snapshot' => $snapshot,
        );
    }

    /** Возвращает файл из снапшота (или удаляет созданный). */
    public static function rollback(array $payload): void
    {
        // Откат — всегда из снапшота; git-коммит не откатываем (см. GitService).
        FileSnapshotService::restore($payload);
    }

    private static function content($raw): string
    {
        if (!is_string($raw)) {
            throw new \InvalidArgumentException('Параметр content обязателен и должен быть строкой.');
        }

        if (str_contains($raw, "\0")) {
            throw new \InvalidArgumentException('Содержимое файла содержит недопустимые символы (null byte).');
        }

        if (strlen($raw) > self::MAX_CONTENT_BYTES) {
            throw new \InvalidArgumentException('Содержимое файла слишком большое (максимум 500 КБ).');
        }

        if (!mb_check_encoding($raw, 'UTF-8')) {
            throw new \InvalidArgumentException('Содержимое файла должно быть в кодировке UTF-8.');
        }

        return $raw;
    }

    private static function buildPreview(string $path, ?string $before, string $content): string
    {
        $diff = self::unifiedDiff($path, $before, $content);
        if ($diff !== null && $diff !== '') {
            return self::cap(Markdown::code($diff, 'diff'));
        }

        return self::cap(Markdown::code(self::excerpt($before, $content)));
    }

    private static function unifiedDiff(string $path, ?string $before, string $content): ?string
    {
        $binary = BinaryFinder::find('diff');
        if ($binary === null) {
            return null;
        }

        $tempDir = realpath(sys_get_temp_dir());
        $root = realpath(LocalFileGuard::root());
        if ($tempDir === false || $root === false
            || $tempDir === $root || str_starts_with($tempDir, $root . '/')) {
            return null;
        }

        $oldFile = tempnam($tempDir, 'bxadmins_old_');
        $newFile = tempnam($tempDir, 'bxadmins_new_');
        if ($oldFile === false || $newFile === false) {
            if (is_string($oldFile)) {
                @unlink($oldFile);
            }
            if (is_string($newFile)) {
                @unlink($newFile);
            }

            return null;
        }

        try {
            if (@file_put_contents($oldFile, $before ?? '') === false
                || @file_put_contents($newFile, $content) === false) {
                return null;
            }

            $descriptors = array(
                0 => array('pipe', 'r'),
                1 => array('pipe', 'w'),
                2 => array('pipe', 'w'),
            );

            $command = array(
                $binary,
                '-u',
                '-L', 'a/' . $path,
                '-L', 'b/' . $path,
                $oldFile,
                $newFile,
            );

            $process = @proc_open($command, $descriptors, $pipes, $tempDir, null);
            if (!is_resource($process)) {
                return null;
            }

            fclose($pipes[0]);
            $stdout = (string)stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            if ($exitCode > 1 || trim($stdout) === '') {
                return null;
            }

            return $stdout;
        } finally {
            @unlink($oldFile);
            @unlink($newFile);
        }
    }

    private static function excerpt(?string $before, string $content): string
    {
        $marker = "\n… (фрагмент сокращён)";

        if ($before === null) {
            $text = "Файл будет создан. Содержимое (" . strlen($content) . " байт):\n\n" . $content;

            return self::cap($text, $marker);
        }

        $text = "Файл будет изменён.\n\nБыло (" . strlen($before) . " байт), первые строки:\n\n"
            . self::truncate($before, self::EXCERPT_LIMIT)
            . "\n\nСтанет (" . strlen($content) . " байт), первые строки:\n\n"
            . self::truncate($content, self::EXCERPT_LIMIT);

        return self::cap($text, $marker);
    }

    private static function cap(string $text, string $marker = "\n… (предпросмотр сокращён, показаны первые " . self::PREVIEW_LIMIT . " символов)"): string
    {
        $length = mb_check_encoding($text, 'UTF-8') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($length <= self::PREVIEW_LIMIT) {
            return $text;
        }

        return self::truncate($text, self::PREVIEW_LIMIT) . $marker;
    }

    private static function truncate(string $text, int $limit): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            return substr($text, 0, $limit);
        }

        return mb_substr($text, 0, $limit, 'UTF-8');
    }
}
