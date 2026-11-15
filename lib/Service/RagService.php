<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Mibazarow\Bxadmins\Model\IndexTable;

/**
 * Векторный поиск по базе знаний с keyword-фолбэком при недоступных эмбеддингах.
 */
class RagService
{
    private const MIN_SCORE = 0.15;
    private const HARD_MAX_ROWS = 50000;

    private static function maxRows(): int
    {
        return max(1000, min(self::HARD_MAX_ROWS, \Mibazarow\Bxadmins\Config::indexSearchMaxRows()));
    }
    private const SNIPPET_LENGTH = 500;
    private const MAX_KEYWORDS = 5;
    private const FALLBACK_ROWS = 50;

    /**
     * Векторный поиск по индексу; при недоступном эмбеддинге — keyword-фолбэк.
     *
     * @return array{results:array, fallback:bool, note?:string}
     */
    public static function search(string $query, int $limit = 5): array
    {
        $query = trim($query);
        $limit = max(1, min(10, $limit));

        if ($query === '') {
            return array('results' => array(), 'fallback' => false, 'note' => 'Пустой запрос.');
        }

        try {
            $queryVector = (new EmbeddingClient())->embedQuery($query);
        } catch (\Throwable $e) {
            return self::keywordSearch($query, $limit);
        }

        // Фаза 1: только ID и вектор — тяжёлый CONTENT не тянем.
        $limitRows = self::maxRows();
        $rows = IndexTable::getList(array(
            'select' => array('ID', 'EMBEDDING'),
            'filter' => array('!=EMBEDDING' => ''),
            'limit' => $limitRows,
        ))->fetchAll();

        // Честно сообщаем, что поиск просмотрел не весь индекс, вместо тихой потери документов.
        $indexTruncated = count($rows) >= $limitRows;

        $expectedDim = count($queryVector);
        $scored = array();

        foreach ($rows as $row) {
            $vector = self::unpackVector((string)$row['EMBEDDING']);
            if (count($vector) !== $expectedDim) {
                continue;
            }

            $score = self::cosine($queryVector, $vector);
            if ($score >= self::MIN_SCORE) {
                $scored[] = array('id' => (int)$row['ID'], 'score' => $score);
            }
        }

        usort($scored, static function (array $a, array $b): int {
            return $b['score'] <=> $a['score'];
        });

        $scored = array_slice($scored, 0, $limit);

        // Фаза 2: детали только для победителей.
        $results = array();

        if ($scored !== array()) {
            $details = array();
            $detailRows = IndexTable::getList(array(
                'select' => array('ID', 'SOURCE_TYPE', 'SOURCE_ID', 'TITLE', 'CONTENT'),
                'filter' => array('=ID' => array_column($scored, 'id')),
            ))->fetchAll();

            foreach ($detailRows as $row) {
                $details[(int)$row['ID']] = $row;
            }

            foreach ($scored as $hit) {
                $row = $details[$hit['id']] ?? null;
                if ($row === null) {
                    continue;
                }

                $results[] = array(
                    'id' => $hit['id'],
                    'source_type' => (string)$row['SOURCE_TYPE'],
                    'source_id' => (string)$row['SOURCE_ID'],
                    'title' => (string)($row['TITLE'] ?? ''),
                    'snippet' => self::snippet((string)$row['CONTENT']),
                    'score' => round($hit['score'], 4),
                );
            }
        }

        if ($results === array()) {
            return array(
                'results' => array(),
                'fallback' => false,
                'note' => 'Ничего не найдено с достаточным сходством (порог ' . self::MIN_SCORE . ').',
            );
        }

        $response = array('results' => $results, 'fallback' => false);
        if ($indexTruncated) {
            $response['note'] = 'Поиск просмотрел первые ' . $limitRows . ' документов индекса: часть базы знаний '
                . 'могла не попасть в выдачу. Увеличьте index_search_max_rows или переиндексируйте базу.';
        }

        return $response;
    }

