<?php
/**
 * Test: content/update, template/layout-init and menu/position-* on WordPress, against a small
 * WordPress kept in memory. What a real site does with them is checked on the test site
 * (docs/todo-016-decision-note-2026-11-13.md).
 *
 * Run from the repo root:
 *   php docker/test-wp-page-tools.php
 */

declare(strict_types=1);

final class WP_Post
{
    public int $ID = 0;
    public string $post_type = 'page';
    public string $post_status = 'publish';
    public string $post_title = '';
    public string $post_name = '';
    public int $post_parent = 0;
    public string $post_content = '';
    public string $post_excerpt = '';
    public string $post_modified_gmt = '2026-11-14 08:00:00';
    public string $filter = 'raw';

    /** @param array<string, mixed> $fields */
    public function __construct(array $fields)
    {
        foreach ($fields as $name => $value) {
            $this->{$name} = $value;
        }
    }
}

/** @param array<string, mixed> $fields */
function page(int $id, string $slug, array $fields = []): WP_Post
{
    return new WP_Post($fields + ['ID' => $id, 'post_name' => $slug, 'post_title' => ucfirst($slug)]);
}

$GLOBALS['wp'] = [
    'posts' => [
        10 => page(10, 'serveis'),
        20 => page(20, 'web', ['post_parent' => 10]),
        21 => page(21, 'manteniment', ['post_parent' => 20]),
        22 => page(22, 'web'),
        30 => page(30, '', ['post_title' => 'Qui som', 'post_status' => 'draft']),
        31 => page(31, 'text', ['post_content' => '<p>Hola</p>']),
        32 => page(32, 'meta'),
        40 => page(40, 'entrada', ['post_type' => 'post']),
        41 => page(41, 'paperera', ['post_status' => 'trash']),
        42 => page(42, 'programada', ['post_status' => 'future']),
        50 => page(50, 'inici'),
        60 => page(60, 'a'),
        61 => page(61, 'a', ['post_parent' => 60]),
        62 => page(62, 'b', ['post_parent' => 61]),
    ],
    'meta' => [32 => ['_yootheme_page' => '{"broken']],
    'options' => ['show_on_front' => 'page', 'page_on_front' => 50, 'page_for_posts' => 0],
    'yootheme_version' => '5.0.53',
    'template' => 'yootheme',
    'registered' => ['navbar' => 'Navbar', 'dialog-mobile' => 'Mobile Dialog'],
    'locations' => ['navbar' => 0],
    'menus' => [
        3 => (object) ['term_id' => 3, 'name' => 'Principal', 'count' => 4],
        4 => (object) ['term_id' => 4, 'name' => 'Buit', 'count' => 0],
    ],
    'writes' => 0,
];

function get_post(mixed $post): ?WP_Post
{
    if ($post instanceof WP_Post) {
        return $post;
    }

    return isset($GLOBALS['wp']['posts'][(int) $post]) ? clone $GLOBALS['wp']['posts'][(int) $post] : null;
}

function get_post_meta(int $postId, string $key, bool $single = false): mixed
{
    return $GLOBALS['wp']['meta'][$postId][$key] ?? ($single ? '' : []);
}

/** @return list<int> */
function get_post_ancestors(mixed $post): array
{
    $post = get_post($post);
    $ancestors = [];

    while ($post !== null && $post->post_parent > 0 && !in_array($post->post_parent, $ancestors, true)) {
        $ancestors[] = $post->post_parent;
        $post = get_post($post->post_parent);
    }

    return $ancestors;
}

function get_page_uri(mixed $page): string
{
    $page = get_post($page);
    $uri = $page->post_name;

    foreach (get_post_ancestors($page) as $parentId) {
        $parent = get_post($parentId);

        if ($parent !== null && $parent->post_name !== '') {
            $uri = $parent->post_name . '/' . $uri;
        }
    }

    return $uri;
}

function get_permalink(mixed $post): string
{
    $post = get_post($post);

    return in_array($post->post_status, ['draft', 'pending', 'auto-draft', 'future'], true)
        ? 'https://serveis.example/?page_id=' . $post->ID
        : 'https://serveis.example/' . get_page_uri($post) . '/';
}

