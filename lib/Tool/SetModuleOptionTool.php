<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Config\Option;
use Bitrix\Main\ModuleManager;

/**
 * Запись настройки модуля. Через это настраиваются модули сайта:
 * почта, SEO, каталог, магазин и прочее.
 */
class SetModuleOptionTool extends AbstractChangeTool
{
    public const DOMAIN = 'core';

    /** Модули, менять настройки которых нельзя: сломает сам харнес. */
    private const PROTECTED = array('mibazarow.bxadmins');

    public static function getName(): string
    {
        return 'set_module_option';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'set_module_option',
            'description' => 'Меняет настройку модуля (опцию из БД). Так настраиваются модули сайта: почта, SEO, каталог, магазин.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'module_id' => array('type' => 'string', 'description' => 'ID модуля, например main'),
                    'name' => array('type' => 'string', 'description' => 'Имя опции'),
                    'value' => array('type' => 'string', 'description' => 'Новое значение'),
                    'site_id' => array('type' => 'string', 'description' => 'Код сайта для сайтовых опций (необязательно)'),
                ),
                'required' => array('module_id', 'name', 'value'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $moduleId = trim((string)($args['module_id'] ?? ''));
        $name = trim((string)($args['name'] ?? ''));
        $value = (string)($args['value'] ?? '');
        $siteId = trim((string)($args['site_id'] ?? ''));

        if ($moduleId === '' || !ModuleManager::isModuleInstalled($moduleId)) {
            throw new \InvalidArgumentException('Модуль "' . $moduleId . '" не установлен.');
        }

        if (in_array($moduleId, self::PROTECTED, true)) {
            throw new \InvalidArgumentException('Настройки модуля BXADMIN-S меняются только через его страницу настроек.');
        }

        if ($name === '' || !preg_match('/^[A-Za-z0-9_.-]{1,100}$/', $name)) {
            throw new \InvalidArgumentException('Некорректное имя опции.');
        }

        // getRealValue возвращает null, если опции не было: откат должен её удалить,
        // а не записать пустое значение.
        $existing = Option::getRealValue($moduleId, $name, $siteId === '' ? false : $siteId);
        $before = (string)($existing ?? '');

        return array(
            'summary' => 'Настройка ' . $moduleId . '.' . $name,
            'payload' => array(
                'args' => array('module_id' => $moduleId, 'name' => $name, 'value' => $value, 'site_id' => $siteId),
                'before' => array('value' => $before, 'existed' => $existing !== null),
            ),
            'preview' => $moduleId . '.' . $name . ($siteId !== '' ? ' (сайт ' . $siteId . ')' : '') . ': '
                . var_export($before, true) . ' → ' . var_export($value, true),
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());

        Option::set(
            (string)$args['module_id'],
            (string)$args['name'],
            (string)$args['value'],
            (string)$args['site_id']
        );

        return array(
            'module_id' => $args['module_id'],
            'name' => $args['name'],
            'value' => $args['value'],
        );
    }

    public static function rollback(array $payload): void
    {
        $args = (array)($payload['args'] ?? array());
        $before = (array)($payload['before'] ?? array());

        $moduleId = (string)($args['module_id'] ?? '');
        $name = (string)($args['name'] ?? '');
        $siteId = (string)($args['site_id'] ?? '');

        if (empty($before['existed'])) {
            Option::delete($moduleId, array(
                'name' => $name,
                'site_id' => $siteId === '' ? false : $siteId,
            ));

            return;
        }

        Option::set($moduleId, $name, (string)($before['value'] ?? ''), $siteId);
    }
}
