<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\Loader;

/** Удаление highload-блока вместе с таблицей и полями. */
class DeleteHighloadBlockTool extends AbstractChangeTool
{
    public const DOMAIN = 'content';

    public static function getName(): string
    {
        return 'delete_highload_block';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'delete_highload_block',
            'description' => 'Удаляет highload-блок: таблицу, поля и все записи. Откат — восстановлением сайта.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'block_id' => array('type' => 'integer', 'description' => 'ID блока (list_highload_blocks)'),
                ),
                'required' => array('block_id'),
            ),
        );
    }

    public static function rollbackNeedsRestore(): bool
    {
        // Таблица с данными удаляется безвозвратно — только полный откат сайта.
        return true;
    }

    public static function preview(array $args): array
    {
        $blockId = (int)($args['block_id'] ?? 0);
        if ($blockId <= 0) {
            throw new \InvalidArgumentException('Параметр block_id обязателен.');
        }

        if (!Loader::includeModule('highloadblock')) {
            throw new \RuntimeException('Модуль highloadblock недоступен.');
        }

        $block = HighloadBlockTable::getById($blockId)->fetch();
        if (!$block) {
            throw new \InvalidArgumentException('Блок #' . $blockId . ' не найден.');
        }

        $rows = 0;
        try {
            $entity = HighloadBlockTable::compileEntity($block);
            $rows = (int)$entity->getDataClass()::getCount();
        } catch (\Throwable $e) {
            $rows = 0;
        }

        return array(
            'summary' => 'Удаление highload-блока ' . $block['NAME'],
            'payload' => array('args' => array('block_id' => $blockId)),
            'preview' => 'Блок ' . $block['NAME'] . ' (таблица ' . $block['TABLE_NAME'] . ') будет удалён вместе с '
                . $rows . ' записями.',
        );
    }

    public static function apply(array $payload): array
    {
        $blockId = (int)($payload['args']['block_id'] ?? 0);

        $result = HighloadBlockTable::delete($blockId);
        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }

        return array('block_id' => $blockId, 'deleted' => true);
    }

    public static function rollback(array $payload): void
    {
        // Восстанавливается через restore_site: см. rollbackNeedsRestore().
    }
}
