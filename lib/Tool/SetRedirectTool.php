<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

/** Новое правило адреса (редирект или ЧПУ). */
class SetRedirectTool extends AbstractChangeTool
{
    public const DOMAIN = 'seo';

    public static function getName(): string
    {
        return 'set_redirect';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'set_redirect',
            'description' => 'Добавляет правило адреса в urlrewrite.php: редирект со старого адреса на новый или правило ЧПУ. Список — list_redirects.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'from' => array('type' => 'string', 'description' => 'Старый путь, например /catalog/old/'),
                    'to' => array('type' => 'string', 'description' => 'Новый путь, например /catalog/new/'),
                    'status' => array('type' => 'integer', 'description' => 'Код редиректа: 301 (постоянный) или 302'),
                ),
                'required' => array('from', 'to'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $from = self::normalizePath((string)($args['from'] ?? ''));
        $to = self::normalizePath((string)($args['to'] ?? ''));
        $status = (int)($args['status'] ?? 301);

        if ($from === '' || $to === '') {
            throw new \InvalidArgumentException('Нужны from и to — пути вида /catalog/old/.');
        }

        if ($from === $to) {
            throw new \InvalidArgumentException('Адрес назначения совпадает с исходным.');
        }

        if (!in_array($status, array(301, 302), true)) {
            throw new \InvalidArgumentException('Код редиректа — 301 или 302.');
        }

        $rules = RedirectFile::read();
        foreach ($rules as $rule) {
            if (is_array($rule) && (string)($rule['CONDITION'] ?? '') === '#^' . preg_quote($from, '#') . '$#') {
                throw new \InvalidArgumentException('Правило для ' . $from . ' уже есть.');
            }
        }

        return array(
            'summary' => 'Редирект ' . $from . ' → ' . $to,
            'payload' => array(
                'args' => array('from' => $from, 'to' => $to, 'status' => $status),
                'before' => array('rules' => $rules),
            ),
            'preview' => 'Новое правило: ' . $from . ' → ' . $to . ' (' . $status . ')',
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());
        $rules = RedirectFile::read();

        $rules[] = array(
            'CONDITION' => '#^' . preg_quote((string)$args['from'], '#') . '$#',
            'RULE' => '',
            'PATH' => (string)$args['to'],
            'REDIRECT' => (string)(int)$args['status'],
        );

        RedirectFile::write($rules);

        return array('from' => (string)$args['from'], 'to' => (string)$args['to'], 'status' => (int)$args['status']);
    }

    public static function rollback(array $payload): void
    {
        $rules = (array)($payload['before']['rules'] ?? array());
        RedirectFile::write($rules);
    }

    private static function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        return $path;
    }
}
