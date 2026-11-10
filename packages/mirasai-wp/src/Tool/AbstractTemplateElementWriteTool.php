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
     * Refuse prop values the installed element does not offer.
     *
     * Silence is not an option here, but neither is guessing: when the element
     * definition cannot be loaded — a third-party element, a type YOOtheme no
     * longer ships — there is nothing to check against, and the write proceeds
     * exactly as it did before.
     *
     * @param array<string, mixed> $props
     * @return array<string, mixed>|null
     */
    protected function rejectInvalidProps(string $elementType, array $props): ?array
    {
        if ($elementType === '' || $props === []) {
            return null;
        }

        $definition = (new YoothemeWpHelper())->loadElementDefinition($elementType);

        if (isset($definition['error']) || !is_array($definition['fields'] ?? null)) {
            return null;
        }

        return YoothemePropsValidator::validate($elementType, $definition['fields'], $props);
    }


    /**
     * rejectInvalidProps() on every node of an element tree, so a section added with its rows,
     * columns and elements is checked as a whole. at is the node's place inside the added element.
     *
     * @param array<string, mixed> $element
     * @return array<string, mixed>|null
     */
    protected function rejectInvalidTree(array $element, string $at = 'element'): ?array
    {
        $rejection = $this->rejectInvalidProps(
            (string) ($element['type'] ?? ''),
            is_array($element['props'] ?? null) ? $element['props'] : []
        );

        if ($rejection !== null) {
            return $rejection + ['at' => $at];
        }

        foreach (is_array($element['children'] ?? null) ? $element['children'] : [] as $index => $child) {
            if (is_array($child)) {
                $type = is_string($child['type'] ?? null) && $child['type'] !== '' ? $child['type'] : 'unknown';
                $rejection = $this->rejectInvalidTree($child, $at . '>' . $type . '[' . $index . ']');

                if ($rejection !== null) {
                    return $rejection;
                }
            }
        }

        return null;
    }

    /**
     * The layouts the installed Builder offers for a row, null when its definition cannot be read.
     *
     * @return list<string>|null
     */
    protected function rowLayoutOptions(): ?array
    {
        $definition = (new YoothemeWpHelper())->loadElementDefinition('row');

        return isset($definition['error']) ? null : YoothemeRowLayout::options($definition);
    }

    /**
     * Set layout on every row of an added element that has columns and no layout, when the widths
     * match one Builder layout (F-044). Rows that cannot be filled come back as warnings.
     *
     * @param array<string, mixed> $element
     * @param list<string>|null $options
     * @param list<array<string, mixed>> $filled
     * @param list<array<string, mixed>> $warnings
     * @return array<string, mixed>
     */
    protected function fillRowLayouts(array $element, ?array $options, array &$filled, array &$warnings, string $at = 'element'): array
    {
        if (($element['type'] ?? null) === 'row') {
            $row = YoothemeRowLayout::filled($element, $options);

            if ($row !== null) {
                $element = $row;
                $filled[] = ['at' => $at, 'layout' => $row['props']['layout']];
            } else {
                $issue = YoothemeRowLayout::check($element, $options);

                if ($issue !== null) {
                    $warnings[] = self::rowLayoutWarning($issue, 'at', $at);
                }
            }
        }

        if (is_array($element['children'] ?? null)) {
            foreach ($element['children'] as $index => $child) {
                if (is_array($child)) {
                    $type = is_string($child['type'] ?? null) && $child['type'] !== '' ? $child['type'] : 'unknown';
                    $element['children'][$index] = $this->fillRowLayouts($child, $options, $filled, $warnings, $at . '>' . $type . '[' . $index . ']');
                }
            }
        }

        return $element;
    }

    /**
     * @param array<string, mixed> $issue from YoothemeRowLayout::check()
     * @return array<string, mixed>
     */
    protected static function rowLayoutWarning(array $issue, string $key, string $place): array
    {
        return [
            'code' => 'row_layout_mismatch',
            $key => $place,
            'message' => YoothemeRowLayout::message($issue),
            'fix' => YoothemeRowLayout::fix($issue),
            'suggested_layout' => $issue['suggested_layout'],
        ];
    }

    /**
     * After a write to a row's layout or to a column's width_*, the row whose layout no longer
     * describes its columns, as a warning (F-044). null when the write touched neither.
     *
     * @param array<string, mixed> $layout the layout after the write
     * @param array<string, mixed> $props the props written
     * @return array<string, mixed>|null
     */
    protected function rowLayoutWarningAfterUpdate(array $layout, string $path, string $type, array $props, bool $merge): ?array
    {
        $keys = array_map('strval', array_keys($props));

        if ($type === 'row' && (!$merge || in_array('layout', $keys, true))) {
            $rowPath = $path;
        } elseif ($type === 'column' && (!$merge || preg_grep('/^width_/', $keys) !== [])) {
            $separator = strrpos($path, '>');
            $rowPath = $separator === false ? '' : substr($path, 0, $separator);
        } else {
            return null;
        }

        $row = (new YoothemeElementNavigator())->findElement($layout, $rowPath);

        if (($row['element']['type'] ?? null) !== 'row') {
            return null;
        }

        $issue = YoothemeRowLayout::check($row['element'], $this->rowLayoutOptions());

        return $issue === null ? null : self::rowLayoutWarning($issue, 'path', $rowPath);
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
