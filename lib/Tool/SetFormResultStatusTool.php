<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;

/**
 * Смена статуса заявки веб-формы (обработана, отклонена и т.п.).
 */
class SetFormResultStatusTool extends AbstractChangeTool
{
    public const DOMAIN = 'content';

    public static function getName(): string
    {
        return 'set_form_result_status';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'set_form_result_status',
            'description' => 'Меняет статус заявки веб-формы. ID заявки и её текущий статус — в get_form_results; ID статуса смотри в настройках формы (Формы → Статусы).',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'result_id' => array('type' => 'integer', 'description' => 'ID заявки'),
                    'status_id' => array('type' => 'integer', 'description' => 'ID нового статуса'),
                ),
                'required' => array('result_id', 'status_id'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $resultId = (int)($args['result_id'] ?? 0);
        $statusId = (int)($args['status_id'] ?? 0);

        if ($resultId <= 0 || $statusId <= 0) {
            throw new \InvalidArgumentException('Нужны result_id и status_id.');
        }

        if (!Loader::includeModule('form')) {
            throw new \RuntimeException('Модуль form недоступен.');
        }

        $result = \CFormResult::GetByID($resultId)->Fetch();
        if (!$result) {
            throw new \InvalidArgumentException('Заявка #' . $resultId . ' не найдена.');
        }

        $before = (int)($result['STATUS_ID'] ?? 0);
        if ($before === $statusId) {
            throw new \InvalidArgumentException('У заявки уже этот статус.');
        }

        return array(
            'summary' => 'Статус заявки #' . $resultId . ': ' . $before . ' → ' . $statusId,
            'payload' => array(
                'args' => array('result_id' => $resultId, 'status_id' => $statusId),
                'before' => array('status_id' => $before),
            ),
            'preview' => 'Заявка #' . $resultId . ': статус ' . $before . ' → ' . $statusId . '.',
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());
        $resultId = (int)($args['result_id'] ?? 0);
        $statusId = (int)($args['status_id'] ?? 0);

        if (!Loader::includeModule('form')) {
            throw new \RuntimeException('Модуль form недоступен.');
        }

        if (!\CFormResult::SetStatus($resultId, $statusId, 'N')) {
            throw new \RuntimeException('Не удалось сменить статус заявки #' . $resultId . '.');
        }

        return array('result_id' => $resultId, 'status_id' => $statusId);
    }

    public static function rollback(array $payload): void
    {
        $resultId = (int)($payload['args']['result_id'] ?? 0);
        $before = (int)($payload['before']['status_id'] ?? 0);

        if ($resultId > 0 && $before > 0 && Loader::includeModule('form')) {
            \CFormResult::SetStatus($resultId, $before, 'N');
        }
    }
}
