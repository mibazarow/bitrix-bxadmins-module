<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\ModuleCoverage;

/**
 * Отчёт о покрытии: какие установленные модули управляются инструментами,
 * а какие — ещё нет. Нужен агенту, чтобы честно ответить «что ты не умеешь»
 * и предложить установку недостающего модуля.
 */
class ModuleCoverageTool extends AbstractTool
{
    public const DOMAIN = 'core';

    public static function getName(): string
    {
        return 'module_coverage';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'module_coverage',
            'description' => 'Какие модули сайта покрыты инструментами, а какие нет. Возвращает список модулей без инструментов — по ним можно предложить установку или доработку.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'only_uncovered' => array('type' => 'boolean', 'description' => 'Показать только модули без инструментов'),
                ),
            ),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        $report = ModuleCoverage::report();

        if (!empty($args['only_uncovered'])) {
            $report['modules'] = array_values(array_filter(
                $report['modules'],
                static fn (array $row): bool => $row['status'] === 'none'
            ));
        }

        return $report;
    }
}
