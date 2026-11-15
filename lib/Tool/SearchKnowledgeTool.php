<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\RagService;

/**
 * Семантический поиск по базе знаний сайта.
 */
class SearchKnowledgeTool extends AbstractTool
{
    public const DOMAIN = 'core';

    public static function getName(): string
    {
        return 'search_knowledge';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'search_knowledge',
            'description' => 'Поиск по базе знаний об этом сайте (индекс модулей, кода local/, компонентов, шаблонов, инфоблоков и событий). Возвращает релевантные фрагменты с источником и score. Используй для вопросов «что на сайте», «где код», «какие инфоблоки/шаблоны/обработчики».',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'query' => array(
                        'type' => 'string',
                        'description' => 'Поисковый запрос (естественный язык).',
                    ),
                    'limit' => array(
                        'type' => 'integer',
                        'description' => 'Число результатов (1–10), по умолчанию 5.',
                    ),
                ),
                'required' => array('query'),
            ),
        );
    }

    /** @return array{query:string,results:array,fallback?:bool,note?:string}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        $query = trim((string)($args['query'] ?? ''));
        if ($query === '') {
            return array('error' => 'Параметр query обязателен и не должен быть пустым.');
        }

        $limit = isset($args['limit']) ? (int)$args['limit'] : 5;
        $limit = max(1, min(10, $limit));

        $search = RagService::search($query, $limit);

        $result = array(
            'query' => $query,
            'results' => $search['results'],
        );

        if (!empty($search['fallback'])) {
            $result['fallback'] = true;
        }

        if (isset($search['note'])) {
            $result['note'] = $search['note'];
        }

        return $result;
    }
}
