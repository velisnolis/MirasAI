<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

/**
 * Mirrored in plg_mirasai_yootheme, where the target is an article.
 */
class TemplateLayoutInitTool extends AbstractTool
{
    /** A page in the trash or an auto-draft is left alone. */
    private const STATUSES = ['draft', 'publish', 'pending', 'private', 'future'];

    public function getName(): string
    {
        return 'template/layout-init';
    }

    public function getDescription(): string
    {
        return 'Gives an existing, empty page its first YOOtheme Builder layout, so template/element-add can build on it. '
            . 'A page created outside the Builder has none and answers post_layout_missing. It takes no content: it writes '
            . 'the empty layout and nothing else. Empty means nothing in any YOOtheme layout meta key and no text in '
            . 'post_content; any other page is refused, and so are posts and other post types. if_match is the etag of '
            . 'content/read. Returns etag, the if_match of template/element-add with post_id and parent_path root.';
    }

    public function getPermissions(): array
    {
        return [
            'risk_level' => self::RISK_GUARDED_WRITE,
            'idempotent' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'post_id' => ['type' => 'integer', 'description' => 'ID of an existing, empty page.'],
                'if_match' => ['type' => 'string', 'description' => 'etag from content/read.'],
                'dry_run' => ['type' => 'boolean', 'description' => 'Preview without writing.'],
                'confirm_guarded_write' => ['type' => 'boolean', 'description' => 'Required with dry_run=false.'],
            ],
            'required' => ['post_id', 'if_match'],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array
    {
        $postId = (int) ($arguments['post_id'] ?? 0);
        $ifMatch = trim((string) ($arguments['if_match'] ?? ''));
        $dryRun = ($arguments['dry_run'] ?? null) === true;

        if ($postId <= 0 || $ifMatch === '') {
            return ['error' => 'post_id and if_match are required.', 'code' => 'missing_arguments'];
        }

        if (!$dryRun && ($arguments['confirm_guarded_write'] ?? null) !== true) {
            return [
                'error' => 'This is a guarded write. Retry with dry_run=true first, then confirm_guarded_write=true if the preview is correct.',
                'code' => 'guarded_write_confirmation_required',
            ];
        }

        $post = get_post($postId);

        if (!$post instanceof \WP_Post) {
            return ['error' => "Post {$postId} not found.", 'code' => 'post_not_found'];
        }

        if ((string) $post->post_type !== 'page') {
            return [
                'error' => 'template/layout-init only works on pages. Posts and other post types are refused.',
                'code' => 'unsupported_post_type',
                'post_type' => (string) $post->post_type,
            ];
        }

        if (!in_array((string) $post->post_status, self::STATUSES, true)) {
            return [
                'error' => 'A page in the trash or an auto-draft gets no layout.',
                'code' => 'unsupported_current_status',
                'post_status' => (string) $post->post_status,
            ];
        }

        $helper = new YoothemeWpHelper();
        $version = $helper->status()['version'];

        if ($version === null || $version === '') {
            return ['error' => 'YOOtheme is not installed on this site.', 'code' => 'yootheme_not_installed'];
        }

        $contentEtag = ContentReadTool::contentEtag($post);

        if (!hash_equals($contentEtag, $ifMatch)) {
            return [
                'error' => 'The page changed. Call content/read again and retry with its etag.',
                'code' => 'stale_etag',
                'expected_etag' => $contentEtag,
                'provided_etag' => $ifMatch,
            ];
        }

        $reason = $helper->postNotBlankReason($postId);

        if ($reason !== null) {
            return [
                'error' => 'The page is not empty, so it gets no new layout. ' . $reason
                    . ' If it already has a layout, read it with template/read.',
                'code' => 'post_not_empty',
            ];
        }

        $layout = self::emptyLayout($version);
        $response = [
            'dry_run' => $dryRun,
            'storage' => 'post',
            'post_id' => $postId,
            'post_title' => (string) $post->post_title,
            'post_status' => (string) $post->post_status,
            'layout' => $layout,
        ];

        if ($dryRun) {
            return $response + [
                'action' => 'preview',
                'content_etag' => $contentEtag,
                'note' => 'No changes were written. Retry with confirm_guarded_write=true and the same if_match.',
            ];
        }

        $write = $helper->createPostLayout($postId, $layout);

        if (($write['failures'] ?? []) !== []) {
            return ['error' => 'The layout could not be written.', 'code' => 'layout_write_failed', 'cache' => $write];
        }

        // WordPress filters post_content on the way in; the stored layout is what counts.
        $stored = $helper->loadPostLayout($postId);

        if ($stored === null || $stored['layout'] !== $layout) {
            return [
                'error' => 'WordPress did not store the layout as written. Read the page with content/read before doing anything else.',
                'code' => 'layout_write_unverified',
            ];
        }

        $fallbackContent = $write['fallback_content'];
        unset($write['fallback_content']);
        $written = get_post($postId);

        return $response + [
            'action' => 'created',
            'etag' => $helper->etag($layout),
            'content_etag' => $written instanceof \WP_Post ? ContentReadTool::contentEtag($written) : null,
            'cache' => $write,
            'fallback_content' => $fallbackContent,
            'next' => 'template/element-add with post_id, parent_path root and etag as if_match.',
        ];
    }

    /**
     * What the Builder holds for a page with nothing on it. The version keeps YOOtheme from running
     * the migrations of older releases over elements added later.
     *
     * @return array<string, mixed>
     */
    public static function emptyLayout(string $version): array
    {
        return ['type' => 'layout', 'children' => [], 'version' => $version];
    }
}
