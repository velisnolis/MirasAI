<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

final class YoothemeElementNavigator
{
    /**
     * @param array<string, mixed> $layout
     * @return list<array<string, mixed>>
     */
    public function listElements(array $layout): array
    {
        $elements = [];
        $this->walk($layout, 'root', null, 0, 0, $elements);

        return $elements;
    }

    /**
     * @param array<string, mixed> $layout
     */
    public function countElements(array $layout): int
    {
        return count($this->listElements($layout));
    }

    /**
     * @param list<array<string, mixed>> $layouts
     * @return list<array<string, mixed>>
     */
    public function summarizeTypes(array $layouts): array
    {
        $types = [];

        foreach ($layouts as $layout) {
            foreach ($this->listElements($layout) as $element) {
                $type = is_string($element['type'] ?? null) ? $element['type'] : 'unknown';

                if (!isset($types[$type])) {
                    $types[$type] = [
                        'type' => $type,
                        'count' => 0,
                        'max_depth' => 0,
                        'prop_keys' => [],
                        'has_source_binding_count' => 0,
                        'sample_paths' => [],
                    ];
                }

                $types[$type]['count']++;
                $types[$type]['max_depth'] = max((int) $types[$type]['max_depth'], (int) ($element['depth'] ?? 0));

                foreach (($element['prop_keys'] ?? []) as $propKey) {
                    if (is_string($propKey)) {
                        $types[$type]['prop_keys'][$propKey] = true;
                    }
                }

                if (!empty($element['has_source_binding'])) {
                    $types[$type]['has_source_binding_count']++;
                }

                if (count($types[$type]['sample_paths']) < 5 && is_string($element['path'] ?? null)) {
                    $types[$type]['sample_paths'][] = $element['path'];
                }
            }
        }

        ksort($types);

        return array_map(
            static function (array $type): array {
                $type['prop_keys'] = array_keys($type['prop_keys']);
                sort($type['prop_keys']);

                return $type;
            },
            array_values($types),
        );
    }

    /**
     * @param array<string, mixed> $layout
     * @return array{metadata: array<string, mixed>, element: array<string, mixed>}|null
     */
    public function findElement(array $layout, string $path): ?array
    {
        $path = trim($path);

        if ($path === '') {
            return null;
        }

        foreach ($this->listElements($layout) as $metadata) {
            if (($metadata['path'] ?? null) !== $path) {
                continue;
            }

            $element = $this->resolveElement($layout, $path);

            if ($element === null) {
                return null;
            }

            return [
                'metadata' => $metadata,
                'element' => $element,
            ];
        }

        return null;
    }

    /**
     * Update props for one element and return the updated layout plus element metadata.
     *
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $props
     * @return array{layout: array<string, mixed>, metadata: array<string, mixed>, element: array<string, mixed>}|null
     */
    public function updateElementProps(array $layout, string $path, array $props, bool $merge = true): ?array
    {
        $path = trim($path);

        if ($path === '') {
            return null;
        }

        $updated = $layout;
        $ok = $this->updateElementPropsInPlace($updated, $path, $props, $merge);

        if (!$ok) {
            return null;
        }

        $found = $this->findElement($updated, $path);

        if ($found === null) {
            return null;
        }

        return [
            'layout' => $updated,
            'metadata' => $found['metadata'],
            'element' => $found['element'],
        ];
    }

