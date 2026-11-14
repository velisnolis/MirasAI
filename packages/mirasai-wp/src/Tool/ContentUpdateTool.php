<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

/**
 * Status, parent and slug of an existing page, and nothing else. It does not create, delete or
 * trash, and it never writes title or content. Widening it is a product decision, not a patch:
 * docs/todo-016-decision-note-2026-11-13.md. Joomla's copy only changes the state of an article.
 */
class ContentUpdateTool extends AbstractTool
{
    private const ARGUMENTS = ['id', 'status', 'parent_id', 'slug', 'if_match', 'dry_run', 'confirm_guarded_write'];

    private const STATUSES = ['draft', 'publish'];

    /** A page in the trash, an auto-draft or a scheduled page is left alone. */
    private const CURRENT_STATUSES = ['draft', 'publish', 'pending', 'private'];

    private const LISTED_STATUSES = ['publish', 'draft', 'pending', 'private', 'future'];

    private const MAX_CHILDREN = 50;

    public function getName(): string
    {
        return 'content/update';
    }

    public function getDescription(): string
    {
        return 'Changes the status (draft or publish), the parent or the slug of an existing page, and nothing else: no title, '
            . 'no content, no trash, no new pages. Posts and other post types are refused. Publishing or moving a page shows '
            . 'at once. The preview gives the permalink before and after, for the page and for the pages under it (after is '
            . 'null where it cannot be predicted). It does not look for links to the old URL, and WordPress stores no redirect '
            . 'for a page that moves. A slug WordPress would rewrite or number is refused, not adjusted. if_match is the etag '
            . 'of content/read. Check the new URL afterwards with site/public-fetch.';
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
                'id' => ['type' => 'integer', 'description' => 'ID of an existing page.'],
                'status' => ['type' => 'string', 'enum' => self::STATUSES, 'description' => 'draft takes the page away from visitors; publish shows it.'],
                'parent_id' => ['type' => 'integer', 'description' => 'ID of another existing page, or 0 for no parent. Changes the URL of the page and of the pages under it.'],
                'slug' => ['type' => 'string', 'description' => 'New slug, exactly as WordPress would store it: lowercase, no spaces or accents.'],
                'if_match' => ['type' => 'string', 'description' => 'etag from content/read.'],
                'dry_run' => ['type' => 'boolean', 'description' => 'Preview without writing.'],
                'confirm_guarded_write' => ['type' => 'boolean', 'description' => 'Required with dry_run=false.'],
            ],
            'required' => ['id', 'if_match'],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array
    {
        $id = (int) ($arguments['id'] ?? 0);
        $ifMatch = trim((string) ($arguments['if_match'] ?? ''));
        $dryRun = ($arguments['dry_run'] ?? null) === true;

        if ($id <= 0 || $ifMatch === '') {
            return ['error' => 'id and if_match are required.', 'code' => 'missing_arguments'];
        }

        $requested = self::requestedChanges($arguments);

        if (isset($requested['error'])) {
            return $requested;
        }

        if (!$dryRun && ($arguments['confirm_guarded_write'] ?? null) !== true) {
            return [
                'error' => 'This is a guarded write. Retry with dry_run=true first, then confirm_guarded_write=true if the preview is correct.',
                'code' => 'guarded_write_confirmation_required',
            ];
        }

        $post = get_post($id);

        if (!$post instanceof \WP_Post) {
            return ['error' => "Page {$id} not found.", 'code' => 'not_found'];
        }

        if ((string) $post->post_type !== 'page') {
            return [
                'error' => 'content/update only changes pages. Posts, attachments and other post types are refused.',
                'code' => 'unsupported_post_type',
                'post_type' => (string) $post->post_type,
            ];
        }

        if (!in_array((string) $post->post_status, self::CURRENT_STATUSES, true)) {
            return [
                'error' => 'content/update leaves a page in the trash, an auto-draft or a scheduled page alone.',
                'code' => 'unsupported_current_status',
                'status' => (string) $post->post_status,
            ];
        }

        $etag = ContentReadTool::contentEtag($post);

        if (!hash_equals($etag, $ifMatch)) {
            return [
                'error' => 'The page changed. Call content/read again and retry with its etag.',
                'code' => 'stale_etag',
                'expected_etag' => $etag,
                'provided_etag' => $ifMatch,
            ];
        }

        $target = $this->target($post, $requested);

        if (isset($target['error'])) {
            return $target;
        }

        $after = clone $post;
        $after->post_status = $target['status'];
        $after->post_parent = $target['parent_id'];
        $after->post_name = $target['slug'];

        $changes = self::changes($post, $after);
        $linkBefore = (string) get_permalink($post);
        $linkAfter = (string) get_permalink($after);
        $children = $this->children($id, (string) get_page_uri($post), (string) get_page_uri($after));
        $response = [
            'dry_run' => $dryRun,
            'id' => $id,
            'title' => (string) $post->post_title,
            'would_change' => $changes !== [],
            'changes' => $changes,
            'permalink' => ['before' => $linkBefore, 'after' => $linkAfter],
            'children_total' => $children['total'],
            'children' => $children['rows'],
            'warnings' => $this->warnings($post, $after, $linkBefore, $linkAfter),
        ];

        if ($dryRun) {
            return $response + [
                'action' => 'preview',
                'etag' => $etag,
                'note' => 'No changes were written. Retry with confirm_guarded_write=true and the same if_match.',
            ];
        }

        if ($changes === []) {
            return $response + ['action' => 'unchanged', 'etag' => $etag];
        }

        $update = ['ID' => $id];

        if (isset($changes['status'])) {
            $update['post_status'] = $target['status'];
        }

        if (isset($changes['parent_id'])) {
            $update['post_parent'] = $target['parent_id'];
        }

        // A slug WordPress derives from the title on publishing is left for WordPress to write.
        if (isset($changes['slug'], $requested['slug'])) {
            $update['post_name'] = $target['slug'];
        }

        $result = wp_update_post(wp_slash($update), true);

        if (is_wp_error($result) || $result === 0) {
            return [
                'error' => is_wp_error($result) ? $result->get_error_message() : 'WordPress did not update the page.',
                'code' => 'update_failed',
            ];
        }

        $written = get_post($id);

        if (!$written instanceof \WP_Post) {
            return ['error' => "Page {$id} could not be read back after the write.", 'code' => 'update_unverified'];
        }

        // From here on the response says what WordPress stored, not what was predicted.
        $response['changes'] = self::changes($post, $written);
        $response['permalink']['after'] = (string) get_permalink($written);

        foreach ($response['children'] as $index => $child) {
            $response['children'][$index]['after'] = (string) get_permalink($child['id']);
        }

        if (self::changes($after, $written) !== []) {
            $response['warnings'][] = [
                'code' => 'result_differs',
                'message' => 'WordPress stored something other than the preview said. changes shows what is stored now.',
            ];
        }

        return $response + [
            'action' => 'updated',
            'old_etag' => $etag,
            'new_etag' => ContentReadTool::contentEtag($written),
            'next' => 'site/public-fetch with permalink.after; if it shows the old page, site/cache-purge.',
        ];
    }

