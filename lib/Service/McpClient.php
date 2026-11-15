<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;

/**
 * Клиент MCP (Model Context Protocol) поверх Streamable HTTP.
 *
 * Работает с любым MCP-сервером: локальным (127.0.0.1) или внешним по HTTPS.
 * Поддерживает оба формата ответа — обычный JSON и text/event-stream.
 */
class McpClient
{
    private const PROTOCOL_VERSION = '2025-03-26';
    private const TIMEOUT = 60;
    private const MAX_BODY = 4 * 1024 * 1024;

    private string $url;
    private string $token;
    private array $headers;
    private int $nextId = 0;
    private bool $initialized = false;
    private ?string $sessionId = null;
    private string $protocolVersion = self::PROTOCOL_VERSION;
    private string $serverName = '';

    public function __construct(
        string $url,
        string $token = '',
        array $headers = array(),
        ?string $sessionId = null,
        string $protocolVersion = ''
    ) {
        $this->url = trim($url);
        $this->token = trim($token);
        $this->headers = $headers;

        if ($sessionId !== null && trim($sessionId) !== '') {
            $this->sessionId = trim($sessionId);
            $this->initialized = true;

            if (trim($protocolVersion) !== '') {
                $this->protocolVersion = trim($protocolVersion);
            }
        }
    }

    public function serverName(): string
    {
        return $this->serverName;
    }

    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    public function protocolVersion(): string
    {
        return $this->protocolVersion;
    }

    /** Drops a stale session so the next call performs a fresh initialize. */
    public function resetSession(): void
    {
        $this->sessionId = null;
        $this->initialized = false;
        $this->protocolVersion = self::PROTOCOL_VERSION;
    }

    /**
     * Список инструментов сервера.
     *
     * @return array<int,array{name:string,description:string,inputSchema:array}>
     * @throws \RuntimeException
     */
    public function listTools(): array
    {
        $this->ensureInitialized();

        $result = $this->rpc('tools/list', array());
        $tools = array();

        foreach ((array)($result['tools'] ?? array()) as $tool) {
            if (!is_array($tool) || !isset($tool['name'])) {
                continue;
            }

            $schema = $tool['inputSchema'] ?? $tool['input_schema'] ?? array();

            $tools[] = array(
                'name' => (string)$tool['name'],
                'description' => (string)($tool['description'] ?? ''),
                'inputSchema' => is_array($schema) ? $schema : array('type' => 'object', 'properties' => new \stdClass()),
                'annotations' => is_array($tool['annotations'] ?? null) ? $tool['annotations'] : array(),
            );
        }

        return $tools;
    }

    /**
     * Resources exposed by the server.
     *
     * @return array<int,array>
     * @throws \RuntimeException
     */
    public function listResources(): array
    {
        $this->ensureInitialized();
        $result = $this->rpc('resources/list', array());

        return is_array($result['resources'] ?? null) ? $result['resources'] : array();
    }

    /**
     * Reads a resource by URI.
     *
     * @return array
     * @throws \RuntimeException
     */
    public function readResource(string $uri): array
    {
        $this->ensureInitialized();

        return $this->rpc('resources/read', array('uri' => $uri));
    }

    /**
     * Prompts exposed by the server.
     *
     * @return array<int,array>
     * @throws \RuntimeException
     */
    public function listPrompts(): array
    {
        $this->ensureInitialized();
        $result = $this->rpc('prompts/list', array());

        return is_array($result['prompts'] ?? null) ? $result['prompts'] : array();
    }

    /**
     * Renders a server-side prompt.
     *
     * @return array
     * @throws \RuntimeException
     */
    public function getPrompt(string $name, array $arguments = array()): array
    {
        $this->ensureInitialized();

        return $this->rpc('prompts/get', array(
            'name' => $name,
            'arguments' => $arguments === array() ? new \stdClass() : $arguments,
        ));
    }

    /**
     * Вызов инструмента MCP.
     *
     * @return array{content:string,is_error:bool,raw:array}
     * @throws \RuntimeException
     */
    public function callTool(string $name, array $arguments): array
    {
        $this->ensureInitialized();

        $result = $this->rpc('tools/call', array(
            'name' => $name,
            'arguments' => $arguments === array() ? new \stdClass() : $arguments,
        ));

        $text = '';
        $isError = !empty($result['isError']);

        foreach ((array)($result['content'] ?? array()) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $type = (string)($item['type'] ?? '');

            if ($type === 'text' && isset($item['text'])) {
                $text .= (string)$item['text'];
                continue;
            }

            if ($type === 'resource' && isset($item['resource']['text'])) {
                $text .= (string)$item['resource']['text'];
                continue;
            }

            $text .= Json::encode($item);
        }

        if ($text === '') {
            $text = Json::encode($result);
        }

        return array('content' => $text, 'is_error' => $isError, 'raw' => $result);
    }

    /** Проверка соединения: initialize + список инструментов. */
    public function test(): array
    {
        $started = microtime(true);
        $tools = $this->listTools();

        return array(
            'server' => $this->serverName,
            'count' => count($tools),
            'tools' => array_slice(array_column($tools, 'name'), 0, 30),
            'elapsed_ms' => (int)round((microtime(true) - $started) * 1000),
        );
    }

