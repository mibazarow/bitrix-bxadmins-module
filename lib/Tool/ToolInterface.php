<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

/**
 * Инструмент, доступный модели. Определение — JSON Schema в формате OpenAI function.
 */
interface ToolInterface
{
    public static function getName(): string;

    /** Домен прав, к которому относится инструмент (ключ из Config::DOMAINS). */
    public static function getDomain(): string;

    /** OpenAI — определение функции для модели: name, description, parameters (JSON Schema). */
    public static function getDefinition(): array;

    /** Результат, сериализуемый в JSON. Контекст: user_id, chat_id, bypass. */
    public static function execute(array $args, array $context = array()): array;
}
