<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class ContentTranslateTool extends AbstractTool
{
    private WordPressTranslationHelper $translations;

    public function __construct(?WordPressTranslationHelper $translations = null)
    {
        $this->translations = $translations ?? new WordPressTranslationHelper();
    }

    public function getName(): string
    {
        return 'content/translate';
    }

    public function getDescription(): string
    {
        return 'Creates or updates a translated WordPress post/page. You must provide translated content; this tool does not auto-translate. Supports YOOtheme Builder post layouts through yootheme_text_replacements from content/read.';
    }

    public function getSurface(): string
    {
        return 'essential';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['source_id', 'target_language', 'translated_title', 'if_match'],
            'properties' => [
                'source_id' => ['type' => 'integer', 'description' => 'ID of the source post/page to translate.'],
                'target_language' => ['type' => 'string', 'description' => 'Target WPML/Polylang language code.'],
                'translated_title' => ['type' => 'string', 'description' => 'Translated post title.'],
                'translated_slug' => ['type' => 'string', 'description' => 'Optional translated slug. Auto-generated from title if omitted.'],
                'translated_content' => ['type' => 'string', 'description' => 'Translated post content for non-YOOtheme posts.'],
                'translated_excerpt' => ['type' => 'string', 'description' => 'Optional translated excerpt.'],
                'translated_layout' => [
                    'description' => 'Optional translated YOOtheme layout object or JSON string for posts/pages with a detected YOOtheme Builder layout.',
                    'oneOf' => [
                        ['type' => 'object'],
                        ['type' => 'string'],
                    ],
                ],
                'yootheme_text_replacements' => [
                    'description' => 'For YOOtheme layouts, either a map of "path.field" => "translated text" or a list of replacement objects with path, field, and text. Paths come from content/read yootheme_translatable_nodes.',
                    'oneOf' => [
                        [
                            'type' => 'object',
                            'additionalProperties' => ['type' => 'string'],
                        ],
                        [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'required' => ['path', 'text'],
                                'properties' => [
                                    'path' => ['type' => 'string'],
                                    'field' => ['type' => 'string'],
                                    'text' => ['type' => 'string'],
                                ],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Target post status. Defaults to draft for new translations, preserves current status when overwriting.',
                ],
                'overwrite' => [
                    'type' => 'boolean',
                    'description' => 'If true, update an existing target-language translation. Default false returns an error if one exists.',
                ],
                'copy_terms' => [
                    'type' => 'boolean',
                    'description' => 'If true, copy source taxonomy terms directly. Defaults to false because multilingual term mapping is site-specific.',
                ],
                'if_match' => [
                    'type' => 'string',
                    'description' => 'Required current source etag from content/read or content/list. Stale values are rejected before writing.',
                ],
                'dry_run' => [
                    'type' => 'boolean',
                    'description' => 'If true, validate and preview without creating or updating content.',
                ],
            ],
        ];
    }

    public function getPermissions(): array
    {
        return [
            'risk_level' => self::RISK_SAFE_WRITE,
            'idempotent' => false,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array
    {
        $sourceId = (int) ($arguments['source_id'] ?? 0);
        $targetLanguage = trim((string) ($arguments['target_language'] ?? ''));
        $translatedTitle = trim((string) ($arguments['translated_title'] ?? ''));
        $ifMatch = trim((string) ($arguments['if_match'] ?? ''));
        $overwrite = !empty($arguments['overwrite']);
        $dryRun = !empty($arguments['dry_run']);

        if ($sourceId <= 0 || $targetLanguage === '' || $translatedTitle === '' || $ifMatch === '') {
            return [
                'error' => 'source_id, target_language, translated_title, and if_match are required.',
                'code' => 'missing_required_argument',
            ];
        }

        $provider = $this->translations->provider();

        if (empty($provider['active'])) {
            return [
                'error' => 'No supported multilingual provider is active. content/translate needs WPML or Polylang.',
                'code' => 'multilingual_provider_missing',
                'provider' => $provider,
            ];
        }

        if (!$this->translations->languageExists($targetLanguage)) {
            return [
                'error' => "Language {$targetLanguage} is not active.",
                'code' => 'language_not_found',
                'provider' => $provider,
                'languages' => $this->translations->languages(),
            ];
        }

        $source = get_post($sourceId);

        if (!$source instanceof \WP_Post) {
            return ['error' => "Source post {$sourceId} not found.", 'code' => 'source_not_found'];
        }

        $currentEtag = ContentReadTool::contentEtag($source);

        if (!hash_equals($currentEtag, $ifMatch)) {
            return [
                'error' => 'Source post etag mismatch. Re-read the source and retry with the fresh etag.',
                'code' => 'stale_etag',
                'expected_etag' => $currentEtag,
                'provided_etag' => $ifMatch,
            ];
        }

        $sourceLanguage = $this->translations->postLanguage($sourceId, (string) $source->post_type);

        if ($sourceLanguage === $targetLanguage) {
            return ['error' => 'target_language matches the source post language.', 'code' => 'same_language'];
        }

        $existingId = $this->translations->postTranslations($sourceId, (string) $source->post_type)[$targetLanguage] ?? null;

        if (is_int($existingId) && $existingId > 0 && !$overwrite) {
            return [
                'error' => "Translation already exists for {$targetLanguage} (post ID: {$existingId}).",
                'code' => 'translation_exists',
                'existing_id' => $existingId,
                'hint' => 'Set overwrite=true to update the existing translation.',
            ];
        }

        $layoutTarget = (new YoothemeWpHelper())->loadPostLayout($sourceId);
        $translatedLayout = null;

        if ($layoutTarget !== null) {
            $layoutResult = $this->resolveTranslatedLayout($layoutTarget['layout'], $arguments);

            if (isset($layoutResult['error'])) {
                return $layoutResult;
            }

            $translatedLayout = $layoutResult['layout'];
        } elseif (trim((string) $source->post_content) !== '' && trim((string) ($arguments['translated_content'] ?? '')) === '') {
            return [
                'error' => 'Non-YOOtheme posts with source content require translated_content.',
                'code' => 'missing_translated_content',
            ];
        }

        $status = isset($arguments['status']) && is_string($arguments['status']) && trim($arguments['status']) !== ''
            ? trim($arguments['status'])
            : (is_int($existingId) && $existingId > 0 ? (string) get_post_status($existingId) : 'draft');
        $translatedSlug = isset($arguments['translated_slug']) && is_string($arguments['translated_slug'])
            ? sanitize_title($arguments['translated_slug'])
            : sanitize_title($translatedTitle);
        $translatedExcerpt = isset($arguments['translated_excerpt']) && is_string($arguments['translated_excerpt'])
            ? $arguments['translated_excerpt']
            : '';
        $translatedContent = isset($arguments['translated_content']) && is_string($arguments['translated_content'])
            ? $arguments['translated_content']
            : ($layoutTarget === null ? '' : (string) $source->post_content);

        $plannedId = is_int($existingId) && $existingId > 0 ? $existingId : null;

        if ($dryRun) {
            return [
                'action' => $plannedId !== null ? 'updated' : 'created',
                'dry_run' => true,
                'source_id' => $sourceId,
                'target_id' => $plannedId,
                'target_language' => $targetLanguage,
                'post_type' => (string) $source->post_type,
                'status' => $status,
                'title' => $translatedTitle,
                'slug' => $translatedSlug,
                'has_yootheme_builder' => $layoutTarget !== null,
                'would_write_layout' => $translatedLayout !== null,
                'copy_terms' => !empty($arguments['copy_terms']),
                'source_etag' => $currentEtag,
                'translation_provider' => $provider,
                'write_performed' => false,
                'note' => 'No changes were written. Retry without dry_run when the preview is correct.',
            ];
        }

        $postFields = [
            'post_type' => (string) $source->post_type,
            'post_status' => $status,
            'post_title' => $translatedTitle,
            'post_name' => $translatedSlug,
            'post_content' => $translatedContent,
            'post_excerpt' => $translatedExcerpt,
            'post_author' => get_current_user_id() ?: (int) $source->post_author,
            'post_parent' => (int) $source->post_parent,
        ];

        if ($plannedId !== null) {
            $postFields['ID'] = $plannedId;
            $result = wp_update_post($postFields, true);
            $action = 'updated';
        } else {
            $result = wp_insert_post($postFields, true);
            $action = 'created';
        }

        if (is_wp_error($result)) {
            return ['error' => $result->get_error_message(), 'code' => 'post_write_failed'];
        }

        $targetId = (int) $result;
        $this->writeTranslatedLayout($targetId, $layoutTarget, $translatedLayout);

        if (!empty($arguments['copy_terms'])) {
            $this->copyTerms($sourceId, $targetId, (string) $source->post_type);
        }

        $association = $this->translations->setPostLanguageAndAssociation(
            $targetId,
            (string) $source->post_type,
            $targetLanguage,
            $sourceId,
        );

        if (isset($association['error'])) {
            return $association + [
                'post_written' => true,
                'target_id' => $targetId,
                'warning' => 'The post was written but language association failed.',
            ];
        }

        $targetPost = get_post($targetId);

        return [
            'action' => $action,
            'source_id' => $sourceId,
            'target_id' => $targetId,
            'target_language' => $targetLanguage,
            'post_type' => (string) $source->post_type,
            'status' => $status,
            'title' => $translatedTitle,
            'slug' => get_post_field('post_name', $targetId),
            'link' => get_permalink($targetId),
            'has_yootheme_builder' => $layoutTarget !== null,
            'layout_written' => $translatedLayout !== null,
            'terms_copied' => !empty($arguments['copy_terms']),
            'source_etag' => $currentEtag,
            'target_etag' => $targetPost instanceof \WP_Post ? ContentReadTool::contentEtag($targetPost) : null,
            'translation_provider' => $provider,
            'write_performed' => true,
        ];
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
                'error' => 'YOOtheme Builder posts require translated_layout or yootheme_text_replacements. Refusing to copy the source layout unchanged.',
                'code' => 'missing_yootheme_translation',
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
     * @param array{layout: array<string, mixed>, meta_key?: string, source: string, raw_value?: mixed}|null $layoutTarget
     * @param array<string, mixed>|null $translatedLayout
     */
    private function writeTranslatedLayout(int $targetId, ?array $layoutTarget, ?array $translatedLayout): void
    {
        if ($layoutTarget === null || $translatedLayout === null) {
            return;
        }

        if (($layoutTarget['source'] ?? '') === 'post_meta' && is_string($layoutTarget['meta_key'] ?? null)) {
            $rawValue = $layoutTarget['raw_value'] ?? null;
            if (is_array($rawValue)) {
                update_post_meta($targetId, $layoutTarget['meta_key'], $translatedLayout);
                return;
            }

            $encoded = wp_json_encode($translatedLayout, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            update_post_meta($targetId, $layoutTarget['meta_key'], is_string($encoded) ? $encoded : '{}');
            return;
        }

        if (($layoutTarget['source'] ?? '') === 'post_content_comment') {
            $encoded = wp_json_encode($translatedLayout, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            wp_update_post([
                'ID' => $targetId,
                'post_content' => '<!-- ' . (is_string($encoded) ? $encoded : '{}') . ' -->',
            ]);
        }
    }

    private function copyTerms(int $sourceId, int $targetId, string $postType): void
    {
        foreach (get_object_taxonomies($postType, 'names') as $taxonomy) {
            $terms = wp_get_object_terms($sourceId, $taxonomy, ['fields' => 'ids']);

            if (is_wp_error($terms) || !is_array($terms)) {
                continue;
            }

            wp_set_object_terms($targetId, array_map('intval', $terms), $taxonomy, false);
        }
    }
}
