<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Agent;

use Bitrix\Main\Loader;
use Mibazarow\Bxadmins\Service\Logger;
use Mibazarow\Bxadmins\Service\McpService;

/**
 * Refreshes MCP tool catalogs whose cache is older than the TTL.
 * Keeps the prompt catalog and the on-demand tool list reasonably fresh.
 */
class McpRefreshAgent
{
    public const CODE = '\\Mibazarow\\Bxadmins\\Agent\\McpRefreshAgent::run();';

    /** Every 10 minutes; McpService decides which servers are stale. */
    public const INTERVAL = 600;

    public static function run(): string
    {
        try {
            if (!Loader::includeModule('mibazarow.bxadmins')) {
                return '';
            }

            McpService::refreshStale();
        } catch (\Throwable $e) {
            try {
                Logger::error('McpRefreshAgent: ' . $e->getMessage());
            } catch (\Throwable) {
            }
        }

        return self::CODE;
    }
}
