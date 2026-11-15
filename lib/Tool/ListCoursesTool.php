<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;

/** Учебные курсы. */
class ListCoursesTool extends AbstractTool
{
    public const DOMAIN = 'collaboration';

    public static function getName(): string
    {
        return 'list_courses';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_courses',
            'description' => 'Учебные курсы (модуль learning): ID, название, активность.',
            'parameters' => array('type' => 'object', 'properties' => new \stdClass()),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('learning')) {
            return array('error' => 'Модуль learning не установлен. Установите его: install_module с module_id=learning.');
        }

        $courses = array();

        // Сигнатура CCourse::GetList: (порядок, поля/фильтр, постранично).
        $list = \CCourse::GetList(
            array('SORT' => 'ASC'),
            array('ACTIVE' => 'Y'),
            array('nTopCount' => 100)
        );

        while ($row = $list->Fetch()) {
            $courses[] = array(
                'id' => (int)$row['ID'],
                'name' => (string)$row['NAME'],
                'active' => (string)$row['ACTIVE'] === 'Y',
                'sort' => (int)$row['SORT'],
            );
        }

        return array('count' => count($courses), 'courses' => $courses);
    }
}
