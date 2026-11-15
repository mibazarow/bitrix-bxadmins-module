<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;
use Mibazarow\Bxadmins\Service\AttachmentService;
use Mibazarow\Bxadmins\Service\SafeHttpClient;
use Mibazarow\Bxadmins\Service\UrlGuard;

/**
 * Ставит картинки элементам инфоблока (новостям, товарам).
 *
 * По умолчанию закрывает и анонс (PREVIEW_PICTURE), и детальную (DETAIL_PICTURE):
 * одна просьба «поставь картинку» не должна оставлять половину полей пустыми.
 * Умеет один элемент или список, после применения проверяет результат.
 */
class SetElementImagesTool extends AbstractChangeTool
{
    public const DOMAIN = 'content';

    private const MAX_DOWNLOAD = 26214400;
    private const MAX_ELEMENTS = 500;

    public static function getName(): string
    {
        return 'set_element_images';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'set_element_images',
            'description' => 'Ставит картинку элементам инфоблока (новостям, товарам): по умолчанию И анонс, И детальную. Можно передать один element_id или список element_ids — одна операция, одно подтверждение. Источник — файл из чата (id) или ссылка http(s). После установки проверяет, что оба поля заполнены.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'element_id' => array('type' => 'integer', 'description' => 'ID одного элемента.'),
                    'element_ids' => array(
                        'type' => 'array',
                        'items' => array('type' => 'integer'),
                        'description' => 'Список ID элементов для одной операции.',
                    ),
                    'image' => array('type' => 'string', 'description' => 'ID файла из чата или ссылка http(s) на картинку.'),
                    'fields' => array(
                        'type' => 'string',
                        'description' => 'both (по умолчанию) — анонс и детальная, preview — только анонс, detail — только детальная.',
                    ),
                    'alt' => array('type' => 'string', 'description' => 'Необязательный alt-текст.'),
                ),
                'required' => array('image'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Модуль iblock недоступен.');
        }

        $ids = self::elementIds($args);
        $image = trim((string)($args['image'] ?? ''));

        if ($image === '') {
            throw new \InvalidArgumentException('Нужна картинка: id файла из чата или ссылка.');
        }

        [$setPreview, $setDetail] = self::fields($args);
        $source = self::resolveSource($image);
        $before = array();
        $lines = array();

        foreach ($ids as $id) {
            $element = \CIBlockElement::GetByID($id)->Fetch();
            if (!$element) {
                throw new \InvalidArgumentException('Элемент #' . $id . ' не найден.');
            }

            $preview = (int)($element['PREVIEW_PICTURE'] ?? 0);
            $detail = (int)($element['DETAIL_PICTURE'] ?? 0);
            $before[$id] = array('preview' => $preview, 'detail' => $detail);

            if (count($lines) < 10) {
                $lines[] = '#' . $id . ' «' . $element['NAME'] . '»: '
                    . ($setPreview ? ($preview > 0 ? 'анонс заменю' : 'анонс добавлю') : '')
                    . ($setPreview && $setDetail ? ', ' : '')
                    . ($setDetail ? ($detail > 0 ? 'детальную заменю' : 'детальную добавлю') : '');
            }
        }

        if (count($ids) > 10) {
            $lines[] = '…и ещё ' . (count($ids) - 10);
        }

