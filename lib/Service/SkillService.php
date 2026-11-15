<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Mibazarow\Bxadmins\Model\SkillTable;
use Mibazarow\Bxadmins\Model\SkillVersionTable;

/**
 * Skills: named instructions the agent applies to matching tasks.
 *
 * The prompt receives a catalog only; full instructions are loaded through the
 * use_skill tool. Every mutation stores a version, deletion is soft, and usage
 * is counted so the admin panel can show what is actually used.
 */
final class SkillService
{
    private const MAX_INSTRUCTIONS = 8000;
    private const MAX_NAME = 150;
    private const MAX_DESCRIPTION = 500;
    private const MAX_TAGS = 500;
    private const MAX_WHEN = 500;
    private const MAX_PRIORITY = 1000;

    private const FIELDS = array(
        'ID', 'NAME', 'DESCRIPTION', 'INSTRUCTIONS', 'ENABLED', 'TAGS', 'WHEN_TO_USE',
        'PRIORITY', 'CREATED_BY', 'SOURCE', 'CHAT_ID', 'USE_COUNT', 'LAST_USED_AT',
        'DELETED_AT', 'CREATED_AT', 'UPDATED_AT',
    );

    /** @var array<string,array> request-level cache */
    private static array $cache = array();
    private static bool $schemaReady = false;

