<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\ChangeService;

/**
 * Общая обвязка изменяющих инструментов: preview → карточка подтверждения либо применение в режиме bypass.
 */
abstract class AbstractChangeTool extends AbstractTool implements ChangeToolInterface
{
    /**
     * По умолчанию дамп БД не нужен: инструмент обязан уметь откатить себя
     * через rollback(), а файлы восстанавливаются git-точкой. Разрушительные
     * операции включает политика Config::sqlDumpPolicy() (irreversible).
     */
    public static function needsSqlDump(): bool
    {
        return false;
    }

    public static function rollbackNeedsRestore(): bool
    {
        return false;
    }

    /**
     * Без bypass создаёт карточку подтверждения, с bypass применяет сразу (после дампа).
     * Контекст: user_id (обязателен), chat_id, bypass.
     */
    public static function execute(array $args, array $context = array()): array
    {
        $userId = (int)($context['user_id'] ?? 0);
        if ($userId <= 0) {
            return array('error' => 'Не удалось определить пользователя для изменения.');
        }

        $chatId = (int)($context['chat_id'] ?? 0);
        // Контекст передаём и в preview: инструментам нужен действующий пользователь
        // (в демоне нет сессии, CurrentUser пуст).
        $prepared = static::preview($args, $context);
        $tool = static::getName();
        $domain = static::getDomain();
        // Все изменения одной задачи образуют одну пачку: подтверждение одно.
        $taskId = (int)($context['task_id'] ?? 0);
        $batchId = $taskId > 0 ? 'task-' . $taskId : '';

        if (!empty($context['bypass'])) {
            $result = ChangeService::applyNow(
                $userId,
                $chatId,
                $domain,
                $tool,
                (string)$prepared['summary'],
                (array)$prepared['payload'],
                (string)$prepared['preview'],
                $batchId
            );

            if (empty($result['ok'])) {
                return array(
                    'executed' => false,
                    'change_id' => (int)($result['change_id'] ?? 0),
                    'error' => (string)($result['error'] ?? 'Изменение не выполнено.'),
                );
            }

            return array(
                'executed' => true,
                'change_id' => (int)$result['change_id'],
                'result' => $result['result'] ?? array(),
            );
        }

        $changeId = ChangeService::propose(
            $userId,
            $chatId,
            $domain,
            $tool,
            (string)$prepared['summary'],
            (array)$prepared['payload'],
            (string)$prepared['preview'],
            $batchId
        );

        return array(
            'requires_confirmation' => true,
            'change_id' => $changeId,
            'summary' => $prepared['summary'],
            'preview' => $prepared['preview'],
            // Прямая инструкция модели: карточка уже создана, повторный вызов не нужен.
            'next_step' => 'Изменение добавлено в пачку этой задачи (карточка #' . $changeId
                . '). Если в задаче есть ещё шаги — выполни их; это же изменение повторно не создавай. '
                . 'Все изменения одной задачи подтверждаются одной карточкой.',
        );
    }
}
