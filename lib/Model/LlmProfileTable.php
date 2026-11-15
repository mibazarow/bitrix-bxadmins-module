<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

/**
 * Сохранённые подключения к провайдерам. Активно ровно одно (ACTIVE = 'Y').
 */
class LlmProfileTable extends DataManager
{
    use DeleteByFilterTrait;

    public static function getTableName()
    {
        return 'b_mibazarow_bxadmins_llm_profile';
    }

    public static function getMap()
    {
        return array(
            (new IntegerField('ID'))
                ->configurePrimary()
                ->configureAutocomplete(),
            (new StringField('NAME'))
                ->configureRequired(),
            (new StringField('ENDPOINT'))
                ->configureRequired(),
            (new StringField('API_KEY'))
                ->configureDefaultValue(''),
            (new StringField('MODEL'))
                ->configureRequired(),
            (new StringField('REASONING'))
                ->configureDefaultValue('medium'),
            (new IntegerField('CONTEXT_WINDOW'))
                ->configureDefaultValue(240000),
            (new StringField('ACTIVE'))
                ->configureDefaultValue('N'),
            (new DatetimeField('CREATED_AT'))
                ->configureRequired(),
            (new DatetimeField('UPDATED_AT'))
                ->configureRequired(),
        );
    }
}
