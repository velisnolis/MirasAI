<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

trait TemplateElementSourceSupportTrait
{
    /**
     * @return array<string, array<string, mixed>>
     */
    private function sourceInputProperties(): array
    {
        return [
            'source' => [
                'type' => 'object',
                'description' => 'Raw YOOtheme source payload to write to the element source binding.',
            ],
            'source_name' => [
                'type' => 'string',
                'description' => 'Source type/name used when source is omitted, for example Post, post, or posts.',
            ],
            'query_field' => [
                'type' => 'string',
                'description' => 'Optional query field name.',
            ],
            'query_arguments' => [
                'type' => 'object',
                'description' => 'Optional query field arguments.',
            ],
            'query_directives' => [
                'type' => 'object',
                'description' => 'Optional query field directives.',
            ],
            'field_mappings' => [
                'type' => 'object',
                'description' => 'Map element prop names to source field names or mapping objects. The native visibility condition is a mapping like any other, under the reserved prop _condition: {"_condition": {"name": "#index", "filters": {"condition": "!!", "show_empty": true}}} hides the element when the query returns nothing.',
                'additionalProperties' => [
                    'type' => ['string', 'object'],
                    'description' => 'A field name, or a mapping object. Date and number formatting goes in filters, not in arguments or directives.',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Source field name.'],
                        'arguments' => ['type' => 'object', 'description' => 'Field arguments passed to the source query.'],
                        'directives' => ['type' => 'object', 'description' => 'GraphQL directives applied to the field.'],
                        'filters' => ['type' => 'object', 'description' => 'Output filters, for example {"date": "d/m/Y"} to format a date.'],
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function summarizeBinding(array $node): array
    {
        $carrier = $this->resolveBindingCarrier($node);

        if ($carrier === null) {
            return [
                'has_binding' => false,
                'canonical_location' => null,
                'source_name' => null,
                'query_field' => null,
                'field_mappings' => [],
                'mapping_count' => 0,
                'raw_source' => null,
            ];
        }

        [$location, $source] = $carrier;
        $query = is_array($source['query'] ?? null) ? $source['query'] : [];
        $queryField = is_array($query['field'] ?? null) ? $query['field'] : [];
        $props = is_array($source['props'] ?? null) ? $source['props'] : [];
        $mappings = [];

        foreach ($props as $propName => $mapping) {
            if (!is_array($mapping)) {
                continue;
            }

            $fieldName = is_string($mapping['name'] ?? null) ? trim((string) $mapping['name']) : '';

            if ($fieldName === '') {
                continue;
            }

            // `filters` carries date and number formatting and is applied at
            // render time, so a summary without it says a binding is plain
            // when it is not. `other_keys` keeps the same omission from
            // happening again the next time YOOtheme adds a key: the reader
            // may not know what it means, but it must not pretend it is absent.
            $mappings[] = [
                'prop' => (string) $propName,
                'field' => $fieldName,
                'arguments' => $this->sanitizeSourceValue($mapping['arguments'] ?? []),
                'directives' => $this->sanitizeSourceValue($mapping['directives'] ?? []),
                'filters' => $this->sanitizeSourceValue($mapping['filters'] ?? []),
                'other_keys' => array_values(array_diff(
                    array_map('strval', array_keys($mapping)),
                    ['name', 'arguments', 'directives', 'filters']
                )),
            ];
        }

        return [
            'has_binding' => true,
            'canonical_location' => $location,
            'source_name' => is_string($query['name'] ?? null) ? (string) $query['name'] : null,
            'query_field' => is_string($queryField['name'] ?? null) ? (string) $queryField['name'] : null,
            'query_shape' => $queryField !== [] ? 'nested' : 'dotted',
            'query_path' => $this->queryPath($query, $queryField),
            // The Builder puts the arguments on the query itself and folds the
            // field into a dotted name; MirasAI's own writer used to nest them
            // under query.field. Reading only the nested carrier reported "no
            // arguments" for every binding made in the Builder, which is all of
            // them in practice — and those arguments are the ones that are hard
            // to get right in the first place.
            'query_arguments' => $this->sanitizeSourceValue($queryField['arguments'] ?? $query['arguments'] ?? []),
            'query_directives' => $this->sanitizeSourceValue($queryField['directives'] ?? $query['directives'] ?? []),
            'field_mappings' => $mappings,
            'mapping_count' => count($mappings),
            'raw_source' => $this->sanitizeSourceValue($source),
        ];
    }

    /**
     * The full dotted path of a binding's query, whichever carrier holds it.
     *
     * `template/source-types` addresses a source by this path, so reporting it
     * turns a binding straight into the call that explains it.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $queryField
     */
    private function queryPath(array $query, array $queryField): ?string
    {
        $name = is_string($query['name'] ?? null) ? trim((string) $query['name']) : '';

        if ($name === '') {
            return null;
        }

        $field = is_string($queryField['name'] ?? null) ? trim((string) $queryField['name']) : '';

        return $field === '' ? $name : $name . '.' . $field;
    }

    /**
     * @param array<string, mixed> $node
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function resolveBindingCarrier(array $node): ?array
    {
        $props = is_array($node['props'] ?? null) ? $node['props'] : [];

        if (is_array($node['source'] ?? null)) {
            return ['source', $node['source']];
        }

        if (is_string($node['source'] ?? null) && trim((string) $node['source']) !== '') {
            return ['source', ['query' => ['name' => trim((string) $node['source'])]]];
        }

        if (is_array($props['source'] ?? null)) {
            return ['props.source', $props['source']];
        }

        if (is_string($props['source'] ?? null) && trim((string) $props['source']) !== '') {
            return ['props.source', ['query' => ['name' => trim((string) $props['source'])]]];
        }

        if (is_array($node['source_extended'] ?? null)) {
            return ['source_extended', $node['source_extended']];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|array{error: string, code: string}
     */
    private function normalizeSourceArgument(array $arguments): array
    {
        if (isset($arguments['source'])) {
            if (!is_array($arguments['source'])) {
                return ['error' => 'source must be an object.', 'code' => 'invalid_source'];
            }

            $source = $arguments['source'];
        } else {
            $source = $this->buildSourceFromShorthand($arguments);

            if (isset($source['error'])) {
                return $source;
            }
        }

        if (!is_array($source['query'] ?? null) && !is_array($source['props'] ?? null)) {
            return ['error' => 'source must contain query and/or props.', 'code' => 'invalid_source'];
        }

        if (isset($source['props']) && !is_array($source['props'])) {
            return ['error' => 'source.props must be an object.', 'code' => 'invalid_source_props'];
        }

        if (isset($source['query']) && !is_array($source['query'])) {
            return ['error' => 'source.query must be an object.', 'code' => 'invalid_source_query'];
        }

        if (isset($source['props']) && is_array($source['props'])) {
            foreach ($source['props'] as $prop => $mapping) {
                if (!is_string($prop) || trim($prop) === '') {
                    return ['error' => 'source.props keys must be non-empty prop names.', 'code' => 'invalid_source_props'];
                }

                if (is_string($mapping)) {
                    $fieldName = trim($mapping);

                    if ($fieldName === '') {
                        return ['error' => 'Each source prop mapping must have a non-empty name.', 'code' => 'invalid_source_mapping'];
                    }

                    $source['props'][$prop] = ['name' => $fieldName];
                    continue;
                }

                if (!is_array($mapping) || trim((string) ($mapping['name'] ?? '')) === '') {
                    return ['error' => 'Each source prop mapping must be an object with a non-empty name.', 'code' => 'invalid_source_mapping'];
                }
            }
        }

        return $source;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|array{error: string, code: string}
     */
    private function buildSourceFromShorthand(array $arguments): array
    {
        $sourceName = trim((string) ($arguments['source_name'] ?? ''));
        $fieldMappings = $arguments['field_mappings'] ?? null;

        if ($sourceName === '') {
            return ['error' => 'source_name is required when source is omitted.', 'code' => 'missing_source_name'];
        }

        if (!is_array($fieldMappings) || $fieldMappings === []) {
            return ['error' => 'field_mappings must be a non-empty object when source is omitted.', 'code' => 'missing_field_mappings'];
        }

        // Write what the Builder writes: one dotted query name, arguments on the
        // query itself. MirasAI used to keep source_name and query_field in
        // separate keys, so the same binding looked different depending on who
        // made it, and a page holding both was a source of surprises. Callers
        // may still pass them separately; they get joined here.
        $queryField = trim((string) ($arguments['query_field'] ?? ''));
        $query = [
            'name' => $queryField !== '' ? $sourceName . '.' . $queryField : $sourceName,
        ];

        if (isset($arguments['query_arguments'])) {
            if (!is_array($arguments['query_arguments'])) {
                return ['error' => 'query_arguments must be an object.', 'code' => 'invalid_query_arguments'];
            }

            $query['arguments'] = $arguments['query_arguments'];
        }

        if (isset($arguments['query_directives'])) {
            if (!is_array($arguments['query_directives'])) {
                return ['error' => 'query_directives must be an object.', 'code' => 'invalid_query_directives'];
            }

            $query['directives'] = $arguments['query_directives'];
        }

        $props = [];

        foreach ($fieldMappings as $prop => $mapping) {
            $propName = trim((string) $prop);

            if ($propName === '') {
                return ['error' => 'field_mappings keys must be non-empty prop names.', 'code' => 'invalid_field_mappings'];
            }

            if (is_string($mapping)) {
                $fieldName = trim($mapping);

                if ($fieldName === '') {
                    return ['error' => 'field mapping names must be non-empty.', 'code' => 'invalid_field_mapping'];
                }

                $props[$propName] = ['name' => $fieldName];
                continue;
            }

            if (!is_array($mapping)) {
                return ['error' => 'field mapping values must be strings or objects.', 'code' => 'invalid_field_mapping'];
            }

            $fieldName = trim((string) ($mapping['name'] ?? ''));

            if ($fieldName === '') {
                return ['error' => 'field mapping objects must include a non-empty name.', 'code' => 'invalid_field_mapping'];
            }

            $props[$propName] = $mapping;
            $props[$propName]['name'] = $fieldName;
        }

        return [
            'query' => $query,
            'props' => $props,
        ];
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function sanitizeSourceValue($value, int $depth = 0)
    {
        if ($depth > 6) {
            return '[max_depth]';
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if ($value instanceof \Closure) {
            return '[closure]';
        }

        if (is_object($value)) {
            return '[object ' . get_class($value) . ']';
        }

        if (!is_array($value)) {
            return '[' . gettype($value) . ']';
        }

        $sanitized = [];

        foreach ($value as $key => $item) {
            $sanitized[$key] = $this->sanitizeSourceValue($item, $depth + 1);
        }

        return $sanitized;
    }

    /**
     * @param array<string, mixed> $layout
     * @return list<array{path: string, type: string, binding: array<string, mixed>}>
     */
    protected function bindingsOnlyFromLayout(object $navigator, array $layout): array
    {
        $bindings = [];

        foreach ($navigator->listElements($layout) as $meta) {
            if (empty($meta['has_source_binding']) || !is_string($meta['path'] ?? null)) {
                continue;
            }

            $found = $navigator->findElement($layout, $meta['path']);

            if ($found === null) {
                continue;
            }

            $binding = $this->summarizeBinding($found['element']);
            unset($binding['raw_source']);

            $bindings[] = [
                'path' => $meta['path'],
                'type' => is_string($meta['type'] ?? null) ? $meta['type'] : 'unknown',
                'binding' => $binding,
            ];
        }

        return $bindings;
    }
}
