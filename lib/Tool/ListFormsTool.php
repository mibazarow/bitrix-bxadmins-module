<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;

/** Веб-формы сайта. */
class ListFormsTool extends AbstractTool
{
    public const DOMAIN = 'content';

    public static function getName(): string
    {
        return 'list_forms';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_forms',
            'description' => 'Веб-формы сайта: ID, название, количество заявок. Результаты — get_form_results.',
            'parameters' => array('type' => 'object', 'properties' => new \stdClass()),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('form')) {
            return array('error' => 'Модуль form недоступен.');
        }

        $by = 's_id';
        $order = 'asc';
        $forms = array();

        // Легаси-исключение: список веб-форм отдаёт только CForm.
        $list = \CForm::GetList($by, $order, array(), array(), false);
        while ($form = $list->Fetch()) {
            $forms[] = array(
                'id' => (int)$form['ID'],
                'name' => (string)$form['NAME'],
                'active' => (string)$form['ACTIVE'],
                'results' => (int)\CFormResult::GetCount((int)$form['ID']),
            );
        }

        return array('forms' => $forms);
    }
}
