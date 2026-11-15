<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\SiteTemplateTable;

/**
 * Новый шаблон сайта: каталог в /local/templates с минимальным каркасом.
 *
 * Создаётся рабочая заготовка (шапка, подвал, стили, описание), которую агент
 * дальше наполняет через правку файлов. Подключение к сайту — отдельный шаг,
 * чтобы шаблон можно было сначала посмотреть.
 */
class CreateSiteTemplateTool extends AbstractChangeTool
{
    public const DOMAIN = 'design';

    public static function getName(): string
    {
        return 'create_site_template';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'create_site_template',
            'description' => 'Создаёт шаблон сайта в /local/templates: header.php, footer.php, styles.css, .description.php. Можно сразу подключить к сайту (attach=true).',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'code' => array('type' => 'string', 'description' => 'Код шаблона латиницей: my_shop'),
                    'name' => array('type' => 'string', 'description' => 'Название шаблона'),
                    'attach' => array('type' => 'boolean', 'description' => 'Подключить к текущему сайту'),
                    'accent' => array('type' => 'string', 'description' => 'Основной цвет, например #2f6bad'),
                ),
                'required' => array('code', 'name'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $code = self::code((string)($args['code'] ?? ''));
        $name = trim((string)($args['name'] ?? ''));

        if ($name === '') {
            throw new \InvalidArgumentException('Нужно название шаблона.');
        }

        $dir = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') . '/local/templates/' . $code;
        if (is_dir($dir)) {
            throw new \InvalidArgumentException('Шаблон ' . $code . ' уже существует.');
        }

        $attach = ($args['attach'] ?? false) === true;
        $siteId = self::siteId();

        $lines = array('Шаблон ' . $code . ' — «' . $name . '»');
        $lines[] = 'Файлы: header.php, footer.php, styles.css, .description.php';

        if ($attach) {
            $lines[] = 'Будет подключён к сайту ' . $siteId . ' вместо текущего шаблона.';
        }

        return array(
            'summary' => 'Шаблон сайта «' . $name . '»',
            'payload' => array(
                'args' => array(
                    'code' => $code,
                    'name' => mb_substr($name, 0, 100),
                    'attach' => $attach,
                    'site_id' => $siteId,
                    'accent' => self::accent((string)($args['accent'] ?? '')),
                ),
            ),
            'preview' => implode("\n", $lines),
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());
        $code = (string)$args['code'];
        $dir = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') . '/local/templates/' . $code;

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать каталог шаблона.');
        }

        $files = self::files((string)$args['name'], (string)$args['accent']);

        foreach ($files as $name => $content) {
            if (@file_put_contents($dir . '/' . $name, $content) === false) {
                throw new \RuntimeException('Не удалось записать файл шаблона ' . $name . '.');
            }
        }

        $attached = false;

        if (!empty($args['attach'])) {
            $attached = self::attach($code, (string)$args['site_id']);
        }

