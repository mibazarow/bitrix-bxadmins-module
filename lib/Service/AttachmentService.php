<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\Application;

/**
 * Файлы из чата: приём, хранение, превью и подготовка для модели.
 *
 * Файлы лежат вне docroot (в bxadmins-data/chat-files), отдаются только
 * администратору через admin/attachment.php. Изображения уходят модели
 * как data-URI (с ресайзом), документы — текстом; PDF рендерится в картинки.
 */
final class AttachmentService
{
    public const MAX_SIZE = 26214400; // 25 МБ
    private const MAX_FILES = 5;
    private const MAX_TEXT = 40000;
    private const MAX_TEXT_PER_FILE = 20000;
    private const MAX_IMAGE_SIDE = 1400;
    private const MAX_PDF_PAGES = 5;

    private const IMAGE_EXT = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp');
    private const TEXT_EXT = array('txt', 'csv', 'md', 'json', 'html', 'htm', 'xml');
    private const DOC_EXT = array('docx', 'xlsx');
    private const OTHER_EXT = array('pdf', 'doc', 'xls', 'zip');

    /** @var array<string,string|null> кэш путей на время запроса */
    private static $pathCache = array();

    /** @var array<string,string> кэш data-URI: одну картинку не ресайзим дважды */
    private static $uriCache = array();

