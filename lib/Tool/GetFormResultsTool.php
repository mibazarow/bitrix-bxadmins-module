<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;

/** Заявки веб-формы. */
class GetFormResultsTool extends AbstractTool
{
    public const DOMAIN = 'content';

    public static function getName(): string
    {
        return 'get_form_results';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'get_form_results',
            'description' => 'Заявки веб-формы: ID, дата, статус и значения полей. Формы — list_forms.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'form_id' => array('type' => 'integer', 'description' => 'ID формы'),
                    'limit' => array('type' => 'integer', 'description' => 'Сколько заявок вернуть, по умолчанию 20'),
                ),
                'required' => array('form_id'),
            ),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        $formId = (int)($args['form_id'] ?? 0);
        $limit = max(1, min(100, (int)($args['limit'] ?? 20)));

        if ($formId <= 0) {
            return array('error' => 'Параметр form_id обязателен.');
        }

        if (!Loader::includeModule('form')) {
            return array('error' => 'Модуль form недоступен.');
        }

        $by = 's_id';
        $order = 'desc';
        $results = array();

        $list = \CFormResult::GetList($formId, $by, $order, array(), false, false, array('limit' => $limit));
        while ($result = $list->Fetch()) {
            $resultId = (int)$result['ID'];
            $answers = array();

            foreach ((array)\CFormResult::GetDataByID($resultId, array(), $result, $fields) as $fieldId => $values) {
                foreach ((array)$values as $value) {
                    $answers[] = array(
                        'field_id' => (int)$fieldId,
                        'value' => mb_substr((string)($value['USER_TEXT'] ?? $value['VALUE'] ?? ''), 0, 500),
                    );
                }
            }

            $results[] = array(
                'id' => $resultId,
                'created_at' => (string)($result['DATE_CREATE'] ?? ''),
                'status_id' => (string)($result['STATUS_ID'] ?? ''),
                'answers' => $answers,
            );
        }

        return array('form_id' => $formId, 'results' => $results);
    }
}
