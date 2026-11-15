<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;
use Bitrix\Main\Mail\Internal\EventMessageTable;

/** Создание и изменение почтового шаблона. */
class SaveMailTemplateTool extends AbstractChangeTool
{
    public const DOMAIN = 'core';

    private const MAX_BODY = 100000;

    public static function getName(): string
    {
        return 'save_mail_template';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'save_mail_template',
            'description' => 'Создаёт или меняет почтовый шаблон для типа события: тема, тело, отправитель.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'template_id' => array('type' => 'integer', 'description' => 'ID шаблона; 0 или не указан — создать новый'),
                    'event_name' => array('type' => 'string', 'description' => 'Тип события, например NEW_USER'),
                    'site_id' => array('type' => 'string', 'description' => 'Код сайта'),
                    'subject' => array('type' => 'string', 'description' => 'Тема письма'),
                    'body' => array('type' => 'string', 'description' => 'Тело письма'),
                    'from' => array('type' => 'string', 'description' => 'Отправитель, например #DEFAULT_EMAIL_FROM#'),
                    'active' => array('type' => 'boolean', 'description' => 'Активен, по умолчанию да'),
                ),
                'required' => array('event_name', 'subject', 'body'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $templateId = (int)($args['template_id'] ?? 0);
        $eventName = trim((string)($args['event_name'] ?? ''));
        $subject = trim((string)($args['subject'] ?? ''));
        $body = (string)($args['body'] ?? '');
        $siteId = trim((string)($args['site_id'] ?? ''));
        $from = trim((string)($args['from'] ?? '#DEFAULT_EMAIL_FROM#'));

        if ($eventName === '') {
            throw new \InvalidArgumentException('Тип события обязателен.');
        }

        if ($subject === '') {
            throw new \InvalidArgumentException('Тема письма обязательна.');
        }

        if (trim($body) === '') {
            throw new \InvalidArgumentException('Тело письма обязательно.');
        }

        if (strlen($body) > self::MAX_BODY) {
            throw new \InvalidArgumentException('Тело письма слишком большое.');
        }

        if (!Loader::includeModule('main')) {
            throw new \RuntimeException('Модуль main недоступен.');
        }

        $before = array();
        if ($templateId > 0) {
            $existing = EventMessageTable::getById($templateId)->fetch();
            if (!$existing) {
                throw new \InvalidArgumentException('Шаблон #' . $templateId . ' не найден.');
            }
            $before = array(
                'subject' => (string)($existing['SUBJECT'] ?? ''),
                'body' => (string)($existing['MESSAGE'] ?? ''),
                'from' => (string)($existing['EMAIL_FROM'] ?? ''),
            );
        }

        $payload = array(
            'template_id' => $templateId,
            'event_name' => $eventName,
            'site_id' => $siteId,
            'subject' => $subject,
            'body' => $body,
            'from' => $from,
            'active' => ($args['active'] ?? true) !== false ? 'Y' : 'N',
        );

        return array(
            'summary' => ($templateId > 0 ? 'Изменение' : 'Создание') . ' шаблона ' . $eventName,
            'payload' => array('args' => $payload, 'before' => $before),
            'preview' => ($templateId > 0 ? 'Шаблон #' . $templateId : 'Новый шаблон') . ' для ' . $eventName
                . "\nТема: " . $subject
                . "\nОт: " . $from
                . "\n\n" . mb_substr($body, 0, 1500),
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());
        $templateId = (int)($args['template_id'] ?? 0);

        $fields = array(
            'EVENT_NAME' => (string)$args['event_name'],
            'SUBJECT' => (string)$args['subject'],
            'MESSAGE' => (string)$args['body'],
            'EMAIL_FROM' => (string)$args['from'],
            'ACTIVE' => (string)$args['active'],
            'BODY_TYPE' => 'html',
        );

        if ((string)$args['site_id'] !== '') {
            $fields['LID'] = (string)$args['site_id'];
        }

        if ($templateId > 0) {
            $result = EventMessageTable::update($templateId, $fields);
            $id = $templateId;
        } else {
            $result = EventMessageTable::add($fields);
            $id = (int)$result->getId();
        }

        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }

        return array('template_id' => $id, 'event_name' => (string)$args['event_name']);
    }

    public static function rollback(array $payload): void
    {
        $args = (array)($payload['args'] ?? array());
        $before = (array)($payload['before'] ?? array());
        $templateId = (int)($args['template_id'] ?? 0);

        if ($templateId <= 0) {
            $created = (int)($payload['result']['template_id'] ?? 0);
            if ($created > 0) {
                EventMessageTable::delete($created);
            }

            return;
        }

        if ($before === array()) {
            return;
        }

        EventMessageTable::update($templateId, array(
            'SUBJECT' => (string)($before['subject'] ?? ''),
            'MESSAGE' => (string)($before['body'] ?? ''),
            'EMAIL_FROM' => (string)($before['from'] ?? ''),
        ));
    }
}
