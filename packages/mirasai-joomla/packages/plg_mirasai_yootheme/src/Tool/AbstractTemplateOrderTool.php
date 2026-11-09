<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Mirasai\Library\Tool\AbstractTool;
use Mirasai\Library\Tool\YooThemeHelper;

/**
 * Switching a template on or off, or moving it, changes which template visitors get. The preview says
 * what the template does among those of its type before and after, from TemplateOrder.
 *
 * Mirrors mirasai-wp's AbstractTemplateOrderTool; storage and etags are Joomla's.
 */
abstract class AbstractTemplateOrderTool extends AbstractTool
{
    public function getPermissions(): array
    {
        return [
            'risk_level' => self::RISK_GUARDED_WRITE,
            'idempotent' => true,
        ];
    }

    /**
     * @param array<string, mixed> $templates storage order
     * @param array<string, mixed> $arguments
     * @return array<string, mixed> the templates in their new state, or an error
     */
    abstract protected function change(array $templates, string $key, array $arguments): array;

    /**
     * @return array<string, mixed>
     */
    protected function baseSchema(): array
    {
        return [
            'key' => ['type' => 'string', 'description' => 'YOOtheme template key from template/list.'],
            'if_match' => ['type' => 'string', 'description' => 'collection_etag from template/list.'],
            'dry_run' => ['type' => 'boolean', 'description' => 'Preview without writing.'],
            'confirm_guarded_write' => ['type' => 'boolean', 'description' => 'Required with dry_run=false.'],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array
    {
        $key = trim((string) ($arguments['key'] ?? ''));
        $ifMatch = trim((string) ($arguments['if_match'] ?? ''));
        $dryRun = ($arguments['dry_run'] ?? null) === true;

        if (!$dryRun && ($arguments['confirm_guarded_write'] ?? null) !== true) {
            return [
                'error' => 'This is a guarded write. Retry with dry_run=true first, then confirm_guarded_write=true if the preview is correct.',
                'code' => 'guarded_write_confirmation_required',
            ];
        }

        $helper = new YooThemeHelper($this->db);
        $templates = $helper->loadTemplates();
        $collectionEtag = $helper->buildTemplatesEtag($templates);

        if (!hash_equals($collectionEtag, $ifMatch)) {
            return [
                'error' => 'YOOtheme templates changed. Call template/list again and retry with its collection_etag.',
                'code' => 'stale_etag',
                'expected_etag' => $collectionEtag,
                'provided_etag' => $ifMatch,
            ];
        }

        if (!is_array($templates[$key] ?? null)) {
            return ['error' => "Template {$key} not found.", 'code' => 'template_not_found'];
        }

        if (trim((string) ($templates[$key]['type'] ?? '')) === '') {
            return ['error' => "Template {$key} has no type, so it applies to no page. Give it one in the Builder first.", 'code' => 'template_type_missing'];
        }

        $changed = $this->change($templates, $key, $arguments);

        if (isset($changed['error'])) {
            return $changed;
        }

        $response = [
            'key' => $key,
            'dry_run' => $dryRun,
            'would_change' => $changed !== $templates || array_keys($changed) !== array_keys($templates),
            'before' => TemplateOrder::describe($templates, $key),
            'after' => TemplateOrder::describe($changed, $key),
        ];

        if ($dryRun) {
            return $response + [
                'action' => 'preview',
                'collection_etag' => $collectionEtag,
                'note' => 'No changes were written. Retry with confirm_guarded_write=true and the same if_match. Then check a page of this type with site/public-fetch.',
            ];
        }

        return $response + [
            'action' => 'updated',
            'cache' => $helper->writeTemplates($changed),
            'collection_etag' => $helper->buildTemplatesEtag($changed),
            'next' => 'site/public-fetch on a page of this type (site/cache-purge if a page cache serves the old one).',
        ];
    }
}
