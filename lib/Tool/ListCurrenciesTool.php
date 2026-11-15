<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Currency\CurrencyTable;
use Bitrix\Main\Loader;

/** Валюты и курсы. */
class ListCurrenciesTool extends AbstractTool
{
    public const DOMAIN = 'commerce';

    public static function getName(): string
    {
        return 'list_currencies';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_currencies',
            'description' => 'Валюты сайта: код, название, курс, базовая ли валюта.',
            'parameters' => array(
                'type' => 'object',
                'properties' => new \stdClass(),
            ),
        );
    }

    /** @return array{count:int,currencies:array}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('currency')) {
            return array('error' => 'Модуль currency недоступен.');
        }

        $currencies = array();

        foreach (CurrencyTable::getList(array(
            'select' => array('CURRENCY', 'BASE', 'AMOUNT', 'AMOUNT_CNT', 'CURRENT_BASE_RATE', 'SORT'),
            'order' => array('SORT' => 'ASC'),
        ))->fetchAll() as $row) {
            $currencies[] = array(
                'code' => (string)$row['CURRENCY'],
                'base' => (string)$row['BASE'] === 'Y',
                // Курс к базовой валюте: 1 единица = CURRENT_BASE_RATE.
                'rate' => (float)$row['CURRENT_BASE_RATE'],
                'amount' => (float)$row['AMOUNT'],
                'amount_cnt' => (int)$row['AMOUNT_CNT'],
            );
        }

        return array('count' => count($currencies), 'currencies' => $currencies);
    }
}
