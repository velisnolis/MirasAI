<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Mirasai\Library\Tool\AbstractTool;
use Mirasai\Library\Tool\YooThemeHelper;

class TemplateListTool extends AbstractTool
{
    /** @var list<string> */
    private const FIELD_KEYS = [
        'key',
        'name',
        'type',
        'language',
        'etag',
        'dynamic_only',
        'has_static_text',
        'translatable_node_count',
        'assignment_fingerprint',
    ];

    private YooThemeHelper $yooHelper;

    public function __construct()
    {
        parent::__construct();
        $this->yooHelper = new YooThemeHelper($this->db);
    }

    public function getName(): string
    {
        return 'template/list';
    }

    public function getDescription(): string
    {
        return 'Lists YOOtheme Builder page templates (NOT articles — these are page-level layout overrides stored in the theme\'s custom_data). '
            . 'Templates control how specific pages look (e.g. a custom blog layout, a landing page template). '
            . 'Returns each template\'s key, assignment type, language filter, and whether it has translatable static text. '
            . 'Use template/read to inspect a specific template, then template/translate to create a language-specific copy.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'language' => [
                    'type' => 'string',
                    'description' => 'Optional language filter (e.g. ca-ES, es-ES). Use "*" to list templates shared across all languages.',
                ],
                'type' => [
                    'type' => 'string',
                    'description' => 'Optional YOOtheme template assignment type filter (e.g. com_content.article).',
                ],
                'has_static_text' => [
                    'type' => 'boolean',
                    'description' => 'If true, only return templates with fixed translatable text.',
                ],
                'fields' => [
                    'type' => 'array',
                    'description' => 'Optional projection to reduce response size. Allowed values: key, name, type, language, etag, dynamic_only, has_static_text, translatable_node_count, assignment_fingerprint.',
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
        $templates = $this->yooHelper->loadTemplates();
        $languageFilter = isset($arguments['language']) ? trim((string) $arguments['language']) : null;
        $typeFilter = isset($arguments['type']) ? trim((string) $arguments['type']) : null;
        $hasStaticFilter = array_key_exists('has_static_text', $arguments)
            ? (bool) $arguments['has_static_text']
            : null;
        $fields = $this->normalizeFields($arguments['fields'] ?? null);

        if (isset($fields['error'])) {
            return $fields;
        }

        $items = [];

        foreach ($templates as $key => $template) {
            if (!is_array($template)) {
                continue;
            }

            $language = $this->yooHelper->getTemplateLanguage($template);
            $language = $language === '' ? '*' : $language;
            $type = is_string($template['type'] ?? null) ? (string) $template['type'] : '';
            $translatableNodes = $this->yooHelper->findTemplateTranslatableNodes($template);
            $hasStaticText = $translatableNodes !== [];

            if ($languageFilter !== null && $languageFilter !== '' && $language !== $languageFilter) {
                continue;
            }

            if ($typeFilter !== null && $typeFilter !== '' && $type !== $typeFilter) {
                continue;
            }

            if ($hasStaticFilter !== null && $hasStaticText !== $hasStaticFilter) {
                continue;
            }

            $item = [
                'key' => (string) $key,
                'name' => $this->yooHelper->getTemplateName($template),
                'type' => $type,
                'language' => $language,
                'etag' => $this->yooHelper->buildTemplateEtag($template),
                'dynamic_only' => !$hasStaticText,
                'has_static_text' => $hasStaticText,
                'translatable_node_count' => count($translatableNodes),
                'assignment_fingerprint' => $this->yooHelper->buildTemplateAssignmentFingerprint($template),
            ];

            if (isset($fields['fields'])) {
                $item = $this->projectFields($item, $fields['fields']);
            }

            $items[] = $item;
        }

        return [
            'count' => count($items),
            'collection_etag' => $this->yooHelper->buildTemplatesEtag($templates),
            'templates' => $items,
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