    /**
     * Фолбэк без эмбеддингов: FULLTEXT (MATCH ... AGAINST), при недоступности —
     * LIKE по словам. Слова короче трёх символов FULLTEXT не находит.
     */
    private static function keywordSearch(string $query, int $limit): array
    {
        preg_match_all('/[\p{L}\p{N}]{3,}/u', $query, $matches);
        $words = array_slice(array_unique($matches[0]), 0, self::MAX_KEYWORDS);

        if ($words === array()) {
            return array('results' => array(), 'fallback' => true, 'note' => 'Запрос слишком короткий для keyword-поиска.');
        }

        $rows = self::fulltextRows($words, $limit);

        if ($rows === null) {
            $rows = self::likeRows($words, $limit);
        }

        $results = array();

        foreach ($rows as $row) {
            $results[] = array(
                'id' => (int)$row['ID'],
                'source_type' => (string)$row['SOURCE_TYPE'],
                'source_id' => (string)$row['SOURCE_ID'],
                'title' => (string)($row['TITLE'] ?? ''),
                'snippet' => self::snippet((string)$row['CONTENT']),
                'score' => 0,
            );
        }

        return array('results' => $results, 'fallback' => true);
    }

    /** @param string[] $words @return array[]|null null — FULLTEXT недоступен */
    private static function fulltextRows(array $words, int $limit): ?array
    {
        $expression = implode(' ', array_map(
            static fn(string $word): string => '+' . $word . '*',
            array_map(static fn(string $word): string => str_replace(array('+', '-', '*', '"', '(', ')', '~', '<', '>'), '', $word), $words)
        ));

        $connection = \Bitrix\Main\Application::getConnection();
        $sql = sprintf(
            'SELECT ID, SOURCE_TYPE, SOURCE_ID, TITLE, CONTENT FROM %s WHERE MATCH (TITLE, CONTENT) AGAINST (%s IN BOOLEAN MODE) ORDER BY MATCH (TITLE, CONTENT) AGAINST (%s IN BOOLEAN MODE) DESC LIMIT %d',
            IndexTable::getTableName(),
            "'" . $connection->getSqlHelper()->forSql($expression) . "'",
            "'" . $connection->getSqlHelper()->forSql($expression) . "'",
            max(1, min(self::FALLBACK_ROWS, $limit))
        );

        try {
            return $connection->query($sql)->fetchAll();
        } catch (\Throwable $e) {
            // FULLTEXT-индекс ещё не создан или движок не поддерживает — уходим в LIKE.
            return null;
        }
    }

    /** @param string[] $words @return array[] */
    private static function likeRows(array $words, int $limit): array
    {
        return IndexTable::getList(array(
            'select' => array('ID', 'SOURCE_TYPE', 'SOURCE_ID', 'TITLE', 'CONTENT'),
            'filter' => array(
                'LOGIC' => 'OR',
                '%TITLE' => $words,
                '%CONTENT' => $words,
            ),
            'order' => array('ID' => 'ASC'),
            'limit' => max(1, min(self::FALLBACK_ROWS, $limit)),
        ))->fetchAll();
    }

    /**
     * Распаковывает float32-вектор, сохранённый pack('g*').
     *
     * @return float[]
     */
    private static function unpackVector(string $blob): array
    {
        if ($blob === '' || strlen($blob) % 4 !== 0) {
            return array();
        }

        $values = unpack('g*', $blob);

        return is_array($values) ? array_values($values) : array();
    }

    private static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $index => $value) {
            $other = (float)$b[$index];
            $dot += $value * $other;
            $normA += $value * $value;
            $normB += $other * $other;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    private static function snippet(string $content): string
    {
        $content = trim(preg_replace('/\s+/', ' ', $content));

        return mb_strlen($content) > self::SNIPPET_LENGTH
            ? mb_substr($content, 0, self::SNIPPET_LENGTH) . '…'
            : $content;
    }
}
