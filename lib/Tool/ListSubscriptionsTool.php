<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;
use Bitrix\Subscribe\RubricTable;
use Bitrix\Subscribe\SubscriptionTable;

/** Подписчики рассылок. */
class ListSubscriptionsTool extends AbstractTool
{
    public const DOMAIN = 'marketing';

    private const LIMIT = 100;

    public static function getName(): string
    {
        return 'list_subscriptions';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_subscriptions',
            'description' => 'Подписчики рассылок: email, активность, дата. По умолчанию 100 последних.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'active_only' => array('type' => 'boolean', 'description' => 'Только активные подписки'),
                ),
            ),
        );
    }

    /** @return array{count:int,truncated:bool,rubrics:array,subscriptions:array}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('subscribe')) {
            return array('error' => 'Модуль subscribe недоступен.');
        }

        $rubrics = array();
        foreach (RubricTable::getList(array('select' => array('ID', 'NAME', 'ACTIVE')))->fetchAll() as $row) {
            $rubrics[] = array(
                'id' => (int)$row['ID'],
                'name' => (string)$row['NAME'],
                'active' => (string)$row['ACTIVE'] === 'Y',
            );
        }

        $filter = array();
        if (($args['active_only'] ?? false) === true) {
            $filter['=ACTIVE'] = 'Y';
        }

        $rows = SubscriptionTable::getList(array(
            'select' => array('ID', 'EMAIL', 'ACTIVE', 'DATE_INSERT', 'CONFIRMED'),
            'filter' => $filter,
            'order' => array('ID' => 'DESC'),
            'limit' => self::LIMIT + 1,
        ))->fetchAll();

        $truncated = count($rows) > self::LIMIT;
        $subscriptions = array();

        foreach (array_slice($rows, 0, self::LIMIT) as $row) {
            $subscriptions[] = array(
                'id' => (int)$row['ID'],
                'email' => (string)$row['EMAIL'],
                'active' => (string)$row['ACTIVE'] === 'Y',
                'confirmed' => (string)($row['CONFIRMED'] ?? 'N') === 'Y',
                'date' => $row['DATE_INSERT'] instanceof \Bitrix\Main\Type\Date ? $row['DATE_INSERT']->toString() : (string)$row['DATE_INSERT'],
            );
        }

        return array(
            'count' => count($subscriptions),
            'truncated' => $truncated,
            'rubrics' => $rubrics,
            'subscriptions' => $subscriptions,
        );
    }
}
