<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Config\Option;
use Bitrix\Main\ModuleManager;

/** Настройки (опции) модуля. */
class GetModuleOptionsTool extends AbstractTool
{
    public const DOMAIN = 'core';

    private const SECRET_HINTS = array('key', 'secret', 'password', 'token', 'passwd');

    public static function getName(): string
    {
        return 'get_module_options';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'get_module_options',
            'description' => 'Возвращает настройки модуля (опции из БД). Секреты маскируются. Без параметра — список всех опций модуля.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'module_id' => array('type' => 'string', 'description' => 'ID модуля, например main или iblock'),
                    'name' => array('type' => 'string', 'description' => 'Имя опции; если не указано — все опции модуля'),
                ),
                'required' => array('module_id'),
            ),
        );
    }

    /** @return array{module_id:string,options:array}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        $moduleId = trim((string)($args['module_id'] ?? ''));
        if ($moduleId === '' || !ModuleManager::isModuleInstalled($moduleId)) {
            return array('error' => 'Модуль "' . $moduleId . '" не установлен.');
        }

        $name = trim((string)($args['name'] ?? ''));

        if ($name !== '') {
            return array(
                'module_id' => $moduleId,
                'options' => array($name => self::mask($name, Option::get($moduleId, $name, ''))),
            );
        }

        $connection = \Bitrix\Main\Application::getConnection();
        $helper = $connection->getSqlHelper();
        $rows = $connection->query(
            "SELECT NAME, VALUE FROM b_option WHERE MODULE_ID = '" . $helper->forSql($moduleId) . "'"
            . ' AND SITE_ID IS NULL ORDER BY NAME'
        )->fetchAll();

        $options = array();
        foreach ($rows as $row) {
            $options[(string)$row['NAME']] = self::mask((string)$row['NAME'], (string)$row['VALUE']);
        }

        return array('module_id' => $moduleId, 'count' => count($options), 'options' => $options);
    }

    private static function mask(string $name, string $value): string
    {
        $lower = strtolower($name);

        foreach (self::SECRET_HINTS as $hint) {
            if (str_contains($lower, $hint)) {
                return $value === '' ? '' : '***';
            }
        }

        return mb_substr($value, 0, 500);
    }
}
