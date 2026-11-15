<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;

/** Почтовые шаблоны и типы событий. */
class ListMailTemplatesTool extends AbstractTool
{
    public const DOMAIN = 'core';

    private const LIMIT = 100;

    public static function getName(): string
    {
        return 'list_mail_templates';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_mail_templates',
            'description' => 'Почтовые шаблоны: ID, тип события, тема, отправитель, активность. Можно фильтровать по типу события.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'event_name' => array('type' => 'string', 'description' => 'Тип события, например NEW_USER'),
                    'site_id' => array('type' => 'string', 'description' => 'Код сайта'),
                ),
            ),
        );
    }

    /** @return array{count:int,truncated:bool,templates:array}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('main')) {
            return array('error' => 'Модуль main недоступен.');
        }

        $filter = array();

        if (trim((string)($args['event_name'] ?? '')) !== '') {
            $filter['=EVENT_NAME'] = trim((string)$args['event_name']);
        }

        if (trim((string)($args['site_id'] ?? '')) !== '') {
            $filter['=LID'] = trim((string)$args['site_id']);
        }

        $rows = \Bitrix\Main\Mail\Internal\EventMessageTable::getList(array(
            'select' => array('ID', 'EVENT_NAME', 'SUBJECT', 'EMAIL_FROM', 'ACTIVE', 'LID', 'BODY_TYPE'),
            'filter' => $filter,
            'order' => array('ID' => 'ASC'),
            'limit' => self::LIMIT + 1,
        ))->fetchAll();

        $truncated = count($rows) > self::LIMIT;
        $templates = array();

        foreach (array_slice($rows, 0, self::LIMIT) as $row) {
            $templates[] = array(
                'id' => (int)$row['ID'],
                'event_name' => (string)$row['EVENT_NAME'],
                'subject' => (string)($row['SUBJECT'] ?? ''),
                'from' => (string)($row['EMAIL_FROM'] ?? ''),
                'site_id' => (string)($row['LID'] ?? ''),
                'active' => (string)$row['ACTIVE'] === 'Y',
                'body_type' => (string)($row['BODY_TYPE'] ?? 'text'),
            );
        }

        return array('count' => count($templates), 'truncated' => $truncated, 'templates' => $templates);
    }
}
