<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\EventManager;
use Mibazarow\Bxadmins\Config;

/**
 * Регистрация обработчика события ядра. Код сохраняется классом в lib/Handler/,
 * регистрация — в b_module_to_module через EventManager::registerEventHandler.
 */
class AddEventHandlerTool extends AbstractChangeTool
{
    public const DOMAIN = 'events';

    public static function getName(): string
    {
        return 'add_event_handler';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'add_event_handler',
            'description' => 'Регистрирует обработчик события Битрикса. Код сохраняется в lib/Handler/<Class>.php модуля.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'module_id' => array('type' => 'string', 'description' => 'Модуль-источник события, например iblock'),
                    'event' => array('type' => 'string', 'description' => 'Имя события, например OnAfterIBlockElementAdd'),
                    'class_name' => array('type' => 'string', 'description' => 'Имя класса латиницей, например PriceSyncHandler'),
                    'code' => array('type' => 'string', 'description' => 'Тело метода handle(), например: return true;'),
                ),
                'required' => array('module_id', 'event', 'class_name', 'code'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $moduleId = trim((string)($args['module_id'] ?? ''));
        $event = trim((string)($args['event'] ?? ''));
        $className = self::normalizeClassName((string)($args['class_name'] ?? ''));
        $code = trim((string)($args['code'] ?? ''));

        if ($moduleId === '' || $event === '' || $code === '') {
            throw new \InvalidArgumentException('Нужны module_id, event и code.');
        }

        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $event)) {
            throw new \InvalidArgumentException('Имя события должно быть вида OnAfterIBlockElementAdd.');
        }

        if (str_contains($code, '<?php') || str_contains($code, '?>')) {
            throw new \InvalidArgumentException('В code передаётся только тело метода, без <?php и ?>.');
        }

        $path = self::handlerPath($className);
        if (is_file($_SERVER['DOCUMENT_ROOT'] . '/' . $path)) {
            throw new \InvalidArgumentException('Обработчик ' . $className . ' уже существует.');
        }

        return array(
            'summary' => 'Обработчик ' . $moduleId . '::' . $event . ' → ' . $className,
            'payload' => array(
                'args' => array(
                    'module_id' => $moduleId,
                    'event' => $event,
                    'class_name' => $className,
                    'path' => $path,
                    'code' => $code,
                ),
            ),
            'preview' => 'Будет создан ' . $path . " и зарегистрирован обработчик\n"
                . $moduleId . ' / ' . $event . "\n\n" . $code,
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());
        $path = (string)$args['path'];
        $className = (string)$args['class_name'];
        $absolute = $_SERVER['DOCUMENT_ROOT'] . '/' . $path;

        $content = "<?php\n\ndeclare(strict_types=1);\n\n"
            . "namespace Mibazarow\\Bxadmins\\Handler;\n\n"
            . "/** Обработчик " . $args['module_id'] . '::' . $args['event'] . ", создан агентом. */\n"
            . "class " . $className . "\n{\n"
            . "    public static function handle(): mixed\n    {\n"
            . self::indent((string)$args['code']) . "\n"
            . "    }\n}\n";

        // Каталог lib/Handler может ещё не существовать (первый обработчик).
        $dir = dirname($absolute);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать каталог обработчиков: ' . $dir);
        }

        if (@file_put_contents($absolute, $content) === false) {
            throw new \RuntimeException('Не удалось записать файл обработчика: ' . $path);
        }

        EventManager::getInstance()->registerEventHandler(
            (string)$args['module_id'],
            (string)$args['event'],
            Config::MODULE_ID,
            'Mibazarow\\Bxadmins\\Handler\\' . $className,
            'handle'
        );

        return array(
            'path' => $path,
            'class' => 'Mibazarow\\Bxadmins\\Handler\\' . $className,
            'module_id' => $args['module_id'],
            'event' => $args['event'],
        );
    }

    public static function rollback(array $payload): void
    {
        $args = (array)($payload['args'] ?? array());

        EventManager::getInstance()->unRegisterEventHandler(
            (string)($args['module_id'] ?? ''),
            (string)($args['event'] ?? ''),
            Config::MODULE_ID,
            'Mibazarow\\Bxadmins\\Handler\\' . (string)($args['class_name'] ?? ''),
            'handle'
        );

        $path = (string)($args['path'] ?? '');
        if ($path !== '') {
            $absolute = $_SERVER['DOCUMENT_ROOT'] . '/' . $path;
            if (is_file($absolute)) {
                @unlink($absolute);
            }
        }
    }

    private static function normalizeClassName(string $name): string
    {
        $name = str_replace(array('-', ' ', '_'), '', ucwords(trim($name), '-_ '));

        if ($name === '' || !preg_match('/^[A-Z][A-Za-z0-9]*$/', $name)) {
            throw new \InvalidArgumentException('class_name должен быть латиницей с заглавной буквы, без подчёркиваний.');
        }

        return $name;
    }

    private static function handlerPath(string $className): string
    {
        return 'local/modules/' . Config::MODULE_ID . '/lib/Handler/' . $className . '.php';
    }

    private static function indent(string $code): string
    {
        $lines = preg_split('/\R/', $code) ?: array();

        return implode("\n", array_map(
            static fn(string $line): string => $line === '' ? '' : '        ' . $line,
            $lines
        ));
    }
}
