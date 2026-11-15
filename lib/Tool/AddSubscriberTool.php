<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;

/** Подписка адреса на рассылку. */
class AddSubscriberTool extends AbstractChangeTool
{
    public const DOMAIN = 'marketing';

    public static function getName(): string
    {
        return 'add_subscriber';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'add_subscriber',
            'description' => 'Подписывает адрес на рассылку. Подтверждение письмом отключается, чтобы адрес сразу стал активным. Рубрики — list_subscribe_rubrics.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'email' => array('type' => 'string', 'description' => 'Адрес подписчика'),
                    'rubric_ids' => array(
                        'type' => 'array',
                        'items' => array('type' => 'integer'),
                        'description' => 'ID рубрик; пусто — все активные',
                    ),
                ),
                'required' => array('email'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $email = trim((string)($args['email'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Некорректный e-mail.');
        }

        if (!Loader::includeModule('subscribe')) {
            throw new \RuntimeException('Модуль subscribe не установлен.');
        }

        $rubricIds = array_values(array_unique(array_map('intval', (array)($args['rubric_ids'] ?? array()))));

        if ($rubricIds === array()) {
            $list = \CRubric::GetList(array('SORT' => 'ASC'), array('ACTIVE' => 'Y'));
            while ($row = $list->Fetch()) {
                $rubricIds[] = (int)$row['ID'];
            }
        }

        if ($rubricIds === array()) {
            throw new \InvalidArgumentException('Нет активных рубрик — сначала создайте рубрику.');
        }

        return array(
            'summary' => 'Подписка ' . $email,
            'payload' => array(
                'args' => array('email' => $email, 'rubric_ids' => $rubricIds),
            ),
            'preview' => 'Адрес ' . $email . ' будет подписан на рубрики: ' . implode(', ', $rubricIds)
                . '. Подтверждение письмом не запрашивается.',
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());
        $email = (string)$args['email'];
        $rubricIds = (array)$args['rubric_ids'];

        $subscription = new \CSubscription();
        $id = (int)$subscription->Add(array(
            'USER_ID' => (int)(\Bitrix\Main\Engine\CurrentUser::get()->getId() ?: 0),
            'EMAIL' => $email,
            'ACTIVE' => 'Y',
            'CONFIRMED' => 'Y',
            'SEND_CONFIRM' => 'N',
            'RUB_ID' => $rubricIds,
            'FORMAT' => 'text',
        ));

        if ($id <= 0) {
            throw new \RuntimeException(
                'Не удалось подписать адрес: ' . (strip_tags((string)$subscription->LAST_ERROR) ?: 'неизвестная ошибка')
            );
        }

        return array('subscription_id' => $id, 'email' => $email, 'rubric_ids' => $rubricIds);
    }

    public static function rollback(array $payload): void
    {
        $id = (int)($payload['result']['subscription_id'] ?? 0);
        if ($id > 0) {
            \CSubscription::Delete($id);
        }
    }
}
