<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

abstract class AbstractTemplateElementWriteTool extends TemplateReadTool
{
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
        $ifMatch = trim((string) ($arguments['if_match'] ?? ''));
        $dryRun = ($arguments['dry_run'] ?? null) === true;
        $confirmed = ($arguments['confirm_guarded_write'] ?? null) === true;
        $helper = new YoothemeWpHelper();

        if ($ifMatch === '') {
            return [
                'error' => 'key, post_id, or widget_id, and if_match are required.',
                'code' => 'missing_if_match',
            ];
        }

        $target = $helper->resolveTarget($arguments);

        if (isset($target['error'])) {
            return $target;
        }

        if (!hash_equals($target['etag'], $ifMatch)) {
            return [
                'error' => 'YOOtheme layout etag mismatch. Re-read the layout and retry with the fresh etag.',
                'code' => 'stale_etag',
                'expected_etag' => $target['etag'],
                'provided_etag' => $ifMatch,
            ];
        }

        if (!$dryRun && !$confirmed) {
            return [
                'error' => 'This is a guarded write. Retry with dry_run=true first, then confirm_guarded_write=true if the preview is correct.',
                'code' => 'guarded_write_confirmation_required',
                'old_etag' => $target['etag'],
            ];
        }

        $mutation = $mutator($target['layout'], $arguments);

        if (isset($mutation['error'])) {
            return $mutation;
        }

        if (!isset($mutation['layout']) || !is_array($mutation['layout'])) {
            return [
                'error' => 'Internal error: mutation did not return a layout.',
                'code' => 'missing_mutation_layout',
            ];
        }

        $newEtag = $this->previewEtag($helper, $target, $mutation['layout']);

        if ($dryRun) {
            $cache = ['cleared' => false, 'groups' => [], 'failures' => [], 'reason' => 'dry_run'];
            $collectionEtag = null;
        } else {
            $freshTarget = $helper->resolveTarget($arguments);

            if (isset($freshTarget['error'])) {
                return $freshTarget;
            }

            if (!hash_equals($freshTarget['etag'], $ifMatch)) {
                return [
                    'error' => 'YOOtheme layout changed before write. Re-read the layout and retry with the fresh etag.',
                    'code' => 'stale_etag',
                    'expected_etag' => $freshTarget['etag'],
                    'provided_etag' => $ifMatch,
                ];
            }

            [$cache, $collectionEtag] = $this->writeTargetLayout($helper, $freshTarget, $mutation['layout']);
        }

        unset($mutation['layout']);

        $response = array_merge([
            'storage' => $target['storage'],
            'id' => $target['id'],
            'label' => $target['label'],
            'dry_run' => $dryRun,
            'would_change' => !hash_equals($target['etag'], $newEtag),
            'old_etag' => $target['etag'],
            'new_etag' => $newEtag,
            'collection_etag' => $collectionEtag,
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

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $layout
     */
    private function previewEtag(YoothemeWpHelper $helper, array $target, array $layout): string
    {
        if ($target['storage'] === 'template') {
            $raw = $target['raw'];
            $helper->setTemplateLayout($raw, $layout);

            return $helper->etag($raw);
        }

        if ($target['storage'] === 'widget') {
            $raw = $target['raw'];
            $helper->setWidgetLayout($raw, $layout);

            return $helper->etag($raw);
        }

        return $helper->etag($layout);
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $layout
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    private function writeTargetLayout(YoothemeWpHelper $helper, array $target, array $layout): array
    {
        if ($target['storage'] === 'template') {
            $state = $helper->loadState();
            $templates = is_array($state['templates'] ?? null) ? $state['templates'] : [];
            $raw = $target['raw'];
            $helper->setTemplateLayout($raw, $layout);
            $templates[$target['id']] = $raw;
            $state['templates'] = $templates;

            return [$helper->writeState($state), $helper->etag($state)];
        }

        if ($target['storage'] === 'widget') {
            $widgets = $helper->loadBuilderWidgets();
            $raw = $target['raw'];
            $helper->setWidgetLayout($raw, $layout);
            $widgets[$target['id']] = $raw;
            update_option('widget_builderwidget', $widgets, false);

            return [$helper->invalidateBuilderCache(), $helper->etag($widgets)];
        }

        $meta = is_array($target['meta'] ?? null) ? $target['meta'] : [];
        $postId = (int) ($meta['post_id'] ?? $target['id']);
        $postTarget = $helper->loadPostLayout($postId) ?? [];

        return [$helper->writePostLayout($postId, $postTarget, $layout), null];
    }
}
