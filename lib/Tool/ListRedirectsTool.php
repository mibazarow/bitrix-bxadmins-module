<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

/** Правила ЧПУ из urlrewrite.php. */
class ListRedirectsTool extends AbstractTool
{
    public const DOMAIN = 'seo';

    public static function getName(): string
    {
        return 'list_redirects';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_redirects',
            'description' => 'Правила адресов (urlrewrite.php): условие, путь, компонент. Для редиректов смотри set_redirect.',
            'parameters' => array('type' => 'object', 'properties' => new \stdClass()),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        $rules = RedirectFile::read();

        $items = array();
        foreach ($rules as $index => $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $items[] = array(
                'index' => (int)$index,
                'condition' => (string)($rule['CONDITION'] ?? ''),
                'path' => (string)($rule['PATH'] ?? ''),
                'rule' => (string)($rule['RULE'] ?? ''),
                'component' => (string)($rule['ID'] ?? ''),
                'redirect' => isset($rule['REDIRECT']) ? (string)$rule['REDIRECT'] : '',
            );
        }

        return array('redirects' => $items, 'count' => count($items));
    }
}
