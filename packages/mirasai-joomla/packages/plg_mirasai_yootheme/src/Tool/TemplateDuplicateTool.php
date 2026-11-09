<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Mirasai\Library\Tool\AbstractTool;
use Mirasai\Library\Tool\YooThemeHelper;

/**
 * Mirrors mirasai-wp's TemplateDuplicateTool; storage and etags are Joomla's.
 */
class TemplateDuplicateTool extends AbstractTool
{
    public function getName(): string
    {
        return 'template/duplicate';
    }

    public function getDescription(): string
    {
        return 'Copies a YOOtheme template with its type, conditions, settings and layout as a new template, switched off and '
            . 'placed right after the original, so no page changes. The original stays as it is. if_match is the collection_etag '
            . 'of template/list. Returns the new key; change the copy with template/element-* and switch it on with template/set-status.';
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
                'key' => ['type' => 'string', 'description' => 'Template to copy, from template/list.'],
                'name' => ['type' => 'string', 'description' => 'Name of the copy. Defaults to the original name followed by (copy).'],
                'if_match' => ['type' => 'string', 'description' => 'collection_etag from template/list.'],
                'dry_run' => ['type' => 'boolean', 'description' => 'Preview without writing.'],
                'confirm_guarded_write' => ['type' => 'boolean', 'description' => 'Required with dry_run=false.'],
            ],
            'required' => ['key', 'if_match'],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array
    {
        $key = trim((string) ($arguments['key'] ?? ''));
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

        if (!hash_equals($collectionEtag, trim((string) ($arguments['if_match'] ?? '')))) {
            return [
                'error' => 'YOOtheme templates changed. Call template/list again and retry with its collection_etag.',
                'code' => 'stale_etag',
                'expected_etag' => $collectionEtag,
            ];
        }

        if (!is_array($templates[$key] ?? null)) {
            return ['error' => "Template {$key} not found.", 'code' => 'template_not_found'];
        }

        $name = trim((string) ($arguments['name'] ?? ''));
        $name = $name !== '' ? $name : trim((string) ($templates[$key]['name'] ?? $key)) . ' (copy)';

        do {
            $newKey = $helper->generateStorageKey();
        } while (isset($templates[$newKey]));

        $changed = TemplateOrder::duplicated($templates, $key, $newKey, $name);
        $response = [
            'source_key' => $key,
            'name' => $name,
            'type' => (string) ($templates[$key]['type'] ?? ''),
            'status' => 'disabled',
            'dry_run' => $dryRun,
        ];

        if ($dryRun) {
            return $response + [
                'action' => 'preview',
                'key' => null,
                'collection_etag' => $collectionEtag,
                'note' => 'No changes were written. The key is chosen when the copy is written. Retry with confirm_guarded_write=true and the same if_match.',
            ];
        }

        return $response + [
            'action' => 'created',
            'key' => $newKey,
            'etag' => $helper->buildTemplateEtag($changed[$newKey]),
            'cache' => $helper->writeTemplates($changed),
            'collection_etag' => $helper->buildTemplatesEtag($changed),
        ];
    }
}
