<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class ContentReadTool extends AbstractTool
{
    private WordPressTranslationHelper $translations;

    public function __construct(?WordPressTranslationHelper $translations = null)
    {
        $this->translations = $translations ?? new WordPressTranslationHelper();
    }

    public function getName(): string
    {
        return 'content/read';
    }

    public function getDescription(): string
    {
        return 'Reads a WordPress post/page by ID. Returns title, content, excerpt, status, author, dates, terms, metadata keys, and permalink.';
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
            'required' => ['id'],
            'properties' => [
                'id' => [
                    'type' => 'integer',
                    'description' => 'WordPress post ID.',
                ],
                'include_meta_values' => [
                    'type' => 'boolean',
                    'description' => 'Include raw post meta values. Defaults to false; meta keys are always listed.',
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
        $id = isset($arguments['id']) ? (int) $arguments['id'] : 0;
        if ($id <= 0) {
            return [
                'error' => 'Post id is required.',
                'code' => 'invalid_arguments',
            ];
        }

        $post = get_post($id);
        if (!$post instanceof \WP_Post) {
            return [
                'error' => 'Post not found.',
                'code' => 'not_found',
                'id' => $id,
            ];
        }

        $includeMetaValues = !empty($arguments['include_meta_values']);
        $meta = get_post_meta($id);

        $layoutTarget = (new YoothemeWpHelper())->loadPostLayout($id);
        $yoothemeNodes = $layoutTarget !== null
            ? (new YoothemeLayoutProcessor())->findTranslatableNodes($layoutTarget['layout'])
            : [];

        return [
            'id' => (int) $post->ID,
            'post_type' => (string) $post->post_type,
            'status' => (string) $post->post_status,
            'etag' => $this->contentEtag($post),
            'title' => get_the_title($post),
            'slug' => (string) $post->post_name,
            'content' => (string) $post->post_content,
            'excerpt' => (string) $post->post_excerpt,
            'author_id' => (int) $post->post_author,
            'date' => (string) $post->post_date,
            'modified' => (string) $post->post_modified,
            'link' => get_permalink($post),
            'terms' => $this->terms($id),
            'meta_keys' => array_keys($meta),
            'meta' => $includeMetaValues ? $meta : null,
            'language' => $this->translations->postLanguage((int) $post->ID, (string) $post->post_type),
            'translations' => $this->translations->postTranslations((int) $post->ID, (string) $post->post_type),
            'translation_provider' => $this->translations->provider(),
            'has_yootheme_builder' => $layoutTarget !== null,
            'yootheme_layout_summary' => $layoutTarget !== null
                ? (new YoothemeLayoutSummarizer())->summarize($layoutTarget['layout'])
                : null,
            'yootheme_translatable_nodes' => $yoothemeNodes,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function terms(int $postId): array
    {
        $terms = [];
        $taxonomies = get_object_taxonomies(get_post_type($postId), 'names');

        foreach ($taxonomies as $taxonomy) {
            $postTerms = get_the_terms($postId, $taxonomy);
            if (!is_array($postTerms)) {
                continue;
            }

            foreach ($postTerms as $term) {
                $terms[] = [
                    'taxonomy' => $taxonomy,
                    'id' => (int) $term->term_id,
                    'name' => (string) $term->name,
                    'slug' => (string) $term->slug,
                ];
            }
        }

        return $terms;
    }

    public static function contentEtag(\WP_Post $post): string
    {
        $value = [
            'ID' => (int) $post->ID,
            'post_type' => (string) $post->post_type,
            'post_status' => (string) $post->post_status,
            'post_title' => (string) $post->post_title,
            'post_name' => (string) $post->post_name,
            'post_content' => (string) $post->post_content,
            'post_excerpt' => (string) $post->post_excerpt,
            'post_modified_gmt' => (string) $post->post_modified_gmt,
        ];

        $encoded = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', is_string($encoded) ? $encoded : '');
    }
}
