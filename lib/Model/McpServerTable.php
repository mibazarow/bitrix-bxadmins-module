<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

/** Подключения к MCP-серверам. */
class McpServerTable extends DataManager
{
    use DeleteByFilterTrait;

    public static function getTableName()
    {
        return 'b_mibazarow_bxadmins_mcp_server';
    }

    public static function getMap()
    {
        return array(
            (new IntegerField('ID'))
                ->configurePrimary()
                ->configureAutocomplete(),
            (new StringField('NAME'))
                ->configureRequired(),
            (new StringField('URL'))
                ->configureRequired(),
            (new StringField('TOKEN'))
                ->configureDefaultValue(''),
            (new TextField('HEADERS')),
            (new TextField('TOOLS')),
            (new StringField('SESSION_ID', array('size' => 255))),
            (new DatetimeField('SESSION_AT')),
            (new StringField('PROTOCOL_VERSION', array('size' => 32))),
            (new StringField('LAST_ERROR', array('size' => 500))),
            (new DatetimeField('LAST_CHECKED_AT')),
            (new TextField('DISABLED_TOOLS')),
            (new StringField('ACTIVE'))
                ->configureDefaultValue('Y'),
            (new DatetimeField('CREATED_AT'))
                ->configureRequired(),
            (new DatetimeField('UPDATED_AT'))
                ->configureRequired(),
        );
    }
}
