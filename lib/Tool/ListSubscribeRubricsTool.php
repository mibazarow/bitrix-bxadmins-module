<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;

/** Рубрики рассылок. */
class ListSubscribeRubricsTool extends AbstractTool
{
    public const DOMAIN = 'marketing';

    public static function getName(): string
    {
        return 'list_subscribe_rubrics';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_subscribe_rubrics',
            'description' => 'Рубрики подписки: ID, название, код, число подписчиков. Подписчики — list_subscriptions.',
            'parameters' => array('type' => 'object', 'properties' => new \stdClass()),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('subscribe')) {
            return array('error' => 'Модуль subscribe не установлен. Установите его: install_module с module_id=subscribe.');
        }

        $rubrics = array();
        $list = \CRubric::GetList(array('SORT' => 'ASC'), array());

        while ($row = $list->Fetch()) {
            $rubrics[] = array(
                'id' => (int)$row['ID'],
                'name' => (string)$row['NAME'],
                'code' => (string)($row['CODE'] ?? ''),
                'active' => (string)$row['ACTIVE'] === 'Y',
                'subscribers' => (int)\CRubric::GetSubscriptionCount((int)$row['ID']),
            );
        }

        return array('count' => count($rubrics), 'rubrics' => $rubrics);
    }
}
