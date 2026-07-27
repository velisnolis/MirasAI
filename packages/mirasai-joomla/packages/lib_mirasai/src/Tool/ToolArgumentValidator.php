<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

/**
 * Rejects arguments a tool does not declare, before the tool runs.
 *
 * Without this, an argument the schema never mentions is silently dropped and
 * the tool still answers `action: updated`. That answer is a lie: nothing was
 * applied. The cost lands on whoever has to work out why a correct-looking
 * call did nothing, and it is usually paid twice, because the first guess is
 * always a caching problem.
 *
 * The validator is deliberately conservative. It only judges what the schema
 * actually declares: a schema with no `properties` and no typed
 * `additionalProperties` cannot tell an unknown argument from an undocumented
 * one, so it is left alone.
 */
final class ToolArgumentValidator
{
    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|null null when the arguments are acceptable
     */
    public static function validate(string $toolName, array $schema, array $arguments): ?array
    {
        $issues = self::inspect($schema, $arguments, '');

        if ($issues === []) {
            return null;
        }

        return [
            'error' => self::summarize($toolName, $issues),
            'code' => self::dominantCode($issues),
            'tool' => $toolName,
            'issues' => $issues,
            'accepted_arguments' => self::declaredNames($schema),
            'action_required' => 'Nothing was applied. Fix the arguments and call the tool again.',
        ];
    }

    /**
     * The envelope code a caller branches on. A call can fail several ways at
     * once; report the one that says the most about what to change, most
     * specific first. Reporting `invalid_argument_value` for a missing
     * required argument is the same kind of imprecision this class exists to
     * remove.
     *
     * @param list<array<string, mixed>> $issues
     */
    private static function dominantCode(array $issues): string
    {
        $codes = array_column($issues, 'code');

        foreach ([
            'unknown_argument',
            'missing_required_argument',
            'invalid_argument_type',
            'invalid_argument_value',
        ] as $candidate) {
            if (in_array($candidate, $codes, true)) {
                return $candidate;
            }
        }

        return 'invalid_argument_value';
    }

