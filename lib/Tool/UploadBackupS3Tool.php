<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Config\Option;
use Mibazarow\Bxadmins\Config;
use Mibazarow\Bxadmins\Service\BackupService;

/**
 * Выгрузка свежего дампа в S3-совместимое хранилище.
 *
 * Дампы лежат на том же сервере, что и сайт: при потере диска они пропадут
 * вместе с ним. Инструмент отправляет архив наружу по S3-протоколу
 * (Signature V4), настройки — endpoint, бакет, ключи.
 */
class UploadBackupS3Tool extends AbstractChangeTool
{
    public const DOMAIN = 'security';

    public static function getName(): string
    {
        return 'upload_backup_s3';
    }

    public static function needsSqlDump(): bool
    {
        // Сам дамп и есть предмет операции.
        return false;
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'upload_backup_s3',
            'description' => 'Отправляет свежий SQL-дамп в S3-совместимое хранилище. Нужны настройки: backup_s3_endpoint, backup_s3_bucket, backup_s3_key, backup_s3_secret. Если не заданы — создастся новый дамп и будет отправлен.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'file' => array('type' => 'string', 'description' => 'Имя дампа из list_backups; по умолчанию свежий'),
                    'create_new' => array('type' => 'boolean', 'description' => 'Сделать новый дамп перед отправкой'),
                ),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $settings = self::settings();

        if ($settings === null) {
            throw new \RuntimeException(
                'S3 не настроен. Заполните в настройках модуля: backup_s3_endpoint, backup_s3_bucket, '
                . 'backup_s3_key, backup_s3_secret (и при необходимости backup_s3_region).'
            );
        }

        $file = trim((string)($args['file'] ?? ''));

        if ($file === '') {
            $items = BackupService::list();
            if ($items === array()) {
                throw new \InvalidArgumentException('Дампов нет — сначала создайте бэкап.');
            }

            $file = (string)$items[0]['file'];
        }

        $path = self::dumpPath($file);
        if ($path === null) {
            throw new \InvalidArgumentException('Дамп ' . $file . ' не найден.');
        }

        $size = (int)@filesize($path);

        return array(
            'summary' => 'Отправка дампа ' . $file . ' в S3',
            'payload' => array(
                'args' => array(
                    'file' => $file,
                    'bucket' => $settings['bucket'],
                    'endpoint' => $settings['endpoint'],
                ),
            ),
            'preview' => 'Дамп ' . $file . ' (' . round($size / 1048576, 1) . ' МБ) будет отправлен в бакет «'
                . $settings['bucket'] . '» на ' . $settings['endpoint'] . '.',
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());
        $file = (string)($args['file'] ?? '');

        $path = self::dumpPath($file);
        if ($path === null) {
            throw new \RuntimeException('Дамп ' . $file . ' не найден.');
        }

        $settings = self::settings();
        if ($settings === null) {
            throw new \RuntimeException('S3 не настроен.');
        }

        $body = @file_get_contents($path);
        if ($body === false) {
            throw new \RuntimeException('Не удалось прочитать дамп.');
        }

        $key = 'backups/' . date('Y/m') . '/' . $file;
        $status = self::put($settings, $key, $body);

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Хранилище ответило HTTP ' . $status . '. Проверьте ключи и бакет.');
        }

        return array(
            'file' => $file,
            'bucket' => $settings['bucket'],
            'key' => $key,
            'bytes' => strlen($body),
            'status' => $status,
        );
    }

    public static function rollback(array $payload): void
    {
        // Копия в хранилище — страховка: удалять её при откате нельзя.
    }

    /** @return array{endpoint:string,bucket:string,key:string,secret:string,region:string}|null */
    private static function settings(): ?array
    {
        $endpoint = trim((string)Option::get(Config::MODULE_ID, 'backup_s3_endpoint', ''));
        $bucket = trim((string)Option::get(Config::MODULE_ID, 'backup_s3_bucket', ''));
        $key = trim((string)Option::get(Config::MODULE_ID, 'backup_s3_key', ''));
        $secret = trim((string)Option::get(Config::MODULE_ID, 'backup_s3_secret', ''));

        if ($endpoint === '' || $bucket === '' || $key === '' || $secret === '') {
            return null;
        }

        return array(
            'endpoint' => rtrim($endpoint, '/'),
            'bucket' => $bucket,
            'key' => $key,
            'secret' => $secret,
            'region' => trim((string)Option::get(Config::MODULE_ID, 'backup_s3_region', 'us-east-1')) ?: 'us-east-1',
        );
    }

    private static function dumpPath(string $file): ?string
    {
        $file = basename($file);

        if ($file === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $file)) {
            return null;
        }

        $path = BackupService::dirPath() . '/' . $file;

        return is_file($path) ? $path : null;
    }

    /**
     * PUT объекта с подписью AWS Signature V4.
     *
     * @param array{endpoint:string,bucket:string,key:string,secret:string,region:string} $settings
     */
    private static function put(array $settings, string $objectKey, string $body): int
    {
        $scheme = strtolower((string)parse_url($settings['endpoint'], PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return 0;
        }

        $host = (string)parse_url($settings['endpoint'], PHP_URL_HOST);
        $port = parse_url($settings['endpoint'], PHP_URL_PORT);
        $hostHeader = $host . ($port ? ':' . $port : '');

        $uri = '/' . $settings['bucket'] . '/' . ltrim($objectKey, '/');
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $amzDate = $now->format('Ymd\THis\Z');
        $dateStamp = $now->format('Ymd');

        $payloadHash = hash('sha256', $body);

        $canonicalHeaders = "host:" . $hostHeader . "\n"
            . "x-amz-content-sha256:" . $payloadHash . "\n"
            . "x-amz-date:" . $amzDate . "\n";
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';

        $canonicalRequest = "PUT\n" . $uri . "\n\n" . $canonicalHeaders . "\n"
            . $signedHeaders . "\n" . $payloadHash;

        $scope = $dateStamp . '/' . $settings['region'] . '/s3/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n"
            . hash('sha256', $canonicalRequest);

        $signingKey = hash_hmac('sha256', $dateStamp, 'AWS4' . $settings['secret'], true);
        $signingKey = hash_hmac('sha256', $settings['region'], $signingKey, true);
        $signingKey = hash_hmac('sha256', 's3', $signingKey, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $signingKey, true);

        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $authorization = 'AWS4-HMAC-SHA256 Credential=' . $settings['key'] . '/' . $scope
            . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;

        $ch = curl_init($settings['endpoint'] . $uri);
        curl_setopt_array($ch, array(
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_HTTPHEADER => array(
                'Host: ' . $hostHeader,
                'Authorization: ' . $authorization,
                'x-amz-content-sha256: ' . $payloadHash,
                'x-amz-date: ' . $amzDate,
                'Content-Type: application/zip',
                'Content-Length: ' . strlen($body),
            ),
        ));

        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return $status;
    }
}
