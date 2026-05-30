<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class AcfTaxonomyListTool extends AbstractTool
{
    private AcfHelper $acf;

    public function __construct(?AcfHelper $acf = null)
    {
        $this->acf = $acf ?? new AcfHelper();
    }

    public function getName(): string
    {
        return 'acf/taxonomy/list';
    }

    public function getDescription(): string
    {
        return 'Lists custom taxonomies managed by ACF when the ACF taxonomy manager is available.';
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array
    {
        if (!$this->acf->isAvailable()) {
            return [
                'ok' => true,
                'available' => false,
                'items' => [],
                'count' => 0,
            ];
        }

        $items = $this->items('acf-taxonomy');

        return [
            'ok' => true,
            'available' => true,
            'items' => $items,
            'count' => count($items),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(string $postType): array
    {
        if (!post_type_exists($postType)) {
            return [];
        }

        $posts = get_posts([
            'post_type' => $postType,
            'post_status' => ['publish', 'acf-disabled'],
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        return array_values(array_map(static fn(\WP_Post $post): array => [
            'id' => (int) $post->ID,
            'key' => (string) $post->post_name,
            'title' => get_the_title($post),
            'status' => (string) $post->post_status,
            'modified' => (string) $post->post_modified,
        ], array_filter($posts, static fn($post): bool => $post instanceof \WP_Post)));
    }
}
