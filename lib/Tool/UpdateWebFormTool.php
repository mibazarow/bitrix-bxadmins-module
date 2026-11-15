<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;

/** Правка веб-формы: название, описание, активность. */
class UpdateWebFormTool extends AbstractChangeTool
{
    public const DOMAIN = 'content';

    public static function getName(): string
    {
        return 'update_web_form';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'update_web_form',
            'description' => 'Меняет веб-форму: название, описание, активность. Список — list_forms.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'form_id' => array('type' => 'integer', 'description' => 'ID формы'),
                    'name' => array('type' => 'string', 'description' => 'Новое название'),
                    'description' => array('type' => 'string', 'description' => 'Новое описание'),
                    'active' => array('type' => 'boolean', 'description' => 'Активность'),
                ),
                'required' => array('form_id'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $formId = (int)($args['form_id'] ?? 0);
        if ($formId <= 0) {
            throw new \InvalidArgumentException('Параметр form_id обязателен.');
        }

        if (!Loader::includeModule('form')) {
            throw new \RuntimeException('Модуль form не установлен.');
        }

        $form = \CForm::GetByID($formId)->Fetch();
        if (!$form) {
            throw new \InvalidArgumentException('Форма #' . $formId . ' не найдена.');
        }

        $fields = array();
        $lines = array();

        if (array_key_exists('name', $args) && trim((string)$args['name']) !== ''
            && trim((string)$args['name']) !== (string)$form['NAME']) {
            $fields['NAME'] = trim((string)$args['name']);
            $lines[] = 'Название: ' . $form['NAME'] . ' → ' . $fields['NAME'];
        }

        if (array_key_exists('description', $args)) {
            $description = trim((string)$args['description']);
            if ($description !== (string)($form['DESCRIPTION'] ?? '')) {
                $fields['DESCRIPTION'] = $description;
                $lines[] = 'Описание обновлено';
            }
        }

        if (array_key_exists('active', $args)) {
            $active = ((bool)$args['active']) ? 'Y' : 'N';
            if ($active !== (string)$form['ACTIVE']) {
                $fields['ACTIVE'] = $active;
                $lines[] = $active === 'Y' ? 'Форма включена' : 'Форма выключена';
            }
        }

        if ($fields === array()) {
            throw new \InvalidArgumentException('Изменений нет: переданные значения совпадают с текущими.');
        }

        return array(
            'summary' => 'Форма «' . $form['NAME'] . '»: ' . implode(', ', array_keys($fields)),
            'payload' => array(
                'args' => array('form_id' => $formId, 'fields' => $fields),
                'before' => array(
                    'NAME' => (string)$form['NAME'],
                    'DESCRIPTION' => (string)($form['DESCRIPTION'] ?? ''),
                    'ACTIVE' => (string)$form['ACTIVE'],
                ),
            ),
            'preview' => 'Форма «' . $form['NAME'] . "\"\n" . implode("\n", $lines),
        );
    }

    public static function apply(array $payload): array
    {
        $formId = (int)($payload['args']['form_id'] ?? 0);
        $fields = (array)($payload['args']['fields'] ?? array());

        $result = \CForm::Set($fields, $formId, 'N');
        if ((int)$result !== $formId) {
            throw new \RuntimeException('Не удалось изменить форму #' . $formId . '.');
        }

        return array('form_id' => $formId, 'updated' => array_keys($fields));
    }

    public static function rollback(array $payload): void
    {
        $formId = (int)($payload['args']['form_id'] ?? 0);
        $before = (array)($payload['before'] ?? array());

        if ($formId > 0 && $before !== array()) {
            \CForm::Set($before, $formId, 'N');
        }
    }
}
