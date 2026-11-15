<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;
use Bitrix\Rest\AppTable;

/** Зарегистрированные REST-приложения и вебхуки. */
class ListRestAppsTool extends AbstractTool
{
    public const DOMAIN = 'integrations';

    private const LIMIT = 100;

    public static function getName(): string
    {
        return 'list_rest_apps';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_rest_apps',
            'description' => 'REST-приложения и вебхуки: ID, название, код, тип (локальное/тиражное/вебхук), активность. Максимум 100.',
            'parameters' => array(
                'type' => 'object',
                'properties' => new \stdClass(),
            ),
        );
    }

    /** @return array{count:int,truncated:bool,apps:array}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('rest')) {
            return array('error' => 'Модуль rest недоступен.');
        }

        $rows = AppTable::getList(array(
            'select' => array('ID', 'CODE', 'CLIENT_ID', 'ACTIVE', 'INSTALLED', 'STATUS', 'SCOPE'),
            'order' => array('ID' => 'DESC'),
            'limit' => self::LIMIT + 1,
        ))->fetchAll();

        $truncated = count($rows) > self::LIMIT;
        $apps = array();

        foreach (array_slice($rows, 0, self::LIMIT) as $row) {
            $apps[] = array(
                'id' => (int)$row['ID'],
                'code' => (string)($row['CODE'] ?? ''),
                'client_id' => (string)($row['CLIENT_ID'] ?? ''),
                'active' => (string)$row['ACTIVE'] === 'Y',
                'installed' => (string)$row['INSTALLED'] === 'Y',
                'status' => (string)($row['STATUS'] ?? ''),
                'scope' => (string)($row['SCOPE'] ?? ''),
            );
        }

        return array('count' => count($apps), 'truncated' => $truncated, 'apps' => $apps);
    }
}
