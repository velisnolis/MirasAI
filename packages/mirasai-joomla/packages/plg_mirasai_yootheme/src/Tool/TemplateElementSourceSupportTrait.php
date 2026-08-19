<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

trait TemplateElementSourceSupportTrait
{
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
                'arguments' => $this->sanitizeValue($mapping['arguments'] ?? []),
                'directives' => $this->sanitizeValue($mapping['directives'] ?? []),
                'filters' => $this->sanitizeValue($mapping['filters'] ?? []),
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
            'query_arguments' => $this->sanitizeValue($queryField['arguments'] ?? $query['arguments'] ?? []),
            'query_directives' => $this->sanitizeValue($queryField['directives'] ?? $query['directives'] ?? []),
            'field_mappings' => $mappings,
            'mapping_count' => count($mappings),
            'raw_source' => $this->sanitizeValue($source),
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

    private function sanitizeValue(mixed $value, int $depth = 0): mixed
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
            return '[object ' . $value::class . ']';
        }

        if (!is_array($value)) {
            return '[' . gettype($value) . ']';
        }

        $sanitized = [];

        foreach ($value as $key => $item) {
            $sanitized[$key] = $this->sanitizeValue($item, $depth + 1);
        }

        return $sanitized;
    }

    /**
     * @param array<string, mixed> $layout
     * @return list<array{path: string, type: string, status?: string, disabled_by?: string, binding: array<string, mixed>}>
     */
    protected function bindingsOnlyFromLayout(object $navigator, array $layout): array
    {
        $elements = $navigator->listElements($layout);
        $parents = [];
        $disabled = [];

        foreach ($elements as $meta) {
            if (!is_string($meta['path'] ?? null)) {
                continue;
            }

            $parents[$meta['path']] = is_string($meta['parent_path'] ?? null) ? $meta['parent_path'] : null;

            if (($meta['status'] ?? null) === 'disabled') {
                $disabled[$meta['path']] = true;
            }
        }

        $bindings = [];

        foreach ($elements as $meta) {
            if (empty($meta['has_source_binding']) || !is_string($meta['path'] ?? null)) {
                continue;
            }

            $found = $navigator->findElement($layout, $meta['path']);

            if ($found === null) {
                continue;
            }

            $binding = $this->summarizeBinding($found['element']);
            unset($binding['raw_source']);

            $row = [
                'path' => $meta['path'],
                'type' => is_string($meta['type'] ?? null) ? $meta['type'] : 'unknown',
            ];

            if (is_string($meta['status'] ?? null) && $meta['status'] !== '') {
                $row['status'] = $meta['status'];
            }

            $disabledBy = $this->nearestDisabledAncestor($meta['path'], $disabled, $parents);

            if ($disabledBy !== null) {
                $row['disabled_by'] = $disabledBy;
            }

            $row['binding'] = $binding;
            $bindings[] = $row;
        }

        return $bindings;
    }

    /**
     * bindings_only is a flat list, so a binding whose row or section is
     * disabled has no nesting left to show it. BIT Vic disables the row and
     * leaves last edition's source on the gallery inside it: without this the
     * agent maps a placeholder it was never meant to touch.
     *
     * @param array<string, true> $disabled
     * @param array<string, string|null> $parents
     */
    private function nearestDisabledAncestor(string $path, array $disabled, array $parents): ?string
    {
        $current = $path;
        $seen = [];

        while (is_string($current) && !isset($seen[$current])) {
            if (isset($disabled[$current])) {
                return $current;
            }

            $seen[$current] = true;
            $current = $parents[$current] ?? null;
        }

        return null;
    }

    /**
     * Schema for the batch form. One if_match, many leaves.
     *
     * @return array<string, mixed>
     */
    protected function leafBatchInputProperties(): array
    {
        // Spelled out rather than borrowed from the single-path schema: only the
        // WordPress trait has sourceInputProperties(), and reaching for it made
        // every Joomla call to this tool a fatal.
        $mappings = [
            'type' => 'object',
            'description' => 'Replace the named prop mappings and keep the rest. The native visibility condition is a mapping like any other, under the reserved prop _condition.',
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
        ];

        return [
            'leaves' => [
                'type' => 'array',
                'description' => 'Rebind several bindings under one if_match. Each entry names one node and what to do with it. Every entry must resolve to exactly one bound node or the whole call is refused without writing: a half-applied rebind is worse than none. Use this instead of repeating template/element-source-set when a clone or a duplicated page needs more than one binding moved.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'match' => [
                            'type' => 'object',
                            'description' => 'How to find the node. Give path, or query_path when the path is unknown; query_path must still resolve to exactly one node.',
                            'properties' => [
                                'path' => ['type' => 'string', 'description' => 'Element path as returned by template/element-list.'],
                                'query_path' => ['type' => 'string', 'description' => 'Canonical query path of the binding, as reported by mode=bindings_only.'],
                            ],
                        ],
                        'set' => [
                            'type' => 'object',
                            'description' => 'What to change. Everything omitted is kept, so changing query_arguments leaves the field mappings alone. Omit set entirely and pass keep:true to assert the leaf exists without touching it.',
                            'properties' => [
                                'keep' => ['type' => 'boolean', 'description' => 'Assert this binding is present and leave it untouched. Use it to put a leaf you must not forget, such as a visibility mapping, into the map as a checklist item.'],
                                'source_name' => ['type' => 'string', 'description' => 'Replace the dotted query name, for example to repoint a folder source at this year edition.'],
                                'query_arguments' => ['type' => 'object', 'description' => 'Replace the query arguments, whichever carrier holds them. Field mappings are untouched.'],
                                'field_mappings' => $mappings,
                                'source' => ['type' => 'object', 'description' => 'Raw source payload, replacing the binding outright. Escape hatch; prefer the named fields.'],
                            ],
                        ],
                    ],
                ],
            ],
            'rebind_disabled' => [
                'type' => 'boolean',
                'description' => 'Allow rebinding a node the Builder does not output, itself or through an ancestor. Defaults to false, because a source left on a disabled row is usually a placeholder kept on purpose.',
            ],
        ];
    }

    /**
     * Apply a batch of leaf rebinds to one layout.
     *
     * Fail-closed on the whole set: the layout comes back mutated only if every
     * leaf resolved. The report covers every bound node in the layout, not just
     * the ones named, because the failure this contract exists to prevent is a
     * leaf nobody remembered to name.
     *
     * @param array<string, mixed> $layout
     * @param list<mixed> $leaves
     * @return array{layout: array<string, mixed>, leaves: list<array<string, mixed>>}|array{error: string, code: string}
     */
    protected function applyLeafBatch(object $navigator, array $layout, array $leaves, bool $rebindDisabled): array
    {
        if ($leaves === []) {
            return ['error' => 'leaves must not be empty.', 'code' => 'invalid_leaves'];
        }

        $meta = [];
        $parents = [];
        $disabled = [];
        $bound = [];

        foreach ($navigator->listElements($layout) as $row) {
            if (!is_string($row['path'] ?? null)) {
                continue;
            }

            $meta[$row['path']] = $row;
            $parents[$row['path']] = is_string($row['parent_path'] ?? null) ? $row['parent_path'] : null;

            if (($row['status'] ?? null) === 'disabled') {
                $disabled[$row['path']] = true;
            }

            if (!empty($row['has_source_binding'])) {
                $bound[] = $row['path'];
            }
        }

        $before = [];

        foreach ($bound as $path) {
            $found = $navigator->findElement($layout, $path);

            if ($found !== null) {
                $before[$path] = $this->summarizeBinding($found['element']);
            }
        }

        $plan = [];

        foreach ($leaves as $index => $leaf) {
            if (!is_array($leaf)) {
                return ['error' => "leaves[{$index}] must be an object.", 'code' => 'invalid_leaf'];
            }

            $match = is_array($leaf['match'] ?? null) ? $leaf['match'] : [];
            $set = is_array($leaf['set'] ?? null) ? $leaf['set'] : [];
            $resolved = $this->resolveLeafMatch($match, $meta, $before, $index);

            if (isset($resolved['error'])) {
                return $resolved;
            }

            $path = $resolved['path'];

            if (isset($plan[$path])) {
                return [
                    'error' => "leaves[{$index}] resolves to {$path}, already named by an earlier entry.",
                    'code' => 'leaf_conflict',
                ];
            }

            if (!isset($before[$path])) {
                return [
                    'error' => "leaves[{$index}] resolves to {$path}, which has no Dynamic Source binding. Use template/element-source-set to create one.",
                    'code' => 'leaf_no_binding',
                ];
            }

            $keep = !empty($set['keep']);
            $changes = array_values(array_diff(array_keys($set), ['keep']));

            if (!$keep && $changes === []) {
                return [
                    'error' => "leaves[{$index}] changes nothing. Pass set.keep=true to assert it, or one of source_name, query_arguments, field_mappings, source.",
                    'code' => 'invalid_leaf',
                ];
            }

            // keep means "this leaf must be here and must not move". Honouring
            // it while a sibling key asks for a change would report the leaf as
            // kept and drop the change without saying so, which is the exact
            // silent miss this contract exists to prevent.
            if ($keep && $changes !== []) {
                return [
                    'error' => "leaves[{$index}] passes set.keep with " . implode(', ', $changes) . '. keep asserts a leaf without touching it; drop keep to change it.',
                    'code' => 'invalid_leaf',
                ];
            }

            $blockedBy = $this->nearestDisabledAncestor($path, $disabled, $parents);

            if ($blockedBy !== null && !$keep && !$rebindDisabled) {
                return [
                    'error' => "leaves[{$index}] resolves to {$path}, which the Builder does not output ({$blockedBy} is disabled). A source there is usually a placeholder. Pass rebind_disabled=true to rebind it anyway.",
                    'code' => 'rebind_disabled_blocked',
                ];
            }

            $plan[$path] = ['keep' => $keep, 'set' => $set, 'index' => $index];
        }

        foreach ($plan as $path => $entry) {
            if ($entry['keep']) {
                continue;
            }

            $found = $navigator->findElement($layout, $path);

            if ($found === null) {
                return ['error' => "Element path {$path} disappeared mid-batch.", 'code' => 'element_not_found'];
            }

            $carrier = $this->resolveBindingCarrier($found['element']);

            if ($carrier === null) {
                return ['error' => "Element path {$path} lost its binding mid-batch.", 'code' => 'leaf_no_binding'];
            }

            $source = $this->applyLeafSet($carrier[1], $entry['set'], (int) $entry['index']);

            if (isset($source['error'])) {
                return $source;
            }

            $written = $navigator->setElementSource($layout, $path, $source);

            if (isset($written['error'])) {
                return $written;
            }

            $layout = $written['layout'];
        }

        $report = [];

        foreach ($bound as $path) {
            $found = $navigator->findElement($layout, $path);
            $after = $found === null ? null : $this->summarizeBinding($found['element']);
            $blockedBy = $this->nearestDisabledAncestor($path, $disabled, $parents);
            $entry = $plan[$path] ?? null;

            if ($entry === null) {
                $state = $blockedBy === null ? 'untouched' : 'skipped_disabled';
            } elseif ($entry['keep']) {
                $state = 'kept';
            } else {
                $state = 'rebound';
            }

            $row = [
                'path' => $path,
                'type' => is_string($meta[$path]['type'] ?? null) ? $meta[$path]['type'] : 'unknown',
                'query_path' => $before[$path]['query_path'] ?? null,
                'state' => $state,
            ];

            if ($blockedBy !== null) {
                $row['disabled_by'] = $blockedBy;
            }

            if ($state === 'rebound' && $after !== null) {
                $row['before'] = $this->leafReportBinding($before[$path] ?? []);
                $row['after'] = $this->leafReportBinding($after);
            }

            $report[] = $row;
        }

        return ['layout' => $layout, 'leaves' => $report];
    }

    /**
     * @param array<string, mixed> $binding
     * @return array<string, mixed>
     */
    private function leafReportBinding(array $binding): array
    {
        unset($binding['raw_source']);

        return $binding;
    }

    /**
     * @param array<string, mixed> $match
     * @param array<string, array<string, mixed>> $meta
     * @param array<string, array<string, mixed>> $bindings
     * @return array{path: string}|array{error: string, code: string}
     */
    private function resolveLeafMatch(array $match, array $meta, array $bindings, int $index): array
    {
        $path = trim((string) ($match['path'] ?? ''));
        $queryPath = trim((string) ($match['query_path'] ?? ''));

        if ($path === '' && $queryPath === '') {
            return [
                'error' => "leaves[{$index}].match needs path or query_path.",
                'code' => 'invalid_leaf',
            ];
        }

        if ($path !== '') {
            if (!isset($meta[$path])) {
                return [
                    'error' => "leaves[{$index}].match.path {$path} matches no element.",
                    'code' => 'leaf_unmatched',
                ];
            }

            if ($queryPath !== '' && ($bindings[$path]['query_path'] ?? null) !== $queryPath) {
                return [
                    'error' => "leaves[{$index}] gives path {$path} and query_path {$queryPath}, which disagree.",
                    'code' => 'leaf_unmatched',
                ];
            }

            return ['path' => $path];
        }

        $hits = [];

        foreach ($bindings as $candidate => $binding) {
            if (($binding['query_path'] ?? null) === $queryPath) {
                $hits[] = $candidate;
            }
        }

        if ($hits === []) {
            return [
                'error' => "leaves[{$index}].match.query_path {$queryPath} matches no binding.",
                'code' => 'leaf_unmatched',
            ];
        }

        if (count($hits) > 1) {
            return [
                'error' => "leaves[{$index}].match.query_path {$queryPath} matches " . count($hits) . ' bindings (' . implode(', ', $hits) . '). Name one by path.',
                'code' => 'leaf_unmatched',
            ];
        }

        return ['path' => $hits[0]];
    }

    /**
     * Apply one leaf's set to the binding it already has.
     *
     * Everything not named survives, so moving a query argument does not quietly
     * drop the field mappings next to it.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $set
     * @return array<string, mixed>|array{error: string, code: string}
     */
    private function applyLeafSet(array $source, array $set, int $index): array
    {
        if (isset($set['source'])) {
            if (!is_array($set['source'])) {
                return ["error" => "leaves[{$index}].set.source must be an object.", 'code' => 'invalid_source'];
            }

            return $set['source'];
        }

        $query = is_array($source['query'] ?? null) ? $source['query'] : [];
        $nested = is_array($query['field'] ?? null);

        if (array_key_exists('source_name', $set)) {
            $name = trim((string) $set['source_name']);

            if ($name === '') {
                return ['error' => "leaves[{$index}].set.source_name must not be empty.", 'code' => 'missing_source_name'];
            }

            $query['name'] = $name;
        }

        if (array_key_exists('query_arguments', $set)) {
            if (!is_array($set['query_arguments'])) {
                return ['error' => "leaves[{$index}].set.query_arguments must be an object.", 'code' => 'invalid_query_arguments'];
            }

            // Read and write the same carrier summarizeBinding reports from, or
            // the call looks applied and the Builder keeps the old arguments.
            if ($nested) {
                $query['field']['arguments'] = $set['query_arguments'];
            } else {
                $query['arguments'] = $set['query_arguments'];
            }
        }

        if ($query !== []) {
            $source['query'] = $query;
        }

        if (array_key_exists('field_mappings', $set)) {
            if (!is_array($set['field_mappings']) || $set['field_mappings'] === []) {
                return ['error' => "leaves[{$index}].set.field_mappings must be a non-empty object.", 'code' => 'invalid_field_mappings'];
            }

            $props = is_array($source['props'] ?? null) ? $source['props'] : [];

            foreach ($set['field_mappings'] as $prop => $mapping) {
                $propName = trim((string) $prop);

                if ($propName === '') {
                    return ['error' => "leaves[{$index}].set.field_mappings keys must be non-empty prop names.", 'code' => 'invalid_field_mappings'];
                }

                if (is_string($mapping)) {
                    $fieldName = trim($mapping);

                    if ($fieldName === '') {
                        return ['error' => "leaves[{$index}].set.field_mappings names must be non-empty.", 'code' => 'invalid_field_mapping'];
                    }

                    $props[$propName] = ['name' => $fieldName];
                    continue;
                }

                if (!is_array($mapping)) {
                    return ['error' => "leaves[{$index}].set.field_mappings values must be strings or objects.", 'code' => 'invalid_field_mapping'];
                }

                $fieldName = trim((string) ($mapping['name'] ?? ''));

                if ($fieldName === '') {
                    return ['error' => "leaves[{$index}].set.field_mappings objects must include a non-empty name.", 'code' => 'invalid_field_mapping'];
                }

                $mapping['name'] = $fieldName;
                $props[$propName] = $mapping;
            }

            $source['props'] = $props;
        }

        return $source;
    }
}