function sanitize_title(string $title, string $fallback = ''): string
{
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');

    return $slug !== '' ? $slug : $fallback;
}

function wp_unique_post_slug(string $slug, int $postId, string $status, string $type, int $parentId): string
{
    if (in_array($status, ['draft', 'pending', 'auto-draft'], true)) {
        return $slug;
    }

    foreach ($GLOBALS['wp']['posts'] as $other) {
        if ($other->ID !== $postId && $other->post_type === $type && $other->post_parent === $parentId && $other->post_name === $slug) {
            return $slug . '-2';
        }
    }

    return $slug;
}

function wp_slash(mixed $value): mixed
{
    if (is_array($value)) {
        return array_map('wp_slash', $value);
    }

    return is_string($value) ? addslashes($value) : $value;
}

/** @param array<string, mixed> $postarr */
function wp_update_post(array $postarr, bool $wpError = false): int
{
    $postId = (int) ($postarr['ID'] ?? 0);

    if (!isset($GLOBALS['wp']['posts'][$postId])) {
        return 0;
    }

    $post = $GLOBALS['wp']['posts'][$postId];

    foreach (['post_status', 'post_parent', 'post_name', 'post_content'] as $field) {
        if (array_key_exists($field, $postarr)) {
            $post->{$field} = is_string($postarr[$field]) ? stripslashes($postarr[$field]) : $postarr[$field];
        }
    }

    if ($post->post_name === '' && !in_array($post->post_status, ['draft', 'pending', 'auto-draft'], true)) {
        $post->post_name = wp_unique_post_slug(sanitize_title($post->post_title, (string) $postId), $postId, $post->post_status, $post->post_type, $post->post_parent);
    }

    $post->post_modified_gmt = '2026-11-14 08:00:' . sprintf('%02d', ++$GLOBALS['wp']['writes']);

    return $postId;
}

function is_wp_error(mixed $value): bool
{
    return false;
}

/**
 * @param array<string, mixed> $arguments
 * @return list<WP_Post>
 */
function get_pages(array $arguments): array
{
    $pages = [];

    foreach ($GLOBALS['wp']['posts'] as $post) {
        if ($post->post_type === 'page' && in_array($post->post_status, $arguments['post_status'], true)
            && in_array($arguments['child_of'], get_post_ancestors($post), true)
        ) {
            $pages[] = clone $post;
        }
    }

    return $pages;
}

function get_the_title(mixed $post): string
{
    return get_post($post)->post_title;
}

function get_option(string $name, mixed $default = false): mixed
{
    return $GLOBALS['wp']['options'][$name] ?? $default;
}

function wp_json_encode(mixed $value, int $flags = 0): string|false
{
    return json_encode($value, $flags);
}

function wp_cache_delete(string $key, string $group = ''): bool
{
    return true;
}

function wp_cache_flush(): bool
{
    return true;
}

function wp_get_theme(string $stylesheet = ''): object
{
    return new class {
        public function exists(): bool
        {
            return $GLOBALS['wp']['yootheme_version'] !== null;
        }

        public function get(string $header): string
        {
            return (string) $GLOBALS['wp']['yootheme_version'];
        }
    };
}

function get_template(): string
{
    return $GLOBALS['wp']['template'];
}

function get_stylesheet(): string
{
    return $GLOBALS['wp']['template'];
}

/** @return list<object> */
function wp_get_nav_menus(): array
{
    return array_values($GLOBALS['wp']['menus']);
}

/** @return array<string, string> */
function get_registered_nav_menus(): array
{
    return $GLOBALS['wp']['registered'];
}

/** @return array<string, int> */
function get_nav_menu_locations(): array
{
    return $GLOBALS['wp']['locations'];
}

function set_theme_mod(string $name, mixed $value): bool
{
    $GLOBALS['wp']['locations'] = $value;

    return true;
}

$tools = dirname(__DIR__) . '/packages/mirasai-wp/src/Tool';

