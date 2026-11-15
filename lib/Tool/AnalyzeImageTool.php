<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\ImageAnalyzer;

/** Смотрит на картинку активной моделью (vision): URL, файл чата или картинка элемента. */
class AnalyzeImageTool extends AbstractTool
{
    public const DOMAIN = 'content';

    public static function getName(): string
    {
        return 'analyze_image';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'analyze_image',
            'description' => 'Смотрит на изображение и отвечает, что на нём: ссылка http(s), файл из чата (id) или картинка элемента (element_id). Используй, когда просят посмотреть на фото товара/новости, проверить картинку или понять, что изображено — вместо скриншота всей страницы.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'source' => array('type' => 'string', 'description' => 'URL картинки или id файла из чата.'),
                    'element_id' => array('type' => 'integer', 'description' => 'ID элемента, у которого нужно посмотреть картинку.'),
                    'question' => array('type' => 'string', 'description' => 'Что именно проверить/описать.'),
                ),
            ),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        return ImageAnalyzer::analyze($args);
    }
}
