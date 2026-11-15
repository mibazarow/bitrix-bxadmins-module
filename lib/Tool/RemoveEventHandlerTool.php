<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\EventManager;
use Mibazarow\Bxadmins\Config;

/** Снятие обработчика события, созданного агентом. */
class RemoveEventHandlerTool extends AbstractChangeTool
{
    public const DOMAIN = 'events';

    public static function getName(): string
    {
        return 'remove_event_handler';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'remove_event_handler',
            'description' => 'Снимает обработчик события модуля, созданный ранее через add_event_handler, и удаляет его класс.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'module_id' => array('type' => 'string', 'description' => 'Модуль-источник события, например iblock'),
                    'event' => array('type' => 'string', 'description' => 'Имя события, например OnAfterIBlockElementAdd'),
                    'class_name' => array('type' => 'string', 'description' => 'Имя класса обработчика, как при создании'),
                ),
                'required' => array('module_id', 'event', 'class_name'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $moduleId = trim((string)($args['module_id'] ?? ''));
        $event = trim((string)($args['event'] ?? ''));
        $className = trim((string)($args['class_name'] ?? ''));

        if ($moduleId === '' || $event === '' || $className === '') {
            throw new \InvalidArgumentException('Нужны module_id, event и class_name.');
        }

        if (!preg_match('/^[A-Z][A-Za-z0-9]*$/', $className)) {
            throw new \InvalidArgumentException('Некорректное имя класса обработчика.');
        }

        $path = 'local/modules/' . Config::MODULE_ID . '/lib/Handler/' . $className . '.php';
        $exists = is_file($_SERVER['DOCUMENT_ROOT'] . '/' . $path);

        if (!$exists) {
            throw new \InvalidArgumentException('Обработчик ' . $className . ' не найден.');
        }

        return array(
            'summary' => 'Снятие обработчика ' . $className,
            'payload' => array(
                'args' => array(
                    'module_id' => $moduleId,
                    'event' => $event,
                    'class_name' => $className,
                    'path' => $path,
                ),
            ),
            'preview' => 'Будет снят обработчик ' . $moduleId . '::' . $event . ' → ' . $className
                . ' и удалён файл ' . $path . '.',
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());

        EventManager::getInstance()->unRegisterEventHandler(
            (string)$args['module_id'],
            (string)$args['event'],
            Config::MODULE_ID,
            'Mibazarow\\Bxadmins\\Handler\\' . (string)$args['class_name'],
            'handle'
        );

        $absolute = $_SERVER['DOCUMENT_ROOT'] . '/' . (string)$args['path'];
        if (is_file($absolute)) {
            @unlink($absolute);
        }

        return array('removed' => true, 'class' => (string)$args['class_name']);
    }

    public static function rollback(array $payload): void
    {
        // Восстановление — из SQL-дампа и снапшота файла.
        throw new \RuntimeException('Снятый обработчик восстанавливается из SQL-дампа.');
    }
}
