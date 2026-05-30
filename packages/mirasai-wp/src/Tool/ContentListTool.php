<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class ContentListTool extends AbstractTool
{
    private WordPressTranslationHelper $translations;

    public function __construct(?WordPressTranslationHelper $translations = null)
    {
        $this->translations = $translations ?? new WordPressTranslationHelper();
    }

    public function getName(): string
    {
        return 'content/list';
    }

    public function getDescription(): string
    {
        return 'Lists WordPress posts, pages, and public REST-enabled custom post types with basic status, author, dates, and link metadata.';
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
                'post_type' => [
                    'type' => 'string',
                    'description' => 'Post type to list. Defaults to any public REST-enabled post type.',
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Post status. Defaults to publish.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                    'description' => 'Maximum number of items. Default 20.',
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Optional search term.',
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
        $limit = isset($arguments['limit']) ? max(1, min(100, (int) $arguments['limit'])) : 20;
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

        if (isset($arguments['search']) && is_string($arguments['search']) && trim($arguments['search']) !== '') {
            $queryArgs['s'] = trim($arguments['search']);
        }

        $query = new \WP_Query($queryArgs);
        $items = [];

        foreach ($query->posts as $post) {
            $items[] = [
                'id' => (int) $post->ID,
                'post_type' => (string) $post->post_type,
                'status' => (string) $post->post_status,
                'etag' => ContentReadTool::contentEtag($post),
                'title' => get_the_title($post),
                'slug' => (string) $post->post_name,
                'author_id' => (int) $post->post_author,
                'date' => (string) $post->post_date,
                'modified' => (string) $post->post_modified,
                'link' => get_permalink($post),
                'language' => $this->translations->postLanguage((int) $post->ID, (string) $post->post_type),
                'translations' => $this->translations->postTranslations((int) $post->ID, (string) $post->post_type),
            ];
        }

        return [
            'items' => $items,
            'count' => count($items),
            'post_type' => $postType,
            'status' => $status,
            'translation_provider' => $this->translations->provider(),
        ];
    }

    /**
     * @return list<string>
     */
    private function defaultPostTypes(): array
    {
        return array_values(get_post_types(['show_in_rest' => true, 'public' => true], 'names'));
    }
}
