<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class ContentAuditMultilingualTool extends AbstractTool
{
    private WordPressTranslationHelper $translations;

    public function __construct(?WordPressTranslationHelper $translations = null)
    {
        $this->translations = $translations ?? new WordPressTranslationHelper();
    }

    public function getName(): string
    {
        return 'content/audit-multilingual';
    }

    public function getDescription(): string
    {
        return 'Scans WordPress content translation completeness for Polylang or WPML. Reports source content without translations and returns read-only diagnostic gaps.';
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
            'properties' => [
                'languages' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Target language codes to audit. If omitted, audits all active non-default languages.',
                ],
                'post_type' => [
                    'type' => 'string',
                    'description' => 'Post type to audit. Defaults to all public REST-enabled post types.',
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Post status to audit. Defaults to publish.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 500,
                    'description' => 'Maximum source items to inspect. Default 200.',
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
        $provider = $this->translations->provider();
        $languages = $this->translations->languages();
        $sourceLanguage = $this->translations->defaultLanguage();

        if (!$provider['active']) {
            return [
                'ok' => true,
                'multilingual_enabled' => false,
                'provider' => $provider,
                'languages' => [],
                'source_language' => null,
                'target_languages' => [],
                'total_gaps' => 0,
                'gaps_by_type' => [],
                'gaps' => [],
                'warnings' => [
                    'No supported WordPress multilingual provider was detected. Supported providers: Polylang and WPML.',
                ],
            ];
        }

        $languageCodes = array_values(array_map(static fn(array $language): string => $language['code'], $languages));
        if ($sourceLanguage === null && $languageCodes !== []) {
            $sourceLanguage = $languageCodes[0];
        }

        $targetLanguages = $this->targetLanguages($arguments, $languageCodes, $sourceLanguage);
        $sourcePosts = $this->sourcePosts($arguments, $sourceLanguage);
        $gaps = $this->auditPosts($sourcePosts, $targetLanguages);

        return [
            'ok' => true,
            'multilingual_enabled' => true,
            'provider' => $provider,
            'languages' => $languages,
            'source_language' => $sourceLanguage,
            'target_languages' => $targetLanguages,
            'scanned_source_count' => count($sourcePosts),
            'total_gaps' => count($gaps),
            'gaps_by_type' => $this->gapsByType($gaps),
            'gaps' => $gaps,
            'warnings' => $this->warnings($sourceLanguage, $targetLanguages),
        ];
    }

    /**
     * @return list<string>
     */
    private function targetLanguages(array $arguments, array $languageCodes, ?string $sourceLanguage): array
    {
        if (isset($arguments['languages']) && is_array($arguments['languages'])) {
            $requested = array_values(array_filter(
                array_map(static fn($language): string => is_string($language) ? trim($language) : '', $arguments['languages']),
                static fn(string $language): bool => $language !== ''
            ));

            if ($requested !== []) {
                return $requested;
            }
        }

        return array_values(array_filter(
            $languageCodes,
            static fn(string $language): bool => $sourceLanguage === null || $language !== $sourceLanguage
        ));
    }

    /**
     * @return list<\WP_Post>
     */
    private function sourcePosts(array $arguments, ?string $sourceLanguage): array
    {
        $limit = isset($arguments['limit']) ? max(1, min(500, (int) $arguments['limit'])) : 200;
        $status = isset($arguments['status']) && is_string($arguments['status']) ? $arguments['status'] : 'publish';
        $postType = isset($arguments['post_type']) && is_string($arguments['post_type']) && $arguments['post_type'] !== ''
            ? $arguments['post_type']
            : $this->defaultPostTypes();

        $queryArgs = [
            'post_type' => $postType,
            'post_status' => $status,
            'posts_per_page' => $limit,
            'orderby' => 'modified',
            'order' => 'DESC',
            'no_found_rows' => true,
        ];

        if ($sourceLanguage !== null && $this->translations->provider()['name'] === 'polylang') {
            $queryArgs['lang'] = $sourceLanguage;
        }

        $query = new \WP_Query($queryArgs);
        $posts = [];

        foreach ($query->posts as $post) {
            if (!$post instanceof \WP_Post) {
                continue;
            }

            if ($sourceLanguage !== null && $this->translations->provider()['name'] === 'wpml') {
                $language = $this->translations->postLanguage((int) $post->ID, (string) $post->post_type);
                if ($language !== $sourceLanguage) {
                    continue;
                }
            }

            $posts[] = $post;
        }

        return $posts;
    }

    /**
     * @param list<\WP_Post> $posts
     * @param list<string> $targetLanguages
     * @return list<array<string, mixed>>
     */
    private function auditPosts(array $posts, array $targetLanguages): array
    {
        $gaps = [];

        foreach ($posts as $post) {
            $translations = $this->translations->postTranslations((int) $post->ID, (string) $post->post_type);

            foreach ($targetLanguages as $targetLanguage) {
                if (isset($translations[$targetLanguage]) && (int) $translations[$targetLanguage] > 0) {
                    continue;
                }

                $gaps[] = [
                    'type' => 'post_untranslated',
                    'severity' => 'high',
                    'source_id' => (int) $post->ID,
                    'source_title' => get_the_title($post),
                    'post_type' => (string) $post->post_type,
                    'missing_in' => $targetLanguage,
                    'hint' => 'Create or connect the missing translation in the active WordPress multilingual plugin.',
                    'fix' => null,
                ];
            }
        }

        return $gaps;
    }

    /**
     * @param list<array<string, mixed>> $gaps
     * @return array<string, int>
     */
    private function gapsByType(array $gaps): array
    {
        $counts = [];

        foreach ($gaps as $gap) {
            $type = is_string($gap['type'] ?? null) ? $gap['type'] : 'unknown';
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    private function warnings(?string $sourceLanguage, array $targetLanguages): array
    {
        $warnings = [];

        if ($sourceLanguage === null) {
            $warnings[] = 'Could not determine a default/source language.';
        }

        if ($targetLanguages === []) {
            $warnings[] = 'No target languages were selected for the audit.';
        }

        return $warnings;
    }

    /**
     * @return list<string>
     */
    private function defaultPostTypes(): array
    {
        return array_values(get_post_types(['show_in_rest' => true, 'public' => true], 'names'));
    }
}