foreach (['ToolInterface', 'AbstractTool', 'ToolArgumentValidator', 'YoothemeWpHelper', 'ContentReadTool', 'ContentUpdateTool',
    'TemplateLayoutInitTool', 'MenuPositionListTool', 'MenuPositionAssignTool'] as $class) {
    require_once $tools . '/' . $class . '.php';
}

use Mirasai\WordPress\Tool\ContentReadTool;
use Mirasai\WordPress\Tool\ContentUpdateTool;
use Mirasai\WordPress\Tool\MenuPositionAssignTool;
use Mirasai\WordPress\Tool\MenuPositionListTool;
use Mirasai\WordPress\Tool\TemplateLayoutInitTool;
use Mirasai\WordPress\Tool\ToolArgumentValidator;
use Mirasai\WordPress\Tool\YoothemeWpHelper;

$fails = 0;
$count = 0;
function check(bool $ok, string $name): void
{
    global $fails, $count;
    $count++;
    if (!$ok) {
        $fails++;
        echo "FAIL: $name\n";
    }
}

function etagOf(int $id): string
{
    return ContentReadTool::contentEtag(get_post($id));
}

/** @return list<string> */
function codes(array $response): array
{
    return array_column($response['warnings'] ?? [], 'code');
}

// ---------------------------------------------------------------------------
// content/update
// ---------------------------------------------------------------------------

$update = new ContentUpdateTool();
$schema = $update->getInputSchema();

check($update->toMcpTool()['metadata']['risk_level'] === 'guarded_write', 'update: guarded write');
check($schema['required'] === ['id', 'if_match'], 'update: id and if_match required');
check(array_keys($schema['properties']) === ['id', 'status', 'parent_id', 'slug', 'if_match', 'dry_run', 'confirm_guarded_write'], 'update: the schema declares status, parent and slug and no other field');

foreach (['title' => 'X', 'content' => '<p>x</p>', 'post_type' => 'post', 'menu_order' => 2] as $field => $value) {
    $rejection = ToolArgumentValidator::validate('content/update', $schema, ['id' => 20, 'if_match' => 'e', $field => $value]);
    check(($rejection['code'] ?? '') === 'unknown_argument', "update: the schema refuses {$field}");
    check((ContentUpdateTool::requestedChanges(['id' => 20, 'if_match' => 'e', $field => $value])['code'] ?? '') === 'unsupported_field', "update: the tool refuses {$field} on a direct call");
}

foreach (['trash', 'private', 'future', 'pending', 'inherit', '', 1] as $status) {
    $label = var_export($status, true);
    check(ToolArgumentValidator::validate('content/update', $schema, ['id' => 20, 'if_match' => 'e', 'status' => $status]) !== null, "update: the schema refuses status {$label}");
    check((ContentUpdateTool::requestedChanges(['status' => $status])['code'] ?? '') === 'invalid_status', "update: the tool refuses status {$label}");
}

check((ContentUpdateTool::requestedChanges(['parent_id' => -1])['code'] ?? '') === 'invalid_parent', 'update: negative parent refused');
check((ContentUpdateTool::requestedChanges(['parent_id' => '10'])['code'] ?? '') === 'invalid_parent', 'update: parent as a string refused');
check(ContentUpdateTool::requestedChanges(['parent_id' => 10.0]) === ['parent_id' => 10], 'update: 10.0 is the integer 10');
check((ContentUpdateTool::requestedChanges(['slug' => ' '])['code'] ?? '') === 'invalid_slug', 'update: blank slug refused');
check((ContentUpdateTool::requestedChanges(['id' => 20, 'if_match' => 'e'])['code'] ?? '') === 'nothing_to_change', 'update: a call without a field to change is refused');

