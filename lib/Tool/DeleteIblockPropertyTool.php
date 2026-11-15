<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Iblock\PropertyTable;
use Bitrix\Main\Loader;

/** Удаление свойства инфоблока вместе со значениями у элементов. */
class DeleteIblockPropertyTool extends AbstractChangeTool
{
    public const DOMAIN = 'content';

    public static function getName(): string
    {
        return 'delete_iblock_property';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'delete_iblock_property',
            'description' => 'Удаляет свойство инфоблока и его значения у всех элементов. Откат — восстановлением сайта.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'property_id' => array('type' => 'integer', 'description' => 'ID свойства'),
                ),
                'required' => array('property_id'),
            ),
        );
    }

    public static function rollbackNeedsRestore(): bool
    {
        // Значения у элементов удаляются каскадно — точечно не вернуть.
        return true;
    }

    public static function preview(array $args): array
    {
        $propertyId = (int)($args['property_id'] ?? 0);
        if ($propertyId <= 0) {
            throw new \InvalidArgumentException('Параметр property_id обязателен.');
        }

        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Модуль iblock недоступен.');
        }

        $property = PropertyTable::getList(array(
            'select' => array('ID', 'IBLOCK_ID', 'CODE', 'NAME'),
            'filter' => array('=ID' => $propertyId),
            'limit' => 1,
        ))->fetch();

        if (!$property) {
            throw new \InvalidArgumentException('Свойство #' . $propertyId . ' не найдено.');
        }

        $values = (int)\Bitrix\Main\Application::getConnection()->queryScalar(
            'SELECT COUNT(*) FROM b_iblock_element_property WHERE IBLOCK_PROPERTY_ID = ' . $propertyId
        );

        return array(
            'summary' => 'Удаление свойства ' . $property['CODE'],
            'payload' => array('args' => array('property_id' => $propertyId)),
            'preview' => 'Свойство «' . $property['NAME'] . '» (' . $property['CODE'] . ') будет удалено вместе с '
                . $values . ' значениями у элементов.',
        );
    }

    public static function apply(array $payload): array
    {
        $propertyId = (int)($payload['args']['property_id'] ?? 0);

        if (!\CIBlockProperty::Delete($propertyId)) {
            throw new \RuntimeException('Не удалось удалить свойство #' . $propertyId . '.');
        }

        return array('property_id' => $propertyId, 'deleted' => true);
    }

    public static function rollback(array $payload): void
    {
        // Восстанавливается через restore_site: см. rollbackNeedsRestore().
    }
}