    /**
     * The fields the call asks to change, or an error. What the tool does not write is refused here
     * as well as by the schema, so a direct call cannot widen it.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public static function requestedChanges(array $arguments): array
    {
        $unknown = array_values(array_diff(array_map('strval', array_keys($arguments)), self::ARGUMENTS));

        if ($unknown !== []) {
            return [
                'error' => 'content/update only changes status, parent_id and slug. Refused: ' . implode(', ', $unknown) . '.',
                'code' => 'unsupported_field',
                'fields' => $unknown,
            ];
        }

        $changes = [];

        if (array_key_exists('status', $arguments)) {
            if (!in_array($arguments['status'], self::STATUSES, true)) {
                return [
                    'error' => 'status must be draft or publish. content/update does not trash, schedule or make a page private.',
                    'code' => 'invalid_status',
                ];
            }

            $changes['status'] = $arguments['status'];
        }

        if (array_key_exists('parent_id', $arguments)) {
            $parentId = $arguments['parent_id'];

            if (is_float($parentId) && floor($parentId) === $parentId) {
                $parentId = (int) $parentId;
            }

            if (!is_int($parentId) || $parentId < 0) {
                return ['error' => 'parent_id must be the ID of a page, or 0 for no parent.', 'code' => 'invalid_parent'];
            }

            $changes['parent_id'] = $parentId;
        }

        if (array_key_exists('slug', $arguments)) {
            if (!is_string($arguments['slug']) || trim($arguments['slug']) === '') {
                return ['error' => 'slug must be a non-empty string.', 'code' => 'invalid_slug'];
            }

            $changes['slug'] = $arguments['slug'];
        }

        if ($changes === []) {
            return ['error' => 'Give at least one of status, parent_id or slug.', 'code' => 'nothing_to_change'];
        }

        return $changes;
    }

    /**
     * Status, parent and slug as they will be stored, or an error. Nothing is adjusted on the way:
     * a slug or a parent WordPress would change by itself is refused.
     *
     * @param array<string, mixed> $requested
     * @return array<string, mixed>
     */
    private function target(\WP_Post $post, array $requested): array
    {
        $id = (int) $post->ID;
        $status = (string) ($requested['status'] ?? $post->post_status);
        $parentId = (int) ($requested['parent_id'] ?? $post->post_parent);
        $slug = (string) ($requested['slug'] ?? $post->post_name);

        if (isset($requested['parent_id']) && $parentId > 0) {
            $parent = $parentId === $id ? null : get_post($parentId);

            if (!$parent instanceof \WP_Post || (string) $parent->post_type !== 'page'
                || in_array((string) $parent->post_status, ['trash', 'auto-draft'], true)
            ) {
                return [
                    'error' => 'parent_id must be another existing page that is not in the trash.',
                    'code' => 'parent_not_found',
                ];
            }

            if (in_array($id, array_map('intval', get_post_ancestors($parentId)), true)) {
                return ['error' => 'parent_id is a page under this one.', 'code' => 'parent_is_descendant'];
            }
        }

        if (isset($requested['slug']) && sanitize_title($slug) !== $slug) {
            $suggested = (string) sanitize_title($slug);

            return [
                'error' => 'WordPress would not store this slug as written.',
                'code' => 'invalid_slug',
            ] + ($suggested !== '' ? ['suggested_slug' => $suggested] : []);
        }

        if ($slug !== '') {
            $free = (string) wp_unique_post_slug($slug, $id, $status, 'page', $parentId);

            if ($free !== $slug) {
                return [
                    'error' => "The slug {$slug} is taken or reserved under that parent: WordPress would store {$free}. Pass the slug you want.",
                    'code' => 'slug_conflict',
                    'available_slug' => $free,
                ];
            }
        } elseif (!in_array($status, ['draft', 'pending'], true)) {
            // A draft that never had a slug gets one on the way out, as in wp_insert_post(): the title,
            // or the ID when there is no title.
            $slug = (string) wp_unique_post_slug(sanitize_title((string) $post->post_title, (string) $id), $id, $status, 'page', $parentId);
        }

        return ['status' => $status, 'parent_id' => $parentId, 'slug' => $slug];
    }

