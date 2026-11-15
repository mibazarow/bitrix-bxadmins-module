<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Bizproc\WorkflowTemplateTable;
use Bitrix\Main\Loader;

/** Шаблоны бизнес-процессов. */
class ListBizprocTemplatesTool extends AbstractTool
{
    public const DOMAIN = 'automation';

    private const LIMIT = 100;

    public static function getName(): string
    {
        return 'list_bizproc_templates';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_bizproc_templates',
            'description' => 'Шаблоны бизнес-процессов: ID, название, модуль, документ, активность. Максимум 100.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'module_id' => array('type' => 'string', 'description' => 'Фильтр по модулю, например iblock'),
                ),
            ),
        );
    }

    /** @return array{count:int,truncated:bool,templates:array}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('bizproc')) {
            return array('error' => 'Модуль bizproc недоступен.');
        }

        $filter = array();
        if (trim((string)($args['module_id'] ?? '')) !== '') {
            $filter['=MODULE_ID'] = trim((string)$args['module_id']);
        }

        $rows = WorkflowTemplateTable::getList(array(
            'select' => array('ID', 'NAME', 'MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE', 'ACTIVE', 'AUTO_EXECUTE'),
            'filter' => $filter,
            'order' => array('ID' => 'DESC'),
            'limit' => self::LIMIT + 1,
        ))->fetchAll();

        $truncated = count($rows) > self::LIMIT;
        $templates = array();

        foreach (array_slice($rows, 0, self::LIMIT) as $row) {
            $templates[] = array(
                'id' => (int)$row['ID'],
                'name' => (string)$row['NAME'],
                'module_id' => (string)$row['MODULE_ID'],
                'entity' => (string)$row['ENTITY'],
                'document_type' => (string)$row['DOCUMENT_TYPE'],
                'active' => (string)$row['ACTIVE'] === 'Y',
                'auto_execute' => (int)$row['AUTO_EXECUTE'],
            );
        }

        return array('count' => count($templates), 'truncated' => $truncated, 'templates' => $templates);
    }
}