    /**
     * Set the canonical Dynamic Source binding for one element at source.
     *
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $source
     * @return array{layout: array<string, mixed>, metadata: array<string, mixed>, element: array<string, mixed>}|array{error: string, code: string}
     */
    public function setElementSource(array $layout, string $path, array $source): array
    {
        $path = trim($path);

        if ($path === '') {
            return ['error' => 'path is required.', 'code' => 'invalid_path'];
        }

        $updated = $layout;
        $ok = $this->mutateElementInPlace(
            $updated,
            $path,
            static function (array &$node) use ($source): void {
                if (is_array($node['props'] ?? null)) {
                    unset($node['props']['source']);
                }

                unset($node['source_extended']);
                $node['source'] = $source;
            },
        );

        if (!$ok) {
            return ['error' => "Element path {$path} not found.", 'code' => 'element_not_found'];
        }

        $found = $this->findElement($updated, $path);

        if ($found === null) {
            return ['error' => 'Source was set but the element could not be resolved.', 'code' => 'element_resolution_failed'];
        }

        return [
            'layout' => $updated,
            'metadata' => $found['metadata'],
            'element' => $found['element'],
        ];
    }

    /**
     * Delete Dynamic Source binding carriers from one element.
     *
     * @param array<string, mixed> $layout
     * @param list<string> $locations
     * @return array{layout: array<string, mixed>, metadata: array<string, mixed>, element: array<string, mixed>, removed_locations: list<string>}|array{error: string, code: string}
     */
    public function deleteElementSource(array $layout, string $path, array $locations = ['source', 'props.source', 'source_extended']): array
    {
        $path = trim($path);

        if ($path === '') {
            return ['error' => 'path is required.', 'code' => 'invalid_path'];
        }

        $allowed = ['source', 'props.source', 'source_extended'];
        $locations = array_values(array_intersect($locations, $allowed));

        if ($locations === []) {
            return ['error' => 'locations must include at least one known binding location.', 'code' => 'invalid_locations'];
        }

        $removed = [];
        $updated = $layout;
        $ok = $this->mutateElementInPlace(
            $updated,
            $path,
            static function (array &$node) use ($locations, &$removed): void {
                if (in_array('source', $locations, true) && array_key_exists('source', $node)) {
                    unset($node['source']);
                    $removed[] = 'source';
                }

                if (in_array('props.source', $locations, true)
                    && is_array($node['props'] ?? null)
                    && array_key_exists('source', $node['props'])
                ) {
                    unset($node['props']['source']);
                    $removed[] = 'props.source';
                }

                if (in_array('source_extended', $locations, true) && array_key_exists('source_extended', $node)) {
                    unset($node['source_extended']);
                    $removed[] = 'source_extended';
                }
            },
        );

        if (!$ok) {
            return ['error' => "Element path {$path} not found.", 'code' => 'element_not_found'];
        }

        $found = $this->findElement($updated, $path);

        if ($found === null) {
            return ['error' => 'Source was deleted but the element could not be resolved.', 'code' => 'element_resolution_failed'];
        }

        return [
            'layout' => $updated,
            'metadata' => $found['metadata'],
            'element' => $found['element'],
            'removed_locations' => array_values(array_unique($removed)),
        ];
    }

    /**
     * Add a new child element under a parent element.
     *
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $element
     * @return array{layout: array<string, mixed>, metadata: array<string, mixed>, element: array<string, mixed>}|array{error: string, code: string}
     */
    public function addElement(array $layout, string $parentPath, array $element, string $position = 'append'): array
    {
        $parentPath = trim($parentPath);
        $position = $position === 'prepend' ? 'prepend' : 'append';
        $element = $this->normalizeNewElement($element);

        if ($parentPath === '') {
            return ['error' => 'parent_path is required.', 'code' => 'invalid_parent_path'];
        }

        if (!$this->validElement($element)) {
            return ['error' => 'element must be an object with a non-empty string type.', 'code' => 'invalid_element'];
        }

        $updated = $layout;
        $newPath = $this->insertChildInPlace($updated, $parentPath, $element, $position);

        if ($newPath === null) {
            return ['error' => "Parent path {$parentPath} not found.", 'code' => 'parent_not_found'];
        }

        $found = $this->findElement($updated, $newPath);

        if ($found === null) {
            return ['error' => 'Element was added but could not be resolved.', 'code' => 'element_resolution_failed'];
        }

        return [
            'layout' => $updated,
            'metadata' => $found['metadata'],
            'element' => $found['element'],
        ];
    }

