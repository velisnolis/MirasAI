<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class TaxonomyTermTranslateTool extends AbstractTool
{
    private WordPressTranslationHelper $translations;

    public function __construct(?WordPressTranslationHelper $translations = null)
    {
        $this->translations = $translations ?? new WordPressTranslationHelper();
    }

    public function getName(): string
    {
        return 'taxonomy/term-translate';
    }

    public function getDescription(): string
    {
        return 'Creates or updates a translated WordPress taxonomy term through WPML or Polylang. Requires a current source term etag from taxonomy/term-list.';
    }

    public function getSurface(): string
    {
        return 'essential';
    }

    /**
     * @return array<string, mixed>
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['source_id', 'taxonomy', 'target_language', 'translated_name', 'if_match'],
            'properties' => [
                'source_id' => ['type' => 'integer', 'description' => 'Source term ID.'],
                'taxonomy' => ['type' => 'string', 'description' => 'Source term taxonomy.'],
                'target_language' => ['type' => 'string', 'description' => 'Target WPML/Polylang language code.'],
                'translated_name' => ['type' => 'string', 'description' => 'Translated term name.'],
                'translated_slug' => ['type' => 'string', 'description' => 'Optional translated slug. Auto-generated from name if omitted.'],
                'translated_description' => ['type' => 'string', 'description' => 'Optional translated term description.'],
                'translated_parent_id' => ['type' => 'integer', 'description' => 'Optional target-language parent term ID.'],
                'overwrite' => ['type' => 'boolean', 'description' => 'If true, update an existing target-language translation.'],
                'if_match' => ['type' => 'string', 'description' => 'Required source etag from taxonomy/term-list.'],
                'dry_run' => ['type' => 'boolean', 'description' => 'If true, validate and preview without writing.'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
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
        $taxonomy = trim((string) ($arguments['taxonomy'] ?? ''));
        $targetLanguage = trim((string) ($arguments['target_language'] ?? ''));
        $translatedName = trim((string) ($arguments['translated_name'] ?? ''));
        $ifMatch = trim((string) ($arguments['if_match'] ?? ''));
        $overwrite = !empty($arguments['overwrite']);
        $dryRun = !empty($arguments['dry_run']);

        if ($sourceId <= 0 || $taxonomy === '' || $targetLanguage === '' || $translatedName === '' || $ifMatch === '') {
            return [
                'error' => 'source_id, taxonomy, target_language, translated_name, and if_match are required.',
                'code' => 'missing_required_argument',
            ];
        }

        if (!taxonomy_exists($taxonomy)) {
            return ['error' => "Taxonomy {$taxonomy} not found.", 'code' => 'taxonomy_not_found'];
        }

        $provider = $this->translations->provider();

        if (empty($provider['active'])) {
            return [
                'error' => 'No supported multilingual provider is active. taxonomy/term-translate needs WPML or Polylang.',
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

        $source = get_term($sourceId, $taxonomy);

        if (!$source instanceof \WP_Term) {
            return ['error' => "Source term {$sourceId} not found in taxonomy {$taxonomy}.", 'code' => 'source_term_not_found'];
        }

        $currentEtag = TaxonomyTermListTool::termEtag($source);

        if (!hash_equals($currentEtag, $ifMatch)) {
            return [
                'error' => 'Source term etag mismatch. Re-read taxonomy/term-list and retry with the fresh etag.',
                'code' => 'stale_etag',
                'expected_etag' => $currentEtag,
                'provided_etag' => $ifMatch,
            ];
        }

        $sourceLanguage = $this->translations->termLanguage($sourceId, $taxonomy);

        if ($sourceLanguage === $targetLanguage) {
            return ['error' => 'target_language matches the source term language.', 'code' => 'same_language'];
        }

        $translations = $this->translations->termTranslations($sourceId, $taxonomy);
        $existingId = $translations[$targetLanguage] ?? null;

        if (is_int($existingId) && $existingId > 0 && !$overwrite) {
            return [
                'error' => "Term translation already exists for {$targetLanguage} (term ID: {$existingId}).",
                'code' => 'translation_exists',
                'existing_id' => $existingId,
                'hint' => 'Set overwrite=true to update the existing term translation.',
            ];
        }

        $translatedSlug = isset($arguments['translated_slug']) && is_string($arguments['translated_slug'])
            ? sanitize_title($arguments['translated_slug'])
            : sanitize_title($translatedName);
        $translatedDescription = isset($arguments['translated_description']) && is_string($arguments['translated_description'])
            ? $arguments['translated_description']
            : '';
        $parentResolution = $this->resolveParent($source, $targetLanguage, $arguments);

        if (isset($parentResolution['error'])) {
            return $parentResolution;
        }

        $plannedId = is_int($existingId) && $existingId > 0 ? $existingId : null;

        if ($dryRun) {
            return [
                'action' => $plannedId !== null ? 'updated' : 'created',
                'dry_run' => true,
                'source_id' => $sourceId,
                'target_id' => $plannedId,
                'taxonomy' => $taxonomy,
                'target_language' => $targetLanguage,
                'name' => $translatedName,
                'slug' => $translatedSlug,
                'description_length' => strlen($translatedDescription),
                'parent' => $parentResolution['parent'],
                'parent_warning' => $parentResolution['warning'] ?? null,
                'source_etag' => $currentEtag,
                'translation_provider' => $provider,
                'write_performed' => false,
                'note' => 'No changes were written. Retry without dry_run when the preview is correct.',
            ];
        }

        $fields = [
            'slug' => $translatedSlug,
            'description' => $translatedDescription,
            'parent' => $parentResolution['parent'],
        ];

        if ($plannedId !== null) {
            $result = wp_update_term($plannedId, $taxonomy, ['name' => $translatedName] + $fields);
            $action = 'updated';
        } else {
            $result = wp_insert_term($translatedName, $taxonomy, $fields);
            $action = 'created';
        }

        if (is_wp_error($result)) {
            return ['error' => $result->get_error_message(), 'code' => 'term_write_failed'];
        }

        $targetId = $plannedId !== null ? $plannedId : (int) ($result['term_id'] ?? 0);
        $association = $this->translations->setTermLanguageAndAssociation($targetId, $taxonomy, $targetLanguage, $sourceId);

        if (isset($association['error'])) {
            return $association + [
                'term_written' => true,
                'target_id' => $targetId,
                'warning' => 'The term was written but language association failed.',
            ];
        }

        $targetTerm = get_term($targetId, $taxonomy);

        return [
            'action' => $action,
            'source_id' => $sourceId,
            'target_id' => $targetId,
            'taxonomy' => $taxonomy,
            'target_language' => $targetLanguage,
            'name' => $translatedName,
            'slug' => $targetTerm instanceof \WP_Term ? (string) $targetTerm->slug : $translatedSlug,
            'parent' => $targetTerm instanceof \WP_Term ? (int) $targetTerm->parent : $parentResolution['parent'],
            'parent_warning' => $parentResolution['warning'] ?? null,
            'source_etag' => $currentEtag,
            'target_etag' => $targetTerm instanceof \WP_Term ? TaxonomyTermListTool::termEtag($targetTerm) : null,
            'translation_provider' => $provider,
            'write_performed' => true,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{parent: int, warning?: string}|array{error: string, code: string}
     */
    private function resolveParent(\WP_Term $source, string $targetLanguage, array $arguments): array
    {
        if (array_key_exists('translated_parent_id', $arguments)) {
            $parentId = max(0, (int) $arguments['translated_parent_id']);

            if ($parentId > 0) {
                $parent = get_term($parentId, (string) $source->taxonomy);
                if (!$parent instanceof \WP_Term) {
                    return ['error' => "Translated parent term {$parentId} not found.", 'code' => 'parent_term_not_found'];
                }
            }

            return ['parent' => $parentId];
        }

        $sourceParent = (int) $source->parent;
        if ($sourceParent <= 0) {
            return ['parent' => 0];
        }

        $parentTranslations = $this->translations->termTranslations($sourceParent, (string) $source->taxonomy);
        $translatedParent = $parentTranslations[$targetLanguage] ?? null;

        if (is_int($translatedParent) && $translatedParent > 0) {
            return ['parent' => $translatedParent];
        }

        return [
            'parent' => 0,
            'warning' => 'Source term has a parent, but no translated parent was found. Target term will be created at taxonomy root unless translated_parent_id is provided.',
        ];
    }
}
