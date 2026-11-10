<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

/**
 * Read-only checks on a stored YOOtheme layout, each finding with its path and a fix.
 *
 * It reads the saved JSON, not the rendered page: a value that comes from a
 * Dynamic Source is unknown here and is not judged. template/render-check shows
 * what actually renders.
 *
 * Mirrored in plg_mirasai_yootheme. Both copies must stay free of WordPress and
 * Joomla calls so docker/test-template-audit.php can run them as plain PHP.
 */
final class YoothemeLayoutAuditor
{
    public const LONG_HEADING = 70;

    private const SEVERITY_ORDER = ['error' => 0, 'warning' => 1, 'notice' => 2];

    /** @var callable(string): array<string, mixed> */
    private $definition;

    /** @var callable(string): ?bool true exists, false missing, null not checkable */
    private $fileExists;

    /** @var array<string, array<string, mixed>> */
    private array $definitions = [];

    /**
     * @param callable(string): array<string, mixed> $definition element type → definition array, [] when unknown
     * @param callable(string): ?bool $fileExists stored image value → exists?
     */
    public function __construct(callable $definition, callable $fileExists)
    {
        $this->definition = $definition;
        $this->fileExists = $fileExists;
    }

    /**
     * @param array<string, mixed> $layout
     * @return array{findings: list<array<string, mixed>>, counts: array<string, int>, headings: list<array<string, mixed>>, disabled_elements: int}
     */
    public function audit(array $layout): array
    {
        $findings = [];
        $headings = [];
        $disabled = 0;
        $this->walk($layout, 'root', null, false, false, $findings, $headings, $disabled);

        $h1 = array_values(array_filter($headings, static fn (array $h): bool => $h['level'] === 1));

        if ($headings !== [] && $h1 === []) {
            $findings[] = self::finding('warning', 'no_h1', 'root', 'layout', 'No element renders an h1.', 'Make the main headline of the page an h1. Do not promote a section label like «Latest news».');
        }

        if (count($h1) > 1) {
            foreach (array_slice($h1, 1) as $heading) {
                $findings[] = self::finding('warning', 'multiple_h1', $heading['path'], $heading['type'], 'A second h1; the first is at ' . $h1[0]['path'] . '.', 'Keep one h1 per page and make this one an h2.');
            }
        }

        $previous = 0;

        foreach ($headings as $heading) {
            if ($previous > 0 && $heading['level'] > $previous + 1) {
                $findings[] = self::finding('warning', 'heading_level_skip', $heading['path'], $heading['type'], sprintf('h%d follows h%d.', $heading['level'], $previous), sprintf('Use h%d here, or style it smaller without changing the level.', $previous + 1));
            }

            $previous = $heading['level'];
        }

        usort($findings, static fn (array $a, array $b): int => self::SEVERITY_ORDER[$a['severity']] <=> self::SEVERITY_ORDER[$b['severity']]);

        $counts = ['error' => 0, 'warning' => 0, 'notice' => 0];

        foreach ($findings as $finding) {
            $counts[$finding['severity']]++;
        }

        return ['findings' => $findings, 'counts' => $counts, 'headings' => $headings, 'disabled_elements' => $disabled];
    }

