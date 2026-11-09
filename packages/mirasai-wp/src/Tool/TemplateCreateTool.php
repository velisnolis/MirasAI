<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

/**
 * Mirrored in plg_mirasai_yootheme; storage and etags are WordPress's.
 */
class TemplateCreateTool extends AbstractTool
{
    public function getName(): string
    {
        return 'template/create';
    }

    public function getDescription(): string
    {
        return 'Creates a new YOOtheme template, switched off, so no page changes until someone switches it on in the Builder. '
            . 'Give name, type as YOOtheme names it (single-post, archive-post, single-page, search, error-404, or single-<post type> '
            . 'for a custom post type; copy it from template/list when unsure) and optionally a starting layout. if_match is the '
            . 'collection_etag of template/list. Returns the new key: fill it with template/element-add and check it with '
            . 'template/render-check and a context_id before switching it on.';
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
                'name' => ['type' => 'string', 'description' => 'Template name shown in the Builder.'],
                'type' => ['type' => 'string', 'description' => 'YOOtheme template type, for example single-post.'],
                'layout' => [
                    'type' => 'object',
                    'description' => 'Optional starting layout: {"type":"layout","children":[...]}. Defaults to an empty layout.',
                ],
                'if_match' => ['type' => 'string', 'description' => 'collection_etag from template/list.'],
                'dry_run' => ['type' => 'boolean', 'description' => 'Preview without writing.'],
                'confirm_guarded_write' => ['type' => 'boolean', 'description' => 'Required with dry_run=false.'],
            ],
            'required' => ['name', 'type', 'if_match'],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array
    {
        $name = trim((string) ($arguments['name'] ?? ''));
        $type = trim((string) ($arguments['type'] ?? ''));
        $ifMatch = trim((string) ($arguments['if_match'] ?? ''));
        $dryRun = ($arguments['dry_run'] ?? null) === true;
        $confirmed = ($arguments['confirm_guarded_write'] ?? null) === true;

        $template = self::buildTemplate($name, $type, $arguments['layout'] ?? null);

        if (isset($template['error'])) {
            return $template;
        }

        if (!$dryRun && !$confirmed) {
            return [
                'error' => 'This is a guarded write. Retry with dry_run=true first, then confirm_guarded_write=true if the preview is correct.',
                'code' => 'guarded_write_confirmation_required',
            ];
        }

        $helper = new YoothemeWpHelper();
        $state = $helper->loadState();
        $collectionEtag = $helper->etag($state);

        if (!hash_equals($collectionEtag, $ifMatch)) {
            return [
                'error' => 'YOOtheme templates changed. Call template/list again and retry with its collection_etag.',
                'code' => 'stale_etag',
                'expected_etag' => $collectionEtag,
                'provided_etag' => $ifMatch,
            ];
        }

        $templates = is_array($state['templates'] ?? null) ? $state['templates'] : [];
        $sameName = self::keysNamed($templates, $name);

        if ($dryRun) {
            return [
                'dry_run' => true,
                'action' => 'preview',
                'key' => null,
                'template' => self::preview($template),
                'same_name' => $sameName,
                'collection_etag' => $collectionEtag,
                'note' => 'No changes were written. The key is chosen when the template is written. Retry with confirm_guarded_write=true and the same if_match.',
            ];
        }

        do {
            $key = $helper->generateStorageKey();
        } while (isset($templates[$key]));

        $templates[$key] = $template;
        $state['templates'] = $templates;
        $cache = $helper->writeState($state);

        return [
            'dry_run' => false,
            'action' => 'created',
            'key' => $key,
            'template' => self::preview($template),
            'same_name' => $sameName,
            'etag' => $helper->etag($template),
            'collection_etag' => $helper->etag($state),
            'cache' => $cache,
            'next' => 'template/element-add with key and this etag as if_match; template/render-check with a context_id; switch it on in the YOOtheme Builder.',
        ];
    }

    /**
     * The stored entry: what YOOtheme's own template editor saves, switched off.
     *
     * @return array<string, mixed>
     */
    public static function buildTemplate(string $name, string $type, mixed $layout): array
    {
        if ($name === '') {
            return ['error' => 'name is required.', 'code' => 'missing_name'];
        }

        if (preg_match('/^[a-z0-9]+(?:[-_.][a-z0-9]+)*$/', $type) !== 1) {
            return ['error' => 'type must look like single-post: lowercase letters, digits, ., - or _.', 'code' => 'invalid_template_type'];
        }

        if ($layout === null) {
            $layout = ['type' => 'layout', 'children' => []];
        }

        if (!is_array($layout) || array_is_list($layout) || ($layout['type'] ?? 'layout') !== 'layout'
            || (isset($layout['children']) && (!is_array($layout['children']) || !array_is_list($layout['children'])))
        ) {
            return ['error' => 'layout must be an object of type layout with a children list.', 'code' => 'invalid_layout'];
        }

        $layout['type'] = 'layout';
        $layout['children'] ??= [];

        return ['name' => $name, 'type' => $type, 'status' => 'disabled', 'query' => [], 'layout' => $layout];
    }

    /**
     * @param array<string, mixed> $templates
     * @return list<string>
     */
    public static function keysNamed(array $templates, string $name): array
    {
        $keys = [];

        foreach ($templates as $key => $template) {
            if (is_array($template) && mb_strtolower(trim((string) ($template['name'] ?? '')), 'UTF-8') === mb_strtolower($name, 'UTF-8')) {
                $keys[] = (string) $key;
            }
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $template
     * @return array<string, mixed>
     */
    private static function preview(array $template): array
    {
        return [
            'name' => $template['name'],
            'type' => $template['type'],
            'status' => $template['status'],
            'top_level_elements' => count($template['layout']['children']),
        ];
    }
}
