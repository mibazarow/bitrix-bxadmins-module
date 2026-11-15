<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Blog\PostTable;
use Bitrix\Main\Loader;

/** Правка поста блога. */
class UpdateBlogPostTool extends AbstractChangeTool
{
    public const DOMAIN = 'collaboration';

    public static function getName(): string
    {
        return 'update_blog_post';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'update_blog_post',
            'description' => 'Меняет пост блога: заголовок, текст, публикацию. Список — list_blog_posts.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'post_id' => array('type' => 'integer', 'description' => 'ID поста'),
                    'title' => array('type' => 'string', 'description' => 'Новый заголовок'),
                    'text' => array('type' => 'string', 'description' => 'Новый текст'),
                    'publish' => array('type' => 'boolean', 'description' => 'Опубликовать (true) или снять с публикации (false)'),
                ),
                'required' => array('post_id'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $postId = (int)($args['post_id'] ?? 0);
        if ($postId <= 0) {
            throw new \InvalidArgumentException('Параметр post_id обязателен.');
        }

        if (!Loader::includeModule('blog')) {
            throw new \RuntimeException('Модуль blog не установлен.');
        }

        $post = PostTable::getList(array(
            'select' => array('ID', 'TITLE', 'DETAIL_TEXT', 'PUBLISH_STATUS'),
            'filter' => array('=ID' => $postId),
            'limit' => 1,
        ))->fetch();

        if (!$post) {
            throw new \InvalidArgumentException('Пост #' . $postId . ' не найден.');
        }

        $fields = array();
        $lines = array();

        if (array_key_exists('title', $args)) {
            $title = trim((string)$args['title']);
            if ($title !== '' && $title !== (string)$post['TITLE']) {
                $fields['TITLE'] = $title;
                $lines[] = 'Заголовок: ' . $post['TITLE'] . ' → ' . $title;
            }
        }

        if (array_key_exists('text', $args)) {
            $text = trim((string)$args['text']);
            if ($text !== '' && $text !== (string)$post['DETAIL_TEXT']) {
                $fields['DETAIL_TEXT'] = $text;
                $lines[] = 'Текст обновлён (' . mb_strlen($text) . ' символов)';
            }
        }

        if (array_key_exists('publish', $args)) {
            $status = ((bool)$args['publish']) ? 'P' : 'D';
            if ($status !== (string)$post['PUBLISH_STATUS']) {
                $fields['PUBLISH_STATUS'] = $status;
                $lines[] = $status === 'P' ? 'Пост опубликован' : 'Пост снят с публикации';
            }
        }

        if ($fields === array()) {
            throw new \InvalidArgumentException('Изменений нет: переданные значения совпадают с текущими.');
        }

        return array(
            'summary' => 'Пост #' . $postId . ': ' . implode(', ', array_keys($fields)),
            'payload' => array(
                'args' => array('post_id' => $postId, 'fields' => $fields),
                'before' => array(
                    'TITLE' => (string)$post['TITLE'],
                    'DETAIL_TEXT' => (string)$post['DETAIL_TEXT'],
                    'PUBLISH_STATUS' => (string)$post['PUBLISH_STATUS'],
                ),
            ),
            'preview' => 'Пост «' . $post['TITLE'] . "\"\n" . implode("\n", $lines),
        );
    }

    public static function apply(array $payload): array
    {
        $postId = (int)($payload['args']['post_id'] ?? 0);
        $fields = (array)($payload['args']['fields'] ?? array());

        // PostTable::update() намеренно не реализован: правка поста — только CBlogPost.
        if (!\CBlogPost::Update($postId, $fields)) {
            throw new \RuntimeException('Не удалось изменить пост #' . $postId . '.');
        }

        return array('post_id' => $postId, 'updated' => array_keys($fields));
    }

    public static function rollback(array $payload): void
    {
        $postId = (int)($payload['args']['post_id'] ?? 0);
        $before = (array)($payload['before'] ?? array());

        if ($postId > 0 && $before !== array()) {
            \CBlogPost::Update($postId, $before);
        }
    }
}
