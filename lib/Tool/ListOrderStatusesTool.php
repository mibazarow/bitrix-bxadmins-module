<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;

/** Статусы заказов с названиями. */
class ListOrderStatusesTool extends AbstractTool
{
    public const DOMAIN = 'commerce';

    public static function getName(): string
    {
        return 'list_order_statuses';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_order_statuses',
            'description' => 'Статусы заказов: код и название. Коды нужны для update_order и update_order_status.',
            'parameters' => array('type' => 'object', 'properties' => new \stdClass()),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('sale')) {
            return array('error' => 'Модуль sale недоступен.');
        }

        $lang = LANGUAGE_ID ?: 'ru';
        $rows = \Bitrix\Sale\Internals\StatusTable::getList(array(
            'select' => array('ID', 'SORT', 'TYPE'),
            'filter' => array('=TYPE' => array('O', 'F')),
            'order' => array('SORT' => 'ASC'),
        ))->fetchAll();

        $names = array();
        foreach (\Bitrix\Sale\Internals\StatusLangTable::getList(array(
            'select' => array('STATUS_ID', 'NAME'),
            'filter' => array('=LID' => $lang),
        ))->fetchAll() as $row) {
            $names[(string)$row['STATUS_ID']] = (string)$row['NAME'];
        }

        $statuses = array();
        foreach ($rows as $row) {
            $statuses[] = array(
                'id' => (string)$row['ID'],
                'name' => $names[(string)$row['ID']] ?? (string)$row['ID'],
                'type' => (string)$row['TYPE'],
            );
        }

        return array('statuses' => $statuses);
    }
}
