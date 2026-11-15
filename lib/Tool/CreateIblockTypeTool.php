<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Iblock\TypeTable;
use Bitrix\Main\Loader;

/** Создание типа инфоблоков. */
class CreateIblockTypeTool extends AbstractChangeTool
{
    public const DOMAIN = 'content';

    public static function getName(): string
    {
        return 'create_iblock_type';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'create_iblock_type',
            'description' => 'Создаёт тип инфоблоков (например catalog, news). Нужен до создания инфоблока нестандартного типа.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'id' => array('type' => 'string', 'description' => 'Код типа: латиница, цифры, подчёркивание'),
                    'name' => array('type' => 'string', 'description' => 'Название типа'),
                ),
                'required' => array('id', 'name'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $id = strtolower(trim((string)($args['id'] ?? '')));
        $name = IblockElementFields::name($args['name'] ?? null);

        if ($id === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $id)) {
            throw new \InvalidArgumentException('Код типа: строчная латиница, цифры и подчёркивание, начинается с буквы.');
        }

        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Модуль iblock недоступен.');
        }

        if (TypeTable::getById($id)->fetch()) {
            throw new \InvalidArgumentException('Тип инфоблоков ' . $id . ' уже существует.');
        }

        return array(
            'summary' => 'Тип инфоблоков ' . $id . ' («' . $name . '»)',
            'payload' => array('args' => array('id' => $id, 'name' => $name)),
            'preview' => 'Новый тип инфоблоков ' . $id . ' («' . $name . '»).'
                . ' Привязка к сайтам задаётся при создании инфоблока.',
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());

        // Легаси-исключение: тип инфоблока создаётся вместе с привязкой к сайтам и правами — CIBlockType.
        $type = new \CIBlockType();
        $ok = $type->Add(array(
            'ID' => (string)$args['id'],
            'SECTIONS' => 'Y',
            'IN_RSS' => 'N',
            'SORT' => 500,
            'LANG' => array(
                LANGUAGE_ID => array('NAME' => (string)$args['name']),
            ),
        ));

        if (!$ok) {
            throw new \RuntimeException(IblockElementFields::legacyError($type, 'Не удалось создать тип инфоблоков.'));
        }

        return array('type_id' => (string)$args['id'], 'name' => (string)$args['name']);
    }

    public static function rollback(array $payload): void
    {
        $id = (string)($payload['result']['type_id'] ?? '');
        if ($id === '') {
            return;
        }

        $type = new \CIBlockType();
        $type->Delete($id);
    }
}
