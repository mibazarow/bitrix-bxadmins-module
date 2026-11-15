<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\Loader;

/**
 * Анализ изображения активной моделью: URL, файл из чата или картинка элемента.
 *
 * Позволяет «посмотреть на фото товара», не делая скриншот всей страницы.
 */
final class ImageAnalyzer
{
    private const MAX_DOWNLOAD = 20971520;
    private const MAX_SIDE = 1600;

    /**
     * @param array{source?:string,element_id?:int,question?:string} $args
     */
    public static function analyze(array $args): array
    {
        $question = trim((string)($args['question'] ?? ''));
        if ($question === '') {
            $question = 'Опиши подробно, что на изображении. Если это товар — назови объект, цвет, материалы, '
                . 'особенности и оцени, подходит ли картинка для карточки товара.';
        }

        [$path, $origin, $temporary] = self::resolve($args);

        try {
            $uri = self::dataUri($path);
            if ($uri === '') {
                throw new \RuntimeException('Не удалось прочитать изображение.');
            }

            $client = new LlmClient();
            $message = $client->chat(array(
                array(
                    'role' => 'system',
                    'content' => 'You analyze images for the site owner. Answer in Russian, concretely and briefly. '
                        . 'If the quality or content does not fit the task, say so directly.',
                ),
                array(
                    'role' => 'user',
                    'content' => array(
                        array('type' => 'text', 'text' => $question),
                        array('type' => 'image_url', 'image_url' => array('url' => $uri)),
                    ),
                ),
            ));

            return array(
                'answer' => trim((string)($message['content'] ?? '')),
                'source' => $origin,
            );
        } finally {
            if ($temporary && is_file($path)) {
                @unlink($path);
            }
        }
    }

    /** @return array{0:string,1:string,2:bool} путь, описание источника, временный ли файл */
    private static function resolve(array $args): array
    {
        $elementId = (int)($args['element_id'] ?? 0);
        if ($elementId > 0) {
            if (!Loader::includeModule('iblock')) {
                throw new \RuntimeException('Модуль iblock недоступен.');
            }

            $element = \CIBlockElement::GetByID($elementId)->Fetch();
            if (!$element) {
                throw new \InvalidArgumentException('Элемент #' . $elementId . ' не найден.');
            }

            $fileId = (int)($element['DETAIL_PICTURE'] ?? 0) ?: (int)($element['PREVIEW_PICTURE'] ?? 0);
            if ($fileId <= 0) {
                throw new \InvalidArgumentException('У элемента #' . $elementId . ' нет картинки.');
            }

            $path = \CFile::GetPath($fileId);
            $absolute = $path !== '' ? $_SERVER['DOCUMENT_ROOT'] . $path : '';
            if ($absolute === '' || !is_file($absolute)) {
                throw new \RuntimeException('Файл картинки элемента не найден.');
            }

            return array($absolute, 'элемент #' . $elementId . ' «' . $element['NAME'] . '»', false);
        }

        $source = trim((string)($args['source'] ?? ''));
        if ($source === '') {
            throw new \InvalidArgumentException('Нужна ссылка, файл из чата или element_id.');
        }

        if (preg_match('/^[a-f0-9]{32}\.[a-z0-9]{1,8}$/', $source)) {
            $path = AttachmentService::path($source);
            if ($path === null) {
                throw new \InvalidArgumentException('Файл из чата не найден.');
            }

            return array($path, 'файл из чата', false);
        }

        if (!preg_match('#^https?://#i', $source)) {
            throw new \InvalidArgumentException('Нужен URL http(s), id файла из чата или element_id.');
        }

        $response = SafeHttpClient::request($source, array(
            'timeout' => 20,
            'ua' => 'Mozilla/5.0 (compatible; BXADMIN-S/1.0)',
        ));
        $body = $response['body'];

        if ($body === '' || $response['status'] >= 400) {
            throw new \RuntimeException('Не удалось скачать картинку (HTTP ' . $response['status'] . ').');
        }
        if (strlen((string)$body) > self::MAX_DOWNLOAD) {
            throw new \RuntimeException('Картинка больше 20 МБ — используй ссылку на превью (thumbnail).');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'bxadmins-vision-');
        if ($tmp === false || file_put_contents($tmp, $body) === false) {
            throw new \RuntimeException('Не удалось сохранить скачанную картинку.');
        }

        if (@getimagesize($tmp) === false) {
            @unlink($tmp);
            throw new \RuntimeException('Ссылка не ведёт на изображение.');
        }

        return array($tmp, 'ссылка ' . mb_substr($source, 0, 90), true);
    }

    private static function dataUri(string $path): string
    {
        if (!class_exists('Imagick')) {
            $data = (string)@file_get_contents($path);

            return $data !== '' ? 'data:image/jpeg;base64,' . base64_encode($data) : '';
        }

        try {
            $image = new \Imagick($path);
            if ($image->getImageWidth() > self::MAX_SIDE || $image->getImageHeight() > self::MAX_SIDE) {
                $image->thumbnailImage(self::MAX_SIDE, self::MAX_SIDE, true);
            }
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(85);
            $data = $image->getImageBlob();
            $image->clear();

            return 'data:image/jpeg;base64,' . base64_encode($data);
        } catch (\Throwable $e) {
            return '';
        }
    }
}
