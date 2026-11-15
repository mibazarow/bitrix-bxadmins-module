<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Iblock\ElementTable;
use Bitrix\Main\Loader;

/**
 * Изменение полей существующего элемента инфоблока.
 */
class UpdateIblockElementTool extends AbstractChangeTool
{
    public const DOMAIN = 'content';

    public static function getName(): string
    {
        return 'update_iblock_element';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'update_iblock_element',
            'description' => 'ИЗМЕНЕНИЕ полей существующего элемента инфоблока (запись). Без bypass возвращает requires_confirmation: изменение ещё не выполнено, пользователь должен подтвердить его карточкой в чате. С bypass выполняется сразу после SQL-дампа. Не утверждай, что элемент изменён, пока результат не содержит executed=true.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'element_id' => array(
                        'type' => 'integer',
                        'description' => 'ID элемента (обязательно).',
                    ),
                    'name' => array(
                        'type' => 'string',
                        'description' => 'Новое название (до 255 символов).',
                    ),
                    'code' => array(
                        'type' => 'string',
                        'description' => 'Новый символьный код (до 255 символов).',
                    ),
                    'section_id' => array(
                        'type' => 'integer',
                        'description' => 'ID раздела (или массив ID), куда переместить элемент. Заменяет текущие разделы.',
                    ),
                    'properties' => array(
                        'type' => 'object',
                        'description' => 'Новые значения свойств по коду: {"ARTNUMBER": "123"}. Множественное — массивом.',
                    ),
                    'active' => array(
                        'type' => 'boolean',
                        'description' => 'Активность элемента.',
                    ),
                    'sort' => array(
                        'type' => 'integer',
                        'description' => 'Сортировка.',
                    ),
                    'preview_text' => array(
                        'type' => 'string',
                        'description' => 'Новый текст анонса.',
                    ),
                    'detail_text' => array(
                        'type' => 'string',
                        'description' => 'Новый детальный текст.',
                    ),
                    'preview_text_type' => array(
                        'type' => 'string',
                        'enum' => array('text', 'html'),
                        'description' => 'Тип текста анонса: text или html.',
                    ),
                    'detail_text_type' => array(
                        'type' => 'string',
                        'enum' => array('text', 'html'),
                        'description' => 'Тип детального текста: text или html.',
                    ),
                ),
                'required' => array('element_id'),
            ),
        );
    }

    /** payload: args (element_id, fields) + before (element_id, fields) для отката. */
    public static function preview(array $args): array
    {
        $elementId = (int)($args['element_id'] ?? 0);
        if ($elementId <= 0) {
            throw new \InvalidArgumentException('Параметр element_id обязателен и должен быть положительным числом.');
        }

        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Модуль iblock недоступен на этом сайте.');
        }

        $element = ElementTable::getById($elementId)->fetch();
        if (!$element) {
            throw new \InvalidArgumentException('Элемент #' . $elementId . ' не найден.');
        }

        $iblock = IblockElementFields::loadIblock((int)$element['IBLOCK_ID']);

        $fields = array();
        $before = array();
        $rows = array();

        if (array_key_exists('name', $args)) {
            $old = (string)$element['NAME'];
            $new = IblockElementFields::name($args['name']);
            if ($new !== $old) {
                $fields['NAME'] = $new;
                $before['NAME'] = $old;
                $rows[] = array('NAME', $old . ' → ' . $new);
            }
        }

        if (array_key_exists('code', $args)) {
            $old = (string)($element['CODE'] ?? '');
            $new = IblockElementFields::code($args['code']);
            if ($new !== $old) {
                $fields['CODE'] = $new;
                $before['CODE'] = $old;
                $rows[] = array('CODE', $old . ' → ' . $new);
            }
        }

        if (array_key_exists('active', $args)) {
            $old = (string)$element['ACTIVE'];
            $new = IblockElementFields::active($args['active']);
            if ($new !== $old) {
                $fields['ACTIVE'] = $new;
                $before['ACTIVE'] = $old;
                $rows[] = array('ACTIVE', $old . ' → ' . $new);
            }
        }

        if (array_key_exists('sort', $args)) {
            $old = (int)$element['SORT'];
            $new = IblockElementFields::sort($args['sort']);
            if ($new !== $old) {
                $fields['SORT'] = $new;
                $before['SORT'] = $old;
                $rows[] = array('SORT', $old . ' → ' . $new);
            }
        }

        if (array_key_exists('section_id', $args)) {
            $newSections = self::sections($args['section_id']);
            sort($newSections);
            $oldSections = self::currentSections($elementId);

            if ($newSections !== $oldSections) {
                $fields['IBLOCK_SECTION'] = $newSections;
                $before['IBLOCK_SECTION'] = $oldSections;
                $rows[] = array('Разделы', implode(', ', array_map('strval', $oldSections)) . ' → ' . implode(', ', array_map('strval', $newSections)));
            }
        }

        $properties = isset($args['properties']) && is_array($args['properties']) ? $args['properties'] : array();
        $propertyValues = array();

        if ($properties !== array()) {
            $prepared = IblockElementFields::prepareProperties((int)$element['IBLOCK_ID'], $properties);
            $propertyValues = $prepared['values'];

            foreach ($prepared['lines'] as $line) {
                $rows[] = array('Свойство', $line);
            }
        }

        self::textPair(
            $element,
            $args,
            'preview_text',
            'PREVIEW_TEXT',
            'PREVIEW_TEXT_TYPE',
            $fields,
            $before,
            $rows
        );

        self::textPair(
            $element,
            $args,
            'detail_text',
            'DETAIL_TEXT',
            'DETAIL_TEXT_TYPE',
            $fields,
            $before,
            $rows
        );

        if ($fields === array() && $propertyValues === array()) {
            throw new \InvalidArgumentException('Изменения не обнаружены: переданные значения совпадают с текущими.');
        }

        return array(
            'summary' => 'Изменить элемент #' . $elementId . ' «' . (string)$element['NAME'] . '» в инфоблоке «' . $iblock['name'] . '» (#' . $iblock['id'] . ')',
            'payload' => array(
                'args' => array(
                    'element_id' => $elementId,
                    'fields' => $fields,
                    'properties' => $propertyValues,
                ),
                'before' => array(
                    'element_id' => $elementId,
                    'fields' => $before,
                    'properties' => $propertyValues !== array()
                        ? IblockElementFields::readPropertyValues($elementId, (int)$element['IBLOCK_ID'], array_keys($propertyValues))
                        : array(),
                    'iblock_id' => (int)$element['IBLOCK_ID'],
                ),
            ),
            'preview' => IblockElementFields::formatPreview($rows),
        );
    }

    /** @return array{element_id:int,updated:string[]} */
    public static function apply(array $payload): array
    {
        $elementId = (int)($payload['args']['element_id'] ?? 0);
        $fields = isset($payload['args']['fields']) && is_array($payload['args']['fields'])
            ? $payload['args']['fields']
            : array();
        $properties = isset($payload['args']['properties']) && is_array($payload['args']['properties'])
            ? $payload['args']['properties']
            : array();

        if ($elementId <= 0 || ($fields === array() && $properties === array())) {
            throw new \InvalidArgumentException('Нет данных для изменения элемента.');
        }

        if ($properties !== array()) {
            $fields['PROPERTY_VALUES'] = $properties;
        }

        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Модуль iblock недоступен на этом сайте.');
        }

        // Легаси-исключение: D7 ElementTable::update() ядро намеренно блокирует
        // (ELEMENT_ENTITY_MESS_UPDATE_BLOCKED) и требует CIBlockElement::Update().
        // D7-аналога для штатного обновления элемента инфоблока в этой версии нет.
        $element = new \CIBlockElement();
        if (!$element->Update($elementId, $fields)) {
            throw new \InvalidArgumentException(IblockElementFields::legacyError($element, 'Не удалось обновить элемент инфоблока.'));
        }

        return array(
            'element_id' => $elementId,
            'updated' => array_keys($fields),
        );
    }

    /** Возвращает прежние значения полей из payload['before']. */
    public static function rollback(array $payload): void
    {
        $elementId = (int)($payload['before']['element_id'] ?? 0);
        $fields = isset($payload['before']['fields']) && is_array($payload['before']['fields'])
            ? $payload['before']['fields']
            : array();
        $properties = isset($payload['before']['properties']) && is_array($payload['before']['properties'])
            ? $payload['before']['properties']
            : array();

        if ($elementId <= 0 || ($fields === array() && $properties === array())) {
            throw new \RuntimeException('Нет сохранённых значений для отката элемента.');
        }

        if ($properties !== array()) {
            $element = new \CIBlockElement();
            $element->SetPropertyValuesEx($elementId, (int)($payload['before']['iblock_id'] ?? 0), $properties);
        }

        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Модуль iblock недоступен на этом сайте.');
        }

        // Легаси-исключение: см. комментарий в apply() — только CIBlockElement::Update().
        $element = new \CIBlockElement();
        if (!$element->Update($elementId, $fields)) {
            throw new \InvalidArgumentException(IblockElementFields::legacyError($element, 'Не удалось обновить элемент инфоблока.'));
        }
    }

    private static function textPair(
        array $element,
        array $args,
        string $textArg,
        string $textField,
        string $typeField,
        array &$fields,
        array &$before,
        array &$rows
    ): void {
        if (!array_key_exists($textArg, $args) && !array_key_exists($textArg . '_type', $args)) {
            return;
        }

        $oldText = (string)($element[$textField] ?? '');
        $oldType = (string)($element[$typeField] ?? '') !== '' ? (string)$element[$typeField] : 'text';

        $newText = array_key_exists($textArg, $args)
            ? ($textArg === 'preview_text'
                ? IblockElementFields::previewText($args[$textArg])
                : IblockElementFields::detailText($args[$textArg]))
            : $oldText;

        $newType = array_key_exists($textArg . '_type', $args)
            ? IblockElementFields::type($args[$textArg . '_type'])
            : $oldType;

        if ($newText !== $oldText) {
            $fields[$textField] = $newText;
            $before[$textField] = $oldText;
            $rows[] = array($textField, $oldText . ' → ' . $newText);
        }

        if ($newType !== $oldType) {
            $fields[$typeField] = $newType;
            $before[$typeField] = $oldType;
            $rows[] = array($typeField, $oldType . ' → ' . $newType);
        }
    }

    /** @return int[] */
    private static function sections($value): array
    {
        $items = is_array($value) ? $value : array($value);
        $ids = array();

        foreach ($items as $item) {
            $id = (int)$item;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return int[] */
    private static function currentSections(int $elementId): array
    {
        $ids = array();

        // Легаси-исключение: привязка элемента к разделам — только CIBlockElement.
        $groups = \CIBlockElement::GetElementGroups($elementId, true, array('ID'));

        while ($group = $groups->Fetch()) {
            $ids[] = (int)$group['ID'];
        }

        sort($ids);

        return $ids;
    }
}
