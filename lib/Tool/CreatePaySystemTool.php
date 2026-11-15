<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;
use Bitrix\Sale\PaySystem\Manager;
use Bitrix\Sale\PaySystem\Manager as PaySystemManager;

/**
 * Новый способ оплаты на основе встроенного обработчика.
 *
 * Создаётся профиль (например, «Наличными при получении»), а не платёжный
 * шлюз: подключение эквайринга требует ключей мерчанта и делается вручную.
 */
class CreatePaySystemTool extends AbstractChangeTool
{
    public const DOMAIN = 'commerce';

    /** Встроенные обработчики, которые можно включить без ключей. */
    private const HANDLERS = array(
        'cash' => 'Наличными при получении',
        'collect' => 'Счёт на оплату',
        'bill' => 'Счёт на оплату',
        'inner' => 'Внутренний счёт',
    );

    public static function getName(): string
    {
        return 'create_pay_system';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'create_pay_system',
            'description' => 'Создаёт способ оплаты на встроенном обработчике (наличные, счёт, внутренний счёт). Эквайринг с ключами подключается вручную. Список — list_pay_systems.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'name' => array('type' => 'string', 'description' => 'Название для покупателя'),
                    'handler' => array('type' => 'string', 'description' => 'cash, collect или inner'),
                    'description' => array('type' => 'string', 'description' => 'Описание'),
                    'active' => array('type' => 'boolean', 'description' => 'Включить сразу, по умолчанию да'),
                ),
                'required' => array('name', 'handler'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $name = trim((string)($args['name'] ?? ''));
        $handler = strtolower(trim((string)($args['handler'] ?? '')));

        if ($name === '') {
            throw new \InvalidArgumentException('Нужно название способа оплаты.');
        }

        if (!isset(self::HANDLERS[$handler])) {
            throw new \InvalidArgumentException(
                'Обработчик «' . $handler . '» не поддерживается. Доступны: ' . implode(', ', array_keys(self::HANDLERS))
            );
        }

        if (!Loader::includeModule('sale')) {
            throw new \RuntimeException('Модуль sale не установлен.');
        }

        $exists = \Bitrix\Sale\Internals\PaySystemActionTable::getList(array(
            'select' => array('ID'),
            'filter' => array('=NAME' => $name),
            'limit' => 1,
        ))->fetch();

        if ($exists) {
            throw new \InvalidArgumentException('Способ оплаты «' . $name . '» уже есть.');
        }

        return array(
            'summary' => 'Способ оплаты «' . $name . '»',
            'payload' => array(
                'args' => array(
                    'name' => mb_substr($name, 0, 255),
                    'handler' => $handler,
                    'description' => mb_substr(trim((string)($args['description'] ?? '')), 0, 1000),
                    'active' => ($args['active'] ?? true) !== false,
                ),
            ),
            'preview' => 'Способ оплаты «' . $name . '» на обработчике «' . self::HANDLERS[$handler] . '». '
                . 'Эквайринг (карты, СБП) подключается отдельно в админке — для него нужны ключи мерчанта.',
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());
        $handler = (string)$args['handler'];

        $actionFile = self::actionFile($handler);
        if ($actionFile === '') {
            throw new \RuntimeException('Не найден обработчик оплаты «' . $handler . '» в этой установке.');
        }

        $result = PaySystemManager::add(array(
            'NAME' => (string)$args['name'],
            'DESCRIPTION' => (string)$args['description'],
            'ACTION_FILE' => $actionFile,
            'ACTIVE' => !empty($args['active']) ? 'Y' : 'N',
            'SORT' => 500,
            'PS_MODE' => '',
            'PS_CHANGE_STATUS_PAY' => 'N',
        ));

        if (!$result->isSuccess()) {
            throw new \RuntimeException(
                'Не удалось создать способ оплаты: ' . implode('; ', $result->getErrorMessages())
            );
        }

        return array('pay_system_id' => (int)$result->getId(), 'name' => (string)$args['name']);
    }

    public static function rollback(array $payload): void
    {
        $paySystemId = (int)($payload['result']['pay_system_id'] ?? 0);
        if ($paySystemId > 0) {
            PaySystemManager::delete($paySystemId);
        }
    }

    /**
     * Код обработчика из списка установки. getHandlerList() отдаёт
     * ['SYSTEM' => [код => подпись], 'USER' => [...]], поэтому ищем по ключу.
     */
    private static function actionFile(string $handler): string
    {
        $map = array(
            'cash' => 'cash',
            'collect' => 'bill',
            'bill' => 'bill',
            'inner' => 'inner',
        );

        $needle = $map[$handler] ?? $handler;
        $list = Manager::getHandlerList();

        foreach (array('SYSTEM', 'USER') as $group) {
            foreach (array_keys((array)($list[$group] ?? array())) as $code) {
                if ((string)$code === $needle) {
                    return (string)$code;
                }
            }
        }

        return '';
    }
}
