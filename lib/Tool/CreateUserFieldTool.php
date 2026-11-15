<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;

/**
 * Пользовательское поле (UF) для пользователей, разделов и инфоблоков.
 *
 * UF — расширение сущности без правки таблиц ядра: им пользуются для
 * дополнительных полей профиля, разделов каталога и элементов.
 */
class CreateUserFieldTool extends AbstractChangeTool
{
    public const DOMAIN = 'content';

    /** Куда можно добавить поле => префикс сущности. */
    private const TARGETS = array(
        'user' => 'USER',
        'section' => 'IBLOCK_%d_SECTION',
        'element' => 'IBLOCK_%d',
    );

    private const TYPES = array(
        'string', 'integer', 'double', 'boolean', 'date', 'datetime', 'text', 'enumeration', 'file', 'url',
    );

    public static function getName(): string
    {
        return 'create_user_field';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'create_user_field',
            'description' => 'Добавляет пользовательское поле (UF): в профиль пользователя, разделы или элементы инфоблока. Типы: string, integer, double, boolean, date, datetime, text, enumeration, file, url.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'target' => array('type' => 'string', 'description' => 'user, section или element'),
                    'iblock_id' => array('type' => 'integer', 'description' => 'ID инфоблока — обязателен для section и element'),
                    'code' => array('type' => 'string', 'description' => 'Код поля латиницей: UF_PHONE или PHONE'),
                    'label' => array('type' => 'string', 'description' => 'Подпись в форме'),
                    'type' => array('type' => 'string', 'description' => 'Тип поля, по умолчанию string'),
                    'multiple' => array('type' => 'boolean', 'description' => 'Множественное'),
                    'required' => array('type' => 'boolean', 'description' => 'Обязательное'),
                    'enum_values' => array(
                        'type' => 'array',
                        'items' => array('type' => 'string'),
                        'description' => 'Значения списка (для типа enumeration)',
                    ),
                ),
                'required' => array('target', 'code', 'label'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        if (!Loader::includeModule('main')) {
            throw new \RuntimeException('Модуль main недоступен.');
        }

        $target = strtolower(trim((string)($args['target'] ?? '')));
        $entityId = self::entityId($target, (int)($args['iblock_id'] ?? 0));

        $code = strtoupper(trim((string)($args['code'] ?? '')));
        if (!str_starts_with($code, 'UF_')) {
            $code = 'UF_' . $code;
        }

        if (preg_match('/^UF_[A-Z0-9_]{1,40}$/', $code) !== 1) {
            throw new \InvalidArgumentException('Код поля — латиница и цифры: UF_PHONE.');
        }

        $label = trim((string)($args['label'] ?? ''));
        if ($label === '') {
            throw new \InvalidArgumentException('Нужна подпись поля.');
        }

        $type = strtolower(trim((string)($args['type'] ?? 'string')));
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Тип: ' . implode(', ', self::TYPES) . '.');
        }

        $enumValues = array_values(array_filter(array_map(
            static fn ($v): string => trim((string)$v),
            (array)($args['enum_values'] ?? array())
        ), static fn (string $v): bool => $v !== ''));

        if ($type === 'enumeration' && $enumValues === array()) {
            throw new \InvalidArgumentException('Для типа enumeration нужны значения списка.');
        }

        // Дубль проверяем по сущности: код поля уникален в её пределах.
        $exists = \CUserTypeEntity::GetList(array(), array(
            'ENTITY_ID' => $entityId,
            'FIELD_NAME' => $code,
        ))->Fetch();

        if ($exists) {
            throw new \InvalidArgumentException('Поле ' . $code . ' у ' . $entityId . ' уже есть.');
        }

        return array(
            'summary' => 'Поле ' . $code . ' для ' . self::targetLabel($target),
            'payload' => array(
                'args' => array(
                    'entity_id' => $entityId,
                    'code' => $code,
                    'label' => mb_substr($label, 0, 100),
                    'type' => $type,
                    'multiple' => !empty($args['multiple']),
                    'required' => !empty($args['required']),
                    'enum_values' => $enumValues,
                ),
            ),
            'preview' => 'Новое поле «' . $label . '» (' . $code . ', ' . $type . ') для '
                . self::targetLabel($target) . '.',
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());

        $fieldId = (int)(new \CUserTypeEntity())->Add(array(
            'ENTITY_ID' => (string)$args['entity_id'],
            'FIELD_NAME' => (string)$args['code'],
            'USER_TYPE_ID' => (string)$args['type'],
            'XML_ID' => '',
            'SORT' => 500,
            'MULTIPLE' => !empty($args['multiple']) ? 'Y' : 'N',
            'MANDATORY' => !empty($args['required']) ? 'Y' : 'N',
            'SHOW_FILTER' => 'I',
            'SHOW_IN_LIST' => 'Y',
            'EDIT_IN_LIST' => 'Y',
            'IS_SEARCHABLE' => 'N',
            'EDIT_FORM_LABEL' => array('ru' => (string)$args['label']),
            'LIST_COLUMN_LABEL' => array('ru' => (string)$args['label']),
            'LIST_FILTER_LABEL' => array('ru' => (string)$args['label']),
        ));

        if ($fieldId <= 0) {
            throw new \RuntimeException('Не удалось создать поле.');
        }

        // Значения списка: без них поле типа enumeration бесполезно.
        foreach ((array)($args['enum_values'] ?? array()) as $index => $value) {
            (new \CUserFieldEnum())->SetEnumValues(array(
                $fieldId => array(
                    'n' . $index => array('VALUE' => (string)$value, 'SORT' => 100 * ($index + 1)),
                ),
            ));
        }

        return array('field_id' => $fieldId, 'code' => (string)$args['code']);
    }

    public static function rollback(array $payload): void
    {
        $fieldId = (int)($payload['result']['field_id'] ?? 0);
        if ($fieldId > 0) {
            (new \CUserTypeEntity())->Delete($fieldId);
        }
    }

    private static function entityId(string $target, int $iblockId): string
    {
        if ($target === 'user') {
            return 'USER';
        }

        if (!isset(self::TARGETS[$target])) {
            throw new \InvalidArgumentException('Куда добавлять: user, section или element.');
        }

        if ($iblockId <= 0) {
            throw new \InvalidArgumentException('Для section и element нужен iblock_id.');
        }

        return sprintf(self::TARGETS[$target], $iblockId);
    }

    private static function targetLabel(string $target): string
    {
        return match ($target) {
            'user' => 'профиля пользователя',
            'section' => 'разделов инфоблока',
            default => 'элементов инфоблока',
        };
    }
}
