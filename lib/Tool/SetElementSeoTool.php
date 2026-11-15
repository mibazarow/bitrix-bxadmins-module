<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Iblock\ElementTable;
use Bitrix\Main\Loader;

/**
 * SEO-поля элемента инфоблока: meta title, description, keywords.
 * Пишутся в штатные IPROPERTY_TEMPLATES элемента.
 */
class SetElementSeoTool extends AbstractChangeTool
{
    public const DOMAIN = 'seo';

    private const KEYS = array(
        'title' => 'ELEMENT_META_TITLE',
        'description' => 'ELEMENT_META_DESCRIPTION',
        'keywords' => 'ELEMENT_META_KEYWORDS',
        'h1' => 'ELEMENT_PAGE_TITLE',
    );

    public static function getName(): string
    {
        return 'set_element_seo';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'set_element_seo',
            'description' => 'Задаёт SEO-поля элемента: meta title, description, keywords и заголовок H1.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'element_id' => array('type' => 'integer', 'description' => 'ID элемента инфоблока'),
                    'title' => array('type' => 'string', 'description' => 'Meta title'),
                    'description' => array('type' => 'string', 'description' => 'Meta description'),
                    'keywords' => array('type' => 'string', 'description' => 'Meta keywords'),
                    'h1' => array('type' => 'string', 'description' => 'Заголовок страницы (H1)'),
                ),
                'required' => array('element_id'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $elementId = (int)($args['element_id'] ?? 0);
        if ($elementId <= 0) {
            throw new \InvalidArgumentException('Параметр element_id обязателен.');
        }

        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Модуль iblock недоступен.');
        }

        $element = ElementTable::getList(array(
            'select' => array('ID', 'NAME', 'IBLOCK_ID'),
            'filter' => array('=ID' => $elementId),
            'limit' => 1,
        ))->fetch();

        if (!$element) {
            throw new \InvalidArgumentException('Элемент #' . $elementId . ' не найден.');
        }

        $templates = array();
        $lines = array();

        foreach (self::KEYS as $arg => $template) {
            if (!array_key_exists($arg, $args)) {
                continue;
            }

            $value = trim((string)$args[$arg]);
            $templates[$template] = $value;
            $lines[] = $template . ': ' . ($value === '' ? '(очистить)' : $value);
        }

        if ($templates === array()) {
            throw new \InvalidArgumentException('Не передано ни одного SEO-поля.');
        }

        // Текущие значения — для честного отката.
        $before = array();
        $iprop = new \Bitrix\Iblock\InheritedProperty\ElementTemplates((int)$element['IBLOCK_ID'], $elementId);
        $current = (array)$iprop->findTemplates();

        foreach (array_keys($templates) as $key) {
            $raw = $current[$key] ?? '';
            // findTemplates() отдаёт строку {ID,CODE,TEMPLATE,...}, а не готовое значение.
            $before[$key] = is_array($raw) ? (string)($raw['TEMPLATE'] ?? '') : (string)$raw;
        }

        return array(
            'summary' => 'SEO элемента #' . $elementId . ' «' . $element['NAME'] . '»',
            'payload' => array(
                'args' => array(
                    'element_id' => $elementId,
                    'iblock_id' => (int)$element['IBLOCK_ID'],
                    'templates' => $templates,
                ),
                'before' => array('templates' => $before),
            ),
            'preview' => implode("\n", $lines),
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());
        $elementId = (int)($args['element_id'] ?? 0);

        // Штатный путь: IPROPERTY_TEMPLATES обрабатывается самим CIBlockElement::Update.
        $element = new \CIBlockElement();
        $ok = $element->Update($elementId, array('IPROPERTY_TEMPLATES' => (array)$args['templates']));

        if (!$ok) {
            throw new \RuntimeException(IblockElementFields::legacyError($element, 'Не удалось сохранить SEO-поля.'));
        }

        return array('element_id' => $elementId, 'updated' => array_keys((array)$args['templates']));
    }

    public static function rollback(array $payload): void
    {
        $elementId = (int)($payload['args']['element_id'] ?? 0);
        $before = (array)($payload['before']['templates'] ?? array());

        if ($elementId <= 0 || $before === array()) {
            return;
        }

        $element = new \CIBlockElement();
        $ok = $element->Update($elementId, array('IPROPERTY_TEMPLATES' => $before));

        if (!$ok) {
            throw new \RuntimeException(IblockElementFields::legacyError($element, 'Не удалось вернуть прежние SEO-поля.'));
        }
    }
}