    /**
     * @param array<string, mixed> $node
     * @param list<array<string, mixed>> $findings
     * @param list<array<string, mixed>> $headings
     */
    private function walk(array $node, string $path, ?string $itemTitleElement, bool $isListItem, bool $insideDisabled, array &$findings, array &$headings, int &$disabled): void
    {
        $type = is_string($node['type'] ?? null) && $node['type'] !== '' ? $node['type'] : 'unknown';
        $props = is_array($node['props'] ?? null) ? $node['props'] : [];
        $bound = is_array($node['source']['props'] ?? null) ? $node['source']['props'] : [];
        $isDisabled = ($props['status'] ?? null) === 'disabled';

        if ($isDisabled && !$insideDisabled) {
            $disabled++;
        }

        // A disabled element does not render, and neither does anything inside it.
        if (!$insideDisabled && !$isDisabled && $path !== 'root') {
            $this->checkElement($type, $path, $props, $bound, $itemTitleElement, $isListItem, $node, $findings, $headings);
        }

        $definition = $this->definitionOf($type);
        $isList = !empty($definition['container']) && !empty($definition['element']) && $type !== 'fragment';
        $childTitleElement = $isList ? self::titleElement($props, $definition) : null;

        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $index => $child) {
            if (is_array($child)) {
                $childType = is_string($child['type'] ?? null) && $child['type'] !== '' ? $child['type'] : 'unknown';
                $this->walk($child, $path . '>' . $childType . '[' . $index . ']', $childTitleElement, $isList, $insideDisabled || $isDisabled, $findings, $headings, $disabled);
            }
        }
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $bound
     * @param array<string, mixed> $node
     * @param list<array<string, mixed>> $findings
     * @param list<array<string, mixed>> $headings
     */
    private function checkElement(string $type, string $path, array $props, array $bound, ?string $itemTitleElement, bool $isListItem, array $node, array &$findings, array &$headings): void
    {
        $definition = $this->definitionOf($type);

        // Headings: a headline's content, or the title of an element that has a title element.
        $headingProp = $type === 'headline' ? 'content' : 'title';
        $titleElement = $itemTitleElement ?? (isset($definition['fields']['title_element']) || isset($definition['defaults']['title_element']) || isset($props['title_element'])
            ? self::titleElement($props, $definition)
            : null);
        $level = $titleElement !== null && preg_match('/^h([1-6])$/', $titleElement, $m) === 1 ? (int) $m[1] : null;
        $headingText = is_string($props[$headingProp] ?? null) ? trim(strip_tags($props[$headingProp])) : '';
        $headingBound = isset($bound[$headingProp]);

        if ($level !== null && ($headingText !== '' || $headingBound)) {
            $headings[] = ['path' => $path, 'type' => $type, 'level' => $level, 'text' => $headingBound && $headingText === '' ? '(dynamic)' : $headingText];

            if (!$headingBound && mb_strlen($headingText, 'UTF-8') > self::LONG_HEADING) {
                $findings[] = self::finding('notice', 'long_heading', $path, $type, sprintf('Heading of %d characters: «%s».', mb_strlen($headingText, 'UTF-8'), mb_substr($headingText, 0, 80, 'UTF-8')), 'Keep headings under ' . self::LONG_HEADING . ' characters; move the rest to a text element.');
            }
        }

        // Images: a static image without alternative text, and a local image file that is not there.
        $image = is_string($props['image'] ?? null) ? trim($props['image']) : '';

        if ($image !== '' && !isset($bound['image'])) {
            $alt = is_string($props['image_alt'] ?? null) ? trim($props['image_alt']) : '';

            if ($alt === '' && !isset($bound['image_alt'])) {
                $findings[] = self::finding('notice', 'missing_alt', $path, $type, 'Image ' . $image . ' has no alternative text.', 'Describe what the image shows in image_alt. A purely decorative image may keep it empty.');
            }

            if (($this->fileExists)($image) === false) {
                $findings[] = self::finding('error', 'image_file_missing', $path, $type, 'Image ' . $image . ' does not exist on the server.', 'Upload the file again or point image at an existing one.');
            }
        }

        // A list item showing a whole post: teaser is the full post when there is no excerpt or more tag (F-043).
        if ($isListItem) {
            foreach ($bound as $prop => $binding) {
                $field = is_array($binding) && is_string($binding['name'] ?? null) ? $binding['name'] : '';
                $limit = is_array($binding) ? ($binding['filters']['limit'] ?? null) : null;

                if (in_array($field, ['teaser', 'content'], true) && ($limit === null || $limit === '' || $limit === 0 || $limit === '0')) {
                    $findings[] = self::finding('notice', 'unbounded_teaser', $path, $type, sprintf('%s shows %s without a length limit: a post without excerpt shows in full in every item.', (string) $prop, $field), 'Add the limit filter to the binding, for example {"name": "' . $field . '", "filters": {"limit": "180"}}.');
                }
            }
        }

        // A row whose layout does not describe its columns: the page is fine, the Builder is not (F-044).
        if ($type === 'row') {
            $issue = YoothemeRowLayout::check($node, YoothemeRowLayout::options($definition));

            if ($issue !== null) {
                $findings[] = self::finding('warning', 'row_layout_mismatch', $path, $type, YoothemeRowLayout::message($issue), YoothemeRowLayout::fix($issue));
            }
        }

        // A list element with nothing in it renders nothing.
        if (!empty($definition['container']) && !empty($definition['element']) && $type !== 'fragment'
            && (!is_array($node['children'] ?? null) || $node['children'] === [])
            && !isset($node['source'])
        ) {
            $findings[] = self::finding('error', 'empty_container', $path, $type, 'A ' . $type . ' without items renders nothing.', 'Add items, bind a Dynamic Source with multiple items, or remove the element.');
        }
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $definition
     */
    private static function titleElement(array $props, array $definition): ?string
    {
        $value = $props['title_element'] ?? ($definition['defaults']['title_element'] ?? null);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<string, mixed> */
    private function definitionOf(string $type): array
    {
        if (!array_key_exists($type, $this->definitions)) {
            $definition = ($this->definition)($type);
            $this->definitions[$type] = is_array($definition) && !isset($definition['error']) ? $definition : [];
        }

        return $this->definitions[$type];
    }

    /** @return array<string, mixed> */
    private static function finding(string $severity, string $code, string $path, string $type, string $message, string $fix): array
    {
        return ['severity' => $severity, 'code' => $code, 'path' => $path, 'type' => $type, 'message' => $message, 'fix' => $fix];
    }
}
