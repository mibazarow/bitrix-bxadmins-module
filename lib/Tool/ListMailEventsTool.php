<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;

/** Типы почтовых событий, зарегистрированные модулями. */
class ListMailEventsTool extends AbstractTool
{
    public const DOMAIN = 'core';

    private const LIMIT = 200;

    public static function getName(): string
    {
        return 'list_mail_events';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_mail_events',
            'description' => 'Типы почтовых событий (например NEW_USER, SALE_NEW_ORDER) с полями-переменными.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'module_id' => array('type' => 'string', 'description' => 'Только события модуля'),
                ),
            ),
        );
    }

    /** @return array{count:int,truncated:bool,events:array}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('main')) {
            return array('error' => 'Модуль main недоступен.');
        }

        $filter = array();

        if (trim((string)($args['module_id'] ?? '')) !== '') {
            $filter['=EVENT_TYPE'] = trim((string)$args['module_id']);
        }

        $rows = \Bitrix\Main\Mail\Internal\EventTypeTable::getList(array(
            'select' => array('ID', 'EVENT_NAME', 'NAME', 'DESCRIPTION', 'SORT', 'EVENT_TYPE'),
            'filter' => $filter,
            'order' => array('EVENT_NAME' => 'ASC'),
            'limit' => self::LIMIT + 1,
        ))->fetchAll();

        $truncated = count($rows) > self::LIMIT;
        $events = array();

        foreach (array_slice($rows, 0, self::LIMIT) as $row) {
            $events[] = array(
                'event_name' => (string)$row['EVENT_NAME'],
                'name' => (string)($row['NAME'] ?? ''),
                'module_id' => (string)($row['EVENT_TYPE'] ?? ''),
                'fields' => (string)($row['DESCRIPTION'] ?? ''),
            );
        }

        return array('count' => count($events), 'truncated' => $truncated, 'events' => $events);
    }
}