check(($update->handle(['id' => 20, 'if_match' => etagOf(20), 'parent_id' => 0])['code'] ?? '') === 'guarded_write_confirmation_required', 'update: no write without dry_run or confirmation');
check(($update->handle(['id' => 999, 'if_match' => 'e', 'status' => 'draft', 'dry_run' => true])['code'] ?? '') === 'not_found', 'update: unknown page');
check(($update->handle(['id' => 40, 'if_match' => etagOf(40), 'status' => 'draft', 'dry_run' => true])['code'] ?? '') === 'unsupported_post_type', 'update: a post is refused');
check(($update->handle(['id' => 41, 'if_match' => etagOf(41), 'status' => 'publish', 'dry_run' => true])['code'] ?? '') === 'unsupported_current_status', 'update: a page in the trash is not restored');
check(($update->handle(['id' => 42, 'if_match' => etagOf(42), 'status' => 'publish', 'dry_run' => true])['code'] ?? '') === 'unsupported_current_status', 'update: a scheduled page is left alone');
check(($update->handle(['id' => 20, 'if_match' => 'old', 'parent_id' => 0, 'dry_run' => true])['code'] ?? '') === 'stale_etag', 'update: stale etag');

// Parent: what is refused.
foreach ([[20, 'parent_not_found', 'itself'], [999, 'parent_not_found', 'a missing page'], [40, 'parent_not_found', 'a post'],
    [41, 'parent_not_found', 'a page in the trash'], [21, 'parent_is_descendant', 'a page under it']] as [$parentId, $code, $label]) {
    $r = $update->handle(['id' => 20, 'if_match' => etagOf(20), 'parent_id' => $parentId, 'dry_run' => true]);
    check(($r['code'] ?? '') === $code, "update: parent refused when it is {$label}");
}

// Slug: nothing is adjusted on the way in.
$r = $update->handle(['id' => 20, 'if_match' => etagOf(20), 'slug' => 'Disseny Web', 'dry_run' => true]);
check(($r['code'] ?? '') === 'invalid_slug' && ($r['suggested_slug'] ?? '') === 'disseny-web', 'update: a slug WordPress would rewrite is refused, with what it would store');
$r = $update->handle(['id' => 20, 'if_match' => etagOf(20), 'parent_id' => 0, 'dry_run' => true]);
check(($r['code'] ?? '') === 'slug_conflict' && ($r['available_slug'] ?? '') === 'web-2', 'update: moving next to a page with the same slug is refused');
$r = $update->handle(['id' => 21, 'if_match' => etagOf(21), 'slug' => 'manteniment', 'dry_run' => true]);
check(($r['would_change'] ?? null) === false && $r['changes'] === [], 'update: the slug it already has changes nothing');

// Preview of a move: the page and the one under it.
$before = etagOf(20);
$r = $update->handle(['id' => 20, 'if_match' => $before, 'parent_id' => 0, 'slug' => 'disseny-web', 'dry_run' => true]);
check($r['action'] === 'preview' && $r['would_change'] === true, 'update: preview');
check($r['changes'] === ['parent_id' => ['before' => 10, 'after' => 0], 'slug' => ['before' => 'web', 'after' => 'disseny-web']], 'update: preview lists the fields that change');
check($r['permalink'] === ['before' => 'https://serveis.example/serveis/web/', 'after' => 'https://serveis.example/disseny-web/'], 'update: preview gives the permalink before and after');
check($r['children_total'] === 1 && $r['children'][0]['before'] === 'https://serveis.example/serveis/web/manteniment/'
    && $r['children'][0]['after'] === 'https://serveis.example/disseny-web/manteniment/', 'update: preview gives the permalink of the page under it');
check(codes($r) === ['old_url_not_redirected'], 'update: a published page that moves is flagged');
check(etagOf(20) === $before && $GLOBALS['wp']['writes'] === 0, 'update: the preview writes nothing');

// The write.
$r = $update->handle(['id' => 20, 'if_match' => $before, 'parent_id' => 0, 'slug' => 'disseny-web', 'confirm_guarded_write' => true]);
$stored = get_post(20);
check($r['action'] === 'updated' && $stored->post_parent === 0 && $stored->post_name === 'disseny-web' && $stored->post_status === 'publish', 'update: parent and slug written, status untouched');
check($r['old_etag'] === $before && $r['new_etag'] === etagOf(20) && $r['new_etag'] !== $before, 'update: old and new etag');
check($r['permalink']['after'] === 'https://serveis.example/disseny-web/' && $r['children'][0]['after'] === 'https://serveis.example/disseny-web/manteniment/', 'update: the write answers the stored permalinks');
check(!in_array('result_differs', codes($r), true), 'update: the write matched the preview');
check(($update->handle(['id' => 20, 'if_match' => $before, 'status' => 'draft', 'confirm_guarded_write' => true])['code'] ?? '') === 'stale_etag', 'update: the old etag no longer writes');