    /**
     * Move an element under a new parent.
     *
     * @param array<string, mixed> $layout
     * @return array{layout: array<string, mixed>, old_path: string, new_path: string, metadata: array<string, mixed>, element: array<string, mixed>}|array{error: string, code: string}
     */
    public function moveElement(array $layout, string $path, string $targetParentPath, string $position = 'append'): array
    {
        $path = trim($path);
        $targetParentPath = trim($targetParentPath);
        $position = $position === 'prepend' ? 'prepend' : 'append';

        if ($path === '' || $path === 'root') {
            return ['error' => 'path must reference a non-root element.', 'code' => 'invalid_path'];
        }

        if ($targetParentPath === '') {
            return ['error' => 'target_parent_path is required.', 'code' => 'invalid_target_parent_path'];
        }

        if ($this->pathIsDescendantOrSame($targetParentPath, $path)) {
            return ['error' => 'Cannot move an element into itself or one of its descendants.', 'code' => 'invalid_target_parent_path'];
        }

        $source = $this->findElement($layout, $path);

        if ($source === null) {
            return ['error' => "Element path {$path} not found.", 'code' => 'element_not_found'];
        }

        $updated = $layout;

        if (!$this->removeElementInPlace($updated, $path)) {
            return ['error' => "Element path {$path} could not be removed.", 'code' => 'element_not_found'];
        }

        $newPath = $this->insertChildInPlace($updated, $targetParentPath, $source['element'], $position);

        if ($newPath === null) {
            return ['error' => "Target parent path {$targetParentPath} not found.", 'code' => 'target_parent_not_found'];
        }

        $found = $this->findElement($updated, $newPath);

        if ($found === null) {
            return ['error' => 'Element was moved but could not be resolved.', 'code' => 'element_resolution_failed'];
        }

        return [
            'layout' => $updated,
            'old_path' => $path,
            'new_path' => $newPath,
            'metadata' => $found['metadata'],
            'element' => $found['element'],
        ];
    }

    /**
     * Clone an element as the next sibling.
     *
     * @param array<string, mixed> $layout
     * @return array{layout: array<string, mixed>, source_path: string, new_path: string, metadata: array<string, mixed>, element: array<string, mixed>}|array{error: string, code: string}
     */
    public function cloneElement(array $layout, string $path): array
    {
        $path = trim($path);

        if ($path === '' || $path === 'root') {
            return ['error' => 'path must reference a non-root element.', 'code' => 'invalid_path'];
        }

        $source = $this->findElement($layout, $path);

        if ($source === null) {
            return ['error' => "Element path {$path} not found.", 'code' => 'element_not_found'];
        }

        $updated = $layout;
        $newPath = $this->insertSiblingAfterInPlace($updated, $path, $source['element']);

        if ($newPath === null) {
            return ['error' => "Element path {$path} could not be cloned.", 'code' => 'element_not_found'];
        }

        $found = $this->findElement($updated, $newPath);

        if ($found === null) {
            return ['error' => 'Element was cloned but could not be resolved.', 'code' => 'element_resolution_failed'];
        }

        return [
            'layout' => $updated,
            'source_path' => $path,
            'new_path' => $newPath,
            'metadata' => $found['metadata'],
            'element' => $found['element'],
        ];
    }

    /**
     * Delete an element.
     *
     * @param array<string, mixed> $layout
     * @return array{layout: array<string, mixed>, deleted_path: string, deleted_type: string}|array{error: string, code: string}
     */
    public function deleteElement(array $layout, string $path): array
    {
        $path = trim($path);

        if ($path === '' || $path === 'root') {
            return ['error' => 'path must reference a non-root element.', 'code' => 'invalid_path'];
        }

        $source = $this->findElement($layout, $path);

        if ($source === null) {
            return ['error' => "Element path {$path} not found.", 'code' => 'element_not_found'];
        }

        $updated = $layout;

        if (!$this->removeElementInPlace($updated, $path)) {
            return ['error' => "Element path {$path} could not be removed.", 'code' => 'element_not_found'];
        }

        return [
            'layout' => $updated,
            'deleted_path' => $path,
            'deleted_type' => (string) ($source['metadata']['type'] ?? 'unknown'),
        ];
    }

