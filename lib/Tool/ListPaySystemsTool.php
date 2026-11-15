<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;
use Bitrix\Sale\PaySystem\Manager;

/** Платёжные системы. */
class ListPaySystemsTool extends AbstractTool
{
    public const DOMAIN = 'commerce';

    public static function getName(): string
    {
        return 'list_pay_systems';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_pay_systems',
            'description' => 'Платёжные системы магазина: ID, название, обработчик, активность.',
            'parameters' => array(
                'type' => 'object',
                'properties' => new \stdClass(),
            ),
        );
    }

    /** @return array{count:int,systems:array}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('sale')) {
            return array('error' => 'Модуль sale недоступен.');
        }

        $systems = array();

        foreach (Manager::getList(array(
            'select' => array('ID', 'NAME', 'ACTION_FILE', 'ACTIVE', 'SORT'),
            'order' => array('SORT' => 'ASC', 'ID' => 'ASC'),
        )) as $row) {
            $systems[] = array(
                'id' => (int)$row['ID'],
                'name' => (string)$row['NAME'],
                'handler' => (string)($row['ACTION_FILE'] ?? ''),
                'active' => (string)$row['ACTIVE'] === 'Y',
            );
        }

        return array('count' => count($systems), 'systems' => $systems);
    }
}
