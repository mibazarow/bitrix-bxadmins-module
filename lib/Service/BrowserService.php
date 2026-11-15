<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Mibazarow\Bxadmins\Config;
use Mibazarow\Bxadmins\Service\UrlGuard;

/**
 * Клиент headless-браузера. Запросы уходят локальному демону, который держит
 * chrome-headless-shell и общается с ним по CDP.
 */
class BrowserService
{
    private const HOST = '127.0.0.1';
    // Должен совпадать с BROWSER_PORT в daemon/bxadmins-daemon.js.
    private const PORT = 8899;
    private const TIMEOUT = 120;
    private const MAX_SCREENSHOT_BYTES = 8 * 1024 * 1024;

    /** Каталог скриншотов — вне docroot. */
    private static function shotDir(): string
    {
        return dirname(dirname(\Bitrix\Main\Application::getDocumentRoot())) . '/bxadmins-data/screenshots';
    }

    public static function isAvailable(): bool
    {
        try {
            $result = self::call('available', array());

            return !empty($result['available']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return array{file:string,width:int,height:int,bytes:int,console:array,failed_requests:array}
     */
    public static function screenshot(string $url, bool $fullPage = false, int $width = 1280, int $height = 900): array
    {
        return self::call('screenshot', array(
            'url' => $url,
            'full_page' => $fullPage,
            'width' => $width,
            'height' => $height,
            'wait_ms' => 1200,
        ));
    }

    /** @return array */
    public static function inspect(string $url): array
    {
        return self::call('inspect', array('url' => $url, 'wait_ms' => 1000));
    }

    /** @param string[] $properties @return array */
    public static function computedCss(string $url, string $selector, array $properties = array()): array
    {
        return self::call('css', array(
            'url' => $url,
            'selector' => $selector,
            'properties' => $properties,
            'wait_ms' => 800,
        ));
    }

    /** @return array */
    public static function smoke(string $url): array
    {
        return self::call('smoke', array('url' => $url, 'wait_ms' => 1200));
    }

    /** Абсолютный путь к сохранённому скриншоту (с защитой от обхода каталога). */
    public static function shotPath(string $file): ?string
    {
        $file = basename($file);
        if ($file === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $file)) {
            return null;
        }

        $path = self::shotDir() . '/' . $file;

        return is_file($path) ? $path : null;
    }

    /** Удаляет скриншоты старше срока хранения. */
    public static function pruneShots(int $days): int
    {
        $dir = self::shotDir();
        if (!is_dir($dir)) {
            return 0;
        }

        $threshold = time() - max(1, $days) * 86400;
        $deleted = 0;

        foreach (glob($dir . '/shot-*') ?: array() as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $threshold) {
                @unlink($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * @return array
     * @throws \RuntimeException понятное владельцу сообщение
     */
    private static function call(string $action, array $payload): array
    {
        if (!empty($payload['url'])) {
            // Браузер живёт на сервере: ссылки на localhost и внутренние сети запрещены.
            UrlGuard::assertPublicHttp((string)$payload['url'], null);
        }

        $body = Json::encode(array('action' => $action) + $payload);
        $timestamp = (string)time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, Config::workerSecret());

        $http = new HttpClient();
        $http->setTimeout(self::TIMEOUT);
        $http->setHeader('Content-Type', 'application/json', true);
        $http->setHeader('X-Bxadmins-Timestamp', $timestamp, true);
        $http->setHeader('X-Bxadmins-Signature', $signature, true);

        try {
            $http->post('http://' . self::HOST . ':' . self::PORT . '/', $body);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Браузер недоступен: демон не отвечает.');
        }

        $status = (int)$http->getStatus();
        $raw = (string)$http->getResult();

        if ($status === 0) {
            throw new \RuntimeException('Браузер недоступен: локальный демон не принимает соединение.');
        }

        try {
            $data = Json::decode($raw);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Браузер вернул некорректный ответ.');
        }

        if (!is_array($data)) {
            throw new \RuntimeException('Браузер вернул некорректный ответ.');
        }

        if (empty($data['ok'])) {
            $error = (string)($data['error'] ?? 'неизвестная ошибка');
            Logger::error('Browser: ' . $action . ': ' . $error);

            throw new \RuntimeException('Браузер: ' . $error);
        }

        return (array)($data['result'] ?? array());
    }
}
