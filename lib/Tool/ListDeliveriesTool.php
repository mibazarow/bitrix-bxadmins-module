<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;
use Bitrix\Sale\Delivery\Services\Manager;

/** Службы доставки. */
class ListDeliveriesTool extends AbstractTool
{
    public const DOMAIN = 'commerce';

    public static function getName(): string
    {
        return 'list_deliveries';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_deliveries',
            'description' => 'Службы доставки: ID, название, обработчик, активность, логистика.',
            'parameters' => array(
                'type' => 'object',
                'properties' => new \stdClass(),
            ),
        );
    }

    /** @return array{count:int,deliveries:array}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('sale')) {
            return array('error' => 'Модуль sale недоступен.');
        }

        $deliveries = array();

        foreach (Manager::getList(array(
            'select' => array('ID', 'NAME', 'CODE', 'ACTIVE', 'CLASS_NAME', 'PARENT_ID'),
            'order' => array('SORT' => 'ASC', 'ID' => 'ASC'),
        )) as $row) {
            $deliveries[] = array(
                'id' => (int)$row['ID'],
                'name' => (string)$row['NAME'],
                'code' => (string)($row['CODE'] ?? ''),
                'active' => (string)$row['ACTIVE'] === 'Y',
                'handler' => (string)($row['CLASS_NAME'] ?? ''),
                'parent_id' => (int)($row['PARENT_ID'] ?? 0),
            );
        }

        return array('count' => count($deliveries), 'deliveries' => $deliveries);
    }
}
