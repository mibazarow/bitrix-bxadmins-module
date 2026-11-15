<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Mibazarow\Bxadmins\Config;

/**
 * Клиент локального embedding-сервиса EmbeddingGemma 2.
 *
 * Сервис (FastAPI, по умолчанию http://127.0.0.1:8000) держит одну загруженную модель
 * и отдаёт векторы через POST /embed {text, profile, dim} -> {embedding, dim, model}.
 * Модель в запросе не передаётся: сервис всегда использует ту, что загружена у него.
 */
class EmbeddingClient
{
    /** Профиль для индексируемого контента сайта. */
    public const PROFILE_PASSAGE = 'site_passage';

    /** Профиль для поискового запроса пользователя. */
    public const PROFILE_QUERY = 'site_query';

    private const TIMEOUT = 120;
    private const TEST_STRING = 'bxadmins embedding self-test';
    private const EXCERPT_LIMIT = 300;

    /**
     * @return float[]
     * @throws \RuntimeException безопасное для показа владельцу сообщение (без секретов)
     */
    public function embed(string $text, string $profile = self::PROFILE_PASSAGE): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new \RuntimeException('Пустой текст для эмбеддинга.');
        }

        $response = $this->postJson('/embed', array(
            'text' => $text,
            'profile' => $profile,
        ));

        if ($response['status'] < 200 || $response['status'] >= 300) {
            Logger::error('Embedding HTTP error ' . $response['status'], array(
                'profile' => $profile,
                'body' => $this->excerpt($response['body']),
            ));
            throw new \RuntimeException(
                'Embedding-сервис вернул ошибку (HTTP ' . $response['status'] . '): ' . $this->excerpt($response['body'])
            );
        }

        $data = $this->decode($response['body']);
        if (!isset($data['embedding']) || !is_array($data['embedding'])) {
            Logger::error('Embedding: ответ без поля embedding', array(
                'body' => $this->excerpt($response['body']),
            ));
            throw new \RuntimeException('Embedding-сервис вернул некорректный ответ (нет embedding).');
        }

        return $this->normalizeVector($data['embedding']);
    }

    /** Эмбеддинг поискового запроса (профиль query, не passage). */
    public function embedQuery(string $text): array
    {
        return $this->embed($text, self::PROFILE_QUERY);
    }

    /**
     * Self-test: проверяет сервис, возвращает модель, размерность и время ответа.
     *
     * @return array{model:string,dimension:int,elapsed_ms:int,profile:string,service_model:string}
     * @throws \RuntimeException
     */
    public function test(): array
    {
        $health = $this->health();

        $started = microtime(true);
        $vector = $this->embed(self::TEST_STRING);

        return array(
            'model' => Config::embeddingModel(),
            'service_model' => (string)($health['model'] ?? ''),
            'dimension' => count($vector),
            'elapsed_ms' => (int)round((microtime(true) - $started) * 1000),
            'profile' => self::PROFILE_PASSAGE,
        );
    }

    /**
     * GET /health — модель, размерность и профили, которые реально отдаёт сервис.
     *
     * @return array{model?:string,dim?:int,profiles?:array}
     * @throws \RuntimeException
     */
    private function health(): array
    {
        $response = $this->request('GET', '/health');

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException(
                'Embedding-сервис вернул ошибку (HTTP ' . $response['status'] . '): ' . $this->excerpt($response['body'])
            );
        }

        return $this->decode($response['body']);
    }

    /** @return float[] */
    private function normalizeVector(array $raw): array
    {
        $vector = array();

        foreach ($raw as $value) {
            if (!is_numeric($value)) {
                throw new \RuntimeException('Embedding-сервис вернул нечисловое значение в векторе.');
            }

            $float = (float)$value;
            if (is_nan($float) || is_infinite($float)) {
                throw new \RuntimeException('Embedding-сервис вернул NaN/inf в векторе.');
            }

            $vector[] = $float;
        }

        if ($vector === array()) {
            throw new \RuntimeException('Embedding-сервис вернул пустой вектор.');
        }

        return $vector;
    }

    private function postJson(string $path, array $payload): array
    {
        return $this->request('POST', $path, Json::encode($payload));
    }

    private function request(string $method, string $path, ?string $body = null): array
    {
        $base = rtrim(Config::embeddingEndpoint(), '/');
        if ($base === '') {
            throw new \RuntimeException('Endpoint эмбеддингов не задан — укажите его в настройках модуля.');
        }

        $http = new HttpClient();
        $http->setTimeout(self::TIMEOUT);
        $http->setHeader('Content-Type', 'application/json', true);
        $http->setHeader('Accept', 'application/json', true);

        try {
            if ($method === 'GET') {
                $http->get($base . $path);
            } else {
                $http->post($base . $path, (string)$body);
            }
        } catch (\Throwable $e) {
            Logger::error('Embedding transport error: ' . $e->getMessage(), array('path' => $path));
            throw new \RuntimeException('Embedding-сервис недоступен: не удалось выполнить запрос к endpoint.');
        }

        $status = (int)$http->getStatus();
        if ($status === 0) {
            Logger::error('Embedding transport error', array(
                'path' => $path,
                'errors' => print_r($http->getError(), true),
            ));
            throw new \RuntimeException('Embedding-сервис недоступен: endpoint не отвечает или соединение не установлено.');
        }

        return array('status' => $status, 'body' => (string)$http->getResult());
    }

    private function decode(string $body): array
    {
        try {
            $data = Json::decode($body);
        } catch (\Throwable $e) {
            $data = null;
        }

        if (!is_array($data)) {
            Logger::error('Embedding вернул не-JSON ответ', array('body' => $this->excerpt($body)));
            throw new \RuntimeException('Embedding-сервис вернул некорректный ответ (не JSON).');
        }

        return $data;
    }

    private function excerpt(string $body): string
    {
        $body = trim(preg_replace('/\s+/', ' ', $body));

        return mb_strlen($body) > self::EXCERPT_LIMIT
            ? mb_substr($body, 0, self::EXCERPT_LIMIT) . '…'
            : $body;
    }
}
