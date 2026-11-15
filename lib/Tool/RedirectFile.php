<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\Logger;

/**
 * Чтение и запись urlrewrite.php.
 *
 * Файл — обычный PHP-массив правил; включаем его в изолированном скоупе и
 * переписываем целиком, сохраняя исходный порядок и поля.
 */
final class RedirectFile
{
    public static function path(): string
    {
        return rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') . '/urlrewrite.php';
    }

    /** @return array<int,array> */
    public static function read(): array
    {
        $file = self::path();
        if (!is_file($file)) {
            return array();
        }

        try {
            // Bitrix хранит правила в $arUrlRewrite; на всякий случай понимаем и return.
            $rules = (static function (string $path) {
                $arUrlRewrite = array();
                $returned = include $path;

                return is_array($returned) ? $returned : $arUrlRewrite;
            })($file);
        } catch (\Throwable $e) {
            Logger::error('RedirectFile: не удалось прочитать urlrewrite.php: ' . $e->getMessage());

            return array();
        }

        return is_array($rules) ? $rules : array();
    }

    /**
     * Системные маршруты, без которых сайт теряет REST API.
     * Их нельзя удалить инструментом правки правил.
     */
    private const PROTECTED = array('/rest/');

    /** Системное правило REST: без него не работают REST-вызовы сайта. */
    private const PROTECTED_RULES = array(
        array(
            'CONDITION' => '#^/rest/#',
            'RULE' => '',
            'ID' => null,
            'PATH' => '/bitrix/services/rest/index.php',
            'SORT' => 100,
        ),
    );

    /**
     * Записывает правила, сохраняя системные маршруты и резервную копию.
     *
     * @param array<int,array> $rules
     */
    public static function write(array $rules): void
    {
        $rules = self::withProtected(self::read(), $rules);

        $file = self::path();
        $temp = $file . '.tmp';

        $body = "<?php\n"
            . "/**\n"
            . " * Маршруты сайта. Файл читает /bitrix/modules/main/include/urlrewrite.php.\n"
            . " *\n"
            . " * Правило /rest/ обязательно: через него работает REST API сайта.\n"
            . " */\n"
            . "\$arUrlRewrite = " . self::export($rules) . ";\n";

        // Копия перед изменением: файл критичен для сайта.
        if (is_file($file)) {
            @copy($file, $file . '.bak');
        }

        if (@file_put_contents($temp, $body) === false || !@rename($temp, $file)) {
            @unlink($temp);

            throw new \RuntimeException('Не удалось записать urlrewrite.php.');
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }

    /**
     * Возвращает новый список правил с системными маршрутами из текущего файла.
     *
     * @param array<int,array> $current
     * @param array<int,array> $next
     * @return array<int,array>
     */
    private static function withProtected(array $current, array $next): array
    {
        foreach ($current as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $condition = (string)($rule['CONDITION'] ?? '');
            $isProtected = false;

            foreach (self::PROTECTED as $pattern) {
                if (str_contains($condition, $pattern)) {
                    $isProtected = true;
                    break;
                }
            }

            if (!$isProtected) {
                continue;
            }

            $alreadyThere = false;
            foreach ($next as $candidate) {
                if (is_array($candidate) && (string)($candidate['CONDITION'] ?? '') === $condition) {
                    $alreadyThere = true;
                    break;
                }
            }

            if (!$alreadyThere) {
                $next[] = $rule;
            }
        }

        // Даже если правило потерялось раньше — возвращаем системные маршруты.
        foreach (self::PROTECTED_RULES as $rule) {
            $condition = (string)$rule['CONDITION'];
            $alreadyThere = false;

            foreach ($next as $candidate) {
                if (is_array($candidate) && (string)($candidate['CONDITION'] ?? '') === $condition) {
                    $alreadyThere = true;
                    break;
                }
            }

            if (!$alreadyThere) {
                $next[] = $rule;
            }
        }

        return $next;
    }

    private static function export(array $rules): string
    {
        $lines = array('array(');

        foreach ($rules as $index => $rule) {
            $lines[] = '    ' . var_export($index, true) . ' => array(';
            foreach ((array)$rule as $key => $value) {
                $lines[] = '        ' . var_export((string)$key, true) . ' => ' . var_export($value, true) . ',';
            }
            $lines[] = '    ),';
        }

        $lines[] = ')';

        return implode("\n", $lines);
    }
}
