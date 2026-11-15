<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Agent;

use Bitrix\Main\Loader;
use Mibazarow\Bxadmins\Service\IndexService;
use Mibazarow\Bxadmins\Service\Logger;

/**
 * Агент cron, который продвигает фоновую индексацию сайта батчами.
 *
 * Постоянный: при первом запуске делает полную индексацию (по 20 документов за раз,
 * раз в минуту), после неё переходит на инкрементальный режим — раз в 15 минут
 * обновляет только изменившееся. Пустую строку возвращает только при удалённом
 * модуле: для ядра это означает «снять агента с учёта».
 */
class IndexAgent
{
    private const BATCH_SIZE = 20;

    public static function run(): string
    {
        try {
            if (!Loader::includeModule('mibazarow.bxadmins')) {
                return '';
            }
        } catch (\Throwable $e) {
            return '';
        }

        try {
            IndexService::runBatch(self::BATCH_SIZE);
        } catch (\Throwable $e) {
            // Транзиентные ошибки не должны глушить индексацию: пишем в журнал
            // и оставляем агент живым.
            try {
                Logger::error('IndexAgent: ' . $e->getMessage());
            } catch (\Throwable $ignored) {
                // Журнал недоступен — молчим, агент важнее.
            }
        }

        return IndexService::AGENT_CODE;
    }
}
