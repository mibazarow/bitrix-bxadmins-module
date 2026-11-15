<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\ORM\Data\DataManager;

/**
 * Поля ORM-сущности (DataManager).
 */
class GetEntityFieldsTool extends AbstractTool
{
    public const DOMAIN = 'core';

    private const LIMIT = 300;
    private const CLASS_PATTERN = '/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/';

    public static function getName(): string
    {
        return 'get_entity_fields';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'get_entity_fields',
            'description' => 'Поля ORM-сущности (DataManager) по имени класса: имя, тип, обязательность, первичный ключ, nullable, размер. Максимум 300 полей.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'entity' => array(
                        'type' => 'string',
                        'description' => 'Полное имя класса DataManager, например "Bitrix\\Iblock\\IblockTable".',
                    ),
                ),
                'required' => array('entity'),
            ),
        );
    }

    /** @return array<string,mixed>|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        $entity = trim((string)($args['entity'] ?? ''));

        if ($entity === '' || strlen($entity) > 200 || !preg_match(self::CLASS_PATTERN, $entity)) {
            return array('error' => 'Некорректный entity: нужно полное имя класса DataManager, например "Bitrix\\\\Iblock\\\\IblockTable".');
        }

        $entity = ltrim($entity, '\\');

        // Классы модулей ядра не автозагружаются, пока модуль не подключён:
        // Bitrix\Iblock\... => includeModule('iblock').
        if (preg_match('/^Bitrix\\\\([A-Za-z0-9_]+)\\\\/', $entity, $matches)) {
            $moduleId = strtolower($matches[1]);
            if ($moduleId !== 'main' && ModuleManager::isModuleInstalled($moduleId)) {
                Loader::includeModule($moduleId);
            }
        }

        if (!class_exists($entity)) {
            return array('error' => 'Класс "' . $entity . '" не найден.');
        }

        if (!is_subclass_of($entity, DataManager::class)) {
            return array('error' => 'Класс "' . $entity . '" не является наследником Bitrix\\Main\\ORM\\Data\\DataManager.');
        }

        try {
            $ormEntity = $entity::getEntity();
            $fields = array();
            $truncated = false;

            foreach ($ormEntity->getFields() as $name => $field) {
                if (count($fields) >= self::LIMIT) {
                    $truncated = true;
                    break;
                }

                $fields[] = array(
                    'name' => (string)$name,
                    'type' => method_exists($field, 'getDataType') ? (string)$field->getDataType() : '',
                    'required' => method_exists($field, 'isRequired') ? (bool)$field->isRequired() : false,
                    'primary' => method_exists($field, 'isPrimary') ? (bool)$field->isPrimary() : false,
                    'nullable' => method_exists($field, 'isNullable') ? (bool)$field->isNullable() : true,
                    'size' => method_exists($field, 'getSize') ? (int)$field->getSize() : null,
                );
            }

            return array(
                'entity' => $entity,
                'table' => (string)$entity::getTableName(),
                'count' => count($fields),
                'truncated' => $truncated,
                'fields' => $fields,
            );
        } catch (\Throwable $e) {
            return array('error' => 'Не удалось прочитать поля сущности "' . $entity . '".');
        }
    }
}
