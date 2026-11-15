<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;

/** Переиндексация поиска сайта. */
class ReindexSearchTool extends AbstractChangeTool
{
    public const DOMAIN = 'seo';

    public static function getName(): string
    {
        return 'reindex_search';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'reindex_search',
            'description' => 'Пересобирает поисковый индекс сайта (модуль search). Может занять время на большом каталоге.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'full' => array('type' => 'boolean', 'description' => 'Полная переиндексация, по умолчанию да'),
                ),
            ),
        );
    }

    public static function preview(array $args): array
    {
        if (!Loader::includeModule('search')) {
            throw new \RuntimeException('Модуль search не установлен.');
        }

        return array(
            'summary' => 'Переиндексация поиска',
            'payload' => array('args' => array('full' => ($args['full'] ?? true) !== false)),
            'preview' => 'Индекс поиска будет пересобран. На крупном каталоге это занимает минуты; '
                . 'сайт при этом работает.',
        );
    }

    public static function apply(array $payload): array
    {
        $full = !empty($payload['args']['full']);

        // Легаси-исключение: полная переиндексация — только CSearch::ReIndexAll.
        \CSearch::ReIndexAll($full);

        return array('reindexed' => true, 'full' => $full);
    }

    public static function rollback(array $payload): void
    {
        // Индекс пересобирается заново — откат не нужен и не имеет смысла.
    }
}