    /**
     * @return array<string, array{before: mixed, after: mixed}>
     */
    private static function changes(\WP_Post $before, \WP_Post $after): array
    {
        $changes = [];

        foreach ([
            'status' => [(string) $before->post_status, (string) $after->post_status],
            'parent_id' => [(int) $before->post_parent, (int) $after->post_parent],
            'slug' => [(string) $before->post_name, (string) $after->post_name],
        ] as $field => [$old, $new]) {
            if ($old !== $new) {
                $changes[$field] = ['before' => $old, 'after' => $new];
            }
        }

        return $changes;
    }

    /**
     * The pages under this one, with the URL each has now and the one it would have.
     *
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    private function children(int $id, string $uriBefore, string $uriAfter): array
    {
        $pages = get_pages([
            'child_of' => $id,
            'post_type' => 'page',
            'post_status' => self::LISTED_STATUSES,
            'sort_column' => 'menu_order,post_title',
        ]);
        $pages = is_array($pages) ? $pages : [];
        $rows = [];

        foreach (array_slice($pages, 0, self::MAX_CHILDREN) as $child) {
            $link = (string) get_permalink($child);
            $rows[] = [
                'id' => (int) $child->ID,
                'title' => (string) $child->post_title,
                'status' => (string) $child->post_status,
                'before' => $link,
                'after' => self::movedLink($link, (string) get_page_uri($child), $uriBefore, $uriAfter),
            ];
        }

        return ['total' => count($pages), 'rows' => $rows];
    }

    /**
     * The URL of a page under one whose path changes. Its own path starts with the path of the page
     * above, and that start is what moves; a slug repeated further down stays. Null when the paths
     * do not say where it goes.
     */
    public static function movedLink(string $link, string $uri, string $uriBefore, string $uriAfter): ?string
    {
        if ($uriBefore === $uriAfter) {
            return $link;
        }

        if ($uriBefore === '' || !str_starts_with($uri, $uriBefore . '/')) {
            return null;
        }

        $at = strrpos($link, '/' . $uri);

        return $at === false
            ? $link
            : substr_replace($link, '/' . $uriAfter . substr($uri, strlen($uriBefore)), $at, strlen($uri) + 1);
    }

    /**
     * @return list<array{code: string, message: string}>
     */
    private function warnings(\WP_Post $before, \WP_Post $after, string $linkBefore, string $linkAfter): array
    {
        $warnings = [];

        if ((string) $before->post_status === 'publish' && (string) $after->post_status !== 'publish') {
            $warnings[] = [
                'code' => 'no_longer_public',
                'message' => 'Visitors get a 404 at the current URL. Links to it in other pages, menus and layouts stay as '
                    . 'they are, and this tool does not look for them.',
            ];
        } elseif ((string) $before->post_status === 'publish' && $linkBefore !== $linkAfter) {
            $warnings[] = [
                'code' => 'old_url_not_redirected',
                'message' => 'WordPress stores no redirect from the old URL of a page. Links to it in other pages, menus and '
                    . 'layouts are not rewritten, and this tool does not look for them.',
            ];
        }

        if ((string) $before->post_status !== 'draft' && (string) $after->post_status === 'draft'
            && get_option('show_on_front') === 'page'
            && in_array((int) $before->ID, [(int) get_option('page_on_front'), (int) get_option('page_for_posts')], true)
        ) {
            $warnings[] = [
                'code' => 'front_page',
                'message' => 'This is the front page or the posts page of the site. As a draft it is no longer shown there.',
            ];
        }

        return $warnings;
    }
}
