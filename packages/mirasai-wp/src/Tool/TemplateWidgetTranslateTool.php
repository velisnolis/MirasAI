<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class TemplateWidgetTranslateTool extends AbstractTool
{
    public function getName(): string
    {
        return 'template/widget-translate';
    }

    public function getDescription(): string
    {
        return 'Creates or updates a translated YOOtheme Builder widget instance. Requires widget_id, target_language, if_match, and dry_run/confirm_guarded_write.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['widget_id', 'target_language', 'if_match'],
            'properties' => [
                'widget_id' => [
                    'type' => 'string',
                    'description' => 'Source YOOtheme Builder widget instance ID from template/list.',
                ],
                'target_language' => [
                    'type' => 'string',
                    'description' => 'Target WPML/Polylang language code.',
                ],
                'translated_title' => [
                    'type' => 'string',
                    'description' => 'Optional translated widget title. Defaults to "<source title> (<lang>)".',
                ],
                'translated_layout' => [
                    'description' => 'Optional translated widget layout object or JSON string.',
                    'oneOf' => [
                        ['type' => 'object'],
                        ['type' => 'string'],
                    ],
                ],
                'yootheme_text_replacements' => [
                    'description' => 'Either a map of "path.field" => "translated text" or a list of replacement objects with path, field, and text. Paths come from template/read with widget_id.',
                    'oneOf' => [
                        ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                        ['type' => 'array', 'items' => ['type' => 'object']],
                    ],
                ],
                'overwrite' => [
                    'type' => 'boolean',
                    'description' => 'If true, update an existing translated widget created by this tool.',
                ],
                'copy_sidebar_position' => [
                    'type' => 'boolean',
                    'description' => 'Defaults to true. Inserts a newly created target widget after the source widget in the same sidebar.',
                ],
                'if_match' => [
                    'type' => 'string',
                    'description' => 'Required current source widget etag from template/list, template/read, or template/summary.',
                ],
                'dry_run' => [
                    'type' => 'boolean',
                    'description' => 'If true, validate and preview without writing options.',
                ],
                'confirm_guarded_write' => [
                    'type' => 'boolean',
                    'description' => 'Required for the real write after review. Not required when dry_run=true.',
                ],
            ],
        ];
    }

    public function getPermissions(): array
    {
        return [
            'risk_level' => self::RISK_GUARDED_WRITE,
            'idempotent' => false,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array
    {
        $widgetId = trim((string) ($arguments['widget_id'] ?? ''));
        $targetLanguage = trim((string) ($arguments['target_language'] ?? ''));
        $ifMatch = trim((string) ($arguments['if_match'] ?? ''));
        $overwrite = !empty($arguments['overwrite']);
        $dryRun = !empty($arguments['dry_run']);
        $confirmed = !empty($arguments['confirm_guarded_write']);
        $copySidebarPosition = array_key_exists('copy_sidebar_position', $arguments)
            ? !empty($arguments['copy_sidebar_position'])
            : true;

        if ($widgetId === '' || $targetLanguage === '' || $ifMatch === '') {
            return [
                'error' => 'widget_id, target_language, and if_match are required.',
                'code' => 'missing_required_argument',
            ];
        }

        if (!$dryRun && !$confirmed) {
            return [
                'error' => 'This is a guarded write. Retry with dry_run=true first, then confirm_guarded_write=true if the preview is correct.',
                'code' => 'guarded_write_confirmation_required',
            ];
        }

        $translations = new WordPressTranslationHelper();
        $languageCheck = $this->languageExists($translations, $targetLanguage);

        if (isset($languageCheck['error'])) {
            return $languageCheck;
        }

        $helper = new YoothemeWpHelper();
        $widgets = $helper->loadBuilderWidgets();
        $sourceWidget = $widgets[$widgetId] ?? null;

        if (!is_array($sourceWidget)) {
            return ['error' => "YOOtheme Builder widget {$widgetId} not found.", 'code' => 'widget_not_found'];
        }

        $sourceLayout = $helper->getWidgetLayout($sourceWidget);

        if ($sourceLayout === null) {
            return ['error' => "YOOtheme Builder widget {$widgetId} has no layout content.", 'code' => 'widget_layout_missing'];
        }

        $currentSourceEtag = $helper->etag($sourceWidget);

        if (!hash_equals($currentSourceEtag, $ifMatch)) {
            return [
                'error' => 'Source widget etag mismatch. Re-read the widget and retry with the fresh etag.',
                'code' => 'stale_etag',
                'expected_etag' => $currentSourceEtag,
                'provided_etag' => $ifMatch,
            ];
        }

        $sourceLanguage = is_string($sourceWidget['wpml_language'] ?? null) ? (string) $sourceWidget['wpml_language'] : null;

        if ($sourceLanguage === $targetLanguage) {
            return ['error' => 'target_language matches the source widget language.', 'code' => 'same_language'];
        }

        $translatedLayout = $this->resolveTranslatedLayout($sourceLayout, $arguments);

        if (isset($translatedLayout['error'])) {
            return $translatedLayout;
        }

        $existingTargetId = $this->findExistingTargetWidgetId($widgets, $widgetId, $targetLanguage);

        if ($existingTargetId !== null && !$overwrite) {
            return [
                'error' => "A translated widget already exists for {$targetLanguage}. Use overwrite=true to replace it.",
                'code' => 'target_widget_exists',
                'target_widget_id' => $existingTargetId,
            ];
        }

        $targetWidgetId = $existingTargetId ?? $this->nextWidgetId($widgets);
        $targetWidget = is_string($existingTargetId) && isset($widgets[$existingTargetId]) && is_array($widgets[$existingTargetId])
            ? $widgets[$existingTargetId]
            : $sourceWidget;

        $helper->setWidgetLayout($targetWidget, $translatedLayout['layout']);
        $targetWidget['title'] = $this->buildTargetTitle(
            $sourceWidget,
            $targetLanguage,
            isset($arguments['translated_title']) ? (string) $arguments['translated_title'] : ''
        );
        $targetWidget['wpml_language'] = $targetLanguage;
        $targetWidget['mirasai_source_widget_id'] = $widgetId;
        $targetWidget['mirasai_target_language'] = $targetLanguage;

        $sidebars = $this->loadSidebarsWidgets();
        $sidebarPlacement = $this->findSidebarPlacement($sidebars, 'builderwidget-' . $widgetId);
        $targetWidgetKey = 'builderwidget-' . $targetWidgetId;
        $willInsertSidebar = $existingTargetId === null && $copySidebarPosition && $sidebarPlacement !== null;

        if (!$dryRun) {
            $widgets[$targetWidgetId] = $targetWidget;
            update_option('widget_builderwidget', $widgets, false);

            if ($willInsertSidebar && $sidebarPlacement !== null) {
                $sidebars = $this->insertWidgetAfter($sidebars, $sidebarPlacement['sidebar'], $sidebarPlacement['index'], $targetWidgetKey);
                update_option('sidebars_widgets', $sidebars, false);
            }
        }

        $cache = $dryRun
            ? ['cleared' => false, 'groups' => [], 'failures' => [], 'reason' => 'dry_run']
            : $helper->invalidateBuilderCache();

        $response = [
            'source_widget_id' => $widgetId,
            'target_widget_id' => $targetWidgetId,
            'target_language' => $targetLanguage,
            'dry_run' => $dryRun,
            'action' => $existingTargetId !== null ? 'updated' : 'created',
            'title' => $targetWidget['title'],
            'source_etag' => $currentSourceEtag,
            'target_etag' => $helper->etag($targetWidget),
            'source_language' => $sourceLanguage,
            'sidebar' => $sidebarPlacement['sidebar'] ?? null,
            'sidebar_inserted' => !$dryRun && $willInsertSidebar,
            'would_insert_sidebar' => $dryRun && $willInsertSidebar,
            'cache' => $cache,
            'write_performed' => !$dryRun,
        ];

        if ($dryRun) {
            $response['note'] = 'No changes were written. Retry with confirm_guarded_write=true and the same if_match if the preview is still current.';
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $sourceLayout
     * @param array<string, mixed> $arguments
     * @return array{layout?: array<string, mixed>, error?: string, code?: string}
     */
    private function resolveTranslatedLayout(array $sourceLayout, array $arguments): array
    {
        if (isset($arguments['translated_layout'])) {
            $translatedLayout = $arguments['translated_layout'];

            if (is_string($translatedLayout)) {
                $decoded = json_decode($translatedLayout, true);

                if (!is_array($decoded)) {
                    return ['error' => 'translated_layout must be valid JSON.', 'code' => 'invalid_translated_layout'];
                }

                return ['layout' => $decoded];
            }

            if (is_array($translatedLayout)) {
                return ['layout' => $translatedLayout];
            }

            return ['error' => 'translated_layout must be an object or JSON string.', 'code' => 'invalid_translated_layout'];
        }

        $replacements = $this->normalizeReplacements($arguments['yootheme_text_replacements'] ?? null);

        if (isset($replacements['error'])) {
            return $replacements;
        }

        if ($replacements === []) {
            return [
                'error' => 'YOOtheme Builder widgets require translated_layout or yootheme_text_replacements. Refusing to copy the source layout unchanged.',
                'code' => 'missing_widget_translation',
            ];
        }

        return ['layout' => (new YoothemeLayoutProcessor())->patchLayoutArray($sourceLayout, $replacements)];
    }

    /**
     * @param mixed $raw
     * @return array<string, string>|array{error: string, code: string}
     */
    private function normalizeReplacements($raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (!is_array($raw)) {
            return ['error' => 'yootheme_text_replacements must be an object map or an array of replacement objects.', 'code' => 'invalid_replacements'];
        }

        if ($raw === []) {
            return [];
        }

        if (!array_is_list($raw)) {
            $normalized = [];

            foreach ($raw as $path => $text) {
                if (!is_string($path) || !is_string($text) || trim($path) === '') {
                    return ['error' => 'Invalid yootheme_text_replacements map entry.', 'code' => 'invalid_replacements'];
                }

                $normalized[$path] = $text;
            }

            return $normalized;
        }

        $normalized = [];

        foreach ($raw as $item) {
            if (!is_array($item)) {
                return ['error' => 'Each replacement entry must be an object with path, optional field, and text.', 'code' => 'invalid_replacements'];
            }

            $path = trim((string) ($item['path'] ?? ''));
            $field = trim((string) ($item['field'] ?? ''));
            $text = $item['text'] ?? null;

            if ($path === '' || !is_string($text)) {
                return ['error' => 'Each replacement entry requires path and text.', 'code' => 'invalid_replacements'];
            }

            $normalized[$field !== '' ? "{$path}.{$field}" : $path] = $text;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $widgets
     */
    private function findExistingTargetWidgetId(array $widgets, string $sourceWidgetId, string $targetLanguage): ?string
    {
        foreach ($widgets as $id => $widget) {
            if (!is_array($widget) || !is_string($id) && !is_int($id)) {
                continue;
            }

            if ((string) ($widget['mirasai_source_widget_id'] ?? '') !== $sourceWidgetId) {
                continue;
            }

            if ((string) ($widget['mirasai_target_language'] ?? $widget['wpml_language'] ?? '') === $targetLanguage) {
                return (string) $id;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $widgets
     */
    private function nextWidgetId(array $widgets): string
    {
        $max = 0;

        foreach (array_keys($widgets) as $key) {
            if (is_numeric($key)) {
                $max = max($max, (int) $key);
            }
        }

        return (string) ($max + 1);
    }

    /**
     * @param array<string, mixed> $widget
     */
    private function buildTargetTitle(array $widget, string $targetLanguage, string $explicit): string
    {
        $explicit = trim($explicit);

        if ($explicit !== '') {
            return $explicit;
        }

        $sourceTitle = is_string($widget['title'] ?? null) && trim((string) $widget['title']) !== ''
            ? trim((string) $widget['title'])
            : 'YOOtheme Builder';

        $baseTitle = preg_replace('/ \([A-Za-z]{2,3}(?:-[A-Za-z]{2,3})?\)$/', '', $sourceTitle) ?: $sourceTitle;

        return trim($baseTitle) . ' (' . $targetLanguage . ')';
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSidebarsWidgets(): array
    {
        $sidebars = get_option('sidebars_widgets', []);

        return is_array($sidebars) ? $sidebars : [];
    }

    /**
     * @param array<string, mixed> $sidebars
     * @return array{sidebar: string, index: int}|null
     */
    private function findSidebarPlacement(array $sidebars, string $widgetKey): ?array
    {
        foreach ($sidebars as $sidebar => $widgets) {
            if (!is_string($sidebar) || !is_array($widgets)) {
                continue;
            }

            $index = array_search($widgetKey, $widgets, true);

            if (is_int($index)) {
                return [
                    'sidebar' => $sidebar,
                    'index' => $index,
                ];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $sidebars
     * @return array<string, mixed>
     */
    private function insertWidgetAfter(array $sidebars, string $sidebar, int $sourceIndex, string $targetWidgetKey): array
    {
        $widgets = is_array($sidebars[$sidebar] ?? null) ? $sidebars[$sidebar] : [];

        if (in_array($targetWidgetKey, $widgets, true)) {
            return $sidebars;
        }

        array_splice($widgets, $sourceIndex + 1, 0, [$targetWidgetKey]);
        $sidebars[$sidebar] = $widgets;

        return $sidebars;
    }

    /**
     * @return array{ok: true}|array{error: string, code: string, provider?: array<string, mixed>, languages?: list<array<string, mixed>>}
     */
    private function languageExists(WordPressTranslationHelper $translations, string $language): array
    {
        $languages = $translations->languages();

        if ($languages === []) {
            return [
                'error' => 'No supported multilingual provider is active. template/widget-translate needs WPML or Polylang language metadata.',
                'code' => 'multilingual_provider_missing',
                'provider' => $translations->provider(),
                'languages' => [],
            ];
        }

        foreach ($languages as $item) {
            if (($item['code'] ?? null) === $language) {
                return ['ok' => true];
            }
        }

        return [
            'error' => "Language {$language} is not active.",
            'code' => 'language_not_found',
            'provider' => $translations->provider(),
            'languages' => $languages,
        ];
    }
}
