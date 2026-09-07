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
        'headline', 'html', 'image_alt', 'link_aria_label', 'home_text',
    ];

    /** @var list<string> */
    private const CONFIG_PROPS = [
        'title_position', 'title_style', 'title_element', 'title_decoration',
        'image_position', 'image_effect', 'meta_align', 'id', 'class',
        'title_rotation', 'title_breakpoint', 'heading_style', 'height',
        'width', 'style', 'animation', 'name', 'status', 'source', 'css',
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
    public function findTranslatableNodes(array $layout, string $path = 'root', ?string $disabledBy = null): array
    {
        $results = [];
        $nodeType = is_string($layout['type'] ?? null) ? $layout['type'] : 'unknown';
        $props = is_array($layout['props'] ?? null) ? $layout['props'] : [];
        $sourceProps = is_array($layout['source']['props'] ?? null) ? $layout['source']['props'] : [];
        $dynamicPropKeys = array_keys($sourceProps);
        if (($props['status'] ?? null) === 'disabled') { $disabledBy = $path; }

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

        if ($disabledBy !== null) {
            foreach ($results as &$entry) { $entry['disabled_by'] = $disabledBy; }
            unset($entry);
        }

        foreach ($layout['children'] ?? [] as $index => $child) {
            if (!is_array($child)) {
                continue;
            }

            $childType = is_string($child['type'] ?? null) ? $child['type'] : 'unknown';
            $results = array_merge(
                $results,
                $this->findTranslatableNodes($child, "{$path}>{$childType}[{$index}]", $disabledBy),
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
        $this->validateReplacements($layout, $replacements);
        $this->applyReplacements($layout, $replacements);

        return $layout;
    }

    private function isTranslatableString(string $field, string $value): bool
    {
        return trim($value) !== '' && in_array($field, self::TEXT_PROPS, true);
    }

    private function detectTextFormat(string $value): string
    {
        return preg_match('/<[a-z][\s>]/i', $value) ? 'html' : 'plain';
    }

    private function isPotentialSourceText(string $field, string $value): bool
    {
        return trim($value) !== '' && in_array($field, ['before', 'after', 'prefix', 'suffix'], true);
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
                if (preg_match('/\.source\.props\.[^.]+\.filters(?:\.|$)/', $path)
                    && $this->isPotentialSourceText($field, $value)) {
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

    /** Full layouts may change the same editorial leaves as replacement maps. */
    public function validateTranslatedLayout(array $source, array $translated): array
    {
        $candidate = array_column($this->findTranslatableNodes($translated), 'text', 'replacement_key');
        $changes = [];
        foreach ($this->findTranslatableNodes($source) as $entry) {
            $key = $entry['replacement_key'];
            if (array_key_exists($key, $candidate)) {
                $changes[$key] = $candidate[$key];
            }
        }
        if ($this->patchLayoutArray($source, $changes) != $translated) {
            throw new \InvalidArgumentException('Translated layout changes non-editorial structure or bindings.');
        }
        return $translated;
    }

    /** Coverage gaps are review items, never additional writable properties. */
    public function getTranslationCoverageWarnings(array $layout, string $path = 'root'): array
    {
        $warnings = [];
        $props = is_array($layout['props'] ?? null) ? $layout['props'] : [];
        foreach ($props as $key => $value) {
            if (is_string($value) && !in_array($key, self::TEXT_PROPS, true)
                && !in_array($key, self::CONFIG_PROPS, true)
                && (preg_match('/(?:text|title|label|caption|description|alt)$/', (string) $key)
                    || (mb_strlen($value) > 15 && str_contains($value, ' ') && !preg_match('/^(?:https?:|\/|#)/', $value)))
                && trim($value) !== '') {
                $warnings[] = ['code' => 'unknown_text_field', 'path' => $path . '.' . $key];
            }
        }
        if (($layout['type'] ?? '') === 'breadcrumbs' && empty($props['home_text'])) {
            $warnings[] = ['code' => 'implicit_home_text', 'path' => $path . '.home_text'];
        }
        foreach ($layout['children'] ?? [] as $index => $child) {
            if (is_array($child)) {
                $type = $child['type'] ?? 'unknown';
                $warnings = array_merge($warnings, $this->getTranslationCoverageWarnings($child, "{$path}>{$type}[{$index}]"));
            }
        }
        return $warnings;
    }

    /** Validate the entire map before changing any value. */
    private function validateReplacements(array $layout, array $replacements): void
    {
        $eligible = array_column($this->findTranslatableNodes($layout), 'text', 'replacement_key');
        foreach ($replacements as $key => $value) {
            if (!is_string($value) || !array_key_exists($key, $eligible)) {
                throw new \InvalidArgumentException('Non-editorial or unknown translation replacement: ' . $key);
            }
            if ($this->translationSignature($eligible[$key]) !== $this->translationSignature($value)) {
                throw new \InvalidArgumentException('Translation changes HTML, URLs or placeholders: ' . $key);
            }
        }
    }

    /** Immutable markup and placeholders; editorial text/attributes may change. */
    private function translationSignature(string $text): array
    {
        preg_match_all('/\{\{.*?\}\}|\{[\w.:-]+\}|%(?:\d+\$)?[sdf]|https?:\/\/[^\s<>"\x27]+/u', $text, $matches);
        $tokens = $matches[0];
        sort($tokens);
        preg_match_all('/<!--.*?-->|<(script|style)\b[^>]*>.*?<\/\1\s*>|<[^>]+>/is', $text, $matches);
        $markup = array_map(static function (string $tag): string {
            if (preg_match('/^<!--|^<(?:script|style)\b/i', $tag)) {
                return $tag;
            }
            // Preserve attribute names and delimiters, allow only visible attribute values.
            return preg_replace('/(\s(?:alt|title|aria-label|placeholder)\s*=\s*)(["\x27]).*?\2/is', '$1$2$2', $tag);
        }, $matches[0]);
        return [$markup, $tokens];
    }
}
