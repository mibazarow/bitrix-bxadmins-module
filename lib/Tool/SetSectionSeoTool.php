<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Iblock\SectionTable;
use Bitrix\Main\Loader;

/**
 * SEO-поля раздела инфоблока: meta title, description, keywords и H1.
 * Пишутся в IPROPERTY_TEMPLATES раздела так же, как у элемента.
 */
class SetSectionSeoTool extends AbstractChangeTool
{
    public const DOMAIN = 'seo';

    private const KEYS = array(
        'title' => 'SECTION_META_TITLE',
        'description' => 'SECTION_META_DESCRIPTION',
        'keywords' => 'SECTION_META_KEYWORDS',
        'h1' => 'SECTION_PAGE_TITLE',
    );

    public static function getName(): string
    {
        return 'set_section_seo';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'set_section_seo',
            'description' => 'Задаёт SEO-поля раздела: meta title, description, keywords и заголовок H1.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'section_id' => array('type' => 'integer', 'description' => 'ID раздела инфоблока'),
                    'title' => array('type' => 'string', 'description' => 'Meta title'),
                    'description' => array('type' => 'string', 'description' => 'Meta description'),
                    'keywords' => array('type' => 'string', 'description' => 'Meta keywords'),
                    'h1' => array('type' => 'string', 'description' => 'Заголовок страницы (H1)'),
                ),
                'required' => array('section_id'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $sectionId = (int)($args['section_id'] ?? 0);
        if ($sectionId <= 0) {
            throw new \InvalidArgumentException('Параметр section_id обязателен.');
        }

        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Модуль iblock недоступен.');
        }

        $section = SectionTable::getList(array(
            'select' => array('ID', 'NAME', 'IBLOCK_ID'),
            'filter' => array('=ID' => $sectionId),
            'limit' => 1,
        ))->fetch();

        if (!$section) {
            throw new \InvalidArgumentException('Раздел #' . $sectionId . ' не найден.');
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

        $before = array();
        $iprop = new \Bitrix\Iblock\InheritedProperty\SectionTemplates((int)$section['IBLOCK_ID'], $sectionId);
        $current = (array)$iprop->findTemplates();

        foreach (array_keys($templates) as $key) {
            $raw = $current[$key] ?? '';
            // findTemplates() отдаёт строку {ID,CODE,TEMPLATE,...}, а не готовое значение.
            $before[$key] = is_array($raw) ? (string)($raw['TEMPLATE'] ?? '') : (string)$raw;
        }

        return array(
            'summary' => 'SEO раздела #' . $sectionId . ' «' . $section['NAME'] . '»',
            'payload' => array(
                'args' => array(
                    'section_id' => $sectionId,
                    'iblock_id' => (int)$section['IBLOCK_ID'],
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
        $sectionId = (int)($args['section_id'] ?? 0);

        // Легаси-исключение: IPROPERTY_TEMPLATES раздела обрабатывает CIBlockSection::Update.
        $section = new \CIBlockSection();
        $ok = $section->Update($sectionId, array('IPROPERTY_TEMPLATES' => (array)$args['templates']));

        if (!$ok) {
            throw new \RuntimeException(IblockElementFields::legacyError($section, 'Не удалось сохранить SEO-поля раздела.'));
        }

        return array('section_id' => $sectionId);
    }

    public static function rollback(array $payload): void
    {
        $sectionId = (int)($payload['args']['section_id'] ?? 0);
        $before = (array)($payload['before']['templates'] ?? array());

        if ($sectionId <= 0 || $before === array()) {
            return;
        }

        $section = new \CIBlockSection();
        if (!$section->Update($sectionId, array('IPROPERTY_TEMPLATES' => $before))) {
            throw new \RuntimeException(IblockElementFields::legacyError($section, 'Не удалось вернуть SEO-поля раздела.'));
        }
    }
}
