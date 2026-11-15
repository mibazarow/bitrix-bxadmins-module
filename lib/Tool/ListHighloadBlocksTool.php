<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\Loader;

/** HighLoad-блоки. */
class ListHighloadBlocksTool extends AbstractTool
{
    public const DOMAIN = 'content';

    private const LIMIT = 100;

    public static function getName(): string
    {
        return 'list_highload_blocks';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_highload_blocks',
            'description' => 'HighLoad-блоки: ID, название, таблица, сущность ORM. Максимум 100.',
            'parameters' => array(
                'type' => 'object',
                'properties' => new \stdClass(),
            ),
        );
    }

    /** @return array{count:int,truncated:bool,blocks:array}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('highloadblock')) {
            return array('error' => 'Модуль highloadblock недоступен.');
        }

        $rows = HighloadBlockTable::getList(array(
            'select' => array('ID', 'NAME', 'TABLE_NAME'),
            'order' => array('ID' => 'ASC'),
            'limit' => self::LIMIT + 1,
        ))->fetchAll();

        $truncated = count($rows) > self::LIMIT;
        $blocks = array();

        foreach (array_slice($rows, 0, self::LIMIT) as $row) {
            $blocks[] = array(
                'id' => (int)$row['ID'],
                'name' => (string)$row['NAME'],
                'table' => (string)$row['TABLE_NAME'],
                'entity' => (string)HighloadBlockTable::compileEntity($row)->getDataClass(),
            );
        }

        return array('count' => count($blocks), 'truncated' => $truncated, 'blocks' => $blocks);
    }
}
