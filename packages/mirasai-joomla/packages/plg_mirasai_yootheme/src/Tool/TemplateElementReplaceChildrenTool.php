<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Mirasai\Library\Tool\YooThemeElementNavigator;

class TemplateElementReplaceChildrenTool extends AbstractTemplateElementWriteTool
{
    public function getName(): string
    {
        return 'template/element-replace-children';
    }

    public function getDescription(): string
    {
        return 'Replaces every child of one element in a single write: the sections of a page with path root (the default), or the rows of a section, the columns of a row, the elements of a column. Use it to rebuild a page or a section instead of deleting and adding one element at a time. Nothing of the old children is kept: the preview lists what is removed and what is added. Each new child is checked like template/element-add, and a row with several columns gets its layout the same way. Supports templates, article layouts, and YOOtheme Builder modules. Requires if_match and uses dry_run/confirm_guarded_write before writing.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['if_match', 'children'],
            'properties' => array_merge($this->targetSelectorSchema(), [
                'path' => ['type' => 'string', 'description' => 'Element whose children are replaced, as returned by template/element-list. Defaults to root, which replaces the whole content of the layout.'],
                'children' => [
                    'type' => 'array',
                    'description' => 'The new children, in order. Each is a YOOtheme element object with type and optional props and children, as in template/element-add. An empty list leaves the element with no children.',
                    'items' => ['type' => 'object'],
                ],
                'if_match' => ['type' => 'string', 'description' => 'Required current layout etag. Stale values are rejected before any write.'],
                'dry_run' => ['type' => 'boolean', 'description' => 'If true, validate and preview without writing.'],
                'confirm_guarded_write' => ['type' => 'boolean', 'description' => 'Required for the real write after review. Not required when dry_run=true.'],
            ]),
        ];
    }

    public function handle(array $arguments): array
    {
        return $this->mutateTemplateElement($arguments, function (array $layout, array $arguments): array {
            $path = trim((string) ($arguments['path'] ?? ''));
            $path = $path === '' ? 'root' : $path;
            $children = $arguments['children'] ?? null;

            if (!is_array($children) || !array_is_list($children)) {
                return ['error' => 'children must be a list of element objects.', 'code' => 'invalid_children'];
            }

            $rowLayoutOptions = $this->rowLayoutOptions();
            $rowLayoutsFilled = [];
            $warnings = [];

            foreach ($children as $index => $child) {
                if (!is_array($child)) {
                    return ['error' => "children[{$index}] must be an object with a non-empty string type.", 'code' => 'invalid_element'];
                }

                $rejection = $this->rejectInvalidTree($child, "children[{$index}]");

                if ($rejection !== null) {
                    return $rejection;
                }

                $children[$index] = $this->fillRowLayouts($child, $rowLayoutOptions, $rowLayoutsFilled, $warnings, "children[{$index}]");
            }

            $navigator = new YooThemeElementNavigator();
            $result = $navigator->replaceChildren($layout, $path, $children);

            if (isset($result['error'])) {
                return $result;
            }

            // New columns under a row that keeps its layout: the Builder places them by that layout.
            $parent = $navigator->findElement($result['layout'], $path);
            $issue = ($parent['element']['type'] ?? null) === 'row' ? YoothemeRowLayout::check($parent['element'], $rowLayoutOptions) : null;

            if ($issue !== null) {
                $warnings[] = self::rowLayoutWarning($issue, 'path', $path);
            }

            $response = [
                'path' => $path,
                'removed_count' => count($result['removed']),
                'removed' => $result['removed'],
                'added_count' => count($result['added']),
                'added' => $result['added'],
                'metadata' => $result['metadata'],
                'layout' => $result['layout'],
            ];

            // Places inside the new children become layout paths.
            $added = $result['added'];
            $inLayout = static fn (string $at): string => (string) preg_replace_callback(
                '/^children\[(\d+)\]/',
                static fn (array $match): string => (string) ($added[(int) $match[1]]['path'] ?? $match[0]),
                $at
            );

            if ($rowLayoutsFilled !== []) {
                $response['row_layout_filled'] = array_map(static fn (array $row): array => ['path' => $inLayout($row['at']), 'layout' => $row['layout']], $rowLayoutsFilled);
            }

            if ($warnings !== []) {
                $response['warnings'] = array_map(static function (array $warning) use ($inLayout): array {
                    if (isset($warning['at'])) {
                        $warning = ['path' => $inLayout((string) $warning['at'])] + $warning;
                        unset($warning['at']);
                    }

                    return $warning;
                }, $warnings);
            }

            return $response;
        });
    }
}
