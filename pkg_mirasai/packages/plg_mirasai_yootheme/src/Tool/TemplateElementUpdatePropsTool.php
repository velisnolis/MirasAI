<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Mirasai\Library\Tool\AbstractTool;
use Mirasai\Library\Tool\YooThemeElementNavigator;
use Mirasai\Library\Tool\YooThemeHelper;

class TemplateElementUpdatePropsTool extends AbstractTool
{
    private YooThemeHelper $yooHelper;

    public function __construct()
    {
        parent::__construct();
        $this->yooHelper = new YooThemeHelper($this->db);
    }

    public function getName(): string
    {
        return 'template/element-update-props';
    }

    public function getDescription(): string
    {
        return 'Updates props on one YOOtheme Builder template element. Requires if_match with the current template etag from template/list, template/summary, template/element-list, or template/element-read. Defaults to merge=true, so supplied props are merged into existing props instead of replacing them wholesale.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'key' => [
                    'type' => 'string',
                    'description' => 'Template storage key as returned by template/list.',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Element path as returned by template/element-list.',
                ],
                'if_match' => [
                    'type' => 'string',
                    'description' => 'Required current template etag. Stale values are rejected before any write.',
                ],
                'props' => [
                    'type' => 'object',
                    'description' => 'Props to merge into or replace on the target element.',
                ],
                'merge' => [
                    'type' => 'boolean',
                    'description' => 'If true or omitted, merge supplied props into existing props. If false, replace the element props object with the supplied props.',
                ],
                'include_element' => [
                    'type' => 'boolean',
                    'description' => 'If true, return the updated element object without children. Defaults to false.',
                ],
                'dry_run' => [
                    'type' => 'boolean',
                    'description' => 'If true, validate and preview the update without writing YOOtheme custom_data.',
                ],
                'confirm_guarded_write' => [
                    'type' => 'boolean',
                    'description' => 'Required for the real write after review. Not required when dry_run=true.',
                ],
            ],
            'required' => ['key', 'path', 'if_match', 'props'],
        ];
    }

    public function getPermissions(): array
    {
        return [
            'risk_level' => self::RISK_GUARDED_WRITE,
            'idempotent' => false,
        ];
    }

    public function handle(array $arguments): array
    {
        $key = trim((string) ($arguments['key'] ?? ''));
        $path = trim((string) ($arguments['path'] ?? ''));
        $ifMatch = trim((string) ($arguments['if_match'] ?? ''));
        $props = $arguments['props'] ?? null;
        $merge = array_key_exists('merge', $arguments) ? (bool) $arguments['merge'] : true;
        $includeElement = !empty($arguments['include_element']);
        $dryRun = !empty($arguments['dry_run']);

        if ($key === '' || $path === '' || $ifMatch === '') {
            return ['error' => 'key, path, and if_match are required.'];
        }

        if (!is_array($props)) {
            return ['error' => 'props must be an object.'];
        }

        $templates = $this->yooHelper->loadTemplates();
        $template = $templates[$key] ?? null;

        if (!is_array($template)) {
            return ['error' => "Template {$key} not found."];
        }

        $currentEtag = $this->yooHelper->buildTemplateEtag($template);

        if (!hash_equals($currentEtag, $ifMatch)) {
            return [
                'error' => 'Template etag mismatch. Re-read the template and retry with the fresh etag.',
                'code' => 'stale_etag',
                'expected_etag' => $currentEtag,
                'provided_etag' => $ifMatch,
            ];
        }

        $layout = $this->yooHelper->getTemplateLayout($template);

        if ($layout === null) {
            return ['error' => "Template {$key} has no layout."];
        }

        $updated = (new YooThemeElementNavigator())->updateElementProps($layout, $path, $props, $merge);

        if ($updated === null) {
            return ['error' => "Element path {$path} not found in template {$key}."];
        }

        $this->yooHelper->setTemplateLayout($template, $updated['layout']);
        $newEtag = $this->yooHelper->buildTemplateEtag($template);
        $templates[$key] = $template;

        $cache = $dryRun
            ? ['cleared' => false, 'groups' => [], 'failures' => [], 'reason' => 'dry_run']
            : $this->yooHelper->writeTemplates($templates);

        $response = [
            'key' => $key,
            'path' => $path,
            'dry_run' => $dryRun,
            'would_change' => !hash_equals($currentEtag, $newEtag),
            'merge' => $merge,
            'updated_prop_keys' => array_values(array_map('strval', array_keys($props))),
            'old_etag' => $currentEtag,
            'new_etag' => $newEtag,
            'collection_etag' => $this->yooHelper->buildTemplatesEtag($templates),
            'cache' => $cache,
            'metadata' => $updated['metadata'],
        ];

        if ($dryRun) {
            $response['action'] = 'preview';
            $response['note'] = 'No changes were written. Retry with confirm_guarded_write=true and the same if_match if the preview is still current.';
        } else {
            $response['action'] = 'updated';
        }

        if ($includeElement) {
            $element = $updated['element'];
            unset($element['children']);
            $response['element'] = $element;
        }

        return $response;
    }
}
