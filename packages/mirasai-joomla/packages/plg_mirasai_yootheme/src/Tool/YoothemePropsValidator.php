<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

/**
 * Checks prop values against the installed YOOtheme element definition.
 *
 * A `select` prop set to a value the element does not offer is written without
 * complaint and only shows up later as a red border in the Builder. It is not
 * inert either: the section simply loses the padding, spacing or style it was
 * supposed to have, so the page is wrong until somebody opens the editor and
 * notices.
 *
 * Only enumerable choices are judged. A field whose options cannot be listed,
 * a prop the element does not declare, and any non-scalar value — dynamic
 * source bindings arrive as objects — are all left alone. The point is to
 * catch the typo, not to become a second opinion on what YOOtheme accepts.
 */
final class YoothemePropsValidator
{
    private const ENUMERABLE_FIELD_TYPES = ['select', 'radio'];

    /**
     * @param array<string, mixed> $fields raw `fields` from the element definition
     * @param array<string, mixed> $props
     * @return array<string, mixed>|null null when every value is acceptable
     */
    public static function validate(string $elementType, array $fields, array $props): ?array
    {
        $issues = [];

        foreach ($props as $name => $value) {
            $allowed = self::allowedValues($fields[$name] ?? null);

            if ($allowed === null || !is_scalar($value)) {
                continue;
            }

            if (in_array(self::stringify($value), $allowed, true)) {
                continue;
            }

            $issues[] = [
                'code' => 'invalid_prop_value',
                'prop' => (string) $name,
                'value' => $value,
                'message' => sprintf(
                    '%s is not an accepted value for %s.%s; the element offers: %s.',
                    self::describe($value),
                    $elementType,
                    (string) $name,
                    implode(', ', array_map(
                        static fn (string $option): string => $option === '' ? '"" (default)' : $option,
                        $allowed
                    ))
                ),
                'accepted_values' => $allowed,
            ];
        }

        if ($issues === []) {
            return null;
        }

        return [
            'error' => sprintf(
                '%s rejected %d prop value(s): %s',
                $elementType,
                count($issues),
                implode(' ', array_column($issues, 'message'))
            ),
            'code' => 'invalid_prop_value',
            'element_type' => $elementType,
            'issues' => $issues,
            'action_required' => 'Nothing was written. Use template/element-schema to see the accepted values.',
        ];
    }

    /**
     * The values a field offers, or null when they cannot be enumerated.
     *
     * YOOtheme writes options as a label => value map. Anything else — a
     * service reference, a nested group, a callable — is not something this
     * class is willing to guess at.
     *
     * @param mixed $field
     * @return list<string>|null
     */
    private static function allowedValues($field): ?array
    {
        if (!is_array($field)) {
            return null;
        }

        $type = is_string($field['type'] ?? null) ? $field['type'] : '';

        if (!in_array($type, self::ENUMERABLE_FIELD_TYPES, true)) {
            return null;
        }

        $options = $field['options'] ?? null;

        if (!is_array($options) || $options === []) {
            return null;
        }

        $values = [];

        foreach ($options as $option) {
            if (!is_scalar($option)) {
                return null;
            }

            $values[] = self::stringify($option);
        }

        return array_values(array_unique($values));
    }

    /**
     * @param mixed $value
     */
    private static function stringify($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /**
     * @param mixed $value
     */
    private static function describe($value): string
    {
        $rendered = self::stringify($value);

        return $rendered === '' ? '""' : '"' . $rendered . '"';
    }
}
