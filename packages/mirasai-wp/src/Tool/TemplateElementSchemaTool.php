<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class TemplateElementSchemaTool extends AbstractTool
{
    public function getName(): string
    {
        return 'template/element-schema';
    }

    public function getDescription(): string
    {
        return 'Reads the installed YOOtheme Builder runtime definition for one WordPress element type and returns normalized field metadata.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['type'],
            'properties' => [
                'type' => [
                    'type' => 'string',
                    'description' => 'YOOtheme element type, for example headline, text, section, grid, or subnav.',
                ],
                'include_raw' => [
                    'type' => 'boolean',
                    'description' => 'Include sanitized raw element definition. Defaults to false.',
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array
    {
        $type = trim((string) ($arguments['type'] ?? ''));

        if ($type === '') {
            return ['error' => 'type is required.', 'code' => 'missing_type'];
        }

        $helper = new YoothemeWpHelper();
        $definition = $helper->loadElementDefinition($type);

        if (isset($definition['error'])) {
            return $definition;
        }

        $fields = $this->summarizeFields($definition['fields'] ?? []);
        $sourceFields = array_values(array_filter(
            $fields,
            static fn(array $field): bool => !empty($field['source'])
        ));

        $response = [
            'type' => is_string($definition['name'] ?? null) ? $definition['name'] : $type,
            'title' => is_string($definition['title'] ?? null) ? $definition['title'] : '',
            'group' => is_string($definition['group'] ?? null) ? $definition['group'] : '',
            'runtime_source' => $helper->elementsRuntimeSource(),
            'is_element' => !empty($definition['element']),
            'is_container' => !empty($definition['container']),
            'width' => is_int($definition['width'] ?? null) ? $definition['width'] : null,
            'defaults' => $this->sanitizeValue($definition['defaults'] ?? []),
            'placeholder_props' => $this->sanitizeValue($definition['placeholder']['props'] ?? []),
            'field_count' => count($fields),
            'source_field_count' => count($sourceFields),
            'source_fields' => $sourceFields,
            'fields' => $fields,
            'props_schema' => $this->buildPropsSchema($fields),
            'note' => 'This schema is derived from the installed YOOtheme PHP element definition. Dynamic enable/show expressions and PHP transforms are reported, not evaluated.',
        ];

        if (!empty($arguments['include_raw'])) {
            $response['raw'] = $this->sanitizeValue($definition);
        }

        return $response;
    }

    /**
     * @param mixed $rawFields
     * @return list<array<string, mixed>>
     */
    private function summarizeFields($rawFields): array
    {
        if (!is_array($rawFields)) {
            return [];
        }

        $fields = [];

        foreach ($rawFields as $name => $definition) {
            if (!is_string($name)) {
                continue;
            }

            $field = [
                'name' => $name,
            ];

            if (is_string($definition)) {
                $field['ref'] = $definition;
                $field['value_schema'] = ['type' => ['string', 'number', 'boolean', 'object', 'array', 'null']];
                $fields[] = $field;
                continue;
            }

            if (is_array($definition)) {
                foreach (['label', 'description', 'type', 'source', 'enable', 'show', 'options', 'attrs', 'text', 'root', 'default'] as $key) {
                    if (array_key_exists($key, $definition)) {
                        $field[$key] = $this->sanitizeValue($definition[$key]);
                    }
                }
            }

            $field['value_schema'] = $this->buildFieldValueSchema(is_array($definition) ? $definition : []);
            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function buildFieldValueSchema(array $definition): array
    {
        $type = is_string($definition['type'] ?? null) ? (string) $definition['type'] : '';

        return match ($type) {
            'checkbox' => ['type' => 'boolean'],
            'number', 'range' => ['type' => ['number', 'integer', 'string', 'null']],
            'select', 'radio', 'icon', 'editor', 'text', 'textarea', 'link', 'image', 'video' => ['type' => ['string', 'object', 'null']],
            default => ['type' => ['string', 'number', 'boolean', 'object', 'array', 'null']],
        };
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

            if ($name === '') {
                continue;
            }

            $properties[$name] = is_array($field['value_schema'] ?? null)
                ? $field['value_schema']
                : ['type' => ['string', 'number', 'boolean', 'object', 'array', 'null']];

            if (is_string($field['label'] ?? null) && $field['label'] !== '') {
                $properties[$name]['title'] = $field['label'];
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
     * @param mixed $value
     * @return mixed
     */
    private function sanitizeValue($value)
    {
        if ($value instanceof \Closure) {
            return '[closure]';
        }

        if (is_object($value)) {
            return '[' . get_class($value) . ']';
        }

        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $child) {
                $sanitized[$key] = $this->sanitizeValue($child);
            }

            return $sanitized;
        }

        return $value;
    }
}
