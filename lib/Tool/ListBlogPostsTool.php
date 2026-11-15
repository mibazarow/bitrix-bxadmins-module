<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Blog\PostTable;
use Bitrix\Main\Loader;

/** Посты блогов и соцсети. */
class ListBlogPostsTool extends AbstractTool
{
    public const DOMAIN = 'collaboration';

    private const LIMIT = 100;

    public static function getName(): string
    {
        return 'list_blog_posts';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_blog_posts',
            'description' => 'Посты блогов и соцсети: ID, заголовок, блог, автор, дата, опубликован. Максимум 100.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'blog_id' => array('type' => 'integer', 'description' => 'Только посты этого блога'),
                    'search' => array('type' => 'string', 'description' => 'Подстрока заголовка'),
                ),
            ),
        );
    }

    /** @return array{count:int,truncated:bool,posts:array}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('blog')) {
            return array('error' => 'Модуль blog недоступен.');
        }

        $filter = array();
        if (($args['blog_id'] ?? 0) > 0) {
            $filter['=BLOG_ID'] = (int)$args['blog_id'];
        }
        if (trim((string)($args['search'] ?? '')) !== '') {
            $filter['%TITLE'] = trim((string)$args['search']);
        }

        $rows = PostTable::getList(array(
            'select' => array('ID', 'BLOG_ID', 'AUTHOR_ID', 'TITLE', 'DATE_PUBLISH', 'PUBLISH_STATUS'),
            'filter' => $filter,
            'order' => array('ID' => 'DESC'),
            'limit' => self::LIMIT + 1,
        ))->fetchAll();

        $truncated = count($rows) > self::LIMIT;
        $posts = array();

        foreach (array_slice($rows, 0, self::LIMIT) as $row) {
            $posts[] = array(
                'id' => (int)$row['ID'],
                'blog_id' => (int)$row['BLOG_ID'],
                'author_id' => (int)$row['AUTHOR_ID'],
                'title' => (string)$row['TITLE'],
                'date' => $row['DATE_PUBLISH'] instanceof \Bitrix\Main\Type\Date ? $row['DATE_PUBLISH']->toString() : (string)$row['DATE_PUBLISH'],
                'published' => (string)$row['PUBLISH_STATUS'] === 'P',
            );
        }

        return array('count' => count($posts), 'truncated' => $truncated, 'posts' => $posts);
    }
}
