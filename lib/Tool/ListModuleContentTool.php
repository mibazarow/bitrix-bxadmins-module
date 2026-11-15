<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Iblock\IblockTable;
use Bitrix\Main\Loader;

/**
 * Содержимое модулей, построенных на инфоблоках: универсальные списки,
 * wiki, фотогалереи. У каждого свой тип инфоблока — поэтому один инструмент
 * на все три вместо трёх почти одинаковых.
 */
class ListModuleContentTool extends AbstractTool
{
    public const DOMAIN = 'content';

    /** Вид контента => тип инфоблока и подпись. */
    private const KINDS = array(
        'lists' => array('type' => 'lists', 'label' => 'универсальный список'),
        'wiki' => array('type' => 'wiki', 'label' => 'wiki-страница'),
        'photogallery' => array('type' => 'photogallery', 'label' => 'фотогалерея'),
    );

    public static function getName(): string
    {
        return 'list_module_content';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_module_content',
            'description' => 'Содержимое модулей на инфоблоках: универсальные списки (lists), wiki, фотогалереи (photogallery). Возвращает инфоблоки и число элементов.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'kind' => array(
                        'type' => 'string',
                        'description' => 'lists, wiki или photogallery',
                    ),
                ),
                'required' => array('kind'),
            ),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        $kind = strtolower(trim((string)($args['kind'] ?? '')));

        if (!isset(self::KINDS[$kind])) {
            return array('error' => 'Неизвестный вид: ' . $kind . '. Доступны: ' . implode(', ', array_keys(self::KINDS)));
        }

        if (!Loader::includeModule('iblock')) {
            return array('error' => 'Модуль iblock не установлен.');
        }

        $module = $kind === 'photogallery' ? 'photogallery' : $kind;
        if (!Loader::includeModule($module)) {
            return array('error' => 'Модуль ' . $module . ' не установлен. Установите его: install_module с module_id=' . $module . '.');
        }

        $iblocks = array();

        foreach (IblockTable::getList(array(
            'select' => array('ID', 'NAME', 'CODE', 'ACTIVE'),
            'filter' => array('=IBLOCK_TYPE_ID' => self::KINDS[$kind]['type']),
            'order' => array('SORT' => 'ASC', 'ID' => 'ASC'),
            'limit' => 100,
        ))->fetchAll() as $row) {
            $iblocks[] = array(
                'id' => (int)$row['ID'],
                'name' => (string)$row['NAME'],
                'code' => (string)($row['CODE'] ?? ''),
                'active' => (string)$row['ACTIVE'] === 'Y',
                'elements' => (int)\CIBlockElement::GetList(array(), array('IBLOCK_ID' => (int)$row['ID']), array()),
            );
        }

        return array(
            'kind' => $kind,
            'label' => self::KINDS[$kind]['label'],
            'count' => count($iblocks),
            'iblocks' => $iblocks,
            'note' => $iblocks === array()
                ? 'Модуль установлен, но контента этого вида ещё нет.'
                : null,
        );
    }
}
