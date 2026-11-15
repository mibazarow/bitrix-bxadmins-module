<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

/**
 * Minimal JSON Schema validator for tool arguments.
 *
 * Checks required fields, primitive types, enums and numeric bounds. Numeric
 * strings are accepted for integer/number fields because models sometimes
 * serialize IDs as strings; the value is not mutated.
 */
final class ToolSchema
{
    /** @return string[] human-readable validation errors */
    public static function validate(array $schema, array $args): array
    {
        $errors = array();
        self::checkObject($schema, $args, '', $errors);

        return $errors;
    }

    private static function checkObject(array $schema, array $args, string $path, array &$errors): void
    {
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : array();
        foreach ($required as $name) {
            if (is_string($name) && !array_key_exists($name, $args)) {
                $errors[] = self::label($path, $name) . ' is required';
            }
        }

        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : array();
        $allowExtra = ($schema['additionalProperties'] ?? true) !== false;

        foreach ($args as $name => $value) {
            if (!is_string($name)) {
                continue;
            }

            if (!isset($properties[$name])) {
                if (!$allowExtra) {
                    $errors[] = self::label($path, $name) . ' is not allowed';
                }

                continue;
            }

            if (is_array($properties[$name])) {
                self::checkValue($properties[$name], $value, self::label($path, $name), $errors);
            }
        }
    }

    private static function checkValue(array $schema, $value, string $label, array &$errors): void
    {
        $type = $schema['type'] ?? '';

        if (is_array($type)) {
            $ok = false;
            foreach ($type as $candidate) {
                if (is_string($candidate) && self::matchesType($candidate, $value)) {
                    $ok = true;
                    break;
                }
            }

            if (!$ok) {
                $errors[] = $label . ' must be one of: ' . implode(', ', array_map('strval', $type));

                return;
            }
        } elseif (is_string($type) && $type !== '' && !self::matchesType($type, $value)) {
            $errors[] = $label . ' must be ' . $type;

            return;
        }

        $objectType = $type === 'object' || (is_array($type) && in_array('object', $type, true));
        $arrayType = $type === 'array' || (is_array($type) && in_array('array', $type, true));

        if (isset($schema['enum']) && is_array($schema['enum'])) {
            $allowed = array_map('strval', $schema['enum']);
            if (!in_array((string)$value, $allowed, true)) {
                $errors[] = $label . ' must be one of: ' . implode(', ', $allowed);
            }
        }

        if (is_numeric($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = $label . ' must be >= ' . $schema['minimum'];
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = $label . ' must be <= ' . $schema['maximum'];
            }
        }

        if (is_string($value)) {
            if (isset($schema['minLength']) && mb_strlen($value) < (int)$schema['minLength']) {
                $errors[] = $label . ' is too short';
            }
            if (isset($schema['maxLength']) && mb_strlen($value) > (int)$schema['maxLength']) {
                $errors[] = $label . ' is too long';
            }
        }

        if ($objectType && is_array($value)) {
            self::checkObject($schema, $value, $label, $errors);
        }

        if ($arrayType && is_array($value) && is_array($schema['items'] ?? null)) {
            foreach ($value as $index => $item) {
                self::checkValue($schema['items'], $item, $label . '[' . $index . ']', $errors);
            }
        }
    }

    private static function matchesType(string $type, $value): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value) || (is_string($value) && ctype_digit(ltrim($value, '-'))),
            'number' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)),
            'boolean' => is_bool($value) || in_array($value, array(0, 1, '0', '1', 'true', 'false'), true),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value),
            'null' => $value === null,
            default => true,
        };
    }

    private static function label(string $path, string $name): string
    {
        return $path !== '' ? $path . '.' . $name : $name;
    }
}
