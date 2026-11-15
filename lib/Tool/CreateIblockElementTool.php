<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Iblock\ElementTable;
use Bitrix\Main\Loader;

/**
 * Создание элемента инфоблока.
 */
class CreateIblockElementTool extends AbstractChangeTool
{
    public const DOMAIN = 'content';

    public static function getName(): string
    {
        return 'create_iblock_element';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'create_iblock_element',
            'description' => 'СОЗДАНИЕ элемента инфоблока (запись). Без bypass возвращает requires_confirmation: изменение ещё не выполнено, пользователь должен подтвердить его карточкой в чате. С bypass выполняется сразу после SQL-дампа. Не утверждай, что элемент создан, пока результат не содержит executed=true.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'iblock_id' => array(
                        'type' => 'integer',
                        'description' => 'ID инфоблока (обязательно).',
                    ),
                    'name' => array(
                        'type' => 'string',
                        'description' => 'Название элемента (обязательно, до 255 символов).',
                    ),
                    'code' => array(
                        'type' => 'string',
                        'description' => 'Символьный код (до 255 символов). Если не указан — генерируется из названия.',
                    ),
                    'section_id' => array(
                        'type' => 'integer',
                        'description' => 'ID раздела, куда положить элемент (или массив ID).',
                    ),
                    'properties' => array(
                        'type' => 'object',
                        'description' => 'Значения свойств по коду: {"ARTNUMBER": "123", "COLOR_REF": "Красный"}.',
                    ),
                    'active' => array(
                        'type' => 'boolean',
                        'description' => 'Активность элемента. По умолчанию true.',
                    ),
                    'sort' => array(
                        'type' => 'integer',
                        'description' => 'Сортировка. По умолчанию 500.',
                    ),
                    'preview_text' => array(
                        'type' => 'string',
                        'description' => 'Текст анонса.',
                    ),
                    'detail_text' => array(
                        'type' => 'string',
                        'description' => 'Детальный текст.',
                    ),
                    'preview_text_type' => array(
                        'type' => 'string',
                        'enum' => array('text', 'html'),
                        'description' => 'Тип текста анонса: text или html. По умолчанию text.',
                    ),
                    'detail_text_type' => array(
                        'type' => 'string',
                        'enum' => array('text', 'html'),
                        'description' => 'Тип детального текста: text или html. По умолчанию text.',
                    ),
                ),
                'required' => array('iblock_id', 'name'),
            ),
        );
    }

    /** payload: args — поля для CIBlockElement::Add(). */
    public static function preview(array $args): array
    {
        $iblockId = (int)($args['iblock_id'] ?? 0);
        $iblock = IblockElementFields::loadIblock($iblockId);

        $name = IblockElementFields::name($args['name'] ?? null);

        $fields = array(
            'IBLOCK_ID' => $iblockId,
            'NAME' => $name,
        );
        $rows = array(
            array('Инфоблок', '#' . $iblock['id'] . ' ' . $iblock['name']),
            array('NAME', $name),
        );

        $code = IblockElementFields::code($args['code'] ?? '');
        if ($code === '') {
            // Без символьного кода элемент не создать там, где он обязателен, — генерируем из названия.
            $code = self::uniqueCode($iblockId, $name);
        }

        if ($code !== '') {
            $fields['CODE'] = $code;
            $rows[] = array('CODE', $code);
        }

        if (array_key_exists('section_id', $args)) {
            $sections = self::sections($args['section_id']);
            if ($sections !== array()) {
                $fields['IBLOCK_SECTION'] = $sections;
                $rows[] = array('Раздел', implode(', ', array_map('strval', $sections)));
            }
        }

        if (isset($args['properties']) && is_array($args['properties']) && $args['properties'] !== array()) {
            $prepared = IblockElementFields::prepareProperties($iblockId, $args['properties']);
            if ($prepared['values'] !== array()) {
                $fields['PROPERTY_VALUES'] = $prepared['values'];
                foreach ($prepared['lines'] as $line) {
                    $rows[] = array('Свойство', $line);
                }
            }
        }

        $active = array_key_exists('active', $args) ? IblockElementFields::active($args['active']) : 'Y';
        $fields['ACTIVE'] = $active;
        $rows[] = array('ACTIVE', $active);

        $sort = array_key_exists('sort', $args) ? IblockElementFields::sort($args['sort']) : 500;
        $fields['SORT'] = $sort;
        $rows[] = array('SORT', (string)$sort);

        if (array_key_exists('preview_text', $args)) {
            $fields['PREVIEW_TEXT'] = IblockElementFields::previewText($args['preview_text']);
            $fields['PREVIEW_TEXT_TYPE'] = array_key_exists('preview_text_type', $args)
                ? IblockElementFields::type($args['preview_text_type'])
                : 'text';
            $rows[] = array('PREVIEW_TEXT', $fields['PREVIEW_TEXT']);
            $rows[] = array('PREVIEW_TEXT_TYPE', $fields['PREVIEW_TEXT_TYPE']);
        }

        if (array_key_exists('detail_text', $args)) {
            $fields['DETAIL_TEXT'] = IblockElementFields::detailText($args['detail_text']);
            $fields['DETAIL_TEXT_TYPE'] = array_key_exists('detail_text_type', $args)
                ? IblockElementFields::type($args['detail_text_type'])
                : 'text';
            $rows[] = array('DETAIL_TEXT', $fields['DETAIL_TEXT']);
            $rows[] = array('DETAIL_TEXT_TYPE', $fields['DETAIL_TEXT_TYPE']);
        }

        return array(
            'summary' => 'Создать элемент «' . $name . '» в инфоблоке «' . $iblock['name'] . '» (#' . $iblock['id'] . ')',
            'payload' => array('args' => $fields, 'before' => array()),
            'preview' => IblockElementFields::formatPreview($rows),
        );
    }

    /** @return array{element_id:int,iblock_id:int,name:string} */
    public static function apply(array $payload): array
    {
        $fields = isset($payload['args']) && is_array($payload['args']) ? $payload['args'] : array();
        if ($fields === array()) {
            throw new \InvalidArgumentException('Нет полей для создания элемента.');
        }

        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Модуль iblock недоступен на этом сайте.');
        }

        // Легаси-исключение: D7 ElementTable::add() ядро намеренно блокирует
        // (ELEMENT_ENTITY_MESS_ADD_BLOCKED) и требует CIBlockElement::Add().
        // D7-аналога для штатной записи элемента инфоблока в этой версии нет.
        $element = new \CIBlockElement();
        $elementId = (int)$element->Add($fields);
        if ($elementId <= 0) {
            $error = IblockElementFields::legacyError($element, 'Не удалось создать элемент инфоблока.');

            // Подсказка, если инфоблок требует раздел: без неё модель не поймёт, чего не хватает.
            if (str_contains($error, 'Привязка к разделам')) {
                $error .= ' Укажите section_id — ID раздела инфоблока (список: list_iblock_sections).';
            }

            throw new \InvalidArgumentException($error);
        }

        return array(
            'element_id' => $elementId,
            'iblock_id' => (int)($fields['IBLOCK_ID'] ?? 0),
            'name' => (string)($fields['NAME'] ?? ''),
        );
    }

    /** Удаляет созданный элемент (rollback после apply). */
    public static function rollback(array $payload): void
    {
        $elementId = (int)($payload['result']['element_id'] ?? 0);
        if ($elementId <= 0) {
            throw new \RuntimeException('Не удалось определить ID созданного элемента для отката.');
        }

        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Модуль iblock недоступен на этом сайте.');
        }

        // Легаси-исключение: ElementTable::delete() заблокирован ядром; CIBlockElement::Delete()
        // дополнительно чистит свойства и поисковый индекс — D7-аналога нет.
        if (!\CIBlockElement::Delete($elementId)) {
            throw new \RuntimeException('Не удалось удалить созданный элемент #' . $elementId . '.');
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

    /** Символьный код из названия, свободный в этом инфоблоке. */
    private static function uniqueCode(int $iblockId, string $name): string
    {
        $base = (string)\CUtil::translit($name, 'ru', array('replace_space' => '-', 'replace_other' => '-'));
        $base = trim((string)preg_replace('/[^a-z0-9-]+/i', '-', mb_strtolower($base)), '-');

        if ($base === '') {
            $base = 'element';
        }

        $base = mb_substr($base, 0, 200);
        $code = $base;
        $suffix = 1;

        while (self::codeExists($iblockId, $code)) {
            $suffix++;
            $code = $base . '-' . $suffix;

            if ($suffix > 100) {
                $code = $base . '-' . substr(md5((string)microtime(true)), 0, 6);
                break;
            }
        }

        return $code;
    }

    private static function codeExists(int $iblockId, string $code): bool
    {
        $row = ElementTable::getList(array(
            'select' => array('ID'),
            'filter' => array('=IBLOCK_ID' => $iblockId, '=CODE' => $code),
            'limit' => 1,
        ))->fetch();

        return $row !== false && $row !== null;
    }
}
