<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Catalog\GroupTable;
use Bitrix\Main\Loader;

/** Типы цен каталога. */
class ListPriceTypesTool extends AbstractTool
{
    public const DOMAIN = 'commerce';

    public static function getName(): string
    {
        return 'list_price_types';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_price_types',
            'description' => 'Типы цен каталога: ID, код, название, валюта. ID нужен для update_product_price.',
            'parameters' => array(
                'type' => 'object',
                'properties' => new \stdClass(),
            ),
        );
    }

    /** @return array{count:int,types:array}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('catalog')) {
            return array('error' => 'Модуль catalog недоступен.');
        }

        $types = array();

        foreach (GroupTable::getList(array(
            'select' => array('ID', 'NAME', 'XML_ID', 'BASE', 'SORT'),
            'order' => array('SORT' => 'ASC', 'ID' => 'ASC'),
        ))->fetchAll() as $row) {
            $types[] = array(
                'id' => (int)$row['ID'],
                'name' => (string)$row['NAME'],
                'code' => (string)($row['XML_ID'] ?? ''),
                'base' => (string)$row['BASE'] === 'Y',
            );
        }

        return array('count' => count($types), 'types' => $types);
    }
}