// The etag covers the parent even when the modification time does not move.
$same = get_post(20);
$moved = clone $same;
$moved->post_parent = 10;
check(ContentReadTool::contentEtag($same) !== ContentReadTool::contentEtag($moved), 'update: the content etag covers the parent');

// A confirm that changes nothing does not write.
$writes = $GLOBALS['wp']['writes'];
$r = $update->handle(['id' => 20, 'if_match' => etagOf(20), 'status' => 'publish', 'confirm_guarded_write' => true]);
check($r['action'] === 'unchanged' && $GLOBALS['wp']['writes'] === $writes, 'update: a confirm with nothing to change does not write');

// Front page to draft.
$r = $update->handle(['id' => 50, 'if_match' => etagOf(50), 'status' => 'draft', 'dry_run' => true]);
check(codes($r) === ['no_longer_public', 'front_page'], 'update: taking the front page down is flagged twice');
check($r['permalink']['after'] === 'https://serveis.example/?page_id=50', 'update: a draft has no public permalink');

// A draft that never had a slug gets it from WordPress on publishing.
$r = $update->handle(['id' => 30, 'if_match' => etagOf(30), 'status' => 'publish', 'dry_run' => true]);
check($r['changes']['slug'] === ['before' => '', 'after' => 'qui-som'] && $r['permalink']['after'] === 'https://serveis.example/qui-som/', 'update: preview predicts the slug of a draft being published');
$r = $update->handle(['id' => 30, 'if_match' => etagOf(30), 'status' => 'publish', 'confirm_guarded_write' => true]);
check($r['action'] === 'updated' && get_post(30)->post_name === 'qui-som' && !in_array('result_differs', codes($r), true), 'update: published with the predicted slug');
$GLOBALS['wp']['posts'][30]->post_status = 'draft';
$GLOBALS['wp']['posts'][30]->post_name = '';

// Paths under a page: only the start that belongs to the page above moves.
$r = $update->handle(['id' => 60, 'if_match' => etagOf(60), 'slug' => 'nou', 'dry_run' => true]);
check(array_column($r['children'], 'after', 'id') === [61 => 'https://serveis.example/nou/a/', 62 => 'https://serveis.example/nou/a/b/'], 'update: a slug repeated further down stays where it is');
check(ContentUpdateTool::movedLink('https://serveis.example/serveis/web/', 'serveis/web', 'serveis', 'empresa') === 'https://serveis.example/empresa/web/', 'update: the host is not mistaken for the path');
check(ContentUpdateTool::movedLink('https://serveis.example/bloc/serveis/web/', 'serveis/web', 'serveis', 'empresa') === 'https://serveis.example/bloc/empresa/web/', 'update: a site in a folder keeps its folder');
check(ContentUpdateTool::movedLink('https://serveis.example/?page_id=21', 'serveis/web', 'serveis', 'empresa') === 'https://serveis.example/?page_id=21', 'update: a plain link does not move');
check(ContentUpdateTool::movedLink('https://serveis.example/fill/', 'fill', '', 'pare') === null, 'update: no prediction under a page without a path');
check(ContentUpdateTool::movedLink('https://serveis.example/altre/fill/', 'altre/fill', 'serveis', 'empresa') === null, 'update: no prediction for a path that is not under the page');

// ---------------------------------------------------------------------------
// template/layout-init
// ---------------------------------------------------------------------------

$init = new TemplateLayoutInitTool();
$helper = new YoothemeWpHelper();
$emptyLayout = ['type' => 'layout', 'children' => [], 'version' => '5.0.53'];