    private function ensureInitialized(): void
    {
        if ($this->initialized) {
            return;
        }

        $result = $this->rpc('initialize', array(
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => new \stdClass(),
            'clientInfo' => array('name' => 'bxadmins', 'version' => '1.0'),
        ), false);

        $this->serverName = (string)($result['serverInfo']['name'] ?? '');
        $this->initialized = true;

        // Уведомление об инициализации — по спецификации после ответа.
        try {
            $this->notify('notifications/initialized');
        } catch (\Throwable $e) {
            // Не все серверы требуют уведомление; игнорируем.
        }
    }

    private function notify(string $method): void
    {
        $payload = array('jsonrpc' => '2.0', 'method' => $method, 'params' => new \stdClass());
        $this->send($payload);
    }

    /** @return array результат result из JSON-RPC */
    private function rpc(string $method, array $params, bool $ensureInit = true): array
    {
        if ($ensureInit) {
            $this->ensureInitialized();
        }

        $id = ++$this->nextId;
        $payload = array(
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params === array() ? new \stdClass() : $params,
        );

        $data = $this->send($payload);

        if (isset($data['error'])) {
            $message = is_array($data['error'])
                ? (string)($data['error']['message'] ?? 'ошибка MCP')
                : (string)$data['error'];

            throw new \RuntimeException('MCP: ' . $message);
        }

        return (array)($data['result'] ?? array());
    }

    /**
     * @return array ответ JSON-RPC
     * @throws \RuntimeException
     */
    private function send(array $payload): array
    {
        if ($this->url === '') {
            throw new \RuntimeException('URL MCP-сервера не задан.');
        }

        $body = Json::encode($payload);

        $http = new HttpClient();
        $http->setTimeout(self::TIMEOUT);
        $http->setHeader('Content-Type', 'application/json', true);
        $http->setHeader('Accept', 'application/json, text/event-stream', true);

        if ($this->token !== '') {
            $http->setHeader('Authorization', 'Bearer ' . $this->token, true);
        }

        foreach ($this->headers as $name => $value) {
            if (is_string($name) && $name !== '' && is_scalar($value)) {
                $http->setHeader((string)$name, (string)$value, true);
            }
        }

        if ($this->sessionId !== null) {
            $http->setHeader('Mcp-Session-Id', $this->sessionId, true);
        }

        if ($this->initialized) {
            // Streamable HTTP: после initialize протокол фиксируется и передаётся в каждом запросе.
            $http->setHeader('MCP-Protocol-Version', $this->protocolVersion, true);
        }

        try {
            $http->post($this->url, $body);
        } catch (\Throwable $e) {
            throw new \RuntimeException('MCP-сервер недоступен: ' . $e->getMessage());
        }

        $status = (int)$http->getStatus();
        $raw = (string)$http->getResult();

        if ($status === 0) {
            throw new \RuntimeException('MCP-сервер не отвечает: соединение не установлено.');
        }

        if ($status >= 400) {
            $excerpt = mb_substr(trim(strip_tags($raw)), 0, 200);
            throw new \RuntimeException('MCP-сервер вернул ошибку (HTTP ' . $status . ')' . ($excerpt !== '' ? ': ' . $excerpt : '.'));
        }

        $headers = method_exists($http, 'getHeaders') ? $http->getHeaders() : array();
        $session = null;

        if ($headers instanceof \Bitrix\Main\Web\HttpHeaders) {
            $session = (string)$headers->get('mcp-session-id');
        } else {
            foreach ((array)$headers as $name => $value) {
                if (strtolower((string)$name) === 'mcp-session-id') {
                    if (is_array($value)) {
                        if (isset($value['values']) && is_array($value['values'])) {
                            $session = (string)reset($value['values']);
                        } elseif (isset($value['value'])) {
                            $session = (string)$value['value'];
                        } else {
                            $session = (string)reset($value);
                        }
                    } else {
                        $session = (string)$value;
                    }
                }
            }
        }

        if ($session !== null && $session !== '') {
            $this->sessionId = $session;
        }

        if (strlen($raw) > self::MAX_BODY) {
            throw new \RuntimeException('MCP-сервер вернул слишком большой ответ.');
        }

        return $this->parse($raw);
    }

    /** @return array */
    private function parse(string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return array();
        }

        // Обычный JSON.
        if ($raw[0] === '{') {
            $decoded = $this->decode($raw);
            if ($decoded !== null) {
                return $decoded;
            }
        }

        // SSE: ищем последнее сообщение с result или error.
        $candidate = null;

        foreach (preg_split('/\R/', $raw) ?: array() as $line) {
            $line = trim($line);
            if (!str_starts_with($line, 'data:')) {
                continue;
            }

            $chunk = trim(substr($line, 5));
            if ($chunk === '' || $chunk === '[DONE]') {
                continue;
            }

            $decoded = $this->decode($chunk);
            if ($decoded === null) {
                continue;
            }

            if (isset($decoded['result']) || isset($decoded['error'])) {
                $candidate = $decoded;
            }
        }

        if ($candidate !== null) {
            return $candidate;
        }

        throw new \RuntimeException('MCP-сервер вернул неожиданный ответ.');
    }

    private function decode(string $raw): ?array
    {
        try {
            $data = Json::decode($raw);
        } catch (\Throwable $e) {
            return null;
        }

        return is_array($data) ? $data : null;
    }
}
