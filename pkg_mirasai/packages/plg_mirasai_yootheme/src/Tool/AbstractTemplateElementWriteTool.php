<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Mirasai\Library\Tool\AbstractTool;
use Mirasai\Library\Tool\YooThemeHelper;

abstract class AbstractTemplateElementWriteTool extends AbstractTool
{
    protected YooThemeHelper $yooHelper;

    public function __construct()
    {
        parent::__construct();
        $this->yooHelper = new YooThemeHelper($this->db);
    }

    public function getPermissions(): array
    {
        return [
            'risk_level' => self::RISK_GUARDED_WRITE,
            'idempotent' => false,
        ];
    }

    /**
     * @param callable(array<string, mixed>, array<string, mixed>): array<string, mixed> $mutator
     * @return array<string, mixed>
     */
    protected function mutateTemplateElement(array $arguments, callable $mutator): array
    {
        $key = trim((string) ($arguments['key'] ?? ''));
        $ifMatch = trim((string) ($arguments['if_match'] ?? ''));
        $dryRun = !empty($arguments['dry_run']);

        if ($key === '' || $ifMatch === '') {
            return [
                'error' => 'key and if_match are required.',
                'code' => 'missing_if_match',
            ];
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

        $mutation = $mutator($layout, $arguments);

        if (isset($mutation['error'])) {
            return $mutation;
        }

        if (!isset($mutation['layout']) || !is_array($mutation['layout'])) {
            return [
                'error' => 'Internal error: mutation did not return a layout.',
                'code' => 'missing_mutation_layout',
            ];
        }

        $this->yooHelper->setTemplateLayout($template, $mutation['layout']);
        $newEtag = $this->yooHelper->buildTemplateEtag($template);
        $templates[$key] = $template;

        $cache = $dryRun
            ? ['cleared' => false, 'groups' => [], 'failures' => [], 'reason' => 'dry_run']
            : $this->yooHelper->writeTemplates($templates);

        unset($mutation['layout']);

        $response = array_merge([
            'key' => $key,
            'dry_run' => $dryRun,
            'would_change' => !hash_equals($currentEtag, $newEtag),
            'old_etag' => $currentEtag,
            'new_etag' => $newEtag,
            'collection_etag' => $this->yooHelper->buildTemplatesEtag($templates),
            'cache' => $cache,
        ], $mutation);

        if ($dryRun) {
            $response['action'] = 'preview';
            $response['note'] = 'No changes were written. Retry with confirm_guarded_write=true and the same if_match if the preview is still current.';
        } else {
            $response['action'] = 'updated';
        }

        return $response;
    }
}