check($init->toMcpTool()['metadata']['risk_level'] === 'guarded_write', 'init: guarded write');
check(array_keys($init->getInputSchema()['properties']) === ['post_id', 'if_match', 'dry_run', 'confirm_guarded_write'], 'init: the schema takes no content');
check($init->getInputSchema()['required'] === ['post_id', 'if_match'], 'init: post_id and if_match required');
check(ToolArgumentValidator::validate('template/layout-init', $init->getInputSchema(), ['post_id' => 30, 'if_match' => 'e', 'layout' => $emptyLayout]) !== null, 'init: a layout argument is refused');

check(str_contains((string) ($helper->resolveTarget(['post_id' => 30])['error'] ?? ''), 'template/layout-init'), 'init: post_layout_missing points here');
check(($init->handle(['post_id' => 30, 'if_match' => etagOf(30)])['code'] ?? '') === 'guarded_write_confirmation_required', 'init: no write without dry_run or confirmation');
check(($init->handle(['post_id' => 999, 'if_match' => 'e', 'dry_run' => true])['code'] ?? '') === 'post_not_found', 'init: unknown page');
check(($init->handle(['post_id' => 40, 'if_match' => etagOf(40), 'dry_run' => true])['code'] ?? '') === 'unsupported_post_type', 'init: a post is refused');
check(($init->handle(['post_id' => 41, 'if_match' => etagOf(41), 'dry_run' => true])['code'] ?? '') === 'unsupported_current_status', 'init: a page in the trash is refused');
check(($init->handle(['post_id' => 30, 'if_match' => 'old', 'dry_run' => true])['code'] ?? '') === 'stale_etag', 'init: stale etag');
check(($init->handle(['post_id' => 31, 'if_match' => etagOf(31), 'dry_run' => true])['code'] ?? '') === 'post_not_empty', 'init: a page with text is refused');
check(($init->handle(['post_id' => 32, 'if_match' => etagOf(32), 'dry_run' => true])['code'] ?? '') === 'post_not_empty', 'init: a page with layout meta is refused, even when it does not decode');

$GLOBALS['wp']['yootheme_version'] = null;
check(($init->handle(['post_id' => 30, 'if_match' => etagOf(30), 'dry_run' => true])['code'] ?? '') === 'yootheme_not_installed', 'init: needs YOOtheme');
$GLOBALS['wp']['yootheme_version'] = '5.0.53';

$writes = $GLOBALS['wp']['writes'];
$before = etagOf(30);
$r = $init->handle(['post_id' => 30, 'if_match' => $before, 'dry_run' => true]);
check($r['action'] === 'preview' && $r['layout'] === $emptyLayout && $GLOBALS['wp']['writes'] === $writes, 'init: preview shows the layout and writes nothing');

$r = $init->handle(['post_id' => 30, 'if_match' => $before, 'confirm_guarded_write' => true]);
check($r['action'] === 'created' && get_post(30)->post_content === '<!-- {"type":"layout","children":[],"version":"5.0.53"} -->', 'init: writes the empty layout comment');
check(($helper->loadPostLayout(30)['layout'] ?? null) === $emptyLayout, 'init: the page now reads as a Builder page');
check($r['etag'] === $helper->resolveTarget(['post_id' => 30])['etag'], 'init: etag is the if_match of template/element-add');
check($r['content_etag'] === etagOf(30) && $r['content_etag'] !== $before, 'init: content etag moves');
check($r['fallback_content'] === ['status' => 'regenerated', 'length' => 0] && !isset($r['cache']['fallback_content']), 'init: fallback_content is answered');
check(($init->handle(['post_id' => 30, 'if_match' => etagOf(30), 'dry_run' => true])['code'] ?? '') === 'post_not_empty', 'init: a second run is refused');

// ---------------------------------------------------------------------------
// menu/position-list and menu/position-assign
// ---------------------------------------------------------------------------

$list = new MenuPositionListTool();
$assign = new MenuPositionAssignTool();

check($list->toMcpTool()['metadata']['risk_level'] === 'read' && $assign->toMcpTool()['metadata']['risk_level'] === 'guarded_write', 'menu: list reads, assign is a guarded write');
check($assign->getInputSchema()['required'] === ['position', 'menu_id', 'if_match'], 'menu: position, menu_id and if_match required');