    /**
     * @param array<string, mixed> $node
     * @param list<array<string, mixed>> $elements
     */
    private function walk(array $node, string $path, ?string $parentPath, int $depth, int $index, array &$elements): void
    {
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        $props = is_array($node['props'] ?? null) ? $node['props'] : [];

        $elements[] = [
            'path' => $path,
            'type' => $this->nodeType($node),
            'depth' => $depth,
            'index' => $index,
            'parent_path' => $parentPath,
            'child_count' => count(array_filter($children, 'is_array')),
            'prop_keys' => array_values(array_map('strval', array_keys($props))),
            'label' => $this->nodeLabel($node),
            'has_source_binding' => $this->hasSourceBinding($node),
        ];

        foreach ($children as $childIndex => $child) {
            if (!is_array($child)) {
                continue;
            }

            $childType = $this->nodeType($child);
            $this->walk($child, "{$path}>{$childType}[{$childIndex}]", $path, $depth + 1, (int) $childIndex, $elements);
        }
    }

    /**
     * @param array<string, mixed> $layout
     * @return array<string, mixed>|null
     */
    private function resolveElement(array $layout, string $path): ?array
    {
        if ($path === 'root') {
            return $layout;
        }

        $current = $layout;
        $segments = explode('>', $path);
        array_shift($segments);

        foreach ($segments as $segment) {
            if (!preg_match('/^(.+)\\[(\\d+)\\]$/', $segment, $matches)) {
                return null;
            }

            $expectedType = $matches[1];
            $index = (int) $matches[2];
            $children = $current['children'] ?? null;

            if (!is_array($children) || !is_array($children[$index] ?? null)) {
                return null;
            }

            $current = $children[$index];

            if ($this->nodeType($current) !== $expectedType) {
                return null;
            }
        }

        return $current;
    }

