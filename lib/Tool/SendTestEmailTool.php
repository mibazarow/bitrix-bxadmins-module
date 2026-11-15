<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Mail\Event;

/**
 * Тестовое письмо: проверка, что почта реально уходит.
 *
 * Отправляется штатным механизмом Битрикса (событие + шаблон), поэтому
 * проверяется именно тот путь, которым уходят письма сайта, а не обходной mail().
 */
class SendTestEmailTool extends AbstractChangeTool
{
    public const DOMAIN = 'core';

    private const EVENT_NAME = 'BXADMINS_TEST_EMAIL';

    public static function getName(): string
    {
        return 'send_test_email';
    }

    public static function needsSqlDump(): bool
    {
        // Письмо ничего не меняет в данных сайта.
        return false;
    }

    public static function rollbackNeedsRestore(): bool
    {
        return false;
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'send_test_email',
            'description' => 'Отправляет тестовое письмо на адрес и сообщает, принял ли его почтовый сервер. Проверяет реальную отправку почты сайта.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'email' => array('type' => 'string', 'description' => 'Куда отправить'),
                    'subject' => array('type' => 'string', 'description' => 'Тема, по умолчанию «Проверка почты сайта»'),
                ),
                'required' => array('email'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $email = trim((string)($args['email'] ?? ''));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Некорректный адрес: ' . $email);
        }

        $subject = trim((string)($args['subject'] ?? '')) ?: 'Проверка почты сайта';

        return array(
            'summary' => 'Тестовое письмо на ' . $email,
            'payload' => array(
                'args' => array('email' => $email, 'subject' => mb_substr($subject, 0, 200)),
            ),
            'preview' => 'Письмо «' . $subject . '» будет отправлено на ' . $email
                . '. Ничего в данных сайта не меняется.',
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());
        $email = (string)$args['email'];
        $subject = (string)$args['subject'];
        $sentAt = date('Y-m-d H:i:s');

        self::ensureTemplate($subject);

        $result = Event::send(array(
            'EVENT_NAME' => self::EVENT_NAME,
            'LID' => self::siteId(),
            'C_FIELDS' => array(
                'EMAIL' => $email,
                'SUBJECT' => $subject,
                'SENT_AT' => $sentAt,
                'SITE_NAME' => self::siteName(),
            ),
        ));

        if (!$result->isSuccess()) {
            throw new \RuntimeException(
                'Почта не приняла письмо: ' . implode('; ', $result->getErrorMessages())
            );
        }

        return array(
            'email' => $email,
            'subject' => $subject,
            'sent_at' => $sentAt,
            'note' => 'Письмо передано почтовому серверу. Если его нет во «Входящих», проверьте «Спам» '
                . 'и настройки почты в админке (Настройки → Настройки продукта → Почта).',
        );
    }

    public static function rollback(array $payload): void
    {
        // Отправленное письмо не отозвать — откатывать нечего.
    }

    /** Создаёт шаблон письма, если его ещё нет: без шаблона событие не отправится. */
    private static function ensureTemplate(string $subject): void
    {
        $exists = \CEventMessage::GetList(
            $by = 'id',
            $order = 'asc',
            array('EVENT_NAME' => self::EVENT_NAME, 'LID' => self::siteId())
        )->Fetch();

        if ($exists) {
            return;
        }

        $message = new \CEventMessage();
        $message->Add(array(
            'EVENT_NAME' => self::EVENT_NAME,
            'LID' => self::siteId(),
            'ACTIVE' => 'Y',
            'EMAIL_FROM' => '#DEFAULT_EMAIL_FROM#',
            'EMAIL_TO' => '#EMAIL#',
            'SUBJECT' => '#SUBJECT#',
            'BODY_TYPE' => 'text',
            'MESSAGE' => "Это тестовое письмо с сайта #SITE_NAME#.\n\n"
                . "Отправлено: #SENT_AT#.\n"
                . "Если вы его получили — почта сайта работает.\n",
        ));
    }

    private static function siteId(): string
    {
        if (defined('SITE_ID') && (string)SITE_ID !== '') {
            return (string)SITE_ID;
        }

        $row = \Bitrix\Main\SiteTable::getList(array(
            'select' => array('LID'),
            'filter' => array('=ACTIVE' => 'Y'),
            'order' => array('DEF' => 'DESC', 'SORT' => 'ASC'),
            'limit' => 1,
        ))->fetch();

        return (string)($row['LID'] ?? 's1');
    }

    private static function siteName(): string
    {
        $row = \Bitrix\Main\SiteTable::getList(array(
            'select' => array('NAME'),
            'filter' => array('=LID' => self::siteId()),
            'limit' => 1,
        ))->fetch();

        return (string)($row['NAME'] ?? 'сайт');
    }
}
