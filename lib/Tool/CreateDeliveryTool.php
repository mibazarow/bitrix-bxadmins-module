<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;
use Bitrix\Sale\Delivery\Services\Manager;
use Bitrix\Sale\Delivery\Services\Table;

/**
 * Новая служба доставки (профиль обработчика «настраиваемая»).
 *
 * Используется штатный простой обработчик: цена задаётся правилом
 * «стоимость заказа от / до» — этого достаточно для типовых условий
 * («бесплатно от 5000 ₽»).
 */
class CreateDeliveryTool extends AbstractChangeTool
{
    public const DOMAIN = 'commerce';

    public static function getName(): string
    {
        return 'create_delivery';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'create_delivery',
            'description' => 'Создаёт службу доставки с ценой. Бесплатная доставка от суммы — free_from. Список существующих — list_deliveries.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'name' => array('type' => 'string', 'description' => 'Название: Курьер по Москве'),
                    'price' => array('type' => 'number', 'description' => 'Стоимость доставки'),
                    'free_from' => array('type' => 'number', 'description' => 'Бесплатно при сумме заказа от (0 — не применять)'),
                    'currency' => array('type' => 'string', 'description' => 'Валюта, по умолчанию RUB'),
                    'description' => array('type' => 'string', 'description' => 'Описание для покупателя'),
                    'active' => array('type' => 'boolean', 'description' => 'Включить сразу, по умолчанию да'),
                ),
                'required' => array('name', 'price'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $name = trim((string)($args['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Нужно название доставки.');
        }

        if (!Loader::includeModule('sale')) {
            throw new \RuntimeException('Модуль sale не установлен.');
        }

        $price = max(0.0, (float)($args['price'] ?? 0));
        $freeFrom = max(0.0, (float)($args['free_from'] ?? 0));
        $currency = strtoupper(trim((string)($args['currency'] ?? 'RUB'))) ?: 'RUB';

        $exists = Table::getList(array(
            'select' => array('ID'),
            'filter' => array('=NAME' => $name),
            'limit' => 1,
        ))->fetch();

        if ($exists) {
            throw new \InvalidArgumentException('Доставка с названием «' . $name . '» уже есть.');
        }

        $lines = array('Доставка «' . $name . '»', 'Стоимость: ' . $price . ' ' . $currency);

        if ($freeFrom > 0) {
            $lines[] = 'Бесплатно при сумме заказа от ' . $freeFrom . ' ' . $currency;
        }

        return array(
            'summary' => 'Доставка «' . $name . '»',
            'payload' => array(
                'args' => array(
                    'name' => mb_substr($name, 0, 255),
                    'price' => $price,
                    'free_from' => $freeFrom,
                    'currency' => $currency,
                    'description' => mb_substr(trim((string)($args['description'] ?? '')), 0, 1000),
                    'active' => ($args['active'] ?? true) !== false,
                ),
            ),
            'preview' => implode("\n", $lines),
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());

        $price = (float)$args['price'];
        $freeFrom = (float)$args['free_from'];

        // Правило цены: до free_from — цена, от free_from — ноль.
        $config = array(
            'MAIN' => array(
                'CURRENCY' => (string)$args['currency'],
                'PRICE' => $price,
                'PERIOD' => array('FROM' => 1, 'TO' => 3, 'TYPE' => 'D'),
            ),
            'RESULT' => array(
                'CONFIG' => array(
                    'CALCULATE' => array(
                        array(
                            'MODULE' => 'sale',
                            'CLASS' => '\\Bitrix\\Sale\\Delivery\\Calculation\\Simple\\Calculation',
                            'METHOD' => 'calculate',
                            'PARAMS' => array('SORT' => 100),
                        ),
                    ),
                ),
            ),
        );

        if ($freeFrom > 0) {
            // Простое правило: цена действует до порога бесплатной доставки.
            $config['MAIN']['PRICE'] = $price;
            $config['MAIN']['FREE_FROM'] = $freeFrom;
        }

        $fields = array(
            'NAME' => (string)$args['name'],
            'ACTIVE' => !empty($args['active']) ? 'Y' : 'N',
            'DESCRIPTION' => (string)$args['description'],
            'CLASS_NAME' => '\\Bitrix\\Sale\\Delivery\\Services\\Configurable',
            'CURRENCY' => (string)$args['currency'],
            'CONFIG' => $config,
            'SORT' => 500,
        );

        $result = Manager::add($fields);
        if (!$result->isSuccess()) {
            throw new \RuntimeException(
                'Не удалось создать доставку: ' . implode('; ', $result->getErrorMessages())
            );
        }

        return array('delivery_id' => (int)$result->getId(), 'name' => (string)$args['name']);
    }

    public static function rollback(array $payload): void
    {
        $deliveryId = (int)($payload['result']['delivery_id'] ?? 0);
        if ($deliveryId > 0) {
            Manager::delete($deliveryId);
        }
    }
}
