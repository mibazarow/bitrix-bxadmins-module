<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;

/**
 * Веб-форма с полями (модуль form).
 *
 * Форма создаётся в статусе «по умолчанию», поля — по порядку. Откат удаляет
 * форму вместе с полями: CForm::Delete чистит связанные записи сам.
 */
class CreateWebFormTool extends AbstractChangeTool
{
    public const DOMAIN = 'content';

    /** Типы полей формы => подпись для человека. */
    private const FIELD_TYPES = array(
        'text' => 'Текст',
        'textarea' => 'Многострочный текст',
        'email' => 'E-mail',
        'phone' => 'Телефон',
        'number' => 'Число',
        'date' => 'Дата',
        'dropdown' => 'Выпадающий список',
        'checkbox' => 'Флажок',
        'radio' => 'Переключатель',
        'file' => 'Файл',
        'hidden' => 'Скрытое',
    );

    public static function getName(): string
    {
        return 'create_web_form';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'create_web_form',
            'description' => 'Создаёт веб-форму с полями. Типы: text, textarea, email, phone, number, date, dropdown, checkbox, radio, file, hidden. Заявки потом читаются через get_form_results.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'name' => array('type' => 'string', 'description' => 'Название формы, например «Обратный звонок»'),
                    'sid' => array('type' => 'string', 'description' => 'Символьный код латиницей, по умолчанию из названия'),
                    'description' => array('type' => 'string', 'description' => 'Описание формы'),
                    'fields' => array(
                        'type' => 'array',
                        'description' => 'Поля по порядку: [{"name": "Имя", "type": "text", "required": true}]',
                        'items' => array(
                            'type' => 'object',
                            'properties' => array(
                                'name' => array('type' => 'string'),
                                'type' => array('type' => 'string'),
                                'required' => array('type' => 'boolean'),
                                'values' => array(
                                    'type' => 'array',
                                    'items' => array('type' => 'string'),
                                    'description' => 'Варианты для dropdown, radio, checkbox',
                                ),
                            ),
                            'required' => array('name'),
                        ),
                    ),
                ),
                'required' => array('name', 'fields'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $name = trim((string)($args['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Нужно название формы.');
        }

        if (!Loader::includeModule('form')) {
            throw new \RuntimeException('Модуль form не установлен. Установите его: install_module с module_id=form.');
        }

        $sid = self::sid((string)($args['sid'] ?? ''), $name);

        // GetBySID возвращает CDBResult, а не строку: без Fetch() проверка всегда истинна.
        $existing = \CForm::GetBySID($sid, self::siteId())->Fetch();

        if (is_array($existing) && !empty($existing['ID'])) {
            throw new \InvalidArgumentException('Форма с кодом ' . $sid . ' уже есть.');
        }

        $fields = self::normalizeFields((array)($args['fields'] ?? array()));
        if ($fields === array()) {
            throw new \InvalidArgumentException('Нужно хотя бы одно поле.');
        }

        $lines = array('Форма «' . $name . '» (' . $sid . '), сайт ' . self::siteId());
        foreach ($fields as $field) {
            $lines[] = '· ' . $field['FIELD_TYPE'] . ': ' . $field['TITLE'] . ($field['REQUIRED'] === 'Y' ? ' — обязательное' : '');
        }

        return array(
            'summary' => 'Веб-форма «' . $name . '»',
            'payload' => array(
                'args' => array(
                    'name' => mb_substr($name, 0, 255),
                    'sid' => $sid,
                    'description' => mb_substr(trim((string)($args['description'] ?? '')), 0, 2000),
                    'fields' => $fields,
                    'site_id' => self::siteId(),
                ),
            ),
            'preview' => implode("\n", $lines),
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());

        // CForm::Add — это добавление результата, а не формы. Форма создаётся Set().
        $formId = (int)\CForm::Set(array(
            'NAME' => (string)$args['name'],
            'SID' => (string)$args['sid'],
            'C_SORT' => 500,
            'ACTIVE' => 'Y',
            'DESCRIPTION' => (string)$args['description'],
            'SITE_ID' => array((string)$args['site_id']),
        ), false, 'N');

        if ($formId <= 0) {
            throw new \RuntimeException('Не удалось создать форму: проверьте код ' . (string)$args['sid'] . '.');
        }

        $statusId = self::defaultStatus($formId);
        $fieldIds = array();

        // Поля и варианты создаются через Set(): статических Add у этих классов нет.
        foreach ((array)$args['fields'] as $field) {
            $fieldId = (int)\CFormField::Set(array(
                'FORM_ID' => $formId,
                'ACTIVE' => 'Y',
                'TITLE' => (string)$field['TITLE'],
                'TITLE_TYPE' => 'text',
                'SID' => (string)$field['SID'],
                'C_SORT' => (int)$field['C_SORT'],
                'ADDITIONAL' => 'N',
                'REQUIRED' => (string)$field['REQUIRED'],
                'FIELD_TYPE' => (string)$field['FIELD_TYPE'],
                'MULTIPLE' => 'N',
                'RESULTS_TABLE_TITLE' => (string)$field['TITLE'],
            ), false, 'N');

            if ($fieldId <= 0) {
                continue;
            }

            $fieldIds[] = $fieldId;

            foreach ((array)$field['ANSWERS'] as $index => $answer) {
                \CFormAnswer::Set(array(
                    'FIELD_ID' => $fieldId,
                    'MESSAGE' => (string)$answer,
                    'VALUE' => (string)$answer,
                    'C_SORT' => 100 * ($index + 1),
                    'ACTIVE' => 'Y',
                ), false, 'N');
            }
        }

        return array(
            'form_id' => $formId,
            'sid' => (string)$args['sid'],
            'status_id' => $statusId,
            'field_ids' => $fieldIds,
        );
    }

    public static function rollback(array $payload): void
    {
        $formId = (int)($payload['result']['form_id'] ?? 0);
        if ($formId > 0) {
            // CHECK_RIGHTS=N: откат идёт из CLI, где проверка прав не пройдёт.
            \CForm::Delete($formId, 'N');
        }
    }

    /** @return array<int,array> */
    private static function normalizeFields(array $fields): array
    {
        $normalized = array();
        $sort = 100;
        $used = array();

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $title = trim((string)($field['name'] ?? ''));
            if ($title === '') {
                continue;
            }

            $type = strtolower(trim((string)($field['type'] ?? 'text')));
            if (!isset(self::FIELD_TYPES[$type])) {
                throw new \InvalidArgumentException('Неизвестный тип поля «' . $type . '». Доступны: '
                    . implode(', ', array_keys(self::FIELD_TYPES)));
            }

            $sid = self::fieldSid($title, $used);

            $answers = array();
            if (in_array($type, array('dropdown', 'radio', 'checkbox'), true)) {
                foreach ((array)($field['values'] ?? array()) as $value) {
                    $value = trim((string)$value);
                    if ($value !== '') {
                        $answers[] = $value;
                    }
                }

                if ($answers === array()) {
                    throw new \InvalidArgumentException('Для поля «' . $title . '» нужны варианты выбора.');
                }
            }

            $normalized[] = array(
                'TITLE' => mb_substr($title, 0, 255),
                'SID' => $sid,
                'FIELD_TYPE' => $type,
                'REQUIRED' => !empty($field['required']) ? 'Y' : 'N',
                'C_SORT' => $sort,
                'ANSWERS' => $answers,
            );

            $sort += 100;
        }

        return $normalized;
    }

