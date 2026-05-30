<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class YoothemeLayoutProcessor
{
    /** @var list<string> */
    private const TEXT_PROPS = [
        'content', 'title', 'meta', 'subtitle', 'text', 'video_title',
        'link_text', 'label', 'description', 'caption', 'alt',
        'button_text', 'heading', 'footer', 'header', 'placeholder',
    ];

    /** @var list<string> */
    private const CONFIG_PROPS = [
        'title_position', 'title_style', 'title_element', 'title_decoration',
        'image_position', 'image_effect', 'meta_align', 'id', 'class',
        'title_rotation', 'title_breakpoint', 'heading_style', 'height',
        'width', 'style', 'animation', 'name', 'status', 'source',
    ];

    /** @var list<string> */
    private const SOURCE_TEXT_KEYS = [
        'before', 'after', 'prefix', 'suffix', 'content', 'title', 'text',
        'label', 'placeholder', 'description', 'caption', 'alt',
    ];

    /**
     * @param array<string, mixed> $layout
     * @return list<array{path: string, node_type: string, field: string, replacement_key: string, text: string, format: string}>
     */
    public function findTranslatableNodes(array $layout, string $path = 'root'): array
    {
        $results = [];
        $nodeType = is_string($layout['type'] ?? null) ? $layout['type'] : 'unknown';
        $props = is_array($layout['props'] ?? null) ? $layout['props'] : [];
        $sourceProps = is_array($layout['source']['props'] ?? null) ? $layout['source']['props'] : [];
        $dynamicPropKeys = array_keys($sourceProps);

        foreach ($props as $key => $value) {
            if (!is_string($value) || !$this->isTranslatableString((string) $key, $value)) {
                continue;
            }

            if (in_array((string) $key, $dynamicPropKeys, true)) {
                continue;
            }

            $results[] = [
                'path' => $path,
                'node_type' => $nodeType,
                'field' => (string) $key,
                'replacement_key' => $path . '.' . (string) $key,
                'text' => $value,
                'format' => $this->detectTextFormat($value),
            ];
        }

        if (is_array($layout['source'] ?? null)) {
            $results = array_merge(
                $results,
                $this->findSourceTextNodes($layout['source'], $path . '.source', $nodeType),
            );
        }

        foreach ($layout['children'] ?? [] as $index => $child) {
            if (!is_array($child)) {
                continue;
            }

            $childType = is_string($child['type'] ?? null) ? $child['type'] : 'unknown';
            $results = array_merge(
                $results,
                $this->findTranslatableNodes($child, "{$path}>{$childType}[{$index}]"),
            );
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $layout
     * @param array<string, string> $replacements
     * @return array<string, mixed>
     */
    public function patchLayoutArray(array $layout, array $replacements): array
    {
        $this->applyReplacements($layout, $replacements);

        return $layout;
    }

    private function isTranslatableString(string $field, string $value): bool
    {
        $trimmed = trim($value);

        if ($trimmed === '' || mb_strlen($trimmed) < 2) {
            return false;
        }

        if (in_array($field, self::CONFIG_PROPS, true)) {
            return false;
        }

        if (preg_match('/^(http|\/|#|images\/|uk-|el-)/', $trimmed)) {
            return false;
        }

        if (preg_match('/^\{.+\}$/', $trimmed) || str_contains($trimmed, '{{')) {
            return false;
        }

        if (in_array($field, self::TEXT_PROPS, true)) {
            return true;
        }

        return mb_strlen($trimmed) > 15
            && str_contains($trimmed, ' ')
            && !preg_match('/(px|vh|vw|%)/', $trimmed);
    }

    private function detectTextFormat(string $value): string
    {
        return preg_match('/<[a-z][\s>]/i', $value) ? 'html' : 'plain';
    }

    private function isPotentialSourceText(string $field, string $value): bool
    {
        $trimmed = trim($value);

        if ($trimmed === '' || mb_strlen($trimmed) < 2) {
            return false;
        }

        if (preg_match('/^(http|\/|#|images\/|uk-|el-)/', $trimmed)) {
            return false;
        }

        if (preg_match('/^\{.+\}$/', $trimmed) || str_contains($trimmed, '{{')) {
            return false;
        }

        if (in_array($field, self::SOURCE_TEXT_KEYS, true)) {
            return true;
        }

        return $this->isTranslatableString($field, $value);
    }

    /**
     * @param array<string, mixed> $source
     * @return list<array{path: string, node_type: string, field: string, replacement_key: string, text: string, format: string}>
     */
    private function findSourceTextNodes(array $source, string $path, string $nodeType): array
    {
        $results = [];

        foreach ($source as $key => $value) {
            $field = (string) $key;

            if (is_string($value)) {
                if ($this->isPotentialSourceText($field, $value)) {
                    $results[] = [
                        'path' => $path,
                        'node_type' => $nodeType,
                        'field' => $field,
                        'replacement_key' => $path . '.' . $field,
                        'text' => $value,
                        'format' => $this->detectTextFormat($value),
                    ];
                }

                continue;
            }

            if (is_array($value)) {
                $results = array_merge(
                    $results,
                    $this->findSourceTextNodes($value, $path . '.' . $field, $nodeType),
                );
            }
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, string> $replacements
     */
    private function applyReplacements(array &$node, array $replacements, string $path = 'root'): void
    {
        if (isset($node['props']) && is_array($node['props'])) {
            foreach ($node['props'] as $key => &$value) {
                $fullPath = "{$path}.{$key}";

                if (is_string($value) && array_key_exists($fullPath, $replacements)) {
                    $value = $replacements[$fullPath];
                }
            }
            unset($value);
        }

        if (isset($node['source']) && is_array($node['source'])) {
            $this->applyNestedReplacements($node['source'], $replacements, $path . '.source');
        }

        if (isset($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $index => &$child) {
                if (!is_array($child)) {
                    continue;
                }

                $childType = is_string($child['type'] ?? null) ? $child['type'] : 'unknown';
                $this->applyReplacements($child, $replacements, "{$path}>{$childType}[{$index}]");
            }
            unset($child);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, string> $replacements
     */
    private function applyNestedReplacements(array &$node, array $replacements, string $path): void
    {
        foreach ($node as $key => &$value) {
            $fullPath = $path . '.' . $key;

            if (is_string($value) && array_key_exists($fullPath, $replacements)) {
                $value = $replacements[$fullPath];
                continue;
            }

            if (is_array($value)) {
                $this->applyNestedReplacements($value, $replacements, $fullPath);
            }
        }
        unset($value);
    }
}
