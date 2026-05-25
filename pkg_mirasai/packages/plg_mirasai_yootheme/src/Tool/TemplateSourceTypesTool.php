<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Mirasai\Library\Tool\AbstractTool;

class TemplateSourceTypesTool extends AbstractTool
{
    public function getName(): string
    {
        return 'template/source-types';
    }

    public function getDescription(): string
    {
        return 'Discovers installed YOOtheme Dynamic Source types through the live Builder Source GraphQL schema when available, with a static package fallback. Use this before binding element source fields.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => [
                    'type' => 'string',
                    'description' => 'Optional GraphQL source type to inspect, for example Article, Category, Site, File, or Query.',
                ],
                'include_fields' => [
                    'type' => 'boolean',
                    'description' => 'Return field metadata. Defaults to true when type is provided, false for the full list.',
                ],
                'kind' => [
                    'type' => 'string',
                    'enum' => ['all', 'query', 'object'],
                    'description' => 'Filter returned source types. Defaults to all.',
                ],
            ],
        ];
    }

    public function getPermissions(): array
    {
        return [
            'risk_level' => self::RISK_READ,
            'idempotent' => true,
        ];
    }

    public function handle(array $arguments): array
    {
        $type = trim((string) ($arguments['type'] ?? ''));
        $kind = trim((string) ($arguments['kind'] ?? 'all'));
        $includeFields = array_key_exists('include_fields', $arguments)
            ? (bool) $arguments['include_fields']
            : $type !== '';

        if ($type !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $type)) {
            return ['error' => 'type must be a GraphQL type name, for example Article or Query.'];
        }

        if (!in_array($kind, ['all', 'query', 'object'], true)) {
            return ['error' => 'kind must be one of: all, query, object.'];
        }

        $live = $this->loadLiveSourceSchema($type, $kind, $includeFields);

        if (!isset($live['error'])) {
            return $live;
        }

        $fallback = $this->discoverInstalledSourcePackages();
        $fallback['live_error'] = $live;

        return $fallback;
    }

    /**
     * @return array<string, mixed>|array{error: string, code: string}
     */
    private function loadLiveSourceSchema(string $typeFilter, string $kindFilter, bool $includeFields): array
    {
        $bootstrap = $this->ensureYooThemeSourceRuntime();

        if (isset($bootstrap['error'])) {
            return [
                'error' => $bootstrap['error'],
                'code' => 'source_runtime_unavailable',
            ];
        }

        try {
            $source = \YOOtheme\app(\YOOtheme\Builder\Source::class);
            $result = $source->queryIntrospection();
            $payload = $result->toArray();
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
        $types = [];

        foreach (($schema['types'] ?? []) as $type) {
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

            $types[] = $item;
        }

        usort(
            $types,
            static fn (array $a, array $b): int => [$a['bucket'], $a['name']] <=> [$b['bucket'], $b['name']],
        );

        return [
            'mode' => 'live_introspection',
            'type' => $typeFilter !== '' ? $typeFilter : null,
            'kind' => $kindFilter,
            'query_type' => $queryTypeName,
            'type_count' => count($types),
            'types' => $types,
            'installed_packages' => $this->discoverSourcePackageNames(),
            'runtime_bootstrap' => $bootstrap,
            'note' => 'This is YOOtheme Builder Source GraphQL introspection as exposed by the installed runtime.',
        ];
    }

    /**
     * @return array<string, mixed>|array{error: string}
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
                        . '/{packages/{platform-joomla,builder,builder-source,builder-joomla,builder-joomla-source,builder-joomla-fields,builder-joomla-regularlabs}/bootstrap.php,config.php}'
                    );
                }
            }
        } catch (\Throwable $exception) {
            return ['error' => 'Unable to bootstrap YOOtheme Builder Source runtime: ' . $exception->getMessage()];
        }

        if (!function_exists('YOOtheme\\app') || !class_exists('YOOtheme\\Builder\\Source')) {
            return ['error' => 'YOOtheme Builder Source runtime is not loaded in this request.'];
        }

        return [
            'attempted' => true,
            'root' => $root !== null ? $this->relativeToSite($root) : null,
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

    private function formatTypeRef(mixed $type): string
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
     * @return array<string, mixed>
     */
    private function discoverInstalledSourcePackages(): array
    {
        $packages = [];

        foreach ($this->findPackageRoots() as $root) {
            $name = basename($root);

            if (!str_contains($name, 'source') && !str_contains($name, 'fields')) {
                continue;
            }

            $typeFiles = glob($root . '/src/Type/*Type.php') ?: [];

            $packages[] = [
                'name' => $name,
                'path' => $this->relativeToSite($root),
                'has_load_source_types_listener' => is_file($root . '/src/Listener/LoadSourceTypes.php'),
                'type_count' => count($typeFiles),
                'types' => array_values(array_map(
                    static fn (string $file): string => preg_replace('/Type\\.php$/', '', basename($file)) ?: basename($file),
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
            static fn (array $package): string => (string) ($package['name'] ?? ''),
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
        $siteRoot = defined('JPATH_SITE') ? JPATH_SITE : (defined('JPATH_ROOT') ? JPATH_ROOT : '');

        if (!is_string($siteRoot) || $siteRoot === '') {
            return null;
        }

        $themeRoot = rtrim($siteRoot, '/') . '/templates/yootheme';

        return is_dir($themeRoot) ? $themeRoot : null;
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

    private function relativeToSite(string $path): string
    {
        $siteRoot = defined('JPATH_SITE') ? JPATH_SITE : (defined('JPATH_ROOT') ? JPATH_ROOT : '');

        if (is_string($siteRoot) && $siteRoot !== '' && str_starts_with($path, rtrim($siteRoot, '/') . '/')) {
            return substr($path, strlen(rtrim($siteRoot, '/')) + 1);
        }

        return basename($path);
    }
}
