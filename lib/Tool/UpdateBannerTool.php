<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;
use Mibazarow\Bxadmins\Service\AttachmentService;
use Mibazarow\Bxadmins\Service\SafeHttpClient;
use Mibazarow\Bxadmins\Service\UrlGuard;

/** Правка рекламного баннера через CAdvBanner::Set (он сам готовит поля). */
class UpdateBannerTool extends AbstractChangeTool
{
    public const DOMAIN = 'marketing';

    private const MAX_DOWNLOAD = 20971520;

    public static function getName(): string
    {
        return 'update_banner';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'update_banner',
            'description' => 'Меняет рекламный баннер модуля advertising: название, активность, вес, ссылку, alt и картинку (файл из чата или URL). Сначала посмотри баннеры через list_banners и назови, какой меняешь.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'banner_id' => array('type' => 'integer', 'description' => 'ID баннера.'),
                    'name' => array('type' => 'string', 'description' => 'Новое название.'),
                    'active' => array('type' => 'boolean', 'description' => 'Включить/выключить.'),
                    'weight' => array('type' => 'integer', 'description' => 'Вес показа.'),
                    'url' => array('type' => 'string', 'description' => 'Ссылка баннера.'),
                    'image_alt' => array('type' => 'string', 'description' => 'Alt-текст картинки.'),
                    'image' => array('type' => 'string', 'description' => 'id файла из чата или URL картинки.'),
                ),
                'required' => array('banner_id'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        if (!Loader::includeModule('advertising')) {
            throw new \RuntimeException('Модуль advertising не установлен.');
        }

        $bannerId = (int)($args['banner_id'] ?? 0);
        $banner = self::load($bannerId);
        if (!$banner) {
            throw new \InvalidArgumentException('Баннер #' . $bannerId . ' не найден.');
        }

        $source = null;
        $image = trim((string)($args['image'] ?? ''));
        if ($image !== '') {
            $source = self::resolveSource($image);
        }

        $fields = self::buildFields($args);

        if ($fields === array() && $source === null) {
            throw new \InvalidArgumentException('Нечего менять: передай хотя бы одно поле.');
        }

        return array(
            'summary' => 'Баннер #' . $bannerId . ' «' . (string)$banner['NAME'] . '»',
            'payload' => array('args' => array('banner_id' => $bannerId, 'fields' => $fields, 'source' => $source)),
            'preview' => self::describe($banner, $fields, $source !== null),
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());
        $bannerId = (int)($args['banner_id'] ?? 0);
        $fields = (array)($args['fields'] ?? array());
        $source = (array)($args['source'] ?? array());

        if (!Loader::includeModule('advertising')) {
            throw new \RuntimeException('Модуль advertising недоступен.');
        }

        if ($source !== array()) {
            $path = self::materialize($source);
            try {
                $fileArray = \CFile::MakeFileArray($path);
                if (!is_array($fileArray)) {
                    throw new \RuntimeException('Битрикс не принял картинку баннера.');
                }
                $fields['arrIMAGE_ID'] = $fileArray;

                // CAdvBanner::Set сам экранирует и сохраняет поля; CHECK_RIGHTS=N для CLI/воркера.
                $result = \CAdvBanner::Set($fields, $bannerId, 'N');
            } finally {
                if (($source['type'] ?? '') === 'url' && is_file($path)) {
                    @unlink($path);
                }
            }
        } else {
            $result = \CAdvBanner::Set($fields, $bannerId, 'N');
        }

        if (!$result) {
            throw new \RuntimeException('Не удалось обновить баннер: ' . \Mibazarow\Bxadmins\Service\LegacyError::message(null, 'проверьте поля'));
        }

        $banner = self::load($bannerId) ?: array();

        return array(
            'banner_id' => $bannerId,
            'active' => (string)($banner['ACTIVE'] ?? '') === 'Y',
            'image_id' => (int)($banner['IMAGE_ID'] ?? 0),
        );
    }

    public static function rollbackNeedsRestore(): bool
    {
        // Поля и картинка баннера восстанавливаются точкой отката сайта.
        return true;
    }

    public static function rollback(array $payload): void
    {
    }

    private static function load(int $id): array
    {
        if ($id <= 0) {
            return array();
        }

        $list = \CAdvBanner::GetList($by = 's_id', $order = 'asc', array('ID' => $id), false, false);
        $row = $list->Fetch();

        return is_array($row) ? $row : array();
    }

    private static function buildFields(array $args): array
    {
        $fields = array();

        if (isset($args['name'])) {
            $fields['NAME'] = trim((string)$args['name']);
        }
        if (isset($args['active'])) {
            $fields['ACTIVE'] = !empty($args['active']) ? 'Y' : 'N';
        }
        if (isset($args['weight'])) {
            $fields['WEIGHT'] = max(0, (int)$args['weight']);
        }
        if (isset($args['url'])) {
            $fields['URL'] = trim((string)$args['url']);
        }
        if (isset($args['image_alt'])) {
            $fields['IMAGE_ALT'] = trim((string)$args['image_alt']);
        }

        return $fields;
    }

    private static function describe(array $banner, array $fields, bool $withImage): string
    {
        $lines = array('Было: «' . (string)$banner['NAME'] . '», активно: ' . (string)$banner['ACTIVE']);

        foreach ($fields as $key => $value) {
            $lines[] = $key . ': ' . (is_scalar($value) ? (string)$value : '…');
        }

        if ($withImage) {
            $lines[] = 'Картинка: будет заменена';
        }

        return implode("\n", $lines);
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

        $response = SafeHttpClient::request((string)($source['url'] ?? ''), array(
            'timeout' => 20,
            'ua' => 'Mozilla/5.0 (compatible; BXADMIN-S/1.0)',
            'ports' => array(80, 443),
        ));
        $body = $response['body'];

        if ($body === '' || $response['status'] >= 400 || strlen($body) > self::MAX_DOWNLOAD) {
            throw new \RuntimeException('Не удалось скачать картинку баннера (или она больше 20 МБ).');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'bxadmins-banner-');
        if ($tmp === false || file_put_contents($tmp, $body) === false) {
            throw new \RuntimeException('Не удалось сохранить картинку баннера.');
        }

        $info = @getimagesize($tmp);
        if ($info === false) {
            @unlink($tmp);
            throw new \RuntimeException('Ссылка не ведёт на изображение.');
        }

        $map = array(IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp', IMAGETYPE_BMP => 'bmp');
        $ext = $map[(int)($info[2] ?? 0)] ?? '';
        if ($ext === '') {
            if (!class_exists('Imagick')) {
                @unlink($tmp);
                throw new \RuntimeException('Формат картинки не поддерживается.');
            }
            try {
                $im = new \Imagick($tmp);
                $im->setImageFormat('jpeg');
                $im->setImageCompressionQuality(85);
                $target = $tmp . '.jpg';
                $im->writeImage($target);
                $im->clear();
                @unlink($tmp);

                return $target;
            } catch (\Throwable $e) {
                @unlink($tmp);
                throw new \RuntimeException('Формат картинки не поддерживается.');
            }
        }

        $final = $tmp . '.' . $ext;
        if (!@rename($tmp, $final)) {
            @unlink($tmp);
            throw new \RuntimeException('Не удалось подготовить картинку баннера.');
        }

        return $final;
    }
}
