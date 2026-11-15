<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;
use Mibazarow\Bxadmins\Model\McpServerTable;

/**
 * MCP servers of the module: storage, tool discovery and calls.
 *
 * Tool schemas are never sent with every request. The prompt contains a server
 * catalog; the model gets names, schemas and results on demand through mcp_tool.
 * Sessions are reused between calls, tool lists refresh on TTL, and read-only /
 * destructive hints from the server are preserved.
 */
class McpService
{
    private const TOOLS_CACHE_TTL = 3600;
    private const MAX_TOOLS_PER_SERVER = 120;

    private static bool $schemaReady = false;
    /** @var array<int,array>|null */
    private static ?array $catalogCache = null;

    /** Adds session/health columns to older installations (idempotent). */
    public static function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }

        self::$schemaReady = true;

        try {
            $connection = Application::getConnection();
            $table = McpServerTable::getTableName();

            if (!$connection->isTableExists($table)) {
                return;
            }

            // SHOW COLUMNS avoids priming the connection column cache before ALTER.
            $fields = array();
            foreach ($connection->query("SHOW COLUMNS FROM {$table}")->fetchAll() as $field) {
                $fields[(string)$field['Field']] = true;
            }
            $columns = array(
                'SESSION_ID' => "VARCHAR(255) NULL",
                'SESSION_AT' => "DATETIME NULL",
                'PROTOCOL_VERSION' => "VARCHAR(32) NULL",
                'LAST_ERROR' => "VARCHAR(500) NULL",
                'LAST_CHECKED_AT' => "DATETIME NULL",
                'DISABLED_TOOLS' => "TEXT NULL",
            );

            foreach ($columns as $name => $definition) {
                if (!isset($fields[$name])) {
                    $connection->queryExecute("ALTER TABLE {$table} ADD COLUMN {$name} {$definition}");
                }
            }
        } catch (\Throwable $e) {
            Logger::error('MCP schema migration failed: ' . $e->getMessage());
        }
    }

    /** @return array<int,array> */
    public static function list(): array
    {
        self::ensureSchema();
        $rows = array();

        try {
            $items = McpServerTable::getList(array(
                'select' => array(
                    'ID', 'NAME', 'URL', 'TOKEN', 'ACTIVE', 'TOOLS', 'SESSION_ID', 'LAST_ERROR',
                    'LAST_CHECKED_AT', 'UPDATED_AT', 'DISABLED_TOOLS',
                ),
                'order' => array('ID' => 'ASC'),
            ))->fetchAll();
        } catch (\Throwable $e) {
            return array();
        }

        foreach ($items as $item) {
            $tools = self::decodeTools((string)($item['TOOLS'] ?? ''));
            $disabled = self::decodeNames((string)($item['DISABLED_TOOLS'] ?? ''));

            $rows[] = array(
                'id' => (int)$item['ID'],
                'name' => (string)$item['NAME'],
                'url' => (string)$item['URL'],
                'token' => (string)$item['TOKEN'] !== '',
                'active' => (string)$item['ACTIVE'] === 'Y',
                'tool_count' => count($tools),
                'enabled_tool_count' => count(array_diff(array_column($tools, 'name'), $disabled)),
                'status' => (string)($item['LAST_ERROR'] ?? '') !== '' ? 'error' : 'ok',
                'last_error' => (string)($item['LAST_ERROR'] ?? ''),
                'last_checked_at' => (string)($item['LAST_CHECKED_AT'] ?? ''),
                'session' => (string)($item['SESSION_ID'] ?? '') !== '',
                'updated_at' => (string)($item['UPDATED_AT'] ?? ''),
            );
        }

        return $rows;
    }

    public static function save(int $id, string $name, string $url, string $token, bool $active, array $disabledTools = array()): int
    {
        self::ensureSchema();

        $name = trim($name);
        $url = trim($url);

        if ($name === '') {
            throw new \InvalidArgumentException('Server name is required.');
        }

        if ($url === '' || !in_array(parse_url($url, PHP_URL_SCHEME), array('http', 'https'), true)) {
            throw new \InvalidArgumentException('URL must start with http:// or https://');
        }

        $fields = array(
            'NAME' => mb_substr($name, 0, 100),
            'URL' => mb_substr($url, 0, 500),
            'ACTIVE' => $active ? 'Y' : 'N',
            'UPDATED_AT' => new DateTime(),
            'SESSION_ID' => null,
            'SESSION_AT' => null,
            'PROTOCOL_VERSION' => null,
            'DISABLED_TOOLS' => $disabledTools !== array() ? Json::encode(array_values(array_unique($disabledTools))) : null,
        );

        if ($token !== '') {
            $fields['TOKEN'] = mb_substr($token, 0, 500);
        }

        if ($id > 0) {
            if (!McpServerTable::getById($id)->fetch()) {
                throw new \InvalidArgumentException('MCP server #' . $id . ' not found.');
            }

            $result = McpServerTable::update($id, $fields);
            if (!$result->isSuccess()) {
                throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
            }

            self::$catalogCache = null;

            return $id;
        }

        $fields['TOOLS'] = '[]';
        $fields['CREATED_AT'] = new DateTime();

        $result = McpServerTable::add($fields);
        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }

        self::$catalogCache = null;

        return (int)$result->getId();
    }

    public static function remove(int $id): void
    {
        self::ensureSchema();
        McpServerTable::delete($id);
        self::$catalogCache = null;
    }

    /**
     * Reloads the tool list from the server and stores it.
     *
     * @return array{count:int,tools:string[]}
     */
    public static function refreshTools(int $id): array
    {
        self::ensureSchema();

        $server = McpServerTable::getById($id)->fetch();
        if (!$server) {
            throw new \InvalidArgumentException('MCP server #' . $id . ' not found.');
        }

        $tools = self::request($server, static fn(McpClient $client): array => $client->listTools());
        $tools = array_slice($tools, 0, self::MAX_TOOLS_PER_SERVER);

        McpServerTable::update($id, array(
            'TOOLS' => Json::encode($tools),
            'UPDATED_AT' => new DateTime(),
            'LAST_ERROR' => null,
            'LAST_CHECKED_AT' => new DateTime(),
        ));
        self::$catalogCache = null;

        return array(
            'count' => count($tools),
            'tools' => array_column($tools, 'name'),
        );
    }

    /**
     * Refreshes every active server whose tool list is older than the TTL.
     *
     * @return array{refreshed:int,failed:int}
     */
    public static function refreshStale(): array
    {
        self::ensureSchema();

        $refreshed = 0;
        $failed = 0;

        foreach (self::activeServers() as $server) {
            $checked = (string)($server['LAST_CHECKED_AT'] ?? '');
            $hasError = (string)($server['LAST_ERROR'] ?? '') !== '';
            if (!$hasError && $checked !== '') {
                $ts = strtotime($checked);
                if ($ts !== false && time() - $ts < self::TOOLS_CACHE_TTL) {
                    continue;
                }
            }

            try {
                self::refreshTools((int)$server['ID']);
                $refreshed++;
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        return array('refreshed' => $refreshed, 'failed' => $failed);
    }

    /** Connection test without saving. */
    public static function test(string $url, string $token = ''): array
    {
        return self::client(array('URL' => $url, 'TOKEN' => $token, 'HEADERS' => ''))->test();
    }

    /**
     * Catalog of active servers and their tools, without JSON schemas.
     *
     * @return array<int,array>
     */
    public static function catalog(bool $includeDisabled = false): array
    {
        self::ensureSchema();

        if (!$includeDisabled && self::$catalogCache !== null) {
            return self::$catalogCache;
        }

        $rows = array();

        foreach (self::activeServers() as $server) {
            $disabled = self::decodeNames((string)($server['DISABLED_TOOLS'] ?? ''));
            $tools = array();

            foreach (self::decodeTools((string)($server['TOOLS'] ?? '')) as $tool) {
                $name = (string)($tool['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $isDisabled = in_array($name, $disabled, true);
                if ($isDisabled && !$includeDisabled) {
                    continue;
                }

                $annotations = is_array($tool['annotations'] ?? null) ? $tool['annotations'] : array();

                $tools[] = array(
                    'name' => $name,
                    'description' => mb_substr(trim((string)($tool['description'] ?? '')), 0, 300),
                    'read_only' => !empty($annotations['readOnlyHint']),
                    'destructive' => !empty($annotations['destructiveHint']),
                    'idempotent' => !empty($annotations['idempotentHint']),
                    'disabled' => $isDisabled,
                );
            }

            $rows[] = array(
                'id' => (int)$server['ID'],
                'name' => (string)$server['NAME'],
                'status' => (string)($server['LAST_ERROR'] ?? '') !== '' ? 'error' : 'ok',
                'last_error' => mb_substr((string)($server['LAST_ERROR'] ?? ''), 0, 300),
                'last_checked_at' => (string)($server['LAST_CHECKED_AT'] ?? ''),
                'tools' => $tools,
            );
        }

        if ($includeDisabled) {
            return $rows;
        }

        return self::$catalogCache = $rows;
    }

    /** Enables or disables one MCP tool for the agent. */
    public static function setToolDisabled(int $serverId, string $tool, bool $disabled): void
    {
        self::ensureSchema();

        $server = McpServerTable::getById($serverId)->fetch();
        if (!$server) {
            throw new \InvalidArgumentException('MCP server not found.');
        }

        $known = array_column(self::decodeTools((string)($server['TOOLS'] ?? '')), 'name');
        if (!in_array($tool, $known, true)) {
            throw new \InvalidArgumentException('MCP tool not found on this server.');
        }

        $names = self::decodeNames((string)($server['DISABLED_TOOLS'] ?? ''));
        $names = $disabled
            ? array_values(array_unique(array_merge($names, array($tool))))
            : array_values(array_diff($names, array($tool)));

        McpServerTable::update($serverId, array(
            'DISABLED_TOOLS' => $names !== array() ? Json::encode($names) : null,
            'UPDATED_AT' => new DateTime(),
        ));
        self::$catalogCache = null;
    }

    /** Active server by ID, full or partial name (case and leading # ignored). */
    public static function find(string $query): ?array
    {
        self::ensureSchema();

        $raw = trim($query);
        $normalized = ltrim($raw, '#');
        if ($normalized === '') {
            return null;
        }

        $byId = ctype_digit($normalized)
            ? (int)$normalized
            : (int)preg_replace('/^mcp/i', '', $normalized);

        foreach (self::activeServers() as $server) {
            if ($byId > 0 && (int)$server['ID'] === $byId) {
                return $server;
            }

            $name = trim((string)$server['NAME']);
            if (mb_strtolower($name) === mb_strtolower($normalized)) {
                return $server;
            }

            if (mb_stripos($name, $normalized) !== false) {
                return $server;
            }
        }

        return null;
    }

    /** Schema of one server tool — for the describe action. */
    public static function toolDefinition(array $server, string $tool): ?array
    {
        $needle = mb_strtolower(trim($tool));
        if ($needle === '') {
            return null;
        }

        foreach (self::decodeTools((string)($server['TOOLS'] ?? '')) as $item) {
            $name = (string)($item['name'] ?? '');
            if ($name === '' || mb_strtolower($name) !== $needle) {
                continue;
            }

            if (self::isDisabled($server, $name)) {
                return null;
            }

            $schema = is_array($item['inputSchema'] ?? null) ? $item['inputSchema'] : array();
            if (($schema['type'] ?? '') === '') {
                $schema['type'] = 'object';
            }
            if (!isset($schema['properties'])) {
                $schema['properties'] = new \stdClass();
            }

            $annotations = is_array($item['annotations'] ?? null) ? $item['annotations'] : array();

            return array(
                'name' => $name,
                'description' => mb_substr(trim((string)($item['description'] ?? '')), 0, 1000),
                'parameters' => $schema,
                'read_only' => !empty($annotations['readOnlyHint']),
                'destructive' => !empty($annotations['destructiveHint']),
                'idempotent' => !empty($annotations['idempotentHint']),
            );
        }

        return null;
    }

    /** Is the name one of our MCP tools (legacy prefix form)? */
    public static function owns(string $toolName): bool
    {
        return preg_match('/^mcp(\d+)_(.+)$/', $toolName, $matches) === 1;
    }

    /**
     * Legacy call by namespaced name from the old tool list.
     *
     * @return array
     */
    public static function execute(string $toolName, array $args): array
    {
        if (preg_match('/^mcp(\d+)_(.+)$/', $toolName, $matches) !== 1) {
            return array('error' => 'Unknown MCP tool: ' . $toolName);
        }

        return self::call((int)$matches[1], (string)$matches[2], $args);
    }

    /** Calls a tool by server ID and original name (without prefix). */
    public static function call(int $serverId, string $toolName, array $args): array
    {
        self::ensureSchema();

        $server = McpServerTable::getById($serverId)->fetch();
        if (!$server) {
            return array('error' => 'MCP server #' . $serverId . ' not found.');
        }

        if ((string)$server['ACTIVE'] !== 'Y') {
            return array('error' => 'MCP server "' . $server['NAME'] . '" is disabled.');
        }

        if (self::isDisabled($server, $toolName)) {
            return array('error' => 'MCP tool "' . $toolName . '" is disabled in settings.');
        }

        try {
            $result = self::request($server, static fn(McpClient $client): array => $client->callTool($toolName, $args));
        } catch (\Throwable $e) {
            return array('error' => 'MCP server "' . $server['NAME'] . '": ' . $e->getMessage());
        }

        if ($result['is_error']) {
            return array('error' => mb_substr((string)$result['content'], 0, 4000));
        }

        $decoded = json_decode((string)$result['content'], true);

        return is_array($decoded)
            ? $decoded
            : array('content' => mb_substr((string)$result['content'], 0, 20000));
    }

    /** MCP resources of a server. */
    public static function resources(array $server): array
    {
        return self::request($server, static fn(McpClient $client): array => $client->listResources());
    }

    public static function readResource(array $server, string $uri): array
    {
        return self::request($server, static fn(McpClient $client): array => $client->readResource($uri));
    }

    public static function prompts(array $server): array
    {
        return self::request($server, static fn(McpClient $client): array => $client->listPrompts());
    }

    public static function prompt(array $server, string $name, array $arguments = array()): array
    {
        return self::request($server, static fn(McpClient $client): array => $client->getPrompt($name, $arguments));
    }

    /** Tool name for the legacy OpenAI schema: mcp<ID>_<tool>. */
    public static function toolName(int $serverId, string $tool): string
    {
        $name = 'mcp' . $serverId . '_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $tool);

        return mb_substr($name, 0, 64);
    }

    /** @return array[] active servers with their tool lists */
    private static function activeServers(): array
    {
        try {
            return McpServerTable::getList(array(
                'select' => array(
                    'ID', 'NAME', 'URL', 'TOKEN', 'HEADERS', 'TOOLS', 'SESSION_ID', 'PROTOCOL_VERSION',
                    'LAST_ERROR', 'LAST_CHECKED_AT', 'DISABLED_TOOLS',
                ),
                'filter' => array('=ACTIVE' => 'Y'),
                'order' => array('ID' => 'ASC'),
            ))->fetchAll();
        } catch (\Throwable $e) {
            return array();
        }
    }

    /**
     * Runs an operation with a reusable MCP session.
     *
     * A stale session (HTTP 400/404) is dropped and retried once. Sessions are
     * persisted so separate PHP requests (worker calls) share them.
     */
    private static function request(array $server, callable $operation): array
    {
        $client = self::client($server);

        try {
            $result = $operation($client);
            self::persistSession($server, $client);

            return $result;
        } catch (\Throwable $e) {
            if ((string)($server['SESSION_ID'] ?? '') !== ''
                && preg_match('/HTTP (400|404)/', $e->getMessage()) === 1) {
                $client->resetSession();
                self::clearSession((int)$server['ID']);

                try {
                    $result = $operation($client);
                    self::persistSession($server, $client);

                    return $result;
                } catch (\Throwable $retryError) {
                    self::markError((int)$server['ID'], $retryError->getMessage());
                    throw $retryError;
                }
            }

            self::markError((int)$server['ID'], $e->getMessage());
            throw $e;
        }
    }

    private static function client(array $server): McpClient
    {
        $headers = array();

        $rawHeaders = (string)($server['HEADERS'] ?? '');
        if ($rawHeaders !== '') {
            $decoded = json_decode($rawHeaders, true);
            if (is_array($decoded)) {
                $headers = $decoded;
            }
        }

        return new McpClient(
            (string)($server['URL'] ?? ''),
            (string)($server['TOKEN'] ?? ''),
            $headers,
            (string)($server['SESSION_ID'] ?? '') !== '' ? (string)$server['SESSION_ID'] : null,
            (string)($server['PROTOCOL_VERSION'] ?? '')
        );
    }

    private static function persistSession(array $server, McpClient $client): void
    {
        $id = (int)$server['ID'];
        $session = (string)($client->sessionId() ?? '');

        try {
            McpServerTable::update($id, array(
                'SESSION_ID' => $session !== '' ? mb_substr($session, 0, 255) : null,
                'SESSION_AT' => new DateTime(),
                'PROTOCOL_VERSION' => mb_substr($client->protocolVersion(), 0, 32),
                'LAST_ERROR' => null,
            ));
            self::$catalogCache = null;
        } catch (\Throwable $e) {
            // Session reuse is an optimization; a failed write must not fail the call.
        }
    }

    private static function clearSession(int $serverId): void
    {
        try {
            McpServerTable::update($serverId, array(
                'SESSION_ID' => null,
                'SESSION_AT' => null,
                'PROTOCOL_VERSION' => null,
            ));
        } catch (\Throwable $e) {
            // ignored
        }
    }

    private static function markError(int $serverId, string $message): void
    {
        try {
            McpServerTable::update($serverId, array(
                'LAST_ERROR' => mb_substr(trim($message), 0, 500),
                'LAST_CHECKED_AT' => new DateTime(),
            ));
            self::$catalogCache = null;
        } catch (\Throwable $e) {
            // ignored
        }
    }

    private static function isDisabled(array $server, string $tool): bool
    {
        return in_array($tool, self::decodeNames((string)($server['DISABLED_TOOLS'] ?? '')), true);
    }

    /** @return string[] */
    private static function decodeNames(string $raw): array
    {
        if ($raw === '') {
            return array();
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : array();
    }

    /** @return array[] */
    private static function decodeTools(string $raw): array
    {
        if ($raw === '') {
            return array();
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : array();
    }
}
