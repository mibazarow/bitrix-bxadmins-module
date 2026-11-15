<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Application;

/**
 * Произвольный SELECT к базе — только чтение.
 *
 * Нужен, когда готового инструмента нет: посчитать, найти, проверить. Запись,
 * DDL и многооператорные строки запрещены, результат ограничен по строкам.
 */
class RunSqlTool extends AbstractTool
{
    public const DOMAIN = 'security';

    private const MAX_ROWS = 200;

    /** Колонки, значения которых нельзя показывать модели. */
    private const SECRET_COLUMNS = array('password', 'passwd', 'secret', 'token', 'api_key', 'apikey', 'private_key', 'hash', 'credential');

    /** Таблицы, где лежат только подключения и секреты: через SQL их не читаем. */
    private const SECRET_TABLES = array(
        'b_mibazarow_bxadmins_llm_profile',
        'b_mibazarow_bxadmins_mcp_server',
    );

    /** Имена опций, значения которых нельзя отдавать модели. */
    private const SECRET_OPTION_NAME = '/(secret|api[_-]?key|apikey|token|password|passwd|private|credential|hash|^worker_secret$|^backup_s3_)/i';

    /** Значения, похожие на ключи, токены и хеши, маскируются независимо от имени колонки. */
    private const SECRET_VALUE_PATTERNS = array(
        '/^sk-[A-Za-z0-9_.\-]{8,}$/',
        '/^AKIA[0-9A-Z]{16}$/',
        '/^eyJ[A-Za-z0-9_\-]{8,}\./',
        '/^\$2[aby]\$[0-9]{2}\$.{20,}$/',
        '/^\$6\$[^\s]{10,}$/',
        '/^\$argon2[a-z]*\$/i',
        '/^[0-9a-f]{32,}$/i',
    );

    private const MAX_LENGTH = 4000;

