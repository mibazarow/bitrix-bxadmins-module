<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Mibazarow\Bxadmins\Config;

/**
 * Клиент OpenAI-совместимого API: chat/completions и models.
 */
class LlmClient
{
    // 60 секунд — порог из ТЗ: если ответа нет, считаем провайдера недоступным.
    private const TIMEOUT = 60;

    /** Коды, при которых виноват провайдер, а не наш запрос. */
    private const UNAVAILABLE_STATUSES = array(0, 401, 402, 403, 404, 429, 500, 502, 503, 504);
    private const EXCERPT_LIMIT = 500;

    /** Переопределения для проверки подключения до сохранения настроек. */
    private ?string $endpointOverride;
    private ?string $apiKeyOverride;

    public function __construct(?string $endpoint = null, ?string $apiKey = null)
    {
        $endpoint = trim((string)$endpoint);
        $apiKey = trim((string)$apiKey);

        $this->endpointOverride = $endpoint !== '' ? rtrim($endpoint, '/') : null;
        $this->apiKeyOverride = $apiKey !== '' ? $apiKey : null;
    }

    /**
     * POST {endpoint}/chat/completions — первый choice.message (role, content, tool_calls).
     *
     * @throws \RuntimeException сообщение безопасно для показа: без ключа и тела запроса
     */
    public function chat(array $messages, array $tools = array()): array
    {
        $payload = array(
            'model' => Config::llmModel(),
            'messages' => $messages,
            'temperature' => 0.2,
        );

        $reasoning = Config::llmReasoning();
        if (in_array($reasoning, Config::REASONING_LEVELS, true) && $reasoning !== 'none') {
            $payload['reasoning_effort'] = $reasoning;
        }

        if ($tools !== array()) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $response = $this->postJson('/chat/completions', $payload);

        // Провайдер может не поддерживать reasoning_effort — один повтор без него.
        if (in_array($response['status'], array(400, 422), true) && isset($payload['reasoning_effort'])) {
            unset($payload['reasoning_effort']);
            $response = $this->postJson('/chat/completions', $payload);
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $this->logHttpFailure('chat/completions', $response);

            // Понятное сообщение вместо «HTTP 400»: чаще всего это превышение окна.
            $body = mb_strtolower($response['body']);
            if (in_array($response['status'], array(400, 413, 422), true)
                && (str_contains($body, 'context') || str_contains($body, 'token'))) {
                throw new \RuntimeException(
                    'Превышено контекстное окно модели. Уменьшите окно в настройках модуля или начните новый чат.'
                );
            }

            if (in_array($response['status'], self::UNAVAILABLE_STATUSES, true)) {
                throw new LlmUnavailableException($this->providerMessage($response['status'], $response['body']));
            }

            // Полный ответ провайдера (без ключа) — в журнале модуля, в чат выводим только статус.
            throw new \RuntimeException('LLM вернул ошибку (HTTP ' . $response['status'] . '). Подробности — в журнале модуля.');
        }

        $data = $this->decode($response['body'], 'chat/completions');

        if (!isset($data['choices'][0]['message']) || !is_array($data['choices'][0]['message'])) {
            Logger::error('LLM вернул ответ без choices[0].message', array(
                'body' => $this->redact($this->excerpt($response['body'])),
            ));
            throw new \RuntimeException('LLM вернул некорректный ответ (нет choices).');
        }

        return $data['choices'][0]['message'];
    }

    /**
     * GET {endpoint}/models — список ID моделей для проверки подключения.
     *
     * @return string[]
     * @throws \RuntimeException
     */
    public function listModels(): array
    {
        $response = $this->getJson('/models');

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $this->logHttpFailure('models', $response);

            if (in_array($response['status'], self::UNAVAILABLE_STATUSES, true)) {
                throw new LlmUnavailableException($this->providerMessage($response['status'], $response['body']));
            }

            throw new \RuntimeException('LLM вернул ошибку (HTTP ' . $response['status'] . '). Подробности — в журнале модуля.');
        }

        $data = $this->decode($response['body'], 'models');
        $models = array();

