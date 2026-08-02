<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Mirasai\Library\Tool\AbstractTool;

class TemplateElementSchemaTool extends AbstractTool
{
    private const FIELD_KEYS = [
        'name',
        'label',
        'description',
        'type',
        'source',
        'enable',
        'show',
        'options',
        'attrs',
        'ref',
        'resolved',
        'value_schema',
        'text',
        'root',
        'default',
        'prop',
        'item',
        'media',
        'internal',
        'width',
    ];

    public function getName(): string
    {
        return 'template/element-schema';
    }

    public function getDescription(): string
    {
        return 'Reads the installed YOOtheme Builder runtime definition for one element type. Returns defaults, placeholder props, field metadata, and source-capable fields from templates/yootheme/packages/builder/elements.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => [
                    'type' => 'string',
                    'description' => 'YOOtheme element type, for example headline, text, section, grid, or column.',
                ],
                'fields' => [
                    'type' => 'array',
                    'description' => 'Optional projection for each field entry.',
                    'items' => [
                        'type' => 'string',
                        'enum' => self::FIELD_KEYS,
                    ],
                ],
                'include_props_schema' => [
                    'type' => 'boolean',
                    'description' => 'Include a JSON-Schema-like props schema derived from YOOtheme field metadata. Defaults to true.',
                ],
                'include_fieldset' => [
                    'type' => 'boolean',
                    'description' => 'Include the normalized YOOtheme fieldset structure. Defaults to false because it can be verbose.',
                ],
                'include_raw' => [
                    'type' => 'boolean',
                    'description' => 'Include the sanitized raw element definition. Defaults to false.',
                ],
                'resolve_refs' => [
                    'type' => 'boolean',
                    'description' => 'Resolve ${builder.*} field references using the installed YOOtheme builder config. Defaults to true.',
                ],
            ],
            'required' => ['type'],
        ];
    }

    public function handle(array $arguments): array
    {
        $type = trim((string) ($arguments['type'] ?? ''));
        $projection = $this->normalizeProjection($arguments['fields'] ?? null);

        if ($type === '') {
            return ['error' => 'type is required.'];
        }

        if (!preg_match('/^[A-Za-z0-9_-]+$/', $type)) {
            return ['error' => 'type may only contain letters, numbers, underscores, and hyphens.'];
        }

        if (isset($projection['error'])) {
            return $projection;
        }

        $root = $this->findElementsRoot();
        $resolveRefs = !array_key_exists('resolve_refs', $arguments) || (bool) $arguments['resolve_refs'];
        $includePropsSchema = !array_key_exists('include_props_schema', $arguments) || (bool) $arguments['include_props_schema'];
        $includeFieldset = !empty($arguments['include_fieldset']);
        $includeRaw = !empty($arguments['include_raw']);

        if ($root === null) {
            return [
                'error' => 'Installed YOOtheme Builder elements directory was not found.',
                'code' => 'yootheme_elements_root_missing',
            ];
        }

        $file = $root . '/' . $type . '/element.php';

        if (!is_file($file)) {
            return [
                'error' => "Element type {$type} was not found in the installed YOOtheme Builder registry.",
                'code' => 'element_schema_not_found',
                'type' => $type,
            ];
        }

        $definition = $this->loadDefinition($file);

        if (isset($definition['error'])) {
            return $definition;
        }

        $builderConfig = $resolveRefs ? $this->loadBuilderConfig($root) : [];
        $fields = $this->summarizeFields($definition['fields'] ?? [], $projection['fields'] ?? [], $builderConfig);
        $sourceFields = array_values(array_filter(
            $fields,
            static fn (array $field): bool => !empty($field['source']),
        ));

        $response = [
            'type' => (string) ($definition['name'] ?? $type),
            'title' => is_string($definition['title'] ?? null) ? $definition['title'] : '',
            'group' => is_string($definition['group'] ?? null) ? $definition['group'] : '',
            'runtime_source' => 'templates/yootheme/packages/builder/elements',
            'file' => $this->relativeToSite($file),
            'refs_resolved' => $resolveRefs,
            'is_element' => !empty($definition['element']),
            'is_container' => !empty($definition['container']),
            'width' => is_int($definition['width'] ?? null) ? $definition['width'] : null,
            'defaults' => $this->sanitizeValue($definition['defaults'] ?? []),
            'placeholder_props' => $this->sanitizeValue($definition['placeholder']['props'] ?? []),
            'field_count' => count($fields),
            'source_field_count' => count($sourceFields),
            'source_fields' => $sourceFields,
            'fields' => $fields,
            'source_binding_schema' => $this->buildSourceBindingSchema($sourceFields),
            'note' => 'This is the installed YOOtheme PHP element definition normalized for MCP. props_schema is derived from runtime field metadata; YOOtheme enable/show expressions and PHP transforms are reported but not evaluated.',
        ];

        if ($includePropsSchema) {
            $response['props_schema'] = $this->buildPropsSchema($fields);
            $response['element_schema'] = $this->buildElementSchema((string) ($definition['name'] ?? $type), $fields);
        }

        if ($includeFieldset) {
            $response['fieldset'] = $this->sanitizeValue($definition['fieldset'] ?? []);
        }

        if ($includeRaw) {
            $response['raw'] = $this->sanitizeValue($definition);
        }

        return $response;
    }

    public function getPermissions(): array
    {
        return [
            'risk_level' => self::RISK_READ,
            'idempotent' => true,
        ];
    }

    private function findElementsRoot(): ?string
    {
        return YoothemeElementDefinitionLoader::root();
    }

    /**
     * @return array<string, mixed>|array{error: string, code: string}
     */
    private function loadDefinition(string $file): array
    {
        return YoothemeElementDefinitionLoader::loadFile($file);
    }

    /**
     * @param mixed $raw
     * @return array{fields?: list<string>, error?: string}
     */
    private function normalizeProjection(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (!is_array($raw)) {
            return ['error' => 'fields must be an array of strings.'];
        }

        $fields = [];

        foreach ($raw as $field) {
            if (!is_string($field)) {
                return ['error' => 'fields must contain strings only.'];
            }

            $field = trim($field);

            if (!in_array($field, self::FIELD_KEYS, true)) {
                return ['error' => "Unsupported field '{$field}'. Allowed fields: " . implode(', ', self::FIELD_KEYS) . '.'];
            }

            if (!in_array($field, $fields, true)) {
                $fields[] = $field;
            }
        }

        return ['fields' => $fields];
    }

    /**
     * @param mixed $rawFields
     * @param list<string> $projection
     * @param array<string, mixed> $builderConfig
     * @return list<array<string, mixed>>
     */
    private function summarizeFields(mixed $rawFields, array $projection, array $builderConfig): array
    {
        if (!is_array($rawFields)) {
            return [];
        }

        $fields = [];

        foreach ($rawFields as $name => $definition) {
            if (!is_string($name)) {
                continue;
            }

            $field = ['name' => $name];
            $fieldDefinition = $definition;

            if (is_string($definition) && preg_match('/^\$\{builder\.([^}]+)\}$/', $definition, $matches)) {
                $field['ref'] = 'builder.' . $matches[1];
                $resolved = $builderConfig[$matches[1]] ?? null;

                if (is_array($resolved)) {
                    $field['resolved'] = true;
                    $fieldDefinition = $resolved;
                }
            }

            if (is_array($fieldDefinition)) {
                foreach (self::FIELD_KEYS as $key) {
                    if (in_array($key, ['name', 'ref', 'resolved', 'value_schema'], true)
                        || !array_key_exists($key, $fieldDefinition)
                    ) {
                        continue;
                    }

                    $field[$key] = $this->sanitizeValue($fieldDefinition[$key]);
                }
            }

            $field['value_schema'] = $this->buildFieldValueSchema(is_array($fieldDefinition) ? $fieldDefinition : []);

            if ($projection !== []) {
                $field = array_intersect_key($field, array_fill_keys($projection, true));
                $field['name'] = $name;
            }

            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadBuilderConfig(string $elementsRoot): array
    {
        $builderRoot = dirname($elementsRoot);
        $configFile = $builderRoot . '/config/builder.php';

        if (!is_file($configFile)) {
            return [];
        }

        return $this->loadConfigWithImports($configFile);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadConfigWithImports(string $file, int $depth = 0): array
    {
        if ($depth > 4 || !is_file($file)) {
            return [];
        }

        try {
            $config = include $file;
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($config)) {
            return [];
        }

        $imports = $config['@import'] ?? [];
        unset($config['@import']);

        if (is_string($imports)) {
            $imports = [$imports];
        }

        $merged = [];

        if (is_array($imports)) {
            foreach ($imports as $import) {
                if (!is_string($import)) {
                    continue;
                }

                $merged = array_replace_recursive($merged, $this->loadConfigWithImports($import, $depth + 1));
            }
        }

        return array_replace_recursive($merged, $config);
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @return array<string, mixed>
     */
    private function buildPropsSchema(array $fields): array
    {
        $properties = [];

        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');

            if ($name === '' || str_starts_with($name, '_')) {
                continue;
            }

            $properties[$name] = is_array($field['value_schema'] ?? null)
                ? $field['value_schema']
                : ['type' => ['string', 'number', 'boolean', 'object', 'array', 'null']];

            if (is_string($field['label'] ?? null) && $field['label'] !== '') {
                $properties[$name]['title'] = $field['label'];
            }

            if (is_string($field['description'] ?? null) && $field['description'] !== '') {
                $properties[$name]['description'] = $field['description'];
            }

            if (array_key_exists('default', $field)) {
                $properties[$name]['default'] = $field['default'];
            }
        }

        return [
            'type' => 'object',
            'additionalProperties' => true,
            'properties' => $properties,
        ];
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @return array<string, mixed>
     */
    private function buildElementSchema(string $type, array $fields): array
    {
        return [
            'type' => 'object',
            'required' => ['type'],
            'additionalProperties' => true,
            'properties' => [
                'type' => [
                    'type' => 'string',
                    'const' => $type,
                ],
                'props' => $this->buildPropsSchema($fields),
                'children' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                    ],
                ],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $sourceFields
     * @return array<string, mixed>
     */
    private function buildSourceBindingSchema(array $sourceFields): array
    {
        $mappableProps = array_values(array_filter(array_map(
            static fn (array $field): string => (string) ($field['name'] ?? ''),
            $sourceFields,
        )));

        return [
            'canonical_location' => 'source',
            'mappable_props' => $mappableProps,
            'shape' => [
                'type' => 'object',
                'required' => ['query', 'props'],
                'additionalProperties' => true,
                'properties' => [
                    'query' => [
                        'type' => 'object',
                        'required' => ['name', 'field'],
                        'additionalProperties' => true,
                    ],
                    'props' => [
                        'type' => 'object',
                        'additionalProperties' => [
                            'type' => 'object',
                            'required' => ['name'],
                            'additionalProperties' => true,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function buildFieldValueSchema(array $definition): array
    {
        $fieldType = is_string($definition['type'] ?? null) ? $definition['type'] : 'text';
        $schema = match ($fieldType) {
            'checkbox' => ['type' => ['boolean', 'string', 'null']],
            'range', 'number' => ['type' => ['number', 'integer', 'string', 'null']],
            'content-items' => ['type' => 'array', 'items' => ['type' => 'object']],
            'fields', 'grid' => ['type' => 'object', 'additionalProperties' => true],
            'image', 'video', 'icon', 'link', 'editor', 'select', 'text', 'textarea', 'color', 'gradient', 'hidden' => ['type' => ['string', 'null']],
            default => ['type' => ['string', 'number', 'boolean', 'object', 'array', 'null']],
        };

        $enum = $this->extractOptionValues($definition['options'] ?? null);

        if ($enum !== []) {
            $schema['enum'] = $enum;
        }

        if (array_key_exists('attrs', $definition) && is_array($definition['attrs'])) {
            foreach (['min', 'max', 'step'] as $key) {
                if (is_scalar($definition['attrs'][$key] ?? null)) {
                    $schema[$key === 'step' ? 'multipleOf' : $key] = $definition['attrs'][$key];
                }
            }
        }

        return $schema;
    }

    /**
     * @return list<mixed>
     */
    private function extractOptionValues(mixed $options): array
    {
        if (!is_array($options)) {
            return [];
        }

        $values = [];

        foreach ($options as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $values[] = $value;
                continue;
            }

            if (is_array($value) && array_key_exists('value', $value) && (is_scalar($value['value']) || $value['value'] === null)) {
                $values[] = $value['value'];
                continue;
            }

            if (is_string($key)) {
                $values[] = $key;
            }
        }

        $unique = [];

        foreach ($values as $value) {
            $fingerprint = gettype($value) . ':' . (string) $value;

            if (!array_key_exists($fingerprint, $unique)) {
                $unique[$fingerprint] = $value;
            }
        }

        return array_values($unique);
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
            if ($item instanceof \Closure || in_array((string) $key, ['transforms', 'templates', 'updates'], true)) {
                continue;
            }

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