    public static function getName(): string
    {
        return 'run_sql';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'run_sql',
            'description' => 'Выполняет SELECT к базе сайта и возвращает строки. Только чтение: INSERT/UPDATE/DELETE/DDL запрещены. Максимум 200 строк. Для изменений используй инструменты.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'query' => array('type' => 'string', 'description' => 'SQL-запрос SELECT'),
                    'limit' => array('type' => 'integer', 'description' => 'Сколько строк вернуть, по умолчанию 50'),
                ),
                'required' => array('query'),
            ),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        $query = trim((string)($args['query'] ?? ''));
        $limit = max(1, min(self::MAX_ROWS, (int)($args['limit'] ?? 50)));

        if ($query === '') {
            return array('error' => 'Параметр query обязателен.');
        }

        if (mb_strlen($query) > self::MAX_LENGTH) {
            return array('error' => 'Запрос слишком длинный.');
        }

        // Один оператор, только чтение.
        $normalized = trim((string)preg_replace('/\s+/', ' ', $query));
        if (str_contains($normalized, ';') && !preg_match('/;\s*$/', $normalized)) {
            return array('error' => 'Разрешён только один запрос.');
        }

        $normalized = rtrim($normalized, ';');
        $firstWord = strtoupper((string)strtok($normalized, " \t\n("));

        if (!in_array($firstWord, array('SELECT', 'WITH', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'), true)) {
            return array('error' => 'Разрешены только SELECT, WITH, SHOW, DESCRIBE и EXPLAIN.');
        }

        // INTO блокируем целиком: это и запись в файл (INTO/**/OUTFILE), и
        // присваивание переменных, и обход фильтра комментариями.
        $forbidden = '/\b(INSERT|UPDATE|DELETE|REPLACE|DROP|ALTER|CREATE|TRUNCATE|GRANT|REVOKE|INTO|OUTFILE|DUMPFILE|LOAD_FILE|SET\s+@|LOCK\s+IN\s+SHARE\s+MODE)\b|SLEEP\s*\(|BENCHMARK\s*\(/i';
        if (preg_match($forbidden, $normalized) === 1) {
            return array('error' => 'Запрос содержит запрещённую операцию.');
        }

        $secretError = self::assertNoSecretRead($normalized);
        if ($secretError !== null) {
            return array('error' => $secretError);
        }

        try {
            $connection = Application::getConnection();
            $result = $connection->query($query);

            $rows = array();
            $columns = array();
            $count = 0;

            while ($row = $result->fetch()) {
                if ($count >= $limit) {
                    break;
                }

                if ($columns === array()) {
                    $columns = array_keys($row);
                }

                $rows[] = self::maskRow($row);
                $count++;
            }

            return array(
                'columns' => $columns,
                'rows' => $rows,
                'count' => count($rows),
                'limit' => $limit,
            );
        } catch (\Throwable $e) {
            return array('error' => 'SQL: ' . mb_substr($e->getMessage(), 0, 500));
        }
    }

    /**
     * Закрывает прямой доступ к секретным таблицам и к hash-колонкам пользователей.
     * Для b_option запрещает alias/подстроки: иначе можно выбрать VALUE AS V и
     * обойти маскировку по имени колонки.
     */
    private static function assertNoSecretRead(string $sql): ?string
    {
        $lower = strtolower($sql);

        foreach (self::SECRET_TABLES as $table) {
            if (str_contains($lower, $table)) {
                return 'Таблица ' . $table . ' содержит ключи подключений и через run_sql не читается. '
                    . 'Подключения смотри в настройках модуля (значения секретов там скрыты).';
            }
        }

        if (str_contains($lower, 'b_user') && preg_match('/\b(password|passwd)\b/', $lower) === 1) {
            return 'Хеши паролей пользователей через run_sql не читаются.';
        }

        if (str_contains($lower, 'b_option')) {
            if (preg_match('/\bas\s+[a-z0-9_`"\[]+/i', $sql) === 1) {
                return 'Запросы к b_option не должны переименовывать колонки (AS): иначе нельзя проверить, '
                    . 'какая опция возвращается. Выбери NAME и VALUE напрямую.';
            }

            if (preg_match('/\b(left|right|substr|substring|mid|concat|hex|unhex|conv|cast)\s*\(/i', $sql) === 1) {
                return 'Запросы к b_option не должны обрезать или преобразовывать значения: секреты маскируются '
                    . 'по полному значению. Выбери NAME и VALUE напрямую.';
            }
        }

        return null;
    }

    /** Прячет пароли, ключи и токены даже если их выбрали запросом. */
    private static function maskRow(array $row): array
    {
        // Для b_option смотрим на NAME и маскируем VALUE у секретных опций.
        $optionName = '';
        foreach ($row as $column => $value) {
            if (strtolower((string)$column) === 'name' && is_scalar($value)) {
                $optionName = (string)$value;
                break;
            }
        }

        $isSecretOption = $optionName !== '' && preg_match(self::SECRET_OPTION_NAME, $optionName) === 1;

        foreach ($row as $column => $value) {
            $lower = strtolower((string)$column);

            foreach (self::SECRET_COLUMNS as $hint) {
                if (str_contains($lower, $hint)) {
                    $row[$column] = $value === null || $value === '' ? $value : '***';
                    continue 2;
                }
            }

            if ($isSecretOption && $lower === 'value') {
                $row[$column] = $value === null || $value === '' ? $value : '***';
                continue;
            }

            if (is_string($value) && self::looksLikeSecret($value)) {
                $row[$column] = '***';
                continue;
            }

            if (is_string($value) && mb_strlen($value) > 2000) {
                $row[$column] = mb_substr($value, 0, 2000) . '…';
            }
        }

        return $row;
    }

    private static function looksLikeSecret(string $value): bool
    {
        $value = trim($value);

        if ($value === '') {
            return false;
        }

        foreach (self::SECRET_VALUE_PATTERNS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }
}