    /**
     * Older installations receive the new columns lazily. The check is cheap and
     * runs once per request; DDL is idempotent.
     */
    public static function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }

        self::$schemaReady = true;

        try {
            $connection = Application::getConnection();
            $table = SkillTable::getTableName();

            if (!$connection->isTableExists($table)) {
                return;
            }

            // SHOW COLUMNS instead of getTableFields: the latter primes the
            // connection column cache before ALTER and hides new fields in this request.
            $fields = array();
            foreach ($connection->query("SHOW COLUMNS FROM {$table}")->fetchAll() as $field) {
                $fields[(string)$field['Field']] = true;
            }
            $columns = array(
                'TAGS' => "VARCHAR(500) NULL",
                'WHEN_TO_USE' => "VARCHAR(500) NULL",
                'PRIORITY' => "INT NOT NULL DEFAULT 100",
                'CREATED_BY' => "INT NOT NULL DEFAULT 0",
                'SOURCE' => "VARCHAR(20) NOT NULL DEFAULT 'chat'",
                'CHAT_ID' => "INT NOT NULL DEFAULT 0",
                'USE_COUNT' => "INT NOT NULL DEFAULT 0",
                'LAST_USED_AT' => "DATETIME NULL",
                'DELETED_AT' => "DATETIME NULL",
            );

            foreach ($columns as $name => $definition) {
                if (!isset($fields[$name])) {
                    $connection->queryExecute("ALTER TABLE {$table} ADD COLUMN {$name} {$definition}");
                }
            }

            $versionTable = SkillVersionTable::getTableName();
            if (!$connection->isTableExists($versionTable)) {
                $connection->queryExecute(
                    "CREATE TABLE IF NOT EXISTS {$versionTable} (
                        ID INT NOT NULL AUTO_INCREMENT,
                        SKILL_ID INT NOT NULL,
                        NAME VARCHAR(150) NOT NULL,
                        DESCRIPTION VARCHAR(500) NULL,
                        INSTRUCTIONS LONGTEXT NOT NULL,
                        ENABLED CHAR(1) NOT NULL DEFAULT 'Y',
                        TAGS VARCHAR(500) NULL,
                        WHEN_TO_USE VARCHAR(500) NULL,
                        PRIORITY INT NOT NULL DEFAULT 100,
                        CREATED_BY INT NOT NULL DEFAULT 0,
                        SOURCE VARCHAR(20) NOT NULL DEFAULT 'chat',
                        CHAT_ID INT NOT NULL DEFAULT 0,
                        CREATED_AT DATETIME NOT NULL,
                        PRIMARY KEY (ID),
                        KEY IX_MIBAZAROW_BXADMINS_SKILL_VERSION (SKILL_ID, ID)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                );
            }
        } catch (\Throwable $e) {
            Logger::error('Skill schema migration failed: ' . $e->getMessage());
        }
    }

    /**
     * @return array<int,array>
     */
    public static function all(bool $enabledOnly = true, int $limit = 50, bool $includeDeleted = false): array
    {
        self::ensureSchema();

        $key = 'all:' . (int)$enabledOnly . ':' . $limit . ':' . (int)$includeDeleted;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $filter = array();
        if ($enabledOnly) {
            $filter['=ENABLED'] = 'Y';
        }
        if (!$includeDeleted) {
            $filter['=DELETED_AT'] = false;
        }

        $rows = array();

        try {
            foreach (SkillTable::getList(array(
                'select' => self::FIELDS,
                'filter' => $filter,
                'order' => array('PRIORITY' => 'ASC', 'NAME' => 'ASC'),
                'limit' => max(1, min(200, $limit)),
            ))->fetchAll() as $row) {
                $rows[] = self::mapRow($row);
            }
        } catch (\Throwable $e) {
            return self::$cache[$key] = array();
        }

        return self::$cache[$key] = $rows;
    }

    /** Enabled skills for the prompt catalog: no instructions, newest metadata included. */
    public static function catalog(int $limit = 30): array
    {
        self::ensureSchema();

        $key = 'catalog:' . $limit;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $rows = array();

        try {
            foreach (SkillTable::getList(array(
                'select' => array('ID', 'NAME', 'DESCRIPTION', 'ENABLED', 'TAGS', 'WHEN_TO_USE', 'PRIORITY', 'USE_COUNT', 'UPDATED_AT'),
                'filter' => array('=ENABLED' => 'Y', '=DELETED_AT' => false),
                'order' => array('PRIORITY' => 'ASC', 'NAME' => 'ASC'),
                'limit' => max(1, min(200, $limit)),
            ))->fetchAll() as $row) {
                $rows[] = self::mapRow($row);
            }
        } catch (\Throwable $e) {
            return self::$cache[$key] = array();
        }

        return self::$cache[$key] = $rows;
    }

    public static function get(int $id): ?array
    {
        self::ensureSchema();

        $row = SkillTable::getById($id)->fetch();

        return $row ? self::mapRow($row) : null;
    }

    /** Finds a skill by ID or name, case-insensitive; deleted rows excluded by default. */
    public static function find(string $query, bool $includeDeleted = false): ?array
    {
        $row = self::resolve($query, $includeDeleted);

        return $row ? self::mapRow($row) : null;
    }

    public static function search(string $query, int $limit = 20): array
    {
        $needle = mb_strtolower(trim($query));
        if ($needle === '') {
            return array();
        }

        $found = array();
        foreach (self::all(false, 200, true) as $skill) {
            $haystack = mb_strtolower($skill['name'] . ' ' . $skill['description'] . ' '
                . $skill['tags'] . ' ' . $skill['when_to_use'] . ' ' . $skill['instructions']);

            if (mb_strpos($haystack, $needle) !== false) {
                unset($skill['instructions']);
                $found[] = $skill;
            }

            if (count($found) >= max(1, min(100, $limit))) {
                break;
            }
        }

        return $found;
    }

    /**
     * Creates or updates a skill by name.
     *
     * @param array{tags?:string,when_to_use?:string,priority?:int,created_by?:int,source?:string,chat_id?:int} $meta
     */
    public static function save(
        string $name,
        string $description,
        string $instructions,
        bool $enabled = true,
        array $meta = array()
    ): int {
        self::ensureSchema();

        $name = trim($name);
        $description = trim($description);
        $instructions = trim($instructions);

        if ($name === '' || $instructions === '') {
            throw new \InvalidArgumentException('Skill name and instructions are required.');
        }
        if (mb_strlen($name) > self::MAX_NAME) {
            throw new \InvalidArgumentException('Skill name must be at most ' . self::MAX_NAME . ' characters.');
        }
        if (mb_strlen($description) > self::MAX_DESCRIPTION) {
            throw new \InvalidArgumentException('Skill description must be at most ' . self::MAX_DESCRIPTION . ' characters.');
        }
        if (mb_strlen($instructions) > self::MAX_INSTRUCTIONS) {
            throw new \InvalidArgumentException('Skill instructions must be at most ' . self::MAX_INSTRUCTIONS . ' characters.');
        }

        $existing = SkillTable::getList(array(
            'select' => self::FIELDS,
            'filter' => array('=NAME' => $name),
            'limit' => 1,
        ))->fetch();

        $tags = self::metaString($meta, 'tags', $existing, 'TAGS', self::MAX_TAGS);
        $when = self::metaString($meta, 'when_to_use', $existing, 'WHEN_TO_USE', self::MAX_WHEN);
        $priority = self::priority($meta, $existing);
        $source = self::metaString($meta, 'source', $existing, 'SOURCE', 20, 'chat');
        $chatId = array_key_exists('chat_id', $meta) ? (int)$meta['chat_id'] : (int)($existing['CHAT_ID'] ?? 0);

        if ($existing) {
            self::snapshot($existing);

            $result = SkillTable::update((int)$existing['ID'], array(
                'DESCRIPTION' => $description,
                'INSTRUCTIONS' => $instructions,
                'ENABLED' => $enabled ? 'Y' : 'N',
                'TAGS' => $tags !== '' ? $tags : null,
                'WHEN_TO_USE' => $when !== '' ? $when : null,
                'PRIORITY' => $priority,
                'SOURCE' => $source,
                'CHAT_ID' => $chatId,
                'DELETED_AT' => null,
                'UPDATED_AT' => new DateTime(),
            ));

            if (!$result->isSuccess()) {
                throw new \RuntimeException('Failed to update the skill.');
            }

            self::invalidate();

            return (int)$existing['ID'];
        }

        $result = SkillTable::add(array(
            'NAME' => $name,
            'DESCRIPTION' => $description,
            'INSTRUCTIONS' => $instructions,
            'ENABLED' => $enabled ? 'Y' : 'N',
            'TAGS' => $tags !== '' ? $tags : null,
            'WHEN_TO_USE' => $when !== '' ? $when : null,
            'PRIORITY' => $priority,
            'CREATED_BY' => (int)($meta['created_by'] ?? 0),
            'SOURCE' => $source,
            'CHAT_ID' => $chatId,
            'USE_COUNT' => 0,
            'CREATED_AT' => new DateTime(),
            'UPDATED_AT' => new DateTime(),
        ));

        if (!$result->isSuccess()) {
            throw new \RuntimeException('Failed to save the skill.');
        }

        self::invalidate();

        return (int)$result->getId();
    }

    public static function setEnabled(int $id, bool $enabled): void
    {
        self::ensureSchema();

        $row = SkillTable::getById($id)->fetch();
        if (!$row) {
            throw new \InvalidArgumentException('Skill not found.');
        }

        self::snapshot($row);
        SkillTable::update($id, array(
            'ENABLED' => $enabled ? 'Y' : 'N',
            'UPDATED_AT' => new DateTime(),
        ));
        self::invalidate();
    }

    public static function rename(int $id, string $name): void
    {
        self::ensureSchema();

        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
            throw new \InvalidArgumentException('Skill name must be 1..' . self::MAX_NAME . ' characters.');
        }

        $row = SkillTable::getById($id)->fetch();
        if (!$row) {
            throw new \InvalidArgumentException('Skill not found.');
        }

        $other = SkillTable::getList(array(
            'select' => array('ID'),
            'filter' => array('=NAME' => $name, '!ID' => $id),
            'limit' => 1,
        ))->fetch();

        if ($other) {
            throw new \InvalidArgumentException('Another skill with this name already exists.');
        }

        self::snapshot($row);
        SkillTable::update($id, array('NAME' => $name, 'UPDATED_AT' => new DateTime()));
        self::invalidate();
    }

    /** Soft-deletes by default; $purge removes the row and its history permanently. */
    public static function remove(string $query, bool $purge = false): int
    {
        self::ensureSchema();

        $row = self::resolve($query, true);
        if (!$row) {
            return 0;
        }

        $id = (int)$row['ID'];

        if ($purge) {
            SkillVersionTable::deleteByFilter(array('=SKILL_ID' => $id));
            SkillTable::delete($id);
        } else {
            self::snapshot($row);
            SkillTable::update($id, array(
                'DELETED_AT' => new DateTime(),
                'UPDATED_AT' => new DateTime(),
            ));
        }

        self::invalidate();

        return 1;
    }

    public static function restore(string $query): int
    {
        self::ensureSchema();

        $row = self::resolve($query, true);
        if (!$row) {
            return 0;
        }

        self::snapshot($row);
        SkillTable::update((int)$row['ID'], array(
            'DELETED_AT' => null,
            'UPDATED_AT' => new DateTime(),
        ));
        self::invalidate();

        return 1;
    }

    /** Counts a successful use_skill load. */
    public static function markUsed(int $id): void
    {
        self::ensureSchema();

        try {
            $connection = Application::getConnection();
            $table = SkillTable::getTableName();
            $connection->queryExecute(
                "UPDATE {$table} SET USE_COUNT = USE_COUNT + 1, LAST_USED_AT = NOW() WHERE ID = " . (int)$id
            );
            self::invalidate();
        } catch (\Throwable $e) {
            // Usage statistics must not break skill loading.
        }
    }

    /** @return array<int,array> */
    public static function versions(int $id, int $limit = 20): array
    {
        self::ensureSchema();

        $rows = array();

        try {
            foreach (SkillVersionTable::getList(array(
                'filter' => array('=SKILL_ID' => $id),
                'order' => array('ID' => 'DESC'),
                'limit' => max(1, min(100, $limit)),
            ))->fetchAll() as $row) {
                $rows[] = self::mapRow($row);
            }
        } catch (\Throwable $e) {
            return array();
        }

        return $rows;
    }

    public static function restoreVersion(int $versionId): ?int
    {
        self::ensureSchema();

        $version = SkillVersionTable::getById($versionId)->fetch();
        if (!$version) {
            return null;
        }

        $current = SkillTable::getById((int)$version['SKILL_ID'])->fetch();
        if (!$current) {
            return null;
        }

        self::snapshot($current);

        SkillTable::update((int)$version['SKILL_ID'], array(
            'NAME' => (string)$version['NAME'],
            'DESCRIPTION' => (string)($version['DESCRIPTION'] ?? ''),
            'INSTRUCTIONS' => (string)$version['INSTRUCTIONS'],
            'ENABLED' => (string)($version['ENABLED'] ?? 'Y'),
            'TAGS' => $version['TAGS'] !== null && $version['TAGS'] !== '' ? (string)$version['TAGS'] : null,
            'WHEN_TO_USE' => $version['WHEN_TO_USE'] !== null && $version['WHEN_TO_USE'] !== '' ? (string)$version['WHEN_TO_USE'] : null,
            'PRIORITY' => (int)($version['PRIORITY'] ?? 100),
            'DELETED_AT' => null,
            'UPDATED_AT' => new DateTime(),
        ));
        self::invalidate();

        return (int)$version['SKILL_ID'];
    }

    /** Imports a SKILL.md-style document with optional frontmatter. */
    public static function importMarkdown(string $raw): int
    {
        $raw = str_replace(array("\r\n", "\r"), "\n", trim($raw));
        if ($raw === '') {
            throw new \InvalidArgumentException('Empty skill document.');
        }

        $meta = array('source' => 'import');
        $body = $raw;

        if (str_starts_with($raw, '---')) {
            $end = strpos($raw, "\n---", 3);
            if ($end !== false) {
                $front = substr($raw, 3, $end - 3);
                $body = ltrim(substr($raw, $end + 4));

                foreach (explode("\n", $front) as $line) {
                    if (!str_contains($line, ':')) {
                        continue;
                    }
                    [$key, $value] = explode(':', $line, 2);
                    $key = strtolower(trim($key));
                    $value = trim($value);

                    if (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') {
                        $value = stripcslashes(substr($value, 1, -1));
                    }

                    if ($key === 'name') {
                        $meta['name'] = $value;
                    } elseif ($key === 'description') {
                        $meta['description'] = $value;
                    } elseif ($key === 'tags') {
                        $meta['tags'] = trim($value, '[] ');
                    } elseif ($key === 'when_to_use' || $key === 'when-to-use') {
                        $meta['when_to_use'] = $value;
                    } elseif ($key === 'priority') {
                        $meta['priority'] = (int)$value;
                    } elseif ($key === 'enabled') {
                        $meta['enabled'] = in_array(strtolower($value), array('1', 'true', 'yes', 'y'), true);
                    }
                }
            }
        }

        $name = trim((string)($meta['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Skill document needs a name in frontmatter.');
        }

        return self::save(
            $name,
            (string)($meta['description'] ?? ''),
            $body,
            $meta['enabled'] ?? true,
            $meta
        );
    }

    public static function exportMarkdown(int $id): ?string
    {
        $skill = self::get($id);
        if (!$skill) {
            return null;
        }

        $escape = static function (string $value): string {
            return str_replace(array('\\', '"', "\n"), array('\\\\', '\\"', ' '), $value);
        };

        return "---\n"
            . 'name: "' . $escape($skill['name']) . "\"\n"
            . 'description: "' . $escape($skill['description']) . "\"\n"
            . 'tags: "' . $escape($skill['tags']) . "\"\n"
            . 'when_to_use: "' . $escape($skill['when_to_use']) . "\"\n"
            . 'priority: ' . (int)$skill['priority'] . "\n"
            . 'enabled: ' . ($skill['enabled'] ? 'true' : 'false') . "\n"
            . "---\n\n"
            . $skill['instructions'] . "\n";
    }

    /** Catalog for the system prompt: names, descriptions and triggers only. */
    public static function promptText(): string
    {
        $skills = self::catalog(30);
        if ($skills === array()) {
            return '';
        }

        $lines = array(
            'SKILLS (catalog; instructions are not preloaded):',
            'If the task matches a skill below, call use_skill with its exact name and follow the returned instructions. Without that call the instructions are unknown.',
        );
        $budget = 3000;

        foreach ($skills as $skill) {
            $line = '• ' . $skill['name']
                . ($skill['description'] !== '' ? ' — ' . $skill['description'] : '')
                . ($skill['when_to_use'] !== '' ? ' [when: ' . $skill['when_to_use'] . ']' : '')
                . ($skill['tags'] !== '' ? ' [tags: ' . $skill['tags'] . ']' : '');

            if (mb_strlen($line) > $budget) {
                $line = mb_substr($line, 0, max(0, $budget)) . '…';
            }

            $lines[] = $line;
            $budget -= mb_strlen($line);
            if ($budget <= 0) {
                break;
            }
        }

        $lines[] = 'Create or edit skills only on an explicit user request. If the user corrects the same thing repeatedly, offer to save a skill, but never create one on your own.';

        return implode("\n", $lines);
    }

    private static function resolve(string $query, bool $includeDeleted = false): ?array
    {
        self::ensureSchema();

        $query = trim($query);
        if ($query === '') {
            return null;
        }

        $row = null;

        if (ctype_digit($query)) {
            $row = SkillTable::getById((int)$query)->fetch();
        }

        if (!$row) {
            $filter = array('=NAME' => $query);
            if (!$includeDeleted) {
                $filter['=DELETED_AT'] = false;
            }

            $row = SkillTable::getList(array(
                'select' => self::FIELDS,
                'filter' => $filter,
                'limit' => 1,
            ))->fetch();
        }

        if (!$row) {
            return null;
        }

        if (!$includeDeleted && !empty($row['DELETED_AT'])) {
            return null;
        }

        return $row;
    }

    private static function snapshot(array $row): void
    {
        try {
            SkillVersionTable::add(array(
                'SKILL_ID' => (int)$row['ID'],
                'NAME' => (string)$row['NAME'],
                'DESCRIPTION' => (string)($row['DESCRIPTION'] ?? ''),
                'INSTRUCTIONS' => (string)($row['INSTRUCTIONS'] ?? ''),
                'ENABLED' => (string)($row['ENABLED'] ?? 'Y'),
                'TAGS' => $row['TAGS'] !== null && $row['TAGS'] !== '' ? (string)$row['TAGS'] : null,
                'WHEN_TO_USE' => $row['WHEN_TO_USE'] !== null && $row['WHEN_TO_USE'] !== '' ? (string)$row['WHEN_TO_USE'] : null,
                'PRIORITY' => (int)($row['PRIORITY'] ?? 100),
                'CREATED_BY' => (int)($row['CREATED_BY'] ?? 0),
                'SOURCE' => (string)($row['SOURCE'] ?? 'chat'),
                'CHAT_ID' => (int)($row['CHAT_ID'] ?? 0),
                'CREATED_AT' => new DateTime(),
            ));
        } catch (\Throwable $e) {
            Logger::error('Skill version snapshot failed: ' . $e->getMessage());
        }
    }

    private static function metaString(array $meta, string $key, $existing, string $column, int $max, string $default = ''): string
    {
        if (array_key_exists($key, $meta)) {
            $value = trim((string)$meta[$key]);
        } elseif ($existing) {
            $value = trim((string)($existing[$column] ?? ''));
        } else {
            $value = $default;
        }

        return mb_substr($value, 0, $max);
    }

    private static function priority(array $meta, $existing): int
    {
        if (array_key_exists('priority', $meta)) {
            $value = (int)$meta['priority'];
        } elseif ($existing) {
            $value = (int)($existing['PRIORITY'] ?? 100);
        } else {
            $value = 100;
        }

        return max(0, min(self::MAX_PRIORITY, $value));
    }

    private static function mapRow(array $row): array
    {
        return array(
            'id' => (int)($row['ID'] ?? 0),
            'name' => (string)($row['NAME'] ?? ''),
            'description' => (string)($row['DESCRIPTION'] ?? ''),
            'instructions' => (string)($row['INSTRUCTIONS'] ?? ''),
            'enabled' => (string)($row['ENABLED'] ?? 'Y') === 'Y',
            'tags' => (string)($row['TAGS'] ?? ''),
            'when_to_use' => (string)($row['WHEN_TO_USE'] ?? ''),
            'priority' => (int)($row['PRIORITY'] ?? 100),
            'created_by' => (int)($row['CREATED_BY'] ?? 0),
            'source' => (string)($row['SOURCE'] ?? ''),
            'chat_id' => (int)($row['CHAT_ID'] ?? 0),
            'use_count' => (int)($row['USE_COUNT'] ?? 0),
            'last_used_at' => (string)($row['LAST_USED_AT'] ?? ''),
            'deleted_at' => (string)($row['DELETED_AT'] ?? ''),
            'created_at' => (string)($row['CREATED_AT'] ?? ''),
            'updated_at' => (string)($row['UPDATED_AT'] ?? ''),
        );
    }

    private static function invalidate(): void
    {
        self::$cache = array();
    }
}
