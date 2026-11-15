<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

/** Удаление правила адреса по исходному пути. */
class DeleteRedirectTool extends AbstractChangeTool
{
    public const DOMAIN = 'seo';

    public static function getName(): string
    {
        return 'delete_redirect';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'delete_redirect',
            'description' => 'Удаляет правило из urlrewrite.php по исходному пути (как в list_redirects).',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'from' => array('type' => 'string', 'description' => 'Исходный путь правила'),
                ),
                'required' => array('from'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $from = trim((string)($args['from'] ?? ''));
        if ($from === '') {
            throw new \InvalidArgumentException('Параметр from обязателен.');
        }

        if (!str_starts_with($from, '/')) {
            $from = '/' . $from;
        }

        $rules = RedirectFile::read();
        $found = null;

        foreach ($rules as $index => $rule) {
            if (is_array($rule) && (string)($rule['CONDITION'] ?? '') === '#^' . preg_quote($from, '#') . '$#') {
                $found = (int)$index;
                break;
            }
        }

        if ($found === null) {
            throw new \InvalidArgumentException('Правило для ' . $from . ' не найдено.');
        }

        return array(
            'summary' => 'Удаление правила ' . $from,
            'payload' => array(
                'args' => array('from' => $from),
                'before' => array('rules' => $rules),
            ),
            'preview' => 'Правило ' . $from . ' → ' . (string)($rules[$found]['PATH'] ?? '') . ' будет удалено.',
        );
    }

    public static function apply(array $payload): array
    {
        $from = (string)($payload['args']['from'] ?? '');
        $rules = RedirectFile::read();

        $kept = array();
        $removed = 0;
        foreach ($rules as $index => $rule) {
            if (is_array($rule) && (string)($rule['CONDITION'] ?? '') === '#^' . preg_quote($from, '#') . '$#') {
                $removed++;

                continue;
            }

            $kept[$index] = $rule;
        }

        RedirectFile::write($kept);

        return array('from' => $from, 'removed' => $removed);
    }

    public static function rollback(array $payload): void
    {
        RedirectFile::write((array)($payload['before']['rules'] ?? array()));
    }
}