    /** Сохраняет загруженный файл и возвращает метаданные для сообщения. */
    public static function save(array $file, int $userId): array
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Нужен пользователь.');
        }

        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException(self::uploadError($error));
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0) {
            throw new \InvalidArgumentException('Файл пустой.');
        }
        if ($size > self::MAX_SIZE) {
            throw new \InvalidArgumentException('Файл больше 25 МБ.');
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \InvalidArgumentException('Файл не загружен.');
        }

        $name = self::cleanName((string)($file['name'] ?? 'file'));
        $extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));

        if (!in_array($extension, array_merge(self::IMAGE_EXT, self::TEXT_EXT, self::DOC_EXT, self::OTHER_EXT), true)) {
            throw new \InvalidArgumentException('Этот формат не поддерживается.');
        }

        $id = bin2hex(random_bytes(16)) . '.' . $extension;
        $dir = self::dir() . '/' . date('Y/m');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать каталог для файлов чата.');
        }

        $path = $dir . '/' . $id;
        if (!move_uploaded_file($tmp, $path)) {
            throw new \RuntimeException('Не удалось сохранить файл.');
        }

        @chmod($path, 0664);

        $mime = self::mime($path);

        return array(
            'id' => $id,
            'name' => $name,
            'mime' => $mime,
            'size' => $size,
            'image' => str_starts_with($mime, 'image/') || in_array($extension, self::IMAGE_EXT, true),
        );
    }

    /** Проверяет метаданные из браузера: файл существует и это наш файл. */
    public static function normalizeList(array $items): array
    {
        $metas = array();
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id = (string)($item['id'] ?? '');
            $path = self::path($id);
            if ($path === null) {
                continue;
            }

            $name = self::cleanName((string)($item['name'] ?? basename($id)));
            $mime = self::mime($path);

            $metas[] = array(
                'id' => basename($path),
                'name' => $name,
                'mime' => $mime,
                'size' => (int)filesize($path),
                'image' => str_starts_with($mime, 'image/') || in_array(
                    strtolower((string)pathinfo($path, PATHINFO_EXTENSION)),
                    self::IMAGE_EXT,
                    true
                ),
            );

            if (count($metas) >= self::MAX_FILES) {
                break;
            }
        }

        return $metas;
    }

    /** Абсолютный путь к файлу по id (только внутри каталога чата). */
    public static function path(string $id): ?string
    {
        if (array_key_exists($id, self::$pathCache)) {
            return self::$pathCache[$id];
        }

        if (!preg_match('/^[a-f0-9]{32}\.[a-z0-9]{1,8}$/', $id)) {
            self::$pathCache[$id] = null;

            return null;
        }

        $root = realpath(self::dir());
        if ($root === false) {
            self::$pathCache[$id] = null;

            return null;
        }

        // Файлы всегда лежат в <root>/<год>/<месяц>/<id> — без рекурсивного обхода.
        $matches = glob($root . '/*/*/' . $id);
        $found = $matches !== false && isset($matches[0]) ? realpath($matches[0]) : false;

        if ($found === false || !str_starts_with($found, $root . '/')) {
            self::$pathCache[$id] = null;

            return null;
        }

        self::$pathCache[$id] = $found;

        return $found;
    }

    /**
     * Текст и изображения для модели.
     *
     * @param array<int,array> $metas
     * @return array{text:string,images:array<int,array{name:string,uri:string}>}
     */
    public static function forModel(array $metas): array
    {
        $texts = array();
        $images = array();
        $budget = self::MAX_TEXT;

        foreach ($metas as $meta) {
            $path = self::path((string)($meta['id'] ?? ''));
            if ($path === null) {
                continue;
            }

            $name = (string)($meta['name'] ?? 'файл');
            $extension = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));

            if (!empty($meta['image']) || in_array($extension, self::IMAGE_EXT, true)) {
                $uri = self::dataUri($path);
                if ($uri !== '') {
                    $images[] = array('name' => $name, 'uri' => $uri);
                    $texts[] = '[изображение: ' . $name . ' (файл ' . basename($path) . ')]';
                }
                continue;
            }

            if ($extension === 'pdf') {
                $pages = self::pdfToImages($path);
                if ($pages !== array()) {
                    foreach ($pages as $index => $uri) {
                        $images[] = array('name' => $name . ' (стр. ' . ($index + 1) . ')', 'uri' => $uri);
                    }
                    $texts[] = '[PDF: ' . $name . ', отправлен страницами-изображениями]';
                } else {
                    $texts[] = '[PDF: ' . $name . ' — прочитать не удалось, попроси текст или скриншот]';
                }
                continue;
            }

            $content = '';
            if (in_array($extension, self::TEXT_EXT, true)) {
                $content = (string)@file_get_contents($path, false, null, 0, self::MAX_TEXT_PER_FILE);
            } elseif (in_array($extension, self::DOC_EXT, true)) {
                $content = self::officeText($path, $extension);
            }

            $content = trim($content);
            if ($content === '') {
                $texts[] = '[файл: ' . $name . ' — формат не читается; попроси текст, скриншот или PDF]';
                continue;
            }

            if (mb_strlen($content) > $budget) {
                $content = mb_substr($content, 0, $budget) . "\n…(обрезано)";
            }
            $budget -= mb_strlen($content);

            $texts[] = '[файл: ' . $name . ' (файл ' . basename($path) . ')]' . "\n" . $content;
        }

        return array('text' => implode("\n\n", $texts), 'images' => $images);
    }

    /**
     * Содержимое сообщения пользователя для OpenAI-совместимого API:
     * строка или массив частей с картинками.
     *
     * @param array<int,array> $metas
     * @return string|array
     */
    public static function buildUserContent(string $text, array $metas, bool $withImages = true)
    {
        if ($metas === array()) {
            return $text;
        }

        $prepared = self::forModel($metas);
        $body = trim($text);
        if ($prepared['text'] !== '') {
            $body = ($body !== '' ? $body . "\n\n" : '') . $prepared['text'];
        }

        if (!$withImages || $prepared['images'] === array()) {
            return $body;
        }

        $parts = array(array('type' => 'text', 'text' => $body !== '' ? $body : 'Посмотри вложение.'));
        foreach ($prepared['images'] as $image) {
            $parts[] = array(
                'type' => 'image_url',
                'image_url' => array('url' => $image['uri']),
            );
        }

        return $parts;
    }

    /** Удаляет файлы удалённого чата. */
    public static function deleteFiles(array $metas): void
    {
        foreach ($metas as $meta) {
            $path = self::path((string)($meta['id'] ?? ''));
            if ($path !== null && is_file($path)) {
                @unlink($path);
            }
        }
    }

    public static function dir(): string
    {
        return dirname(dirname(Application::getDocumentRoot())) . '/bxadmins-data/chat-files';
    }

    private static function dataUri(string $path): string
    {
        if (isset(self::$uriCache[$path])) {
            return self::$uriCache[$path];
        }

        $data = '';
        $mime = self::mime($path);

        if (class_exists('Imagick')) {
            try {
                $image = new \Imagick($path);
                $width = $image->getImageWidth();
                $height = $image->getImageHeight();

                if ($width > self::MAX_IMAGE_SIDE || $height > self::MAX_IMAGE_SIDE) {
                    $image->thumbnailImage(self::MAX_IMAGE_SIDE, self::MAX_IMAGE_SIDE, true);
                }

                $image->setImageFormat('jpeg');
                $image->setImageCompressionQuality(82);
                $data = $image->getImageBlob();
                $mime = 'image/jpeg';
                $image->clear();
            } catch (\Throwable $e) {
                $data = '';
            }
        }

        if ($data === '') {
            $data = (string)@file_get_contents($path);
        }

        if ($data === '') {
            return '';
        }

        $uri = 'data:' . ($mime !== '' ? $mime : 'application/octet-stream') . ';base64,' . base64_encode($data);
        self::$uriCache[$path] = $uri;

        return $uri;
    }

    /** @return string[] data-URI по страницам PDF */
    private static function pdfToImages(string $path): array
    {
        if (!class_exists('Imagick')) {
            return array();
        }

        $images = array();

        try {
            $pdf = new \Imagick();
            $pdf->setResolution(120, 120);
            $pdf->readImage($path . '[0-' . (self::MAX_PDF_PAGES - 1) . ']');

            foreach ($pdf as $page) {
                $page->setImageFormat('jpeg');
                $page->setImageCompressionQuality(80);
                $images[] = 'data:image/jpeg;base64,' . base64_encode($page->getImageBlob());
            }

            $pdf->clear();
        } catch (\Throwable $e) {
            return array();
        }

        return $images;
    }

    private static function officeText(string $path, string $extension): string
    {
        if (!class_exists('ZipArchive')) {
            return '';
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return '';
        }

        $text = '';

        try {
            if ($extension === 'docx') {
                $xml = (string)$zip->getFromName('word/document.xml');
                $text = self::xmlText($xml);
            } else {
                $shared = (string)$zip->getFromName('xl/sharedStrings.xml');
                if ($shared !== '') {
                    $text = self::xmlText($shared, ' ');
                }

                for ($index = 1; $index <= 10; $index++) {
                    $sheet = (string)$zip->getFromName('xl/worksheets/sheet' . $index . '.xml');
                    if ($sheet === '') {
                        break;
                    }
                    $text .= "\n" . self::xmlText($sheet, ' ');
                }
            }
        } finally {
            $zip->close();
        }

        return trim($text);
    }

    private static function xmlText(string $xml, string $separator = "\n"): string
    {
        if ($xml === '') {
            return '';
        }

        $xml = preg_replace('/<\/(w:p|row|si)>/', $separator, $xml) ?? $xml;
        $text = strip_tags($xml);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');

        return trim(preg_replace('/[ \t]+/', ' ', $text) ?? $text);
    }

    private static function mime(string $path): string
    {
        try {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = (string)$finfo->file($path);

            return $mime !== '' ? $mime : 'application/octet-stream';
        } catch (\Throwable $e) {
            return 'application/octet-stream';
        }
    }

    private static function cleanName(string $name): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/', '', $name) ?? '');
        $name = basename(str_replace('\\', '/', $name));

        return mb_substr($name !== '' ? $name : 'файл', 0, 180);
    }

    private static function uploadError(int $code): string
    {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'Файл слишком большой.';
            case UPLOAD_ERR_PARTIAL:
                return 'Файл загрузился не полностью, попробуйте ещё раз.';
            case UPLOAD_ERR_NO_FILE:
                return 'Файл не выбран.';
            default:
                return 'Не удалось загрузить файл (код ' . $code . ').';
        }
    }
}
