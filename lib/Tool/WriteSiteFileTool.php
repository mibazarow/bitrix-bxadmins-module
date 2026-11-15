<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\BinaryFinder;
use Mibazarow\Bxadmins\Service\FileSnapshotService;
use Mibazarow\Bxadmins\Service\LocalFileGuard;
use Mibazarow\Bxadmins\Service\Markdown;

/**
 * Создание и изменение любого файла сайта (страницы, меню, стили, .htaccess).
 * Ядро, upload и local/php_interface защищены. Откат — из снапшота.
 */
class WriteSiteFileTool extends AbstractChangeTool
{
    public const DOMAIN = 'design';

    private const MAX_BYTES = 500000;

    public static function getName(): string
    {
        return 'write_site_file';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'write_site_file',
            'description' => 'Создаёт или меняет файл сайта: страницу, шаблон меню, CSS, .htaccess. Нельзя менять bitrix/, upload/ и local/php_interface/. Показывает diff и требует подтверждения.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'path' => array('type' => 'string', 'description' => 'Путь от корня сайта, например about/index.php или local/templates/site/style.css'),
                    'content' => array('type' => 'string', 'description' => 'Новое содержимое файла целиком'),
                    'summary' => array('type' => 'string', 'description' => 'Короткое описание правки для карточки подтверждения'),
                ),
                'required' => array('path', 'content'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $path = LocalFileGuard::assertSitePath((string)($args['path'] ?? ''));
        $content = (string)($args['content'] ?? '');

        if (strlen($content) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Содержимое больше 500 КБ — такую правку через агента не делаем.');
        }

        $absolute = LocalFileGuard::absolute($path);
        $exists = is_file($absolute);
        $before = $exists ? (string)file_get_contents($absolute) : '';

        $summary = trim((string)($args['summary'] ?? ''));
        if ($summary === '') {
            $summary = ($exists ? 'Изменение файла ' : 'Создание файла ') . $path;
        }

        return array(
            'summary' => mb_substr($summary, 0, 200),
            'payload' => array(
                'path' => $path,
                'args' => array('path' => $path, 'content' => $content),
                'before' => array('exists' => $exists, 'hash' => $exists ? md5($before) : ''),
            ),
            'preview' => self::diff($path, $before, $content, $exists),
        );
    }

    public static function apply(array $payload): array
    {
        $path = LocalFileGuard::assertSitePath((string)($payload['path'] ?? ''));
        $content = (string)($payload['args']['content'] ?? '');
        $absolute = LocalFileGuard::absolute($path);
        $before = (array)($payload['before'] ?? array());

        if (!empty($before['exists']) && is_file($absolute) && md5((string)file_get_contents($absolute)) !== (string)$before['hash']) {
            throw new \RuntimeException('Файл изменился после предпросмотра — повторите правку.');
        }

        $snapshot = FileSnapshotService::snapshot($path, (int)($payload['change_id'] ?? 0));

        $dir = dirname($absolute);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать каталог ' . dirname($path) . '.');
        }

        if (@file_put_contents($absolute, $content) === false) {
            throw new \RuntimeException('Не удалось записать файл ' . $path . '.');
        }

        @chmod($absolute, 0644);

        return array(
            'path' => $path,
            'bytes' => strlen($content),
            'snapshot' => $snapshot,
        );
    }

    public static function rollback(array $payload): void
    {
        FileSnapshotService::restore($payload);
    }

    private static function diff(string $path, string $before, string $after, bool $exists): string
    {
        if (!$exists) {
            return 'Новый файл ' . $path . ' (' . strlen($after) . " байт):\n\n"
                . Markdown::code(self::excerpt($after), self::language($path));
        }

        $diff = self::shellDiff($path, $before, $after);
        if ($diff !== null) {
            return 'Файл ' . $path . ":\n\n" . Markdown::code($diff, 'diff');
        }

        return 'Файл ' . $path . ': ' . strlen($before) . ' → ' . strlen($after) . " байт\n\n"
            . Markdown::code(self::excerpt($after), self::language($path));
    }

    /** Язык для подсветки по расширению — только для подписи блока. */
    private static function language(string $path): string
    {
        $extension = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, array('php', 'js', 'css', 'html', 'json', 'xml', 'sql', 'txt'), true)
            ? $extension
            : '';
    }

    /** Полноценный diff через системный diff, если он есть. */
    private static function shellDiff(string $path, string $before, string $after): ?string
    {
        $binary = BinaryFinder::find('diff');
        if ($binary === null) {
            return null;
        }

        $tmpBefore = tempnam(sys_get_temp_dir(), 'bxadmins_b_');
        $tmpAfter = tempnam(sys_get_temp_dir(), 'bxadmins_a_');

        if ($tmpBefore === false || $tmpAfter === false) {
            return null;
        }

        file_put_contents($tmpBefore, $before);
        file_put_contents($tmpAfter, $after);

        $descriptors = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
        $process = @proc_open(
            array($binary, '-u', '--label', 'a/' . $path, '--label', 'b/' . $path, $tmpBefore, $tmpAfter),
            $descriptors,
            $pipes
        );

        $output = '';

        if (is_resource($process)) {
            fclose($pipes[0]);
            $output = (string)stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }

        @unlink($tmpBefore);
        @unlink($tmpAfter);

        if ($output === '') {
            return null;
        }

        if (mb_strlen($output) > 20000) {
            $output = mb_substr($output, 0, 20000) . "\n… (diff обрезан)";
        }

        return $output;
    }

    private static function excerpt(string $content): string
    {
        return mb_strlen($content) > 4000
            ? mb_substr($content, 0, 4000) . "\n… (обрезано)"
            : $content;
    }
}
