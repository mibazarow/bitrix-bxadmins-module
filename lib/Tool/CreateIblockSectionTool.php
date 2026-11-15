<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Iblock\SectionTable;
use Bitrix\Main\Loader;

/** Создание раздела инфоблока. */
class CreateIblockSectionTool extends AbstractChangeTool
{
    public const DOMAIN = 'content';

    public static function getName(): string
    {
        return 'create_iblock_section';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'create_iblock_section',
            'description' => 'Создаёт раздел инфоблока. Требует подтверждения пользователя (кроме bypass).',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'iblock_id' => array('type' => 'integer', 'description' => 'ID инфоблока'),
                    'name' => array('type' => 'string', 'description' => 'Название раздела'),
                    'code' => array('type' => 'string', 'description' => 'Символьный код; если не указан — генерируется из названия'),
                    'parent_id' => array('type' => 'integer', 'description' => 'ID родительского раздела'),
                    'active' => array('type' => 'boolean', 'description' => 'Активен (по умолчанию да)'),
                    'sort' => array('type' => 'integer', 'description' => 'Сортировка'),
                ),
                'required' => array('iblock_id', 'name'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $iblock = IblockElementFields::loadIblock((int)($args['iblock_id'] ?? 0));
        $name = IblockElementFields::name($args['name'] ?? null);

        $code = IblockElementFields::code($args['code'] ?? '');
        if ($code === '') {
            // Без символьного кода раздел не создать там, где он обязателен.
            $code = self::uniqueCode($iblock['id'], $name);
        }

        $payload = array(
            'iblock_id' => $iblock['id'],
            'name' => $name,
            'code' => $code,
            'parent_id' => (int)($args['parent_id'] ?? 0),
            'active' => IblockElementFields::active($args['active'] ?? true),
            'sort' => IblockElementFields::sort($args['sort'] ?? 500),
        );

        $preview = 'Создать раздел «' . $name . '» в инфоблоке «' . $iblock['name'] . '»'
            . ($payload['parent_id'] > 0 ? ' (родитель #' . $payload['parent_id'] . ')' : ' (верхний уровень)');

        return array(
            'summary' => 'Создание раздела «' . $name . '»',
            'payload' => array('args' => $payload),
            'preview' => $preview,
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());

        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Модуль iblock недоступен.');
        }

        // Легаси-исключение: дерево разделов (LEFT_MARGIN/RIGHT_MARGIN) поддерживает только CIBlockSection.
        $section = new \CIBlockSection();
        $id = $section->Add(array(
            'IBLOCK_ID' => (int)$args['iblock_id'],
            'NAME' => (string)$args['name'],
            'CODE' => (string)$args['code'],
            'IBLOCK_SECTION_ID' => (int)$args['parent_id'] > 0 ? (int)$args['parent_id'] : false,
            'ACTIVE' => (string)$args['active'],
            'SORT' => (int)$args['sort'],
        ));

        if (!$id) {
            throw new \RuntimeException(IblockElementFields::legacyError($section, 'Не удалось создать раздел.'));
        }

        return array('section_id' => (int)$id, 'iblock_id' => (int)$args['iblock_id'], 'name' => (string)$args['name']);
    }

    public static function rollback(array $payload): void
    {
        $sectionId = (int)($payload['result']['section_id'] ?? 0);
        if ($sectionId <= 0) {
            return;
        }

        // Легаси-исключение: удаление раздела вместе с деревом — только CIBlockSection.
        $section = new \CIBlockSection();
        if (!$section->Delete($sectionId)) {
            throw new \RuntimeException(IblockElementFields::legacyError($section, 'Не удалось удалить раздел при откате.'));
        }
    }

    /** Символьный код из названия, свободный в этом инфоблоке. */
    private static function uniqueCode(int $iblockId, string $name): string
    {
        $base = (string)\CUtil::translit($name, 'ru', array('replace_space' => '-', 'replace_other' => '-'));
        $base = trim((string)preg_replace('/[^a-z0-9-]+/i', '-', mb_strtolower($base)), '-');

        if ($base === '') {
            $base = 'section';
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
        $row = SectionTable::getList(array(
            'select' => array('ID'),
            'filter' => array('=IBLOCK_ID' => $iblockId, '=CODE' => $code),
            'limit' => 1,
        ))->fetch();

        return $row !== false && $row !== null;
    }
}