        foreach ((array)($data['data'] ?? array()) as $item) {
            if (is_array($item) && isset($item['id']) && is_string($item['id'])) {
                $models[] = $item['id'];
            }
        }

        return $models;
    }

    private function postJson(string $path, array $payload): array
    {
        return $this->request('POST', $path, Json::encode($payload));
    }

    private function getJson(string $path): array
    {
        return $this->request('GET', $path);
    }

    private function request(string $method, string $path, string $body = ''): array
    {
        $http = $this->createClient();

        if ($body !== '') {
            $http->setHeader('Content-Type', 'application/json', true);
        }

        try {
            if ($method === 'POST') {
                $http->post($this->endpoint($path), $body);
            } else {
                $http->get($this->endpoint($path));
            }
        } catch (\Throwable $e) {
            Logger::error('LLM transport error: ' . $e->getMessage(), array('path' => $path));
            throw new LlmUnavailableException('Провайдер не ответил за 60 секунд или соединение не установлено.');
        }

        return $this->collect($http, $path);
    }

    private function createClient(): HttpClient
    {
        $apiKey = $this->apiKeyOverride ?? Config::llmApiKey();
        if ($apiKey === '') {
            throw new \RuntimeException('API-ключ LLM не задан — укажите его в настройках модуля.');
        }

        $http = new HttpClient();
        $http->setTimeout(self::TIMEOUT);
        $http->setHeader('Authorization', 'Bearer ' . $apiKey, true);

        return $http;
    }

    private function collect(HttpClient $http, string $path): array
    {
        $status = (int)$http->getStatus();
        $body = (string)$http->getResult();

        if ($status === 0) {
            Logger::error('LLM transport error', array(
                'path' => $path,
                'errors' => $this->redact(print_r($http->getError(), true)),
            ));
            throw new LlmUnavailableException('Провайдер не ответил: endpoint не принимает соединение.');
        }

        return array('status' => $status, 'body' => $body);
    }

    /** Понятное человеку объяснение ошибки провайдера. */
    private function providerMessage(int $status, string $body): string
    {
        $text = mb_strtolower($body);

        if (str_contains($text, 'balance') || str_contains($text, 'quota') || str_contains($text, 'insufficient')) {
            return 'Провайдер сообщает о недостаточном балансе или исчерпанной квоте (HTTP ' . $status . ').';
        }

        if ($status === 401 || $status === 403) {
            return 'Провайдер отклонил ключ доступа (HTTP ' . $status . ').';
        }

        if ($status === 429) {
            return 'Провайдер ограничил частоту запросов (HTTP 429).';
        }

        return 'Провайдер вернул ошибку (HTTP ' . $status . ') — сервис недоступен или перегружен.';
    }

    private function endpoint(string $path): string
    {
        $base = $this->endpointOverride ?? rtrim(Config::llmEndpoint(), '/');
        if ($base === '') {
            throw new \RuntimeException('Endpoint LLM не задан — укажите его в настройках модуля.');
        }

        return $base . $path;
    }

    private function decode(string $body, string $path): array
    {
        try {
            $data = Json::decode($body);
        } catch (\Throwable $e) {
            $data = null;
        }

        if (!is_array($data)) {
            Logger::error('LLM вернул не-JSON ответ', array(
                'path' => $path,
                'body' => $this->redact($this->excerpt($body)),
            ));
            throw new \RuntimeException('LLM вернул некорректный ответ (не JSON).');
        }

        return $data;
    }

    private function logHttpFailure(string $path, array $response): void
    {
        Logger::error('LLM HTTP error ' . $response['status'], array(
            'path' => $path,
            'body' => $this->redact($this->excerpt($response['body'])),
        ));
    }

    private function excerpt(string $body): string
    {
        $body = trim(preg_replace('/\s+/', ' ', $body));

        return mb_strlen($body) > self::EXCERPT_LIMIT
            ? mb_substr($body, 0, self::EXCERPT_LIMIT) . '…'
            : $body;
    }

    private function redact(string $text): string
    {
        $apiKey = Config::llmApiKey();
        if ($apiKey !== '') {
            $text = str_replace($apiKey, '***', $text);
        }

        return $text;
    }
}
