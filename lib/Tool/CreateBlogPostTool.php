<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;

/** Публикация поста в блоге или соцсети. */
class CreateBlogPostTool extends AbstractChangeTool
{
    public const DOMAIN = 'collaboration';

    private const MAX_TEXT = 200000;

    public static function getName(): string
    {
        return 'create_blog_post';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'create_blog_post',
            'description' => 'Создаёт пост в блоге (например, в ленте соцсети). Возвращает ID поста.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'blog_id' => array('type' => 'integer', 'description' => 'ID блога; по умолчанию блог текущего пользователя'),
                    'title' => array('type' => 'string', 'description' => 'Заголовок'),
                    'text' => array('type' => 'string', 'description' => 'Текст поста'),
                    'author_id' => array('type' => 'integer', 'description' => 'ID автора, по умолчанию текущий пользователь'),
                    'publish' => array('type' => 'boolean', 'description' => 'Опубликовать сразу (по умолчанию да)'),
                ),
                'required' => array('title', 'text'),
            ),
        );
    }

    public static function preview(array $args, array $context = array()): array
    {
        $title = IblockElementFields::name($args['title'] ?? null);
        $text = trim((string)($args['text'] ?? ''));

        if ($text === '') {
            throw new \InvalidArgumentException('Текст поста обязателен.');
        }

        if (mb_strlen($text) > self::MAX_TEXT) {
            throw new \InvalidArgumentException('Текст поста слишком длинный.');
        }

        if (!Loader::includeModule('blog')) {
            throw new \RuntimeException('Модуль blog недоступен.');
        }

        // Демон вызывает инструмент без сессии, поэтому автор — из контекста.
        $authorId = (int)($args['author_id'] ?? 0)
            ?: (int)($context['user_id'] ?? 0)
            ?: (int)\Bitrix\Main\Engine\CurrentUser::get()->getId();

        if ($authorId <= 0) {
            throw new \InvalidArgumentException('Не удалось определить автора поста.');
        }

        $blogId = (int)($args['blog_id'] ?? 0);

        if ($blogId <= 0) {
            // D7-сущности блогов в этой версии нет — читаем таблицу напрямую.
            $connection = \Bitrix\Main\Application::getConnection();

            $row = $connection->query(
                'SELECT ID FROM b_blog WHERE OWNER_ID = ' . $authorId . ' ORDER BY ID ASC LIMIT 1'
            )->fetch();

            if (!$row) {
                $row = $connection->query('SELECT ID FROM b_blog ORDER BY ID ASC LIMIT 1')->fetch();
            }

            $blogId = (int)($row['ID'] ?? 0);
        }

        if ($blogId <= 0) {
            throw new \InvalidArgumentException('На сайте нет блогов — создать пост некуда.');
        }

        $payload = array(
            'blog_id' => $blogId,
            'title' => $title,
            'text' => $text,
            'author_id' => $authorId,
            'publish' => ($args['publish'] ?? true) !== false,
        );

        return array(
            'summary' => 'Пост «' . $title . '»',
            'payload' => array('args' => $payload),
            'preview' => 'Новый пост в блоге #' . $blogId . ': «' . $title . "»\n\n" . mb_substr($text, 0, 1200),
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());
        $publish = !empty($args['publish']);

        // Легаси-исключение: публикация поста с правами и счётчиками — только CBlogPost.
        $id = \CBlogPost::Add(array(
            'BLOG_ID' => (int)$args['blog_id'],
            'AUTHOR_ID' => (int)$args['author_id'],
            'TITLE' => (string)$args['title'],
            'DETAIL_TEXT' => (string)$args['text'],
            'DETAIL_TEXT_TYPE' => 'text',
            'DATE_PUBLISH' => new \Bitrix\Main\Type\DateTime(),
            'PUBLISH_STATUS' => $publish ? 'P' : 'D',
            'ENABLE_COMMENTS' => 'Y',
        ));

        if (!is_int($id) || $id <= 0) {
            global $APPLICATION;
            $error = $APPLICATION instanceof \CMain ? trim(strip_tags((string)$APPLICATION->GetException())) : '';
            throw new \RuntimeException($error !== '' ? $error : 'Не удалось создать пост.');
        }

        return array('post_id' => (int)$id, 'blog_id' => (int)$args['blog_id'], 'published' => $publish);
    }

    public static function rollback(array $payload): void
    {
        $postId = (int)($payload['result']['post_id'] ?? 0);
        if ($postId > 0) {
            \CBlogPost::Delete($postId);
        }
    }
}