        return array('code' => $code, 'path' => '/local/templates/' . $code, 'attached' => $attached);
    }

    public static function rollback(array $payload): void
    {
        $code = (string)($payload['result']['code'] ?? '');
        if ($code === '') {
            return;
        }

        // Снимаем подключение, если включали, и удаляем каталог целиком.
        self::detach($code);

        $dir = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') . '/local/templates/' . $code;
        if (is_dir($dir)) {
            self::removeDir($dir);
        }
    }

    /** @return array<string,string> имя файла => содержимое */
    private static function files(string $name, string $accent): array
    {
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeAccent = htmlspecialchars($accent, ENT_QUOTES, 'UTF-8');

        $description = "<?php\n"
            . "if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();\n\n"
            . "\$arTemplate = array(\n"
            . "    'NAME' => '" . addslashes($name) . "',\n"
            . "    'DESCRIPTION' => 'Шаблон создан BXADMIN-S',\n"
            . "    'SORT' => 100,\n"
            . "    'TYPE' => '',\n"
            . ");\n";

        $header = "<?php\n"
            . "if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();\n"
            . "?><!DOCTYPE html>\n"
            . "<html lang=\"ru\">\n"
            . "<head>\n"
            . "    <meta charset=\"utf-8\">\n"
            . "    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
            . "    <title><?php \$APPLICATION->ShowTitle(); ?></title>\n"
            . "    <?php \$APPLICATION->ShowHead(); ?>\n"
            . "    <link rel=\"stylesheet\" href=\"<?= SITE_TEMPLATE_PATH ?>/styles.css\">\n"
            . "</head>\n"
            . "<body>\n"
            . "<header class=\"site-header\">\n"
            . "    <a class=\"site-logo\" href=\"/\">" . $safeName . "</a>\n"
            . "    <?php \$APPLICATION->IncludeComponent('bitrix:menu', '', array(\n"
            . "        'ROOT_MENU_TYPE' => 'top',\n"
            . "        'MAX_LEVEL' => 2,\n"
            . "        'MENU_CACHE_TYPE' => 'A',\n"
            . "    )); ?>\n"
            . "</header>\n"
            . "<main class=\"site-main\">\n";

        $footer = "<?php if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die(); ?>\n"
            . "</main>\n"
            . "<footer class=\"site-footer\">\n"
            . "    <span>&copy; <?= date('Y') ?> " . $safeName . "</span>\n"
            . "</footer>\n"
            . "</body>\n"
            . "</html>\n";

        $styles = "/* Шаблон " . $name . " */\n"
            . ":root { --accent: " . $safeAccent . "; }\n"
            . "* { box-sizing: border-box; }\n"
            . "body { margin: 0; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;\n"
            . "       color: #22272e; line-height: 1.5; }\n"
            . "a { color: var(--accent); }\n"
            . ".site-header { display: flex; align-items: center; gap: 24px;\n"
            . "               padding: 16px 24px; border-bottom: 1px solid #e6e9ee; }\n"
            . ".site-logo { font-weight: 700; font-size: 18px; text-decoration: none; color: inherit; }\n"
            . ".site-main { max-width: 1140px; margin: 0 auto; padding: 24px; }\n"
            . ".site-footer { max-width: 1140px; margin: 40px auto 0; padding: 20px 24px;\n"
            . "               border-top: 1px solid #e6e9ee; color: #8a949f; font-size: 13px; }\n";

        return array(
            '.description.php' => $description,
            'header.php' => $header,
            'footer.php' => $footer,
            'styles.css' => $styles,
        );
    }

    private static function attach(string $code, string $siteId): bool
    {
        $exists = SiteTemplateTable::getList(array(
            'select' => array('ID'),
            'filter' => array('=SITE_ID' => $siteId, '=TEMPLATE' => $code),
            'limit' => 1,
        ))->fetch();

        if ($exists) {
            return true;
        }

        // Шаблон ставится первым в списке сайта — он и станет активным.
        $result = SiteTemplateTable::add(array(
            'SITE_ID' => $siteId,
            'TEMPLATE' => $code,
            'SORT' => 1,
            'CONDITION' => '',
        ));

        return $result->isSuccess();
    }

    private static function detach(string $code): void
    {
        $rows = SiteTemplateTable::getList(array(
            'select' => array('ID'),
            'filter' => array('=TEMPLATE' => $code),
        ))->fetchAll();

        foreach ($rows as $row) {
            SiteTemplateTable::delete((int)$row['ID']);
        }
    }

    private static function removeDir(string $dir): void
    {
        $entries = @scandir($dir);
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (is_dir($path)) {
                self::removeDir($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($dir);
    }

    private static function code(string $code): string
    {
        $code = strtolower(trim($code));
        $code = (string)preg_replace('/[^a-z0-9_]+/', '_', $code);
        $code = trim($code, '_');

        if ($code === '' || preg_match('/^[a-z][a-z0-9_]{1,30}$/', $code) !== 1) {
            throw new \InvalidArgumentException('Код шаблона — строчная латиница, цифры и подчёркивание.');
        }

        return $code;
    }

    private static function accent(string $accent): string
    {
        return preg_match('/^#[0-9a-f]{3,8}$/i', trim($accent)) === 1 ? trim($accent) : '#2f6bad';
    }

    private static function siteId(): string
    {
        if (defined('SITE_ID') && (string)SITE_ID !== '') {
            return (string)SITE_ID;
        }

        $row = \Bitrix\Main\SiteTable::getList(array(
            'select' => array('LID'),
            'filter' => array('=ACTIVE' => 'Y'),
            'order' => array('DEF' => 'DESC', 'SORT' => 'ASC'),
            'limit' => 1,
        ))->fetch();

        return (string)($row['LID'] ?? 's1');
    }
}
