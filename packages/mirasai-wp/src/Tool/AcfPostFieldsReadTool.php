<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class AcfPostFieldsReadTool extends AbstractTool
{
    private AcfHelper $acf;

    public function __construct(?AcfHelper $acf = null)
    {
        $this->acf = $acf ?? new AcfHelper();
    }

    public function getName(): string
    {
        return 'acf/post-fields/read';
    }

    public function getDescription(): string
    {
        return 'Reads ACF field objects and resolved values for one WordPress post, page, or custom post.';
    }

    /**
     * @return array<string, mixed>
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['post_id'],
            'properties' => [
                'post_id' => [
                    'type' => 'integer',
                    'description' => 'WordPress post ID.',
                ],
                'include_values' => [
                    'type' => 'boolean',
                    'description' => 'Include resolved field values. Defaults to true.',
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
        if (!$this->acf->isAvailable()) {
            return [
                'ok' => true,
                'available' => false,
                'post_id' => isset($arguments['post_id']) ? (int) $arguments['post_id'] : null,
                'fields' => [],
                'field_count' => 0,
            ];
        }

        $postId = isset($arguments['post_id']) ? (int) $arguments['post_id'] : 0;
        if ($postId <= 0) {
            return [
                'error' => 'post_id is required.',
                'code' => 'invalid_arguments',
            ];
        }

        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            return [
                'error' => 'Post not found.',
                'code' => 'not_found',
                'post_id' => $postId,
            ];
        }

        if (!function_exists('get_field_objects')) {
            return [
                'ok' => true,
                'available' => true,
                'post_id' => $postId,
                'fields' => [],
                'field_count' => 0,
                'warnings' => ['ACF get_field_objects() is not available.'],
            ];
        }

        $includeValues = !array_key_exists('include_values', $arguments) || !empty($arguments['include_values']);
        $fieldObjects = get_field_objects($postId, false, true, true);
        $fields = [];

        if (is_array($fieldObjects)) {
            foreach ($fieldObjects as $field) {
                if (is_array($field)) {
                    $fields[] = $this->acf->summarizeField($field, $includeValues);
                }
            }
        }

        return [
            'ok' => true,
            'available' => true,
            'post' => [
                'id' => $postId,
                'post_type' => (string) $post->post_type,
                'title' => get_the_title($post),
                'status' => (string) $post->post_status,
            ],
            'include_values' => $includeValues,
            'fields' => $fields,
            'field_count' => count($fields),
        ];
    }
}