$state = $list->handle([]);
check($state['yootheme_active'] === true && array_column($state['positions'], 'menu_id', 'position') === ['navbar' => null, 'dialog-mobile' => null], 'menu: a 0 left by a deleted menu reads as no menu');
check($state['menus'] === [['id' => 3, 'name' => 'Principal', 'items' => 4], ['id' => 4, 'name' => 'Buit', 'items' => 0]], 'menu: menus listed with their item count');
$GLOBALS['wp']['locations'] = [];
check($list->handle([])['etag'] === $state['etag'], 'menu: the etag does not depend on that 0');
$etag = $state['etag'];

check(($assign->handle(['position' => 'navbar', 'menu_id' => 3, 'if_match' => $etag])['code'] ?? '') === 'guarded_write_confirmation_required', 'menu: no write without dry_run or confirmation');
check(($assign->handle(['position' => 'navbar', 'menu_id' => 3, 'if_match' => 'old', 'dry_run' => true])['code'] ?? '') === 'stale_etag', 'menu: stale etag');
$r = $assign->handle(['position' => 'footer', 'menu_id' => 3, 'if_match' => $etag, 'dry_run' => true]);
check(($r['code'] ?? '') === 'position_not_found' && $r['available_positions'] === ['navbar', 'dialog-mobile'], 'menu: unknown position, with the ones that exist');
check(($assign->handle(['position' => 'navbar', 'menu_id' => 9, 'if_match' => $etag, 'dry_run' => true])['code'] ?? '') === 'menu_not_found', 'menu: unknown menu, none is created');
check(($assign->handle(['position' => 'navbar', 'menu_id' => -1, 'if_match' => $etag, 'dry_run' => true])['code'] ?? '') === 'missing_arguments', 'menu: negative menu refused');

$GLOBALS['wp']['template'] = 'twentytwentyfive';
check(($assign->handle(['position' => 'navbar', 'menu_id' => 3, 'if_match' => MenuPositionListTool::state()['etag'], 'dry_run' => true])['code'] ?? '') === 'yootheme_not_active', 'menu: only on YOOtheme');
$GLOBALS['wp']['template'] = 'yootheme';

$r = $assign->handle(['position' => 'navbar', 'menu_id' => 3, 'if_match' => $etag, 'dry_run' => true]);
check($r['action'] === 'preview' && $r['before'] === ['menu_id' => null, 'menu_name' => null] && $r['after'] === ['menu_id' => 3, 'menu_name' => 'Principal'], 'menu: preview before and after');
check($GLOBALS['wp']['locations'] === [], 'menu: the preview writes nothing');
check(codes($assign->handle(['position' => 'navbar', 'menu_id' => 4, 'if_match' => $etag, 'dry_run' => true])) === ['menu_empty'], 'menu: a menu without items is flagged');

$GLOBALS['wp']['locations'] = ['dialog-mobile' => 4];
$etag = MenuPositionListTool::state()['etag'];
$r = $assign->handle(['position' => 'navbar', 'menu_id' => 3, 'if_match' => $etag, 'confirm_guarded_write' => true]);
check($r['action'] === 'updated' && $GLOBALS['wp']['locations'] === ['dialog-mobile' => 4, 'navbar' => 3], 'menu: assigned, the other position kept');
check($r['old_etag'] === $etag && $r['new_etag'] === MenuPositionListTool::state()['etag'] && $r['new_etag'] !== $etag, 'menu: old and new etag');

$r = $assign->handle(['position' => 'navbar', 'menu_id' => 3, 'if_match' => $r['new_etag'], 'confirm_guarded_write' => true]);
check($r['action'] === 'unchanged', 'menu: the same menu again changes nothing');
$r = $assign->handle(['position' => 'navbar', 'menu_id' => 0, 'if_match' => MenuPositionListTool::state()['etag'], 'confirm_guarded_write' => true]);
check($r['action'] === 'updated' && $GLOBALS['wp']['locations'] === ['dialog-mobile' => 4], 'menu: menu_id 0 empties the position');

echo $fails === 0 ? "OK: $count checks\n" : "$fails of $count checks failed\n";
exit($fails === 0 ? 0 : 1);
