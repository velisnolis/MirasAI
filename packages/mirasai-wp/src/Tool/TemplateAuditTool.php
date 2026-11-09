<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class TemplateAuditTool extends AbstractTool
{
    public const SITE_MAX_PER_LAYOUT = 5;

    public function getName(): string
    {
        return 'template/audit';
    }

    public function getDescription(): string
    {
        return 'Audits one stored YOOtheme layout and lists findings by severity, each with its element path and a fix: '
            . 'image files that do not exist and list elements (grid, slideshow…) with no items (error); no h1, a second h1 '
            . 'and skipped heading levels (warning); images without alternative text and headings over 70 characters (notice). '
            . 'Also returns the heading outline. scope=site audits every template, post and widget layout instead and returns, '
            . 'per layout with findings, its counts and at most max_per_layout findings, worst first; clean layouts are only counted. '
            . 'It reads the saved layout: values from Dynamic Sources are not judged, '
            . 'use template/render-check for what renders. Meta titles and descriptions belong to the SEO plugin and are not checked. Read-only.';
    }

    /**
     * @return array<string, mixed>
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'key' => ['type' => 'string', 'description' => 'YOOtheme template key from template/list.'],
                'post_id' => ['type' => 'integer', 'description' => 'Post or page with its own YOOtheme layout.'],
                'widget_id' => ['type' => 'string', 'description' => 'YOOtheme Builder widget.'],
                'scope' => [
                    'type' => 'string',
                    'enum' => ['layout', 'site'],
                    'description' => 'layout (default) audits the one target given; site audits every layout and takes no target.',
                ],
                'max_per_layout' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 50,
                    'description' => 'scope=site only: findings listed per layout. Defaults to ' . self::SITE_MAX_PER_LAYOUT . '.',
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array
    {
        $helper = new YoothemeWpHelper();
        $root = defined('ABSPATH') ? rtrim((string) ABSPATH, '/') : '';
        $auditor = new YoothemeLayoutAuditor(
            static fn (string $type): array => $helper->loadElementDefinition($type),
            static fn (string $image): ?bool => self::localFileExists($root, $image),
        );

        if (($arguments['scope'] ?? 'layout') === 'site') {
            foreach (['key', 'post_id', 'widget_id'] as $name) {
                if (isset($arguments[$name])) {
                    return ['error' => 'scope=site audits every layout; drop key, post_id and widget_id.', 'code' => 'site_scope_takes_no_target'];
                }
            }

            $layouts = [];

            foreach ($helper->listLayouts() as $item) {
                if (empty($item['has_layout'])) {
                    continue;
                }

                $selector = array_intersect_key($item, ['key' => true, 'post_id' => true, 'widget_id' => true]);
                $target = $helper->resolveTarget($selector);

                if (!isset($target['error'])) {
                    $layouts[] = ['selector' => $selector, 'label' => $target['label'], 'layout' => $target['layout']];
                }
            }

            return self::siteReport($auditor, $layouts, (int) ($arguments['max_per_layout'] ?? self::SITE_MAX_PER_LAYOUT));
        }

        $target = $helper->resolveTarget($arguments);

        if (isset($target['error'])) {
            return $target;
        }

        return [
            'storage' => $target['storage'],
            'id' => $target['id'],
            'label' => $target['label'],
            'etag' => $target['etag'],
        ] + $auditor->audit($target['layout']);
    }

    /**
     * One row per layout with findings, worst first; clean layouts only counted.
     *
     * @param list<array{selector: array<string, mixed>, label: string, layout: array<string, mixed>}> $layouts
     * @return array<string, mixed>
     */
    public static function siteReport(YoothemeLayoutAuditor $auditor, array $layouts, int $maxPerLayout): array
    {
        $maxPerLayout = max(1, min(50, $maxPerLayout));
        $rows = [];
        $totals = ['error' => 0, 'warning' => 0, 'notice' => 0];
        $clean = 0;

        foreach ($layouts as $item) {
            $result = $auditor->audit($item['layout']);

            foreach ($result['counts'] as $severity => $count) {
                $totals[$severity] += $count;
            }

            if ($result['findings'] === []) {
                $clean++;
                continue;
            }

            $rows[] = $item['selector'] + [
                'label' => $item['label'],
                'counts' => $result['counts'],
                'findings' => array_slice($result['findings'], 0, $maxPerLayout),
                'more_findings' => max(0, count($result['findings']) - $maxPerLayout),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$b['counts']['error'], $b['counts']['warning'], $b['counts']['notice']] <=> [$a['counts']['error'], $a['counts']['warning'], $a['counts']['notice']]);

        return [
            'scope' => 'site',
            'layouts_audited' => count($layouts),
            'layouts_clean' => $clean,
            'totals' => $totals,
            'layouts' => $rows,
            'note' => 'Audit one layout (scope=layout with its key, post_id or widget_id) for every finding and its heading outline.',
        ];
    }

    /** null when the value is not a local path this check can see (a URL, a data URI). */
    public static function localFileExists(string $root, string $image): ?bool
    {
        if ($root === '' || preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $image) === 1) {
            return null;
        }

        // Decode first, then judge: a check before decoding lets %2e%2e through.
        $path = ltrim(rawurldecode((string) preg_replace('/[?#].*$/', '', $image)), '/');

        if ($path === '' || preg_match('#(^|/)\.\.(/|$)|[\x00\\\\]#', $path) === 1) {
            return null;
        }

        $base = realpath($root);
        $file = realpath($root . '/' . $path);

        if ($file === false) {
            return false;
        }

        // A file that resolves outside the site root (a symlinked uploads folder) is not judged.
        if ($base === false || !str_starts_with($file, $base . '/')) {
            return null;
        }

        return is_file($file);
    }
}
