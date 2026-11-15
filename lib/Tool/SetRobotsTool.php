<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

/** Запись robots.txt. */
class SetRobotsTool extends AbstractChangeTool
{
    public const DOMAIN = 'seo';

    private const MAX_LENGTH = 20000;

    public static function getName(): string
    {
        return 'set_robots';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'set_robots',
            'description' => 'Заменяет robots.txt целиком. Текущее содержимое — get_robots.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'content' => array('type' => 'string', 'description' => 'Полный текст robots.txt'),
                ),
                'required' => array('content'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $content = (string)($args['content'] ?? '');
        if (trim($content) === '') {
            throw new \InvalidArgumentException('Содержимое пустое.');
        }

        if (mb_strlen($content) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('Слишком длинный robots.txt.');
        }

        $path = self::path();
        $before = is_file($path) ? (string)file_get_contents($path) : '';

        if ($before === $content) {
            throw new \InvalidArgumentException('Изменений нет: содержимое совпадает.');
        }

        return array(
            'summary' => 'robots.txt',
            'payload' => array(
                'args' => array('content' => $content),
                'before' => array('content' => $before, 'exists' => is_file($path)),
            ),
            'preview' => "robots.txt будет заменён:\n\n"
                . \Mibazarow\Bxadmins\Service\Markdown::code(mb_substr($content, 0, 1500)),
        );
    }

    public static function apply(array $payload): array
    {
        $content = (string)($payload['args']['content'] ?? '');
        $path = self::path();

        if (@file_put_contents($path, $content) === false) {
            throw new \RuntimeException('Не удалось записать robots.txt.');
        }

        return array('path' => '/robots.txt', 'bytes' => strlen($content));
    }

    public static function rollback(array $payload): void
    {
        $path = self::path();
        $before = (array)($payload['before'] ?? array());

        if (empty($before['exists'])) {
            @unlink($path);

            return;
        }

        @file_put_contents($path, (string)($before['content'] ?? ''));
    }

    private static function path(): string
    {
        return rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') . '/robots.txt';
    }
}
