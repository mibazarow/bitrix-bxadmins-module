<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

/**
 * ORM-таблица базы знаний: фрагменты контента и их эмбеддинги.
 */
class IndexTable extends DataManager
{
    use DeleteByFilterTrait;

    public static function getTableName()
    {
        return 'b_mibazarow_bxadmins_index';
    }

    public static function getMap()
    {
        return array(
            (new IntegerField('ID'))
                ->configurePrimary()
                ->configureAutocomplete(),
            (new StringField('SOURCE_TYPE'))
                ->configureRequired(),
            (new StringField('SOURCE_ID'))
                ->configureRequired(),
            (new StringField('TITLE')),
            (new TextField('CONTENT'))
                ->configureRequired(),
            // float32-вектор, упакованный pack('g*'): ~1 КБ вместо ~5 КБ JSON.
            (new StringField('EMBEDDING'))
                ->configureBinary(true),
            (new IntegerField('DIM'))
                ->configureRequired()
                ->configureDefaultValue(0),
            (new StringField('HASH'))
                ->configureRequired(),
            (new DatetimeField('UPDATED_AT'))
                ->configureRequired(),
        );
    }
}