    /**
     * @param array<string, mixed> $schema
     * @param mixed $value
     * @return list<array<string, mixed>>
     */
    private static function inspect(array $schema, $value, string $prefix): array
    {
        if (!is_array($value)) {
            return [];
        }

        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $additional = $schema['additionalProperties'] ?? null;
        $issues = [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];

        foreach ($required as $requiredName) {
            $name = (string) $requiredName;

            if ($name === '' || array_key_exists($name, $value)) {
                continue;
            }

            $qualified = $prefix === '' ? $name : $prefix . '.' . $name;
            $issues[] = [
                'code' => 'missing_required_argument',
                'argument' => $qualified,
                'message' => sprintf('%s is required.', $qualified),
            ];
        }

        // An explicit `additionalProperties: true`, or a schema without any
        // declared shape, means the tool accepts keys we cannot enumerate.
        $enforceUnknown = $properties !== [] && $additional !== true;

        foreach ($value as $key => $item) {
            $name = (string) $key;
            $qualified = $prefix === '' ? $name : $prefix . '.' . $name;

            if (isset($properties[$name]) && is_array($properties[$name])) {
                $issues = array_merge($issues, self::inspectValue($properties[$name], $item, $qualified));
                continue;
            }

            if (is_array($additional)) {
                $issues = array_merge($issues, self::inspectValue($additional, $item, $qualified));
                continue;
            }

            if ($enforceUnknown) {
                $issues[] = self::unknownIssue($qualified, $name, array_keys($properties));
            }
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $schema
     * @param mixed $value
     * @return list<array<string, mixed>>
     */
    private static function inspectValue(array $schema, $value, string $qualified): array
    {
        $types = self::declaredTypes($schema);

        if ($types !== [] && !self::matchesAnyType($value, $types)) {
            return [[
                'code' => 'invalid_argument_type',
                'argument' => $qualified,
                'message' => sprintf(
                    '%s must be of type %s.',
                    $qualified,
                    implode(' or ', $types)
                ),
                'accepted_types' => $types,
                'actual_type' => get_debug_type($value),
            ]];
        }

        $enum = is_array($schema['enum'] ?? null) ? $schema['enum'] : null;

        if ($enum !== null && (is_scalar($value) || $value === null) && !in_array($value, $enum, true)) {
            return [[
                'code' => 'invalid_argument_value',
                'argument' => $qualified,
                'message' => sprintf(
                    '%s must be one of: %s.',
                    $qualified,
                    implode(', ', array_map(static fn ($option): string => self::stringify($option), $enum))
                ),
                'accepted_values' => array_values($enum),
            ]];
        }

        if (is_array($schema['items'] ?? null) && is_array($value)) {
            $issues = [];

            foreach ($value as $index => $item) {
                $issues = array_merge(
                    $issues,
                    self::inspectValue($schema['items'], $item, $qualified . '[' . $index . ']')
                );
            }

            return $issues;
        }

        return self::inspect($schema, $value, $qualified);
    }

    /**
     * @param array<string, mixed> $schema
     * @return list<string>
     */
    private static function declaredTypes(array $schema): array
    {
        $type = $schema['type'] ?? null;

        if (is_string($type) && $type !== '') {
            return [$type];
        }

        if (!is_array($type)) {
            return [];
        }

        return array_values(array_filter(
            array_map('strval', $type),
            static fn (string $name): bool => $name !== ''
        ));
    }

    /**
     * @param list<string> $types
     */
    private static function matchesAnyType($value, array $types): bool
    {
        foreach ($types as $type) {
            $matches = match ($type) {
                'array' => is_array($value) && ($value === [] || array_is_list($value)),
                'boolean' => is_bool($value),
                // JSON Schema treats 12.0 as an integer: what matters is the
                // absence of a fractional part, not the transport's numeric
                // type. Clients that emit 12.0 for an id are not wrong.
                'integer' => is_int($value) || (is_float($value) && !is_nan($value) && !is_infinite($value) && floor($value) === $value),
                'null' => $value === null,
                'number' => is_int($value) || is_float($value),
                'object' => is_array($value) && ($value === [] || !array_is_list($value)),
                'string' => is_string($value),
                default => true,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $accepted
     * @return array<string, mixed>
     */
    private static function unknownIssue(string $qualified, string $name, array $accepted): array
    {
        $suggestion = self::closestName($name, $accepted);

        return [
            'code' => 'unknown_argument',
            'argument' => $qualified,
            'message' => $suggestion !== null
                ? sprintf('%s is not an argument of this tool. Did you mean %s?', $qualified, $suggestion)
                : sprintf('%s is not an argument of this tool.', $qualified),
            'accepted_arguments' => array_values($accepted),
        ] + ($suggestion !== null ? ['did_you_mean' => $suggestion] : []);
    }

    /**
     * @param list<string> $candidates
     */
    private static function closestName(string $name, array $candidates): ?string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;
        $budget = max(2, (int) floor(strlen($name) / 3));

        foreach ($candidates as $candidate) {
            $distance = levenshtein($name, (string) $candidate);

            // A shared prefix is a strong signal even when the tails diverge:
            // target_index against target_parent_path is 8 edits apart, but it
            // is obviously the argument the caller was reaching for.
            if (self::sharedPrefixLength($name, (string) $candidate) >= 4) {
                $distance = min($distance, $budget);
            }

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = (string) $candidate;
            }
        }

        return $bestDistance <= $budget ? $best : null;
    }

    private static function sharedPrefixLength(string $left, string $right): int
    {
        $limit = min(strlen($left), strlen($right));

        for ($index = 0; $index < $limit; $index++) {
            if ($left[$index] !== $right[$index]) {
                return $index;
            }
        }

        return $limit;
    }

    /**
     * @param array<string, mixed> $schema
     * @return list<string>
     */
    private static function declaredNames(array $schema): array
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        return array_values(array_map('strval', array_keys($properties)));
    }

    /**
     * @param list<array<string, mixed>> $issues
     */
    private static function summarize(string $toolName, array $issues): string
    {
        $messages = array_map(
            static fn (array $issue): string => (string) $issue['message'],
            array_slice($issues, 0, 5)
        );

        $extra = count($issues) - count($messages);

        return sprintf(
            '%s rejected the call: %s%s',
            $toolName,
            implode(' ', $messages),
            $extra > 0 ? sprintf(' (%d more issue(s).)', $extra) : ''
        );
    }

    /**
     * @param mixed $value
     */
    private static function stringify($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return $value === null ? 'null' : (string) $value;
    }
}
