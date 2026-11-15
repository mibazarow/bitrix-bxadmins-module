<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Forum\Message;
use Bitrix\Forum\MessageTable;
use Bitrix\Forum\Topic;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

/**
 * Удаление сообщения форума (модерация).
 * Снимок сообщения сохраняется, откат восстанавливает его через Message::add.
 */
class DeleteForumPostTool extends AbstractChangeTool
{
    public const DOMAIN = 'collaboration';

    public static function getName(): string
    {
        return 'delete_forum_post';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'delete_forum_post',
            'description' => 'Удаляет сообщение форума. Снимок сохраняется, откат восстановит сообщение.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'message_id' => array('type' => 'integer', 'description' => 'ID сообщения'),
                ),
                'required' => array('message_id'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $messageId = (int)($args['message_id'] ?? 0);
        if ($messageId <= 0) {
            throw new \InvalidArgumentException('Параметр message_id обязателен.');
        }

        if (!Loader::includeModule('forum')) {
            throw new \RuntimeException('Модуль forum недоступен.');
        }

        $message = MessageTable::getList(array(
            'select' => array('ID', 'TOPIC_ID', 'FORUM_ID', 'AUTHOR_ID', 'AUTHOR_NAME', 'AUTHOR_EMAIL', 'POST_MESSAGE', 'POST_DATE', 'APPROVED'),
            'filter' => array('=ID' => $messageId),
            'limit' => 1,
        ))->fetch();

        if (!$message) {
            throw new \InvalidArgumentException('Сообщение #' . $messageId . ' не найдено.');
        }

        $before = array(
            'TOPIC_ID' => (int)$message['TOPIC_ID'],
            'FORUM_ID' => (int)$message['FORUM_ID'],
            'AUTHOR_ID' => (int)$message['AUTHOR_ID'],
            'AUTHOR_NAME' => (string)($message['AUTHOR_NAME'] ?? ''),
            'AUTHOR_EMAIL' => (string)($message['AUTHOR_EMAIL'] ?? ''),
            'POST_MESSAGE' => (string)$message['POST_MESSAGE'],
            'APPROVED' => (string)($message['APPROVED'] ?? 'Y'),
            'POST_DATE' => $message['POST_DATE'] instanceof DateTime
                ? $message['POST_DATE']->format('Y-m-d H:i:s')
                : (string)$message['POST_DATE'],
        );

        return array(
            'summary' => 'Удаление сообщения форума #' . $messageId,
            'payload' => array('args' => array('message_id' => $messageId), 'before' => $before),
            'preview' => 'Будет удалено сообщение #' . $messageId . ' (тема #' . $before['TOPIC_ID'] . '): '
                . mb_substr($before['POST_MESSAGE'], 0, 200),
        );
    }

    public static function apply(array $payload): array
    {
        $messageId = (int)($payload['args']['message_id'] ?? 0);

        // remove() пересчитывает счётчики темы и форума и чистит индекс;
        // статический Message::delete и MessageTable::delete этого не делают.
        $message = Message::getById($messageId);
        if ($message === null) {
            throw new \RuntimeException('Сообщение #' . $messageId . ' не найдено.');
        }

        $result = $message->remove();
        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }

        return array('message_id' => $messageId, 'deleted' => true);
    }

    public static function rollback(array $payload): void
    {
        $before = (array)($payload['before'] ?? array());
        if ($before === array()) {
            return;
        }

        $topic = Topic::getById((int)$before['TOPIC_ID']);
        if ($topic === null) {
            throw new \RuntimeException('Тема #' . (int)$before['TOPIC_ID'] . ' больше не существует — сообщение не восстановить.');
        }

        $postDate = new DateTime();
        if (!empty($before['POST_DATE'])) {
            try {
                $postDate = new DateTime((string)$before['POST_DATE']);
            } catch (\Throwable $e) {
                // Некорректная дата в снимке — ставим текущую.
            }
        }

        $result = Message::add($topic, array(
            'USE_SMILES' => 'Y',
            'APPROVED' => (string)($before['APPROVED'] ?? 'Y'),
            'POST_DATE' => $postDate,
            'POST_MESSAGE' => (string)$before['POST_MESSAGE'],
            'AUTHOR_ID' => (int)$before['AUTHOR_ID'],
            'AUTHOR_NAME' => (string)($before['AUTHOR_NAME'] ?? ''),
            'AUTHOR_EMAIL' => (string)($before['AUTHOR_EMAIL'] ?? ''),
        ));

        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }
    }
}