        return array(
            'summary' => 'Картинки для ' . count($ids) . ' ' . self::elementWord(count($ids))
                . ($setPreview && $setDetail ? ' (анонс и детальная)' : ($setPreview ? ' (анонс)' : ' (детальная)')),
            'payload' => array(
                'args' => array(
                    'ids' => $ids,
                    'set_preview' => $setPreview,
                    'set_detail' => $setDetail,
                    'source' => $source,
                    'alt' => trim((string)($args['alt'] ?? '')),
                    'before' => $before,
                ),
            ),
            'preview' => implode("\n", $lines),
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());
        $ids = array_values(array_map('intval', (array)($args['ids'] ?? array())));
        $setPreview = !empty($args['set_preview']);
        $setDetail = !empty($args['set_detail']);

        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Модуль iblock недоступен.');
        }

        if ($ids === array()) {
            throw new \RuntimeException('Нет элементов для установки картинки.');
        }

        $path = self::materialize((array)($args['source'] ?? array()));

        $temporary = (($args['source']['type'] ?? '') === 'url');
        $elements = array();
        $done = 0;

        try {
            foreach ($ids as $id) {
                $before = (array)($args['before'][$id] ?? array());
                $copies = array('preview' => '', 'detail' => '');

                // Битрикс удаляет старые файлы при замене — сохраняем копии для отката.
                foreach (array('preview' => 'PREVIEW_PICTURE', 'detail' => 'DETAIL_PICTURE') as $key => $field) {
                    $oldId = (int)($before[$key] ?? 0);
                    if ($oldId <= 0) {
                        continue;
                    }

                    $oldPath = \CFile::GetPath($oldId);
                    $absolute = $oldPath !== '' ? $_SERVER['DOCUMENT_ROOT'] . $oldPath : '';
                    if ($absolute === '' || !is_file($absolute)) {
                        continue;
                    }

                    $copy = self::copyForRollback($absolute, (int)($payload['change_id'] ?? 0));
                    if ($copy !== '') {
                        $copies[$key] = $copy;
                    }
                }

                $fields = array();
                $newIds = array('preview' => 0, 'detail' => 0);

                foreach (array('preview' => 'PREVIEW_PICTURE', 'detail' => 'DETAIL_PICTURE') as $key => $field) {
                    if (($key === 'preview' && !$setPreview) || ($key === 'detail' && !$setDetail)) {
                        continue;
                    }

                    $fileArray = \CFile::MakeFileArray($path);
                    if (!is_array($fileArray)) {
                        throw new \RuntimeException('Битрикс не принял файл картинки.');
                    }

                    if (!empty($args['alt'])) {
                        $fileArray['DESCRIPTION'] = (string)$args['alt'];
                    }

                    $fields[$field] = $fileArray;
                }

                if ($fields === array()) {
                    continue;
                }

                // Легаси-вызов без D7-аналога: только он принимает массив файла CFile.
                $ok = (new \CIBlockElement())->Update($id, $fields);
                if (!$ok) {
                    throw new \RuntimeException('Не удалось обновить картинки элемента #' . $id . '.');
                }

                $element = \CIBlockElement::GetByID($id)->Fetch() ?: array();
                $newIds['preview'] = (int)($element['PREVIEW_PICTURE'] ?? 0);
                $newIds['detail'] = (int)($element['DETAIL_PICTURE'] ?? 0);

                // Проверяем фактическое состояние: пустое поле — ошибка, а не «готово».
                if ($setPreview && $newIds['preview'] <= 0) {
                    throw new \RuntimeException('Анонс не установился у элемента #' . $id . '.');
                }
                if ($setDetail && $newIds['detail'] <= 0) {
                    throw new \RuntimeException('Детальная картинка не установилась у элемента #' . $id . '.');
                }

                $elements[$id] = array(
                    'preview' => $newIds['preview'],
                    'detail' => $newIds['detail'],
                    'before' => $before,
                    'copies' => $copies,
                );
                $done++;
            }
        } catch (\Throwable $e) {
            // Не оставляем половину элементов без второй картинки.
            self::undo(array('elements' => $elements));

            if ($temporary && is_file($path)) {
                @unlink($path);
            }

            throw $e;
        }

        if ($temporary && is_file($path)) {
            @unlink($path);
        }

        return array(
            'updated' => $done,
            'total' => count($ids),
            'set_preview' => $setPreview,
            'set_detail' => $setDetail,
            'elements' => $elements,
        );
    }

    public static function rollback(array $payload): void
    {
        $result = (array)($payload['result'] ?? array());

        // Совместимость со старыми изменениями (один элемент, одно поле).
        if (!isset($result['elements'])) {
            self::rollbackLegacy($payload);

            return;
        }

        self::undo($result);
    }

    private static function undo(array $result): void
    {
        foreach ((array)($result['elements'] ?? array()) as $id => $data) {
            $id = (int)$id;
            if ($id <= 0) {
                continue;
            }

            $fields = array();
            $before = (array)($data['before'] ?? array());
            $copies = (array)($data['copies'] ?? array());

            foreach (array('preview' => 'PREVIEW_PICTURE', 'detail' => 'DETAIL_PICTURE') as $key => $field) {
                $newId = (int)($data[$key] ?? 0);
                if ($newId <= 0) {
                    continue;
                }

                $copy = (string)($copies[$key] ?? '');
                $value = array('del' => 'Y');

                if ($copy !== '' && is_file($copy)) {
                    $fileArray = \CFile::MakeFileArray($copy);
                    if (is_array($fileArray)) {
                        $value = $fileArray;
                    }
                }

                $fields[$field] = $value;
            }

            if ($fields !== array()) {
                (new \CIBlockElement())->Update($id, $fields);
            }

            foreach (array('preview', 'detail') as $key) {
                $newId = (int)($data[$key] ?? 0);
                $copy = (string)($copies[$key] ?? '');

                if ($newId > 0 && $newId !== (int)($before[$key] ?? 0)) {
                    \CFile::Delete($newId);
                }
                if ($copy !== '' && is_file($copy)) {
                    @unlink($copy);
                }
            }
        }
    }

    /** Старый формат: result.file_id + payload.args.field/before. */
    private static function rollbackLegacy(array $payload): void
    {
        $args = (array)($payload['args'] ?? array());
        $result = (array)($payload['result'] ?? array());
        $elementId = (int)($args['element_id'] ?? 0);
        $field = (string)($args['field'] ?? 'PREVIEW_PICTURE');
        $before = (int)($args['before'] ?? 0);
        $newFile = (int)($result['file_id'] ?? 0);

        if ($elementId > 0) {
            $value = array('del' => 'Y');
            $copy = (string)($result['before_copy'] ?? '');

            if ($copy === '' && $before > 0) {
                $path = \CFile::GetPath($before);
                $copy = $path !== '' ? $_SERVER['DOCUMENT_ROOT'] . $path : '';
            }

            if ($copy !== '' && is_file($copy)) {
                $fileArray = \CFile::MakeFileArray($copy);
                if (is_array($fileArray)) {
                    $value = $fileArray;
                }
            }

            (new \CIBlockElement())->Update($elementId, array($field => $value));

            if ($copy !== '' && is_file($copy)) {
                @unlink($copy);
            }
        }

        if ($newFile > 0 && $newFile !== $before) {
            \CFile::Delete($newFile);
        }
    }

    /** @return int[] */
    private static function elementIds(array $args): array
    {
        $ids = array();

        if (!empty($args['element_ids']) && is_array($args['element_ids'])) {
            foreach ($args['element_ids'] as $id) {
                $ids[] = (int)$id;
            }
        }

        if (isset($args['element_id'])) {
            $ids[] = (int)$args['element_id'];
        }

        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        if ($ids === array()) {
            throw new \InvalidArgumentException('Нужен element_id или element_ids.');
        }
        if (count($ids) > self::MAX_ELEMENTS) {
            throw new \InvalidArgumentException('Слишком много элементов: максимум ' . self::MAX_ELEMENTS . '.');
        }

        return $ids;
    }

    /** @return array{0:bool,1:bool} */
    private static function fields(array $args): array
    {
        $mode = strtolower(trim((string)($args['fields'] ?? 'both')));

        if ($mode === 'preview') {
            return array(true, false);
        }
        if ($mode === 'detail') {
            return array(false, true);
        }

        return array(true, true);
    }

    /** @return array{type:string,id?:string,url?:string} */
    private static function resolveSource(string $image): array
    {
        if (preg_match('/^[a-f0-9]{32}\.[a-z0-9]{1,8}$/', $image)) {
            if (AttachmentService::path($image) === null) {
                throw new \InvalidArgumentException('Файл из чата не найден.');
            }

            return array('type' => 'file', 'id' => $image);
        }

        if (!preg_match('#^https?://#i', $image)) {
            throw new \InvalidArgumentException('Нужен id файла из чата или ссылка http(s).');
        }

        UrlGuard::assertPublicHttp($image, array(80, 443));

        return array('type' => 'url', 'url' => $image);
    }

    private static function materialize(array $source): string
    {
        if (($source['type'] ?? '') === 'file') {
            $path = AttachmentService::path((string)($source['id'] ?? ''));
            if ($path === null) {
                throw new \RuntimeException('Файл из чата не найден.');
            }

            return $path;
        }

        $url = (string)($source['url'] ?? '');
        if ($url === '') {
            throw new \RuntimeException('Не указана ссылка на картинку.');
        }

        $response = SafeHttpClient::request($url, array(
            'timeout' => 20,
            'ua' => 'Mozilla/5.0 (compatible; BXADMIN-S/1.0)',
            'ports' => array(80, 443),
        ));
        $body = $response['body'];

        if ($body === '' || $response['status'] >= 400) {
            throw new \RuntimeException(
                'Не удалось скачать картинку (HTTP ' . $response['status'] . '). '
                . 'Возьми прямую ссылку на изображение или превью (thumbnail).'
            );
        }

        if (strlen((string)$body) > self::MAX_DOWNLOAD) {
            throw new \RuntimeException(
                'Картинка больше 25 МБ — не скачиваю. Используй ссылку на превью (thumbnail), а не оригинал.'
            );
        }

        $tmp = tempnam(sys_get_temp_dir(), 'bxadmins-img-');
        if ($tmp === false || file_put_contents($tmp, $body) === false) {
            throw new \RuntimeException('Не удалось сохранить скачанную картинку.');
        }

        $info = @getimagesize($tmp);
        if ($info === false) {
            @unlink($tmp);

            throw new \RuntimeException('Ссылка не ведёт на изображение (не картинка или повреждённый файл).');
        }

        $type = (int)($info[2] ?? 0);
        $map = array(
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_GIF => 'gif',
            IMAGETYPE_WEBP => 'webp',
            IMAGETYPE_BMP => 'bmp',
        );

        // TIFF и другие «не веб» форматы приводим к JPEG: иначе Битрикс откажется.
        if (!isset($map[$type]) || strlen((string)$body) > 6 * 1024 * 1024) {
            $converted = self::convertToJpeg($tmp);
            @unlink($tmp);

            if ($converted === '') {
                throw new \RuntimeException(
                    'Формат картинки не поддерживается. Для Wikimedia бери thumbnail — он уже JPEG.'
                );
            }

            return $converted;
        }

        $final = $tmp . '.' . $map[$type];
        if (!@rename($tmp, $final)) {
            @unlink($tmp);

            throw new \RuntimeException('Не удалось подготовить картинку.');
        }

        return $final;
    }

    private static function convertToJpeg(string $path): string
    {
        if (!class_exists('Imagick')) {
            return '';
        }

        try {
            $image = new \Imagick($path);
            $width = $image->getImageWidth();
            $height = $image->getImageHeight();

            if ($width > 2000 || $height > 2000) {
                $image->thumbnailImage(2000, 2000, true);
            }

            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(85);

            $target = $path . '.jpg';
            if (!$image->writeImage($target)) {
                return '';
            }
            $image->clear();

            return is_file($target) ? $target : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function copyForRollback(string $source, int $changeId): string
    {
        $dir = dirname(dirname(\Bitrix\Main\Application::getDocumentRoot())) . '/bxadmins-data/rollback-files';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $dir = sys_get_temp_dir();
        }

        $target = $dir . '/change-' . $changeId . '-' . bin2hex(random_bytes(4)) . '.'
            . strtolower((string)pathinfo($source, PATHINFO_EXTENSION));

        return @copy($source, $target) ? $target : '';
    }

    private static function elementWord(int $count): string
    {
        $mod10 = $count % 10;
        $mod100 = $count % 100;

        if ($mod10 >= 1 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return 'элемента';
        }

        return 'элементов';
    }
}
