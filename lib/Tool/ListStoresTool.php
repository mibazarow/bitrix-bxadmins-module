<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Catalog\StoreTable;
use Bitrix\Main\Loader;

/** Склады. */
class ListStoresTool extends AbstractTool
{
    public const DOMAIN = 'commerce';

    public static function getName(): string
    {
        return 'list_stores';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_stores',
            'description' => 'Склады: ID, название, код, адрес, активность.',
            'parameters' => array(
                'type' => 'object',
                'properties' => new \stdClass(),
            ),
        );
    }

    /** @return array{count:int,stores:array}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('catalog')) {
            return array('error' => 'Модуль catalog недоступен.');
        }

        $stores = array();

        foreach (StoreTable::getList(array(
            'select' => array('ID', 'TITLE', 'CODE', 'ADDRESS', 'ACTIVE', 'SORT'),
            'order' => array('SORT' => 'ASC', 'ID' => 'ASC'),
        ))->fetchAll() as $row) {
            $stores[] = array(
                'id' => (int)$row['ID'],
                'title' => (string)$row['TITLE'],
                'code' => (string)($row['CODE'] ?? ''),
                'address' => (string)($row['ADDRESS'] ?? ''),
                'active' => (string)$row['ACTIVE'] === 'Y',
            );
        }

        return array('count' => count($stores), 'stores' => $stores);
    }
}
