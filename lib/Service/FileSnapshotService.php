<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\IO\Directory;
use Bitrix\Main\IO\File;

/**
 * Снапшоты файлов перед записью. Хранятся вместе с изменением и являются
 * источником правды для отката (git не используется для revert).
 */
class FileSnapshotService
{
    public const DIR = '/bitrix/backup/bx_admin_s_bacaps/files';

    /**
     * @return array{existed:bool,snapshot_path:string|null,hash:string|null,size:int}
     */
    public static function snapshot(string $relativePath, int $changeId): array
    {
        $relativePath = LocalFileGuard::assertSitePath($relativePath);

        if ($changeId <= 0) {
            throw new \InvalidArgumentException('Не удалось определить изменение для снапшота.');
        }

        $source = LocalFileGuard::absolute($relativePath);

        if (!is_file($source)) {
            return array(
                'existed' => false,
                'snapshot_path' => null,
                'hash' => null,
                'size' => 0,
            );
        }

        $target = self::changeDir($changeId) . '/' . $relativePath;
        Directory::createDirectory(dirname($target));

        if (!@copy($source, $target)) {
            throw new \RuntimeException('Не удалось сохранить снапшот файла.');
        }

        $permissions = @fileperms($source);
        if ($permissions !== false) {
            @chmod($target, $permissions & 0777);
        }

        $hash = hash_file('sha256', $target);
        $size = filesize($target);

        if (!is_string($hash) || $size === false) {
            throw new \RuntimeException('Не удалось прочитать файл для снапшота.');
        }

        return array(
            'existed' => true,
            'snapshot_path' => $target,
            'hash' => $hash,
            'size' => (int)$size,
        );
    }

    /**
     * @param array{path?:string,args?:array,snapshot?:array} $payload
     */
    public static function restore(array $payload): void
    {
        $relativePath = (string)($payload['path'] ?? '');
        if ($relativePath === '' && isset($payload['args']['path'])) {
            $relativePath = (string)$payload['args']['path'];
        }

        $relativePath = LocalFileGuard::assertSitePath($relativePath);

        $snapshot = null;
        if (isset($payload['snapshot']) && is_array($payload['snapshot'])) {
            $snapshot = $payload['snapshot'];
        } elseif (isset($payload['result']['snapshot']) && is_array($payload['result']['snapshot'])) {
            $snapshot = $payload['result']['snapshot'];
        }

        if ($snapshot === null) {
            throw new \InvalidArgumentException('Нет данных снапшота для отката файла.');
        }

        $target = LocalFileGuard::absolute($relativePath);

        if (empty($snapshot['existed'])) {
            if (is_file($target) || is_link($target)) {
                if (!File::deleteFile($target)) {
                    throw new \RuntimeException('Не удалось удалить созданный файл при откате.');
                }
            }

            return;
        }

        $snapshotPath = (string)($snapshot['snapshot_path'] ?? '');
        if ($snapshotPath === '' || !is_file($snapshotPath)) {
            throw new \RuntimeException('Файл снапшота не найден — откат невозможен.');
        }

        self::assertSnapshotPath($snapshotPath);

        $expectedHash = (string)($snapshot['hash'] ?? '');
        if ($expectedHash !== '') {
            $actualHash = hash_file('sha256', $snapshotPath);
            if (!is_string($actualHash) || !hash_equals($expectedHash, $actualHash)) {
                throw new \RuntimeException('Снапшот повреждён — откат невозможен.');
            }
        }

        Directory::createDirectory(dirname($target));

        if (!@copy($snapshotPath, $target)) {
            throw new \RuntimeException('Не удалось восстановить файл из снапшота.');
        }

        $permissions = @fileperms($snapshotPath);
        if ($permissions !== false) {
            @chmod($target, $permissions & 0777);
        }
    }

    private static function changeDir(int $changeId): string
    {
        if ($changeId <= 0) {
            throw new \InvalidArgumentException('Некорректный идентификатор изменения.');
        }

        return LocalFileGuard::root() . self::DIR . '/' . $changeId;
    }

    private static function assertSnapshotPath(string $snapshotPath): void
    {
        $base = realpath(LocalFileGuard::root() . self::DIR);
        $real = realpath($snapshotPath);

        if ($base === false || $real === false || !str_starts_with($real, $base . '/')) {
            throw new \RuntimeException('Снапшот находится вне каталога изменений — откат запрещён.');
        }
    }
}