    private static function fieldSid(string $title, array &$used): string
    {
        $base = (string)\CUtil::translit($title, 'ru', array('replace_space' => '_', 'replace_other' => '_'));
        $base = strtoupper(trim((string)preg_replace('/[^A-Za-z0-9_]+/', '_', $base), '_'));

        if ($base === '') {
            $base = 'FIELD';
        }

        $sid = $base;
        $suffix = 1;

        while (isset($used[$sid])) {
            $suffix++;
            $sid = $base . '_' . $suffix;
        }

        $used[$sid] = true;

        return mb_substr($sid, 0, 50);
    }

    private static function defaultStatus(int $formId): int
    {
        $status = \CFormStatus::GetDefault($formId);
        if (is_array($status) && !empty($status['ID'])) {
            return (int)$status['ID'];
        }

        // Статусы создаются через Set, а не Add.
        $created = (int)\CFormStatus::Set(array(
            'FORM_ID' => $formId,
            'C_SORT' => 100,
            'ACTIVE' => 'Y',
            'TITLE' => 'По умолчанию',
            'DESCRIPTION' => '',
            'DEFAULT_VALUE' => 'Y',
        ), false, 'N');

        return $created;
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

    private static function sid(string $sid, string $name): string
    {
        $sid = trim($sid);

        if ($sid === '') {
            $sid = (string)\CUtil::translit($name, 'ru', array('replace_space' => '_', 'replace_other' => '_'));
        }

        $sid = strtoupper(trim((string)preg_replace('/[^A-Za-z0-9_]+/', '_', $sid), '_'));

        return $sid !== '' ? mb_substr($sid, 0, 50) : 'FORM_' . substr(md5($name), 0, 6);
    }
}
