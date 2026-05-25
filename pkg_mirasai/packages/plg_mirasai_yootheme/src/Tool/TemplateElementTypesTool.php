<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Mirasai\Library\Tool\AbstractTool;
use Mirasai\Library\Tool\YooThemeElementNavigator;
use Mirasai\Library\Tool\YooThemeHelper;

class TemplateElementTypesTool extends AbstractTool
{
    /** @var list<string> */
    private const FIELD_KEYS = [
        'type',
        'count',
        'max_depth',
        'prop_keys',
        'has_source_binding_count',
        'sample_paths',
    ];

    private YooThemeHelper $yooHelper;

    public function __construct()
    {
        parent::__construct();
        $this->yooHelper = new YooThemeHelper($this->db);
    }

    public function getName(): string
    {
        return 'template/element-types';
    }

    public function getDescription(): string
    {
        return 'Summarizes observed YOOtheme Builder element types from one template or all stored templates. Returns counts, max depth, observed prop keys, source-binding counts, and sample element paths. This is discovery from saved layouts, not the full YOOtheme registry schema.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'key' => [
                    'type' => 'string',
                    'description' => 'Optional template storage key. If omitted, summarize all stored YOOtheme templates.',
                ],
                'fields' => [
                    'type' => 'array',
                    'description' => 'Optional projection to reduce response size.',
                    'items' => [
                        'type' => 'string',
                        'enum' => self::FIELD_KEYS,
                    ],
                ],
            ],
        ];
    }

    public function handle(array $arguments): array
    {
        $key = isset($arguments['key']) ? trim((string) $arguments['key']) : '';
        $fields = $this->normalizeFields($arguments['fields'] ?? null);

        if (isset($fields['error'])) {
            return $fields;
        }

        $templates = $this->yooHelper->loadTemplates();
        $selectedTemplates = [];
        $templateNames = [];
        $templateEtag = null;

        if ($key !== '') {
            $template = $templates[$key] ?? null;

            if (!is_array($template)) {
                return ['error' => "Template {$key} not found."];
            }

            $layout = $this->yooHelper->getTemplateLayout($template);

            if ($layout === null) {
                return ['error' => "Template {$key} has no layout."];
            }

            $selectedTemplates[$key] = $layout;
            $templateNames[$key] = $this->yooHelper->getTemplateName($template);
            $templateEtag = $this->yooHelper->buildTemplateEtag($template);
        } else {
            foreach ($templates as $templateKey => $template) {
                if (!is_array($template)) {
                    continue;
                }

                $layout = $this->yooHelper->getTemplateLayout($template);

                if ($layout === null) {
                    continue;
                }

                $selectedTemplates[(string) $templateKey] = $layout;
                $templateNames[(string) $templateKey] = $this->yooHelper->getTemplateName($template);
            }
        }

        $types = (new YooThemeElementNavigator())->summarizeTypes(array_values($selectedTemplates));

        if (isset($fields['fields'])) {
            $types = array_map(
                fn (array $item): array => $this->projectFields($item, $fields['fields']),
                $types,
            );
        }

        $response = [
            'key' => $key !== '' ? $key : null,
            'etag' => $templateEtag,
            'collection_etag' => $key === '' ? $this->yooHelper->buildTemplatesEtag($templates) : null,
            'template_count' => count($selectedTemplates),
            'templates' => $templateNames,
            'type_count' => count($types),
            'types' => $types,
        ];

        return $response;
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
