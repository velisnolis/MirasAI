<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class TemplateSourceTypesTool extends AbstractTool
{
    public function getName(): string
    {
        return 'template/source-types';
    }

    public function getDescription(): string
    {
        return 'Discovers installed YOOtheme Dynamic Source types through the live Builder Source GraphQL schema when available, with a static package fallback.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => [
                    'type' => 'string',
                    'description' => 'Optional GraphQL source type to inspect, for example Post, Page, Category, Site, File, or Query.',
                ],
                'source_name' => [
                    'type' => 'string',
                    'description' => 'Optional YOOtheme source query path to resolve exactly, for example post, posts, page, or pages.',
                ],
                'include_fields' => [
                    'type' => 'boolean',
                    'description' => 'Return field metadata. Defaults to true when type or source_name is provided, false for the full list.',
                ],
                'include_raw' => [
                    'type' => 'boolean',
                    'description' => 'Include raw introspection entries for matched types. Defaults to false.',
                ],
                'include_binding_hints' => [
                    'type' => 'boolean',
                    'description' => 'Include source binding hints with query arguments and mappable result fields. Defaults to true when source_name is provided.',
                ],
                'kind' => [
                    'type' => 'string',
                    'enum' => ['all', 'query', 'object'],
                    'description' => 'Filter returned source types. Defaults to all.',
                ],
            ],
        ];
    }

    public function handle(array $arguments): array
    {
        $type = trim((string) ($arguments['type'] ?? ''));
        $sourceName = trim((string) ($arguments['source_name'] ?? ''));
        $kind = trim((string) ($arguments['kind'] ?? 'all'));
        $includeFields = array_key_exists('include_fields', $arguments)
            ? (bool) $arguments['include_fields']
            : $type !== '' || $sourceName !== '';
        $includeRaw = !empty($arguments['include_raw']);
        $includeBindingHints = array_key_exists('include_binding_hints', $arguments)
            ? (bool) $arguments['include_binding_hints']
            : $sourceName !== '';

        if ($type !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $type)) {
            return ['error' => 'type must be a GraphQL type name, for example Post or Query.', 'code' => 'invalid_type'];
        }

        if ($sourceName !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\.[A-Za-z_][A-Za-z0-9_]*)*$/', $sourceName)) {
            return ['error' => 'source_name must be a dotted GraphQL field path, for example post or posts.', 'code' => 'invalid_source_name'];
        }

        if (!in_array($kind, ['all', 'query', 'object'], true)) {
            return ['error' => 'kind must be one of: all, query, object.', 'code' => 'invalid_kind'];
        }

        $live = $this->loadLiveSourceSchema($type, $sourceName, $kind, $includeFields, $includeRaw, $includeBindingHints);

        if (!isset($live['error'])) {
            return $live;
        }

        $fallback = $this->discoverInstalledSourcePackages();
        $fallback['live_error'] = $live;

        return $fallback;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadLiveSourceSchema(
        string $typeFilter,
        string $sourceName,
        string $kindFilter,
        bool $includeFields,
        bool $includeRaw,
        bool $includeBindingHints
    ): array {
        $bootstrap = $this->ensureYooThemeSourceRuntime();

        if (isset($bootstrap['error'])) {
            return [
                'error' => $bootstrap['error'],
                'code' => 'source_runtime_unavailable',
            ];
        }

        try {
            $source = \YOOtheme\app(\YOOtheme\Builder\Source::class);
            $payload = $source->queryIntrospection()->toArray();
        } catch (\Throwable $exception) {
            return [
                'error' => 'Unable to introspect YOOtheme Builder Source schema: ' . $exception->getMessage(),
                'code' => 'source_introspection_failed',
            ];
        }

        $schema = $payload['data']['__schema'] ?? null;

        if (!is_array($schema)) {
            return [
                'error' => 'YOOtheme Builder Source introspection did not return a schema.',
                'code' => 'source_schema_missing',
            ];
        }

        $queryTypeName = is_array($schema['queryType'] ?? null)
            ? (string) ($schema['queryType']['name'] ?? 'Query')
            : 'Query';
        $schemaTypes = is_array($schema['types'] ?? null) ? $schema['types'] : [];
        $typeMap = $this->indexTypes($schemaTypes);
        $types = [];

        foreach ($schemaTypes as $type) {
            if (!is_array($type)) {
                continue;
            }

            $name = (string) ($type['name'] ?? '');
            $kind = (string) ($type['kind'] ?? '');

            if ($name === '' || str_starts_with($name, '__')) {
                continue;
            }

            if ($typeFilter !== '' && $name !== $typeFilter) {
                continue;
            }

            $bucket = $name === $queryTypeName ? 'query' : strtolower($kind);

            if ($kindFilter === 'query' && $bucket !== 'query') {
                continue;
            }

            if ($kindFilter === 'object' && $bucket !== 'object') {
                continue;
            }

            if (!in_array($kind, ['OBJECT', 'SCALAR', 'ENUM', 'UNION', 'INTERFACE'], true)) {
                continue;
            }

            $item = [
                'name' => $name,
                'kind' => $kind,
                'bucket' => $bucket,
                'description' => is_string($type['description'] ?? null) ? $type['description'] : '',
                'metadata' => $this->sanitizeValue($type['metadata'] ?? null),
                'field_count' => is_array($type['fields'] ?? null) ? count($type['fields']) : 0,
            ];

            if ($includeFields && is_array($type['fields'] ?? null)) {
                $item['fields'] = $this->summarizeFields($type['fields']);
            }

            if ($includeRaw) {
                $item['raw'] = $this->sanitizeValue($type);
            }

            $types[] = $item;
        }

        usort(
            $types,
            static fn(array $a, array $b): int => [$a['bucket'], $a['name']] <=> [$b['bucket'], $b['name']],
        );

        $response = [
            'mode' => 'live_introspection',
            'type' => $typeFilter !== '' ? $typeFilter : null,
            'source_name' => $sourceName !== '' ? $sourceName : null,
            'kind' => $kindFilter,
            'query_type' => $queryTypeName,
            'type_count' => count($types),
            'types' => $types,
            'installed_packages' => $this->discoverSourcePackageNames(),
            'runtime_bootstrap' => $bootstrap,
            'note' => 'This is YOOtheme Builder Source GraphQL introspection as exposed by the installed runtime.',
        ];

        if ($includeBindingHints) {
            $response['binding_hints'] = $sourceName !== ''
                ? $this->resolveSourceBindingHints($sourceName, $queryTypeName, $typeMap)
                : $this->summarizeQueryBindingHints($queryTypeName, $typeMap);
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function ensureYooThemeSourceRuntime(): array
    {
        $root = $this->findYooThemeRoot();

        try {
            if (!function_exists('YOOtheme\\app')) {
                if ($root === null || !is_file($root . '/bootstrap.php')) {
                    return ['error' => 'YOOtheme Builder Source runtime is not loaded in this request.'];
                }

                require $root . '/bootstrap.php';
            }

            if (function_exists('YOOtheme\\app') && $root !== null) {
                $app = \YOOtheme\app();

                if (is_object($app) && method_exists($app, 'load')) {
                    $app->load(
                        $root
                        . '/{packages/{platform-wordpress,builder,builder-source*,builder-wordpress*}/bootstrap.php,config.php}'
                    );
                }
            }
        } catch (\Throwable $exception) {
            return ['error' => 'Unable to bootstrap YOOtheme Builder Source runtime: ' . $exception->getMessage()];
        }

        if (!function_exists('YOOtheme\\app')) {
            return ['error' => 'YOOtheme Builder Source runtime is not loaded in this request.'];
        }

        try {
            $source = \YOOtheme\app(\YOOtheme\Builder\Source::class);
            if (!is_object($source) || !method_exists($source, 'queryIntrospection')) {
                return ['error' => 'YOOtheme Builder Source service is unavailable in the runtime container.'];
            }
        } catch (\Throwable $exception) {
            return ['error' => 'Unable to resolve YOOtheme Builder Source service: ' . $exception->getMessage()];
        }

        return [
            'attempted' => true,
            'root' => $root !== null ? $this->relativeToRoot($root) : null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @return list<array<string, mixed>>
     */
    private function summarizeFields(array $fields): array
    {
        $result = [];

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $args = [];

            foreach (($field['args'] ?? []) as $arg) {
                if (!is_array($arg)) {
                    continue;
                }

                $args[] = [
                    'name' => (string) ($arg['name'] ?? ''),
                    'type' => $this->formatTypeRef($arg['type'] ?? null),
                    'default' => $this->sanitizeValue($arg['defaultValue'] ?? null),
                    'metadata' => $this->sanitizeValue($arg['metadata'] ?? null),
                ];
            }

            $result[] = [
                'name' => (string) ($field['name'] ?? ''),
                'type' => $this->formatTypeRef($field['type'] ?? null),
                'description' => is_string($field['description'] ?? null) ? $field['description'] : '',
                'metadata' => $this->sanitizeValue($field['metadata'] ?? null),
                'args' => $args,
            ];
        }

        return $result;
    }

    private function formatTypeRef($type): string
    {
        if (!is_array($type)) {
            return '';
        }

        $kind = (string) ($type['kind'] ?? '');
        $name = (string) ($type['name'] ?? '');

        if ($name !== '') {
            return $name;
        }

        $ofType = $this->formatTypeRef($type['ofType'] ?? null);

        return match ($kind) {
            'NON_NULL' => $ofType !== '' ? $ofType . '!' : '!',
            'LIST' => '[' . $ofType . ']',
            default => $ofType,
        };
    }

    /**
     * @param list<array<string, mixed>> $types
     * @return array<string, array<string, mixed>>
     */
    private function indexTypes(array $types): array
    {
        $map = [];

        foreach ($types as $type) {
            if (is_array($type) && is_string($type['name'] ?? null) && $type['name'] !== '') {
                $map[$type['name']] = $type;
            }
        }

        return $map;
    }

    /**
     * @param array<string, array<string, mixed>> $typeMap
     * @return array<string, mixed>
     */
    private function resolveSourceBindingHints(string $sourceName, string $queryTypeName, array $typeMap): array
    {
        $segments = explode('.', $sourceName);
        $currentTypeName = $queryTypeName;
        $path = [];
        $field = null;

        foreach ($segments as $segment) {
            $currentType = $typeMap[$currentTypeName] ?? null;

            if (!is_array($currentType)) {
                return [
                    'error' => "Type {$currentTypeName} not found while resolving {$sourceName}.",
                    'code' => 'source_type_not_found',
                    'resolved_path' => $path,
                ];
            }

            $field = $this->findField($currentType, $segment);

            if ($field === null) {
                return [
                    'error' => "Source segment {$segment} not found on type {$currentTypeName}.",
                    'code' => 'source_field_not_found',
                    'resolved_path' => $path,
                    'available_fields' => $this->summarizeAvailableFields($currentType),
                ];
            }

            $fieldType = $this->unwrapTypeRef($field['type'] ?? null);
            $path[] = [
                'field' => $segment,
                'parent_type' => $currentTypeName,
                'result_type' => $fieldType['name'],
                'result_kind' => $fieldType['kind'],
                'is_list' => $fieldType['is_list'],
            ];
            $currentTypeName = $fieldType['name'] !== '' ? $fieldType['name'] : $currentTypeName;
        }

        if ($field === null) {
            return [
                'error' => 'source_name did not contain any segments.',
                'code' => 'invalid_source_name',
            ];
        }

        $resultType = $this->unwrapTypeRef($field['type'] ?? null);
        $targetType = $typeMap[$resultType['name']] ?? null;

        return [
            'query' => [
                'name' => $sourceName,
                'segments' => $segments,
                'path' => $path,
                'result_type' => $resultType['name'],
                'result_kind' => $resultType['kind'],
                'is_multiple' => $resultType['is_list'],
                'args' => $this->summarizeFields([$field])[0]['args'] ?? [],
                'metadata' => $this->sanitizeValue($field['metadata'] ?? null),
            ],
            'mappable_type' => $resultType['name'],
            'mappable_fields' => is_array($targetType['fields'] ?? null) ? $this->summarizeFields($targetType['fields']) : [],
            'example_source' => [
                'query' => [
                    'name' => $sourceName,
                    'arguments' => new \stdClass(),
                ],
                'props' => new \stdClass(),
            ],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $typeMap
     * @return list<array<string, mixed>>
     */
    private function summarizeQueryBindingHints(string $queryTypeName, array $typeMap): array
    {
        $queryType = $typeMap[$queryTypeName] ?? null;

        if (!is_array($queryType['fields'] ?? null)) {
            return [];
        }

        $hints = [];

        foreach ($queryType['fields'] as $field) {
            if (!is_array($field)) {
                continue;
            }

            $type = $this->unwrapTypeRef($field['type'] ?? null);
            $metadata = is_array($field['metadata'] ?? null) ? $field['metadata'] : [];
            $hints[] = [
                'source_name' => (string) ($field['name'] ?? ''),
                'label' => (string) ($metadata['label'] ?? ''),
                'group' => (string) ($metadata['group'] ?? ''),
                'result_type' => $type['name'],
                'is_multiple' => $type['is_list'],
                'arg_count' => is_array($field['args'] ?? null) ? count($field['args']) : 0,
            ];
        }

        return $hints;
    }

    /**
     * @param array<string, mixed> $type
     * @return array<string, mixed>|null
     */
    private function findField(array $type, string $name): ?array
    {
        foreach (($type['fields'] ?? []) as $field) {
            if (is_array($field) && ($field['name'] ?? '') === $name) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $type
     * @return list<string>
     */
    private function summarizeAvailableFields(array $type): array
    {
        $fields = [];

        foreach (($type['fields'] ?? []) as $field) {
            if (is_array($field) && is_string($field['name'] ?? null)) {
                $fields[] = $field['name'];
            }
        }

        return $fields;
    }

    /**
     * @return array{name: string, kind: string, is_list: bool, non_null: bool}
     */
    private function unwrapTypeRef($type): array
    {
        $result = [
            'name' => '',
            'kind' => '',
            'is_list' => false,
            'non_null' => false,
        ];

        while (is_array($type)) {
            $kind = (string) ($type['kind'] ?? '');

            if ($kind === 'LIST') {
                $result['is_list'] = true;
                $type = $type['ofType'] ?? null;
                continue;
            }

            if ($kind === 'NON_NULL') {
                $result['non_null'] = true;
                $type = $type['ofType'] ?? null;
                continue;
            }

            $result['kind'] = $kind;
            $result['name'] = (string) ($type['name'] ?? '');
            break;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function discoverInstalledSourcePackages(): array
    {
        $packages = [];

        foreach ($this->findPackageRoots() as $root) {
            $name = basename($root);

            if (!str_contains($name, 'source') && !str_contains($name, 'acf')) {
                continue;
            }

            $typeFiles = glob($root . '/src/Type/*Type.php') ?: [];

            $packages[] = [
                'name' => $name,
                'path' => $this->relativeToRoot($root),
                'has_load_source_types_listener' => is_file($root . '/src/Listener/LoadSourceTypes.php'),
                'type_count' => count($typeFiles),
                'types' => array_values(array_map(
                    static fn(string $file): string => preg_replace('/Type\\.php$/', '', basename($file)) ?: basename($file),
                    $typeFiles,
                )),
            ];
        }

        return [
            'mode' => 'static_package_scan',
            'package_count' => count($packages),
            'packages' => $packages,
            'note' => 'Live Source introspection was unavailable, so this only lists installed source packages and Type classes.',
        ];
    }

    /**
     * @return list<string>
     */
    private function discoverSourcePackageNames(): array
    {
        return array_values(array_map(
            static fn(array $package): string => (string) ($package['name'] ?? ''),
            $this->discoverInstalledSourcePackages()['packages'] ?? [],
        ));
    }

    /**
     * @return list<string>
     */
    private function findPackageRoots(): array
    {
        $themeRoot = $this->findYooThemeRoot();

        if ($themeRoot === null) {
            return [];
        }

        $packagesRoot = $themeRoot . '/packages';

        if (!is_dir($packagesRoot)) {
            return [];
        }

        return array_values(array_filter(glob($packagesRoot . '/*') ?: [], 'is_dir'));
    }

    private function findYooThemeRoot(): ?string
    {
        $root = defined('ABSPATH') ? rtrim((string) ABSPATH, '/') : '';

        if ($root === '') {
            return null;
        }

        $candidates = [
            $root . '/wp-content/themes/yootheme',
            get_template_directory(),
            get_stylesheet_directory(),
        ];

        foreach (array_unique($candidates) as $candidate) {
            if (is_dir($candidate . '/packages/builder-source')) {
                return rtrim($candidate, '/');
            }
        }

        return null;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function sanitizeValue($value, int $depth = 0)
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
            $sanitized[$key] = $this->sanitizeValue($item, $depth + 1);
        }

        return $sanitized;
    }

    private function relativeToRoot(string $path): string
    {
        $root = defined('ABSPATH') ? rtrim((string) ABSPATH, '/') . '/' : '';

        if ($root !== '' && str_starts_with($path, $root)) {
            return ltrim(substr($path, strlen($root)), '/');
        }

        return $path;
    }
}
