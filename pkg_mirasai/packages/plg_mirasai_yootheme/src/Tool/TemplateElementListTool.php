<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Mirasai\Library\Tool\AbstractTool;
use Mirasai\Library\Tool\YooThemeElementNavigator;
use Mirasai\Library\Tool\YooThemeHelper;

class TemplateElementListTool extends AbstractTool
{
    /** @var list<string> */
    private const FIELD_KEYS = [
        'path',
        'type',
        'depth',
        'index',
        'parent_path',
        'child_count',
        'prop_keys',
        'label',
        'has_source_binding',
    ];

    private YooThemeHelper $yooHelper;

    public function __construct()
    {
        parent::__construct();
        $this->yooHelper = new YooThemeHelper($this->db);
    }

    public function getName(): string
    {
        return 'template/element-list';
    }

    public function getDescription(): string
    {
        return 'Lists elements in a YOOtheme Builder template as a flat depth-first index with stable paths, type, depth, parent, child count, prop keys, label, and source-binding flag. Use this before template/element-read to locate the element to inspect.';
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
                'fields' => [
                    'type' => 'array',
                    'description' => 'Optional projection to reduce response size.',
                    'items' => [
                        'type' => 'string',
                        'enum' => self::FIELD_KEYS,
                    ],
                ],
                'max_results' => [
                    'type' => 'integer',
                    'description' => 'Optional maximum number of elements to return. Default 500, max 2000.',
                    'minimum' => 1,
                    'maximum' => 2000,
                ],
            ],
            'required' => ['key'],
        ];
    }

    public function handle(array $arguments): array
    {
        $key = trim((string) ($arguments['key'] ?? ''));

        if ($key === '') {
            return ['error' => 'Template key is required.'];
        }

        $fields = $this->normalizeFields($arguments['fields'] ?? null);

        if (isset($fields['error'])) {
            return $fields;
        }

        $maxResults = $this->normalizeMaxResults($arguments['max_results'] ?? null);

        if (isset($maxResults['error'])) {
            return $maxResults;
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

        $elements = (new YooThemeElementNavigator())->listElements($layout);
        $truncated = count($elements) > $maxResults['max_results'];
        $elements = array_slice($elements, 0, $maxResults['max_results']);

        if (isset($fields['fields'])) {
            $elements = array_map(
                fn (array $item): array => $this->projectFields($item, $fields['fields']),
                $elements,
            );
        }

        return [
            'key' => $key,
            'name' => $this->yooHelper->getTemplateName($template),
            'etag' => $this->yooHelper->buildTemplateEtag($template),
            'count' => count($elements),
            'truncated' => $truncated,
            'elements' => $elements,
        ];
    }

    /**
     * @return array{fields?: list<string>, error?: string}
     */
    private function normalizeFields(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (!is_array($value)) {
            return ['error' => 'fields must be an array of strings.'];
        }

        $fields = [];

        foreach ($value as $field) {
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
     * @return array{max_results: int, error?: string}
     */
    private function normalizeMaxResults(mixed $value): array
    {
        if ($value === null) {
            return ['max_results' => 500];
        }

        $maxResults = (int) $value;

        if ($maxResults < 1 || $maxResults > 2000) {
            return ['max_results' => 500, 'error' => 'max_results must be between 1 and 2000.'];
        }

        return ['max_results' => $maxResults];
    }

    /**
     * @param array<string, mixed> $item
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    private function projectFields(array $item, array $fields): array
    {
        if ($fields === []) {
            return $item;
        }

        $projected = [];

        foreach ($fields as $field) {
            $projected[$field] = $item[$field] ?? null;
        }

        return $projected;
    }
}
