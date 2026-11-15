<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;

/**
 * Базовый поиск подозрительных файлов в upload/: исполняемые расширения,
 * .htaccess и PHP-код внутри картинок. Только чтение, без удаления.
 */
class ScanUploadsTool extends AbstractTool
{
    public const DOMAIN = 'security';

    private const EXECUTABLE = array(
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht',
        'cgi', 'pl', 'py', 'sh', 'bash', 'exe', 'jsp', 'asp', 'aspx',
    );

    private const IMAGES = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp');

    public static function getName(): string
    {
        return 'scan_uploads';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'scan_uploads',
            'description' => 'Проверяет upload/ на подозрительные файлы: исполняемые расширения, .htaccess и PHP-код внутри картинок. Только чтение; ничего не удаляет.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'max_files' => array('type' => 'integer', 'description' => 'Сколько файлов просмотреть, по умолчанию 5000, максимум 20000'),
                ),
            ),
        );
    }

    /** @return array<string,mixed>|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('main')) {
            return array('error' => 'Ядро Битрикса недоступно.');
        }

        $root = rtrim((string)Loader::getDocumentRoot(), '/') . '/upload';
        if (!is_dir($root)) {
            return array('error' => 'Каталог upload/ не найден.');
        }

        $maxFiles = max(100, min(20000, (int)($args['max_files'] ?? 5000)));
        $scanned = 0;
        $truncated = false;
        $suspicious = array();

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->isLink() || !$file->isFile()) {
                continue;
            }

            if (++$scanned > $maxFiles) {
                $truncated = true;
                break;
            }

            $name = $file->getFilename();
            $ext = strtolower($file->getExtension());
            $path = $file->getPathname();
            $relative = str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
            $reason = null;

            // Временные каталоги ядра — штатный мусор, не сигнал.
            if (str_contains($relative, '/tmp/BXTEMP-')) {
                continue;
            }

            if ($name === '.htaccess') {
                $body = (string)@file_get_contents($path, false, null, 0, 4096);

                // Штатный .htaccess в upload/ запрещает PHP; опасен тот, что
                // наоборот назначает обработчик или переписывает правила.
                $protective = preg_match('/(php_flag\s+engine\s+off|RemoveHandler|ForceType\s+text\/plain|Require\s+all\s+denied)/i', $body) === 1;

                if (!$protective
                    && preg_match('/(AddType|SetHandler|AddHandler|RewriteRule[^\n]*\.(php|phtml))/i', $body) === 1) {
                    $reason = '.htaccess с обработчиком или правилом на PHP';
                }
            } elseif (in_array($ext, self::EXECUTABLE, true)) {
                $reason = 'исполняемое расширение .' . $ext;
            } elseif (in_array($ext, self::IMAGES, true) && $file->getSize() <= 5 * 1024 * 1024) {
                $head = (string)@file_get_contents($path, false, null, 0, 2048);

                if (stripos($head, '<?php') !== false || stripos($head, '<?=') !== false) {
                    $reason = 'PHP-код внутри файла изображения';
                } elseif ($ext === 'svg' && stripos($head, '<script') !== false) {
                    $reason = 'script в SVG';
                }
            }

            if ($reason !== null) {
                $suspicious[] = array(
                    'path' => $relative,
                    'size' => $file->getSize(),
                    'modified' => date('Y-m-d H:i:s', $file->getMTime()),
                    'reason' => $reason,
                );

                if (count($suspicious) >= 100) {
                    $truncated = true;
                    break;
                }
            }
        }

        return array(
            'scanned' => $scanned,
            'suspicious_count' => count($suspicious),
            'suspicious' => $suspicious,
            'truncated' => $truncated,
            'note' => 'Это базовая эвристика, а не антивирус: она не заменяет проверку целостности ядра и аудит логов.',
        );
    }
}
