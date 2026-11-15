<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\Loader;

/**
 * Git-точки отката: коммит всей файловой структуры сайта перед изменением.
 *
 * Репозиториев два, потому что local/ в мультисайте — симлинк на общий каталог:
 * site  — docroot без bitrix/, upload/ и local/;
 * local — сам каталог local/ (общий для обоих сайтов).
 * Сами репозитории лежат вне docroot: <bxadmins-data>/git.
 */
class GitService
{
    private const SITE = 'site';
    private const LOCAL = 'local';

    private const SHA_PATTERN = '/^[0-9a-f]{40}$/';
    private const MAX_MESSAGE_LENGTH = 200;

    /** Исключения site-репозитория: ядро, пользовательские файлы и symlink на local. */
    private const SITE_EXCLUDES = "/bitrix\n/upload\n/local\n";

    /**
     * Исключения local-репозитория: сам харнес. Иначе откат сайта вернул бы
     * модуль к старой версии кода вместе с данными.
     */
    private const LOCAL_EXCLUDES = "/modules/mibazarow.bxadmins/\n";

    private static ?string $binary = null;
    private static bool $binaryResolved = false;
    private static string $lastError = '';

    /** Git доступен: есть исполняемый файл и каталог для репозиториев. */
    public static function isAvailable(): bool
    {
        if (self::binary() === null) {
            self::$lastError = 'не найден исполняемый файл git';

            return false;
        }

        $dir = self::dataDir();
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            self::$lastError = 'не удалось создать каталог git-репозиториев: ' . $dir;

            return false;
        }

        if (!is_writable($dir)) {
            self::$lastError = 'каталог git-репозиториев недоступен для записи: ' . $dir;

            return false;
        }

