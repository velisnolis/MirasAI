<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class TaxonomyTermListTool extends AbstractTool
{
    private WordPressTranslationHelper $translations;

    public function __construct(?WordPressTranslationHelper $translations = null)
    {
        $this->translations = $translations ?? new WordPressTranslationHelper();
    }

    public function getName(): string
    {
        return 'taxonomy/term-list';
    }

    public function getDescription(): string
    {
        return 'Lists WordPress taxonomy terms with etags and multilingual metadata when WPML or Polylang is active.';
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
                'taxonomy' => [
                    'type' => 'string',
                    'description' => 'Taxonomy name. Defaults to category.',
                ],
                'hide_empty' => [
                    'type' => 'boolean',
                    'description' => 'Whether to hide terms with no posts. Defaults to false.',
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Optional term search string.',
                ],
                'parent' => [
                    'type' => 'integer',
                    'description' => 'Optional parent term ID filter.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                    'description' => 'Maximum number of terms. Default 50.',
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
        $taxonomy = isset($arguments['taxonomy']) && is_string($arguments['taxonomy']) && trim($arguments['taxonomy']) !== ''
            ? trim($arguments['taxonomy'])
            : 'category';

        if (!taxonomy_exists($taxonomy)) {
            return ['error' => "Taxonomy {$taxonomy} not found.", 'code' => 'taxonomy_not_found'];
        }

        $limit = isset($arguments['limit']) ? max(1, min(100, (int) $arguments['limit'])) : 50;
        $query = [
            'taxonomy' => $taxonomy,
            'hide_empty' => array_key_exists('hide_empty', $arguments) ? !empty($arguments['hide_empty']) : false,
            'number' => $limit,
            'orderby' => 'name',
            'order' => 'ASC',
        ];

        if (isset($arguments['search']) && is_string($arguments['search']) && trim($arguments['search']) !== '') {
            $query['search'] = trim($arguments['search']);
        }

        if (array_key_exists('parent', $arguments)) {
            $query['parent'] = max(0, (int) $arguments['parent']);
        }

        $terms = get_terms($query);

        if (is_wp_error($terms)) {
            return ['error' => $terms->get_error_message(), 'code' => 'term_query_failed'];
        }

        $items = [];

        foreach ($terms as $term) {
            if (!$term instanceof \WP_Term) {
                continue;
            }

            $items[] = [
                'id' => (int) $term->term_id,
                'term_taxonomy_id' => (int) $term->term_taxonomy_id,
                'taxonomy' => (string) $term->taxonomy,
                'name' => (string) $term->name,
                'slug' => (string) $term->slug,
                'description' => (string) $term->description,
                'parent' => (int) $term->parent,
                'count' => (int) $term->count,
                'etag' => self::termEtag($term),
                'language' => $this->translations->termLanguage((int) $term->term_id, (string) $term->taxonomy),
                'translations' => $this->translations->termTranslations((int) $term->term_id, (string) $term->taxonomy),
            ];
        }

        return [
            'items' => $items,
            'count' => count($items),
            'taxonomy' => $taxonomy,
            'translation_provider' => $this->translations->provider(),
        ];
    }

    public static function termEtag(\WP_Term $term): string
    {
        $value = [
            'term_id' => (int) $term->term_id,
            'term_taxonomy_id' => (int) $term->term_taxonomy_id,
            'taxonomy' => (string) $term->taxonomy,
            'name' => (string) $term->name,
            'slug' => (string) $term->slug,
            'description' => (string) $term->description,
            'parent' => (int) $term->parent,
        ];

        $encoded = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return hash('sha256', is_string($encoded) ? $encoded : '');
    }
}
