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
        'options',
        'attrs',
        'ref',
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

        $fields = $this->summarizeFields($definition['fields'] ?? [], $projection['fields'] ?? []);
        $sourceFields = array_values(array_filter(
            $fields,
            static fn (array $field): bool => !empty($field['source']),
        ));

        return [
            'type' => (string) ($definition['name'] ?? $type),
            'title' => is_string($definition['title'] ?? null) ? $definition['title'] : '',
            'group' => is_string($definition['group'] ?? null) ? $definition['group'] : '',
            'runtime_source' => 'templates/yootheme/packages/builder/elements',
            'file' => $this->relativeToSite($file),
            'is_element' => !empty($definition['element']),
            'width' => is_int($definition['width'] ?? null) ? $definition['width'] : null,
            'defaults' => $this->sanitizeValue($definition['defaults'] ?? []),
            'placeholder_props' => $this->sanitizeValue($definition['placeholder']['props'] ?? []),
            'field_count' => count($fields),
            'source_field_count' => count($sourceFields),
            'source_fields' => $sourceFields,
            'fields' => $fields,
            'note' => 'This is the installed YOOtheme PHP element definition, normalized for MCP. It is not a full JSON Schema validator.',
        ];
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
        $siteRoot = defined('JPATH_SITE') ? JPATH_SITE : (defined('JPATH_ROOT') ? JPATH_ROOT : '');

        if (!is_string($siteRoot) || $siteRoot === '') {
            return null;
        }

        $candidates = [
            $siteRoot . '/templates/yootheme/packages/builder/elements',
            $siteRoot . '/media/templates/site/yootheme/packages/builder/elements',
        ];

        foreach ($candidates as $candidate) {
            if (is_dir($candidate)) {
                return rtrim($candidate, '/');
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|array{error: string, code: string}
     */
    private function loadDefinition(string $file): array
    {
        try {
            $definition = include $file;
        } catch (\Throwable $exception) {
            return [
                'error' => 'Unable to load YOOtheme element definition: ' . $exception->getMessage(),
                'code' => 'element_schema_load_failed',
            ];
        }

        if (!is_array($definition)) {
            return [
                'error' => 'YOOtheme element definition did not return an array.',
                'code' => 'element_schema_invalid',
            ];
        }

        return $definition;
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
     * @return list<array<string, mixed>>
     */
    private function summarizeFields(mixed $rawFields, array $projection): array
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

            if (is_string($definition) && preg_match('/^\$\{builder\.([^}]+)\}$/', $definition, $matches)) {
                $field['ref'] = 'builder.' . $matches[1];
            } elseif (is_array($definition)) {
                foreach (self::FIELD_KEYS as $key) {
                    if ($key === 'name' || !array_key_exists($key, $definition)) {
                        continue;
                    }

                    $field[$key] = $this->sanitizeValue($definition[$key]);
                }
            }

            if ($projection !== []) {
                $field = array_intersect_key($field, array_fill_keys($projection, true));
                $field['name'] = $name;
            }

            $fields[] = $field;
        }

        return $fields;
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
