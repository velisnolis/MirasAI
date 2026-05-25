<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Mirasai\Library\Tool\AbstractTool;
use Mirasai\Library\Tool\YooThemeElementNavigator;
use Mirasai\Library\Tool\YooThemeHelper;

class TemplateElementSourceReadTool extends AbstractTool
{
    private YooThemeHelper $yooHelper;

    public function __construct()
    {
        parent::__construct();
        $this->yooHelper = new YooThemeHelper($this->db);
    }

    public function getName(): string
    {
        return 'template/element-source-read';
    }

    public function getDescription(): string
    {
        return 'Reads the Dynamic Source binding for one YOOtheme Builder element. Uses props.source as the canonical binding, with source and source_extended as compatibility fallbacks.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'key' => [
                    'type' => 'string',
                    'description' => 'Template storage key as returned by template/list.',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Element path as returned by template/element-list.',
                ],
                'include_raw' => [
                    'type' => 'boolean',
                    'description' => 'Include the raw source binding payload. Defaults to false.',
                ],
            ],
            'required' => ['key', 'path'],
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
        $key = trim((string) ($arguments['key'] ?? ''));
        $path = trim((string) ($arguments['path'] ?? ''));
        $includeRaw = !empty($arguments['include_raw']);

        if ($key === '' || $path === '') {
            return ['error' => 'key and path are required.'];
        }

        $templates = $this->yooHelper->loadTemplates();
        $template = $templates[$key] ?? null;

        if (!is_array($template)) {
            return ['error' => "Template {$key} not found."];
        }

        $layout = $this->yooHelper->getTemplateLayout($template);

        if ($layout === null) {
            return ['error' => "Template {$key} has no layout."];
        }

        $result = (new YooThemeElementNavigator())->findElement($layout, $path);

        if ($result === null) {
            return ['error' => "Element path {$path} not found in template {$key}."];
        }

        $binding = $this->summarizeBinding($result['element']);

        if (!$includeRaw) {
            unset($binding['raw_source']);
        }

        return [
            'key' => $key,
            'name' => $this->yooHelper->getTemplateName($template),
            'etag' => $this->yooHelper->buildTemplateEtag($template),
            'metadata' => $result['metadata'],
            'binding' => $binding,
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

            $mappings[] = [
                'prop' => (string) $propName,
                'field' => $fieldName,
                'arguments' => $this->sanitizeValue($mapping['arguments'] ?? []),
                'directives' => $this->sanitizeValue($mapping['directives'] ?? []),
            ];
        }

        return [
            'has_binding' => true,
            'canonical_location' => $location,
            'source_name' => is_string($query['name'] ?? null) ? (string) $query['name'] : null,
            'query_field' => is_string($queryField['name'] ?? null) ? (string) $queryField['name'] : null,
            'query_arguments' => $this->sanitizeValue($queryField['arguments'] ?? []),
            'query_directives' => $this->sanitizeValue($queryField['directives'] ?? []),
            'field_mappings' => $mappings,
            'mapping_count' => count($mappings),
            'raw_source' => $this->sanitizeValue($source),
        ];
    }

    /**
     * @param array<string, mixed> $node
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function resolveBindingCarrier(array $node): ?array
    {
        $props = is_array($node['props'] ?? null) ? $node['props'] : [];

        if (is_array($props['source'] ?? null)) {
            return ['props.source', $props['source']];
        }

        if (is_string($props['source'] ?? null) && trim((string) $props['source']) !== '') {
            return ['props.source', ['query' => ['name' => trim((string) $props['source'])]]];
        }

        if (is_array($node['source'] ?? null)) {
            return ['source', $node['source']];
        }

        if (is_string($node['source'] ?? null) && trim((string) $node['source']) !== '') {
            return ['source', ['query' => ['name' => trim((string) $node['source'])]]];
        }

        if (is_array($node['source_extended'] ?? null)) {
            return ['source_extended', $node['source_extended']];
        }

        return null;
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
}