        return true;
    }

    /**
     * Точка отката: коммитит текущее состояние обоих репозиториев.
     * Возвращает SHA коммитов; null — репозиторий недоступен или пуст.
     *
     * @return array{site:string|null,local:string|null}
     */
    public static function point(string $message): array
    {
        $point = array(self::SITE => null, self::LOCAL => null);

        if (!self::isAvailable()) {
            return $point;
        }

        $message = self::normalizeMessage($message);

        foreach (array(self::SITE, self::LOCAL) as $kind) {
            try {
                self::ensureRepo($kind);
                $point[$kind] = self::commitAll($kind, $message);
            } catch (\Throwable $e) {
                Logger::error('Git: точка отката (' . $kind . ') не создана: ' . $e->getMessage());
            }
        }

        return $point;
    }

    /**
     * Возвращает файлы к состоянию коммитов точки отката.
     *
     * @param array{site?:string|null,local?:string|null} $point
     * @return array{site:string,local:string} человекочитаемый статус по репозиториям
     */
    public static function restore(array $point): array
    {
        $status = array(self::SITE => 'точки нет', self::LOCAL => 'точки нет');

        foreach (array(self::SITE, self::LOCAL) as $kind) {
            $sha = trim((string)($point[$kind] ?? ''));

            if ($sha === '') {
                continue;
            }

            if (preg_match(self::SHA_PATTERN, $sha) !== 1) {
                throw new \RuntimeException('Некорректный идентификатор коммита для ' . $kind . '.');
            }

            self::ensureRepo($kind);

            $reset = self::exec($kind, array('reset', '--hard', $sha));
            if (!$reset['ok']) {
                throw new \RuntimeException(
                    'Не удалось вернуть файлы из коммита ' . $sha . ': '
                    . self::excerpt($reset['stderr'], 'git reset завершился ошибкой')
                );
            }

            $status[$kind] = 'восстановлено (' . $sha . ')';
        }

        return $status;
    }

    public static function lastError(): string
    {
        return self::$lastError;
    }

    private static function dataDir(): string
    {
        return dirname(dirname(Loader::getDocumentRoot())) . '/bxadmins-data/git';
    }

    private static function repoDir(string $kind): string
    {
        return self::dataDir() . '/' . $kind . '.git';
    }

    private static function workTree(string $kind): string
    {
        $root = LocalFileGuard::root();

        if ($kind !== self::LOCAL) {
            return $root;
        }

        $local = realpath($root . '/local');

        return $local !== false ? $local : $root . '/local';
    }

    private static function ensureRepo(string $kind): void
    {
        $dir = self::repoDir($kind);

        if (is_dir($dir . '/objects')) {
            // Исключения могли появиться в новой версии модуля — применяем и к готовому репозиторию.
            self::applyExcludes($kind);

            return;
        }

        $parent = dirname($dir);
        if (!is_dir($parent) && !@mkdir($parent, 0770, true) && !is_dir($parent)) {
            throw new \RuntimeException('Не удалось создать каталог git: ' . $parent);
        }

        $init = self::exec($kind, array('init', '-q'));
        if (!$init['ok']) {
            throw new \RuntimeException(
                'Не удалось инициализировать git-репозиторий: '
                . self::excerpt($init['stderr'], 'git init завершился ошибкой')
            );
        }

        self::exec($kind, array('config', 'user.name', 'BXADMIN-S'));
        self::exec($kind, array('config', 'user.email', 'bxadmins@localhost'));

        self::applyExcludes($kind);
    }

    /** Пишет info/exclude и убирает из индекса то, что исключено. */
    private static function applyExcludes(string $kind): void
    {
        $info = self::repoDir($kind) . '/info';
        if (!is_dir($info)) {
            @mkdir($info, 0770, true);
        }

        $content = $kind === self::SITE ? self::SITE_EXCLUDES : self::LOCAL_EXCLUDES;
        $file = $info . '/exclude';

        if (@file_get_contents($file) !== $content) {
            @file_put_contents($file, $content);
        }

        if ($kind !== self::SITE) {
            // Файлы модуля могли попасть в индекс раньше: убираем, иначе
            // reset --hard вернёт старую версию харнеса.
            self::untrackModule();
        }
    }

    /** Убирает файлы модуля из индекса local-репозитория (сами файлы не трогает). */
    private static function untrackModule(): void
    {
        $tracked = self::exec(self::LOCAL, array('ls-files', '--', 'modules/mibazarow.bxadmins'));

        if (!$tracked['ok'] || trim($tracked['stdout']) === '') {
            return;
        }

        $removed = self::exec(self::LOCAL, array('rm', '-r', '-q', '--cached', '--', 'modules/mibazarow.bxadmins'));

        if (!$removed['ok']) {
            Logger::error('Git: не удалось убрать модуль из индекса local-репозитория.');
        }
    }

    private static function commitAll(string $kind, string $message): ?string
    {
        return self::commitPathsIn($kind, array(), $message);
    }

    /**
     * @param string[] $paths пустой массив — добавить всё дерево (git add -A)
     */
    private static function commitPathsIn(string $kind, array $paths, string $message): ?string
    {
        $addArgs = array('add', '-A', '--');
        $addArgs = array_merge($addArgs, $paths);

        $add = self::exec($kind, $addArgs);
        if (!$add['ok']) {
            throw new \RuntimeException(self::excerpt($add['stderr'], 'git add завершился ошибкой'));
        }

        // Нет изменений — возвращаем текущий HEAD, новый коммит не нужен.
        $staged = self::exec($kind, array('diff', '--cached', '--quiet'));
        if ($staged['ok']) {
            return self::head($kind);
        }

        $commit = self::exec($kind, array('commit', '-q', '-m', $message));
        if (!$commit['ok']) {
            throw new \RuntimeException(self::excerpt($commit['stderr'], 'git commit завершился ошибкой'));
        }

        return self::head($kind);
    }

    private static function head(string $kind): ?string
    {
        $head = self::exec($kind, array('rev-parse', '--verify', '-q', 'HEAD'));

        return $head['ok'] && preg_match(self::SHA_PATTERN, $head['stdout']) === 1 ? $head['stdout'] : null;
    }

    private static function normalizeMessage(string $message): string
    {
        $message = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($message)) ?? '');

        return mb_substr($message !== '' ? $message : 'BXADMIN-S: точка отката', 0, self::MAX_MESSAGE_LENGTH);
    }

    private static function binary(): ?string
    {
        if (!self::$binaryResolved) {
            self::$binaryResolved = true;
            self::$binary = BinaryFinder::find('git');
        }

        return self::$binary;
    }

    /**
     * @param string[] $command
     * @return array{ok:bool,stdout:string,stderr:string}
     */
    private static function exec(string $kind, array $command): array
    {
        $binary = self::binary();
        if ($binary === null) {
            return array('ok' => false, 'stdout' => '', 'stderr' => 'git не найден');
        }

        array_unshift($command, $binary);

        $env = getenv();
        if (!is_array($env)) {
            $env = array();
        }
        $env['GIT_DIR'] = self::repoDir($kind);
        $env['GIT_WORK_TREE'] = self::workTree($kind);
        $env['GIT_TERMINAL_PROMPT'] = '0';

        $descriptors = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );

        $process = @proc_open($command, $descriptors, $pipes, self::workTree($kind), $env);
        if (!is_resource($process)) {
            return array('ok' => false, 'stdout' => '', 'stderr' => 'не удалось запустить git');
        }

        fclose($pipes[0]);
        $stdout = trim((string)stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        $stderr = trim((string)stream_get_contents($pipes[2]));
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return array('ok' => $exitCode === 0, 'stdout' => $stdout, 'stderr' => $stderr);
    }

    private static function excerpt(string $output, string $fallback): string
    {
        $output = trim(str_replace("\0", '', $output));

        return $output !== '' ? mb_substr($output, 0, 300) : $fallback;
    }
}
