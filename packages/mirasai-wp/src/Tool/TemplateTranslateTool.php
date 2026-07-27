<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class TemplateTranslateTool extends AbstractTool
{
    public function getName(): string
    {
        return 'template/translate';
    }

    public function getDescription(): string
    {
        return 'Creates or updates a translated copy of a YOOtheme Builder template. You must provide translated text through translated_layout or yootheme_text_replacements from template/read. Requires if_match and uses dry_run/confirm_guarded_write before writing.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['key', 'target_language', 'if_match'],
            'properties' => [
                'key' => [
                    'type' => 'string',
                    'description' => 'Source YOOtheme template key as returned by template/list.',
                ],
                'target_language' => [
                    'type' => 'string',
                    'description' => 'Target WordPress multilingual language code, for example ca, es, en, ca-ES, es-ES, or en-GB depending on WPML/Polylang configuration.',
                ],
                'translated_name' => [
                    'type' => 'string',
                    'description' => 'Optional translated template name. Defaults to "<source name> (<lang>)".',
                ],
                'translated_layout' => [
                    'description' => 'Optional translated layout JSON. Provide an object or a JSON string.',
                    'oneOf' => [
                        ['type' => 'object'],
                        ['type' => 'string'],
                    ],
                ],
                'yootheme_text_replacements' => [
                    'description' => 'Either a map of "path.field" => "translated text" or a list of replacement objects with path, field, and text. Paths come from template/read translatable_nodes.',
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
                'overwrite' => [
                    'type' => 'boolean',
                    'description' => 'If true, overwrite an existing target-language template with the same assignment fingerprint.',
                ],
                'if_match' => [
                    'type' => 'string',
                    'description' => 'Required source template etag from template/list, template/summary, or template/read. Stale values are rejected before any write.',
                ],
                'dry_run' => [
                    'type' => 'boolean',
                    'description' => 'If true, validate and preview without writing the yootheme option.',
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
        $key = trim((string) ($arguments['key'] ?? ''));
        $targetLanguage = trim((string) ($arguments['target_language'] ?? ''));
        $ifMatch = trim((string) ($arguments['if_match'] ?? ''));
        $overwrite = !empty($arguments['overwrite']);
        $dryRun = ($arguments['dry_run'] ?? null) === true;
        $confirmed = ($arguments['confirm_guarded_write'] ?? null) === true;

        if ($key === '' || $targetLanguage === '' || $ifMatch === '') {
            return [
                'error' => 'key, target_language, and if_match are required.',
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
        $state = $helper->loadState();
        $templates = is_array($state['templates'] ?? null) ? $state['templates'] : [];
        $sourceTemplate = $templates[$key] ?? null;

        if (!is_array($sourceTemplate)) {
            return ['error' => "Template {$key} not found.", 'code' => 'template_not_found'];
        }

        $currentSourceEtag = $helper->etag($sourceTemplate);

        if (!hash_equals($currentSourceEtag, $ifMatch)) {
            return [
                'error' => 'Source template etag mismatch. Re-read the template and retry with the fresh etag.',
                'code' => 'stale_etag',
                'expected_etag' => $currentSourceEtag,
                'provided_etag' => $ifMatch,
            ];
        }

        $sourceTemplateLanguage = $helper->templateLanguage($sourceTemplate);
        $sourceLanguage = $this->defaultLanguage($translations);

        if ($sourceTemplateLanguage === $targetLanguage
            || ($sourceTemplateLanguage === '*' && $sourceLanguage === $targetLanguage)
        ) {
            return ['error' => 'target_language matches the source template language.', 'code' => 'same_language'];
        }

        $sourceLayout = $helper->getTemplateLayout($sourceTemplate);

        if ($sourceLayout === null) {
            return ['error' => "Template {$key} has no layout.", 'code' => 'template_layout_missing'];
        }

        $hasStaticText = $helper->templateHasStaticText($sourceTemplate);
        $translatedLayout = $this->resolveTranslatedLayout($sourceLayout, $arguments, $hasStaticText);

        if (isset($translatedLayout['error'])) {
            return $translatedLayout;
        }

        $fingerprint = $helper->buildTemplateAssignmentFingerprint($sourceTemplate);
        $existingTargetKey = $this->findTemplateKeyByFingerprintAndLanguage($templates, $fingerprint, $targetLanguage, $key, $helper);

        if ($existingTargetKey !== null && !$overwrite) {
            return [
                'error' => "A target-language template already exists for {$targetLanguage}. Use overwrite=true to replace it.",
                'code' => 'target_template_exists',
                'target_key' => $existingTargetKey,
            ];
        }

        $targetKey = $existingTargetKey ?? $this->generateUniqueTemplateKey($templates, $helper);
        $targetTemplate = $sourceTemplate;
        $helper->setTemplateLanguage($targetTemplate, $targetLanguage);
        $helper->setTemplateLayout($targetTemplate, $translatedLayout['layout']);
        $targetTemplate['name'] = $this->buildTargetTemplateName(
            $sourceTemplate,
            $targetLanguage,
            isset($arguments['translated_name']) ? (string) $arguments['translated_name'] : '',
            $helper,
        );

        $templates[$targetKey] = $targetTemplate;
        $sourceLanguageWasScoped = false;

        if ($hasStaticText && $sourceTemplateLanguage === '*' && $sourceLanguage !== null) {
            $helper->setTemplateLanguage($sourceTemplate, $sourceLanguage);
            $templates[$key] = $sourceTemplate;
            $sourceLanguageWasScoped = true;
        }

        $state['templates'] = $templates;
        $cache = $dryRun
            ? ['cleared' => false, 'groups' => [], 'failures' => [], 'reason' => 'dry_run']
            : $helper->writeState($state);

        $response = [
            'source_key' => $key,
            'target_key' => $targetKey,
            'target_language' => $targetLanguage,
            'dry_run' => $dryRun,
            'action' => $existingTargetKey !== null ? 'updated' : 'created',
            'name' => $targetTemplate['name'],
            'source_etag' => $currentSourceEtag,
            'target_etag' => $helper->etag($targetTemplate),
            'collection_etag' => $helper->etag($state),
            'has_static_text' => $hasStaticText,
            'source_language_scoped' => $sourceLanguageWasScoped,
            'source_template_language' => $sourceTemplateLanguage,
            'cache' => $cache,
        ];

        if ($dryRun) {
            $response['note'] = 'No changes were written. Retry with confirm_guarded_write=true and the same if_match if the preview is still current.';
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $sourceTemplate
     */
    private function buildTargetTemplateName(array $sourceTemplate, string $targetLanguage, string $explicit, YoothemeWpHelper $helper): string
    {
        $explicit = trim($explicit);

        if ($explicit !== '') {
            return $explicit;
        }

        $sourceName = $helper->templateName($sourceTemplate, 'Template');
        $baseName = preg_replace('/ \([A-Za-z]{2,3}(?:-[A-Za-z]{2,3})?\)$/', '', $sourceName) ?: $sourceName;

        return trim($baseName) . ' (' . $targetLanguage . ')';
    }

    /**
     * @param array<string, array<string, mixed>> $templates
     */
    private function findTemplateKeyByFingerprintAndLanguage(
        array $templates,
        string $fingerprint,
        string $language,
        string $excludeKey,
        YoothemeWpHelper $helper
    ): ?string {
        foreach ($templates as $templateKey => $template) {
            if (!is_array($template) || $templateKey === $excludeKey) {
                continue;
            }

            if ($helper->templateLanguage($template) !== $language) {
                continue;
            }

            if ($helper->buildTemplateAssignmentFingerprint($template) === $fingerprint) {
                return (string) $templateKey;
            }
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $templates
     */
    private function generateUniqueTemplateKey(array $templates, YoothemeWpHelper $helper): string
    {
        do {
            $key = $helper->generateStorageKey();
        } while (isset($templates[$key]));

        return $key;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $sourceLayout
     * @return array{layout?: array<string, mixed>, error?: string, code?: string}
     */
    private function resolveTranslatedLayout(array $sourceLayout, array $arguments, bool $hasStaticText): array
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

        if (!empty($replacements)) {
            return ['layout' => (new YoothemeLayoutProcessor())->patchLayoutArray($sourceLayout, $replacements)];
        }

        if ($hasStaticText) {
            return [
                'error' => 'Templates with fixed text require translated_layout or yootheme_text_replacements.',
                'code' => 'missing_template_translation',
            ];
        }

        return ['layout' => $sourceLayout];
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
            return [
                'error' => 'yootheme_text_replacements must be an object map or an array of replacement objects.',
                'code' => 'invalid_replacements',
            ];
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
                return [
                    'error' => 'Each replacement entry must be an object with path, optional field, and text.',
                    'code' => 'invalid_replacements',
                ];
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
     * @return array{ok: true}|array{error: string, code: string, provider?: array<string, mixed>, languages?: list<array<string, mixed>>}
     */
    private function languageExists(WordPressTranslationHelper $translations, string $language): array
    {
        $languages = $translations->languages();

        if ($languages === []) {
            return [
                'error' => 'No supported multilingual provider is active. template/translate needs WPML or Polylang language metadata.',
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

    private function defaultLanguage(WordPressTranslationHelper $translations): ?string
    {
        foreach ($translations->languages() as $language) {
            if (!empty($language['default']) && is_string($language['code'] ?? null)) {
                return $language['code'];
            }
        }

        return null;
    }
}
