<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

final class YooThemeLayoutSummarizer
{
    /** @var list<string> */
    private const LANDMARK_TYPES = [
        'section',
        'row',
        'column',
        'grid',
        'headline',
        'heading',
        'text',
        'panel',
        'card',
        'module',
    ];

    /**
     * Build a compact structural summary of a decoded YOOtheme layout.
     *
     * @param array<string, mixed> $layout
     * @return array<string, mixed>
     */
    public function summarize(array $layout): array
    {
        $summary = [
            'root_type' => $this->nodeType($layout),
            'total_elements' => 0,
            'max_depth' => 0,
            'element_counts_by_type' => [],
            'source_binding_count' => 0,
            'named_landmarks' => [],
        ];

        $this->walk($layout, 'root', 0, $summary);

        ksort($summary['element_counts_by_type']);

        return $summary;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $summary
     */
    private function walk(array $node, string $path, int $depth, array &$summary): void
    {
        $type = $this->nodeType($node);

        $summary['total_elements']++;
        $summary['max_depth'] = max((int) $summary['max_depth'], $depth);
        $summary['element_counts_by_type'][$type] = ((int) ($summary['element_counts_by_type'][$type] ?? 0)) + 1;

        if ($this->hasSourceBinding($node)) {
            $summary['source_binding_count']++;
        }

        $label = $this->nodeLabel($node);

        if ($label !== '' && in_array($type, self::LANDMARK_TYPES, true)) {
            $summary['named_landmarks'][] = [
                'path' => $path,
                'type' => $type,
                'depth' => $depth,
                'label' => $label,
            ];
        }

        $children = $node['children'] ?? [];

        if (!is_array($children)) {
            return;
        }

        foreach ($children as $index => $child) {
            if (!is_array($child)) {
                continue;
            }

            $childType = $this->nodeType($child);
            $this->walk($child, "{$path}>{$childType}[{$index}]", $depth + 1, $summary);
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeType(array $node): string
    {
        return is_string($node['type'] ?? null) && trim($node['type']) !== ''
            ? trim((string) $node['type'])
            : 'unknown';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasSourceBinding(array $node): bool
    {
        if (is_array($node['source'] ?? null)) {
            return true;
        }

        $props = $node['props'] ?? null;

        return is_array($props) && is_array($props['source'] ?? null);
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

            $label = trim(preg_replace('/\s+/', ' ', strip_tags($value)) ?? '');

            if ($label !== '') {
                return mb_substr($label, 0, 80);
            }
        }

        return '';
    }
}