    /**
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $props
     */
    private function updateElementPropsInPlace(array &$layout, string $path, array $props, bool $merge): bool
    {
        if ($path === 'root') {
            $currentProps = is_array($layout['props'] ?? null) ? $layout['props'] : [];
            $layout['props'] = $merge ? $this->mergeProps($currentProps, $props) : $props;

            return true;
        }

        $segments = explode('>', $path);
        array_shift($segments);

        return $this->updateBySegments($layout, $segments, $props, $merge);
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string> $segments
     * @param array<string, mixed> $props
     */
    private function updateBySegments(array &$node, array $segments, array $props, bool $merge): bool
    {
        if ($segments === []) {
            $currentProps = is_array($node['props'] ?? null) ? $node['props'] : [];
            $node['props'] = $merge ? $this->mergeProps($currentProps, $props) : $props;

            return true;
        }

        $segment = array_shift($segments);

        if (!is_string($segment)) {
            return false;
        }

        $parsed = $this->parseSegment($segment);

        if ($parsed === null) {
            return false;
        }

        [$expectedType, $index] = $parsed;

        if (!isset($node['children']) || !is_array($node['children']) || !is_array($node['children'][$index] ?? null)) {
            return false;
        }

        if ($this->nodeType($node['children'][$index]) !== $expectedType) {
            return false;
        }

        return $this->updateBySegments($node['children'][$index], $segments, $props, $merge);
    }

    /**
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $element
     */
    private function insertChildInPlace(array &$layout, string $parentPath, array $element, string $position): ?string
    {
        if ($parentPath === 'root') {
            if (!isset($layout['children']) || !is_array($layout['children'])) {
                $layout['children'] = [];
            }

            $index = $position === 'prepend' ? 0 : count($layout['children']);
            array_splice($layout['children'], $index, 0, [$element]);

            return 'root>' . $this->nodeType($element) . '[' . $index . ']';
        }

        $segments = explode('>', $parentPath);
        array_shift($segments);

        return $this->insertChildBySegments($layout, $segments, $parentPath, $element, $position);
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string> $segments
     * @param array<string, mixed> $element
     */
    private function insertChildBySegments(array &$node, array $segments, string $parentPath, array $element, string $position): ?string
    {
        if ($segments === []) {
            if (!isset($node['children']) || !is_array($node['children'])) {
                $node['children'] = [];
            }

            $index = $position === 'prepend' ? 0 : count($node['children']);
            array_splice($node['children'], $index, 0, [$element]);

            return $parentPath . '>' . $this->nodeType($element) . '[' . $index . ']';
        }

        $segment = array_shift($segments);

        if (!is_string($segment)) {
            return null;
        }

        $parsed = $this->parseSegment($segment);

        if ($parsed === null) {
            return null;
        }

        [$expectedType, $index] = $parsed;

        if (!isset($node['children']) || !is_array($node['children']) || !is_array($node['children'][$index] ?? null)) {
            return null;
        }

        if ($this->nodeType($node['children'][$index]) !== $expectedType) {
            return null;
        }

        return $this->insertChildBySegments($node['children'][$index], $segments, $parentPath, $element, $position);
    }

    /**
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $element
     */
    private function insertSiblingAfterInPlace(array &$layout, string $path, array $element): ?string
    {
        $segments = explode('>', $path);
        array_shift($segments);

        $newPath = $this->insertSiblingAfterBySegments($layout, $segments, $element);

        return $newPath === null ? null : 'root>' . $newPath;
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string> $segments
     * @param array<string, mixed> $element
     */
    private function insertSiblingAfterBySegments(array &$node, array $segments, array $element): ?string
    {
        $segment = array_shift($segments);

        if (!is_string($segment)) {
            return null;
        }

        $parsed = $this->parseSegment($segment);

        if ($parsed === null) {
            return null;
        }

        [$expectedType, $index] = $parsed;

        if (!isset($node['children']) || !is_array($node['children']) || !is_array($node['children'][$index] ?? null)) {
            return null;
        }

        if ($this->nodeType($node['children'][$index]) !== $expectedType) {
            return null;
        }

        if ($segments === []) {
            $insertIndex = $index + 1;
            array_splice($node['children'], $insertIndex, 0, [$element]);

            return $this->pathForCurrentInsertion($insertIndex, $element);
        }

        $childPath = $this->insertSiblingAfterBySegments($node['children'][$index], $segments, $element);

        return $childPath === null ? null : $segment . '>' . $childPath;
    }

    /**
     * @param array<string, mixed> $layout
     */
    private function removeElementInPlace(array &$layout, string $path): bool
    {
        $segments = explode('>', $path);
        array_shift($segments);

        return $this->removeBySegments($layout, $segments);
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string> $segments
     */
    private function removeBySegments(array &$node, array $segments): bool
    {
        $segment = array_shift($segments);

        if (!is_string($segment)) {
            return false;
        }

        $parsed = $this->parseSegment($segment);

        if ($parsed === null) {
            return false;
        }

        [$expectedType, $index] = $parsed;

        if (!isset($node['children']) || !is_array($node['children']) || !is_array($node['children'][$index] ?? null)) {
            return false;
        }

        if ($this->nodeType($node['children'][$index]) !== $expectedType) {
            return false;
        }

        if ($segments === []) {
            array_splice($node['children'], $index, 1);

            return true;
        }

        return $this->removeBySegments($node['children'][$index], $segments);
    }

    /**
     * @param array<string, mixed> $layout
     * @param callable(array<string, mixed>): void $mutator
     */
    private function mutateElementInPlace(array &$layout, string $path, callable $mutator): bool
    {
        if ($path === 'root') {
            $mutator($layout);

            return true;
        }

        $segments = explode('>', $path);
        array_shift($segments);

        return $this->mutateBySegments($layout, $segments, $mutator);
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string> $segments
     * @param callable(array<string, mixed>): void $mutator
     */
    private function mutateBySegments(array &$node, array $segments, callable $mutator): bool
    {
        if ($segments === []) {
            $mutator($node);

            return true;
        }

        $segment = array_shift($segments);

        if (!is_string($segment)) {
            return false;
        }

        $parsed = $this->parseSegment($segment);

        if ($parsed === null) {
            return false;
        }

        [$expectedType, $index] = $parsed;

        if (!isset($node['children']) || !is_array($node['children']) || !is_array($node['children'][$index] ?? null)) {
            return false;
        }

        if ($this->nodeType($node['children'][$index]) !== $expectedType) {
            return false;
        }

        return $this->mutateBySegments($node['children'][$index], $segments, $mutator);
    }

    /**
     * @return array{string, int}|null
     */
    private function parseSegment(string $segment): ?array
    {
        if (!preg_match('/^(.+)\\[(\\d+)\\]$/', $segment, $matches)) {
            return null;
        }

        return [$matches[1], (int) $matches[2]];
    }

    /**
     * @param array<string, mixed> $element
     */
    private function pathForCurrentInsertion(int $index, array $element): string
    {
        return $this->nodeType($element) . '[' . $index . ']';
    }

    /**
     * @param array<string, mixed> $element
     * @return array<string, mixed>
     */
    private function normalizeNewElement(array $element): array
    {
        if (!isset($element['props']) || !is_array($element['props'])) {
            $element['props'] = [];
        }

        if (isset($element['children']) && !is_array($element['children'])) {
            $element['children'] = [];
        }

        return $element;
    }

    /**
     * @param array<string, mixed> $element
     */
    private function validElement(array $element): bool
    {
        return is_string($element['type'] ?? null) && trim((string) $element['type']) !== '';
    }

    private function pathIsDescendantOrSame(string $path, string $ancestor): bool
    {
        return $path === $ancestor || str_starts_with($path, $ancestor . '>');
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    private function mergeProps(array $current, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if (is_array($value)
                && is_array($current[$key] ?? null)
                && $this->isAssociative($value)
                && $this->isAssociative($current[$key])
            ) {
                $current[$key] = $this->mergeProps($current[$key], $value);
                continue;
            }

            $current[$key] = $value;
        }

        return $current;
    }

    /**
     * @param array<mixed> $value
     */
    private function isAssociative(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeType(array $node): string
    {
        return is_string($node['type'] ?? null) && trim((string) $node['type']) !== ''
            ? trim((string) $node['type'])
            : 'unknown';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasSourceBinding(array $node): bool
    {
        if (is_array($node['source'] ?? null) || is_array($node['source_extended'] ?? null)) {
            return true;
        }

        if (is_string($node['source'] ?? null) && trim((string) $node['source']) !== '') {
            return true;
        }

        $props = $node['props'] ?? null;

        if (!is_array($props)) {
            return false;
        }

        if (is_array($props['source'] ?? null)) {
            return true;
        }

        return is_string($props['source'] ?? null) && trim((string) $props['source']) !== '';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeLabel(array $node): string
    {
        $props = is_array($node['props'] ?? null) ? $node['props'] : [];

        foreach (['title', 'name', 'content', 'text', 'label', 'meta'] as $key) {
            $value = $props[$key] ?? null;

            if (!is_string($value)) {
                continue;
            }

            $label = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($value)) ?? '');

            if ($label !== '') {
                return mb_substr($label, 0, 80);
            }
        }

        return '';
    }
}
