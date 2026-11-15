<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Forum\Message;
use Bitrix\Forum\MessageTable;
use Bitrix\Main\Loader;

/** Правка и модерация сообщения форума. */
class UpdateForumPostTool extends AbstractChangeTool
{
    public const DOMAIN = 'collaboration';

    public static function getName(): string
    {
        return 'update_forum_post';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'update_forum_post',
            'description' => 'Меняет текст сообщения форума и/или скрывает-одобряет его (модерация). Список — list_forum_posts.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'message_id' => array('type' => 'integer', 'description' => 'ID сообщения'),
                    'text' => array('type' => 'string', 'description' => 'Новый текст'),
                    'approved' => array('type' => 'boolean', 'description' => 'Одобрить (true) или скрыть (false)'),
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
            throw new \RuntimeException('Модуль forum не установлен.');
        }

        $message = MessageTable::getList(array(
            'select' => array('ID', 'TOPIC_ID', 'AUTHOR_NAME', 'POST_MESSAGE', 'APPROVED'),
            'filter' => array('=ID' => $messageId),
            'limit' => 1,
        ))->fetch();

        if (!$message) {
            throw new \InvalidArgumentException('Сообщение #' . $messageId . ' не найдено.');
        }

        $changes = array();
        $lines = array();

        if (array_key_exists('text', $args)) {
            $text = trim((string)$args['text']);
            if ($text !== '' && $text !== (string)$message['POST_MESSAGE']) {
                $changes['POST_MESSAGE'] = $text;
                $lines[] = 'Текст: ' . mb_substr((string)$message['POST_MESSAGE'], 0, 80)
                    . ' → ' . mb_substr($text, 0, 80);
            }
        }

        if (array_key_exists('approved', $args)) {
            $approved = ((bool)$args['approved']) ? 'Y' : 'N';
            if ($approved !== (string)$message['APPROVED']) {
                $changes['APPROVED'] = $approved;
                $lines[] = $approved === 'Y' ? 'Сообщение одобрено' : 'Сообщение скрыто';
            }
        }

        if ($changes === array()) {
            throw new \InvalidArgumentException('Изменений нет: сообщение уже в этом состоянии.');
        }

        return array(
            'summary' => 'Сообщение форума #' . $messageId,
            'payload' => array(
                'args' => array('message_id' => $messageId, 'changes' => $changes),
                'before' => array(
                    'POST_MESSAGE' => (string)$message['POST_MESSAGE'],
                    'APPROVED' => (string)$message['APPROVED'],
                ),
            ),
            'preview' => 'Сообщение #' . $messageId . ' (тема #' . $message['TOPIC_ID'] . ")\n"
                . implode("\n", $lines),
        );
    }

    public static function apply(array $payload): array
    {
        $messageId = (int)($payload['args']['message_id'] ?? 0);
        $changes = (array)($payload['args']['changes'] ?? array());

        $message = Message::getById($messageId);
        if ($message === null) {
            throw new \RuntimeException('Сообщение #' . $messageId . ' не найдено.');
        }

        // Текст — через edit(): он переиндексирует сообщение в поиске.
        if (isset($changes['POST_MESSAGE'])) {
            $result = $message->edit(array('POST_MESSAGE' => $changes['POST_MESSAGE']));
            if (!$result->isSuccess()) {
                throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
            }
        }

        // Одобрение — через легаси CForumMessage::Update: он пересчитывает
        // счётчики темы и форума. Message::edit() поле APPROVED игнорирует.
        if (isset($changes['APPROVED'])) {
            if (!\CForumMessage::Update($messageId, array('APPROVED' => $changes['APPROVED']))) {
                throw new \RuntimeException('Не удалось изменить статус сообщения #' . $messageId . '.');
            }
        }

        return array('message_id' => $messageId, 'updated' => array_keys($changes));
    }

    public static function rollback(array $payload): void
    {
        $messageId = (int)($payload['args']['message_id'] ?? 0);
        $before = (array)($payload['before'] ?? array());

        $message = $messageId > 0 ? Message::getById($messageId) : null;
        if ($message === null || $before === array()) {
            return;
        }

        if (isset($before['POST_MESSAGE'])) {
            $message->edit(array('POST_MESSAGE' => $before['POST_MESSAGE']));
        }

        if (isset($before['APPROVED'])) {
            \CForumMessage::Update($messageId, array('APPROVED' => $before['APPROVED']));
        }
    }
}
