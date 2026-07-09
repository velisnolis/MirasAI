<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Mirasai\Library\Tool\YooThemeElementNavigator;

class TemplateElementUpdatePropsTool extends AbstractTemplateElementWriteTool
{
    public function getName(): string
    {
        return 'template/element-update-props';
    }

    public function getDescription(): string
    {
        return 'Updates props on one YOOtheme Builder layout element. Supports templates, article layouts, and Builder modules. Requires if_match and defaults to merge=true, so supplied props are merged into existing props.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge($this->targetSelectorSchema(), [
                'path' => [
                    'type' => 'string',
                    'description' => 'Element path as returned by template/element-list.',
                ],
                'if_match' => [
                    'type' => 'string',
                    'description' => 'Required current layout etag. Stale values are rejected before any write.',
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
            ]),
            'required' => ['path', 'if_match', 'props'],
        ];
    }

    public function handle(array $arguments): array
    {
        $path = trim((string) ($arguments['path'] ?? ''));
        $props = $arguments['props'] ?? null;
        $merge = array_key_exists('merge', $arguments) ? (bool) $arguments['merge'] : true;
        $includeElement = !empty($arguments['include_element']);

        if ($path === '') {
            return ['error' => 'path is required.', 'code' => 'invalid_path'];
        }

        if (!is_array($props)) {
            return ['error' => 'props must be an object.', 'code' => 'invalid_props'];
        }

        return $this->mutateTemplateElement(
            $arguments,
            function (array $layout) use ($path, $props, $merge, $includeElement): array {
                $updated = (new YooThemeElementNavigator())->updateElementProps($layout, $path, $props, $merge);

                if ($updated === null) {
                    return ['error' => "Element path {$path} not found.", 'code' => 'element_not_found'];
                }

                $response = [
                    'path' => $path,
                    'merge' => $merge,
                    'updated_prop_keys' => array_values(array_map('strval', array_keys($props))),
                    'metadata' => $updated['metadata'],
                    'layout' => $updated['layout'],
                ];

                if ($includeElement) {
                    $element = $updated['element'];
                    unset($element['children']);
                    $response['element'] = $element;
                }

                return $response;
            },
        );
    }
}
