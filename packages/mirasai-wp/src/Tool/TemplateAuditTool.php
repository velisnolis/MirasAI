<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class TemplateAuditTool extends AbstractTool
{
    public const SITE_MAX_PER_LAYOUT = 5;
    public const SITE_DEFAULT_LIMIT = 100;
    public const SITE_MAX_LIMIT = 500;

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
            . 'It pages through every layout with offset/limit; next_offset is null once all are covered. '
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
                'offset' => ['type' => 'integer', 'minimum' => 0, 'description' => 'scope=site only: skip this many layouts. Defaults to 0.'],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::SITE_MAX_LIMIT,
                    'description' => 'scope=site only: layouts audited per call. Defaults to ' . self::SITE_DEFAULT_LIMIT . '; next_offset continues.',
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

            $selectors = [];

            foreach ($helper->listLayouts(['template', 'widget']) as $item) {
                if (!empty($item['has_layout'])) {
                    $selectors[] = array_intersect_key($item, ['key' => true, 'widget_id' => true]);
                }
            }

            foreach ($helper->allPostLayoutIds() as $postId) {
                $selectors[] = ['post_id' => $postId];
            }

            return self::sitePage($auditor, $selectors, $arguments, static function (array $selector) use ($helper): ?array {
                $target = $helper->resolveTarget($selector);

                return isset($target['error']) ? null : ['label' => $target['label'], 'layout' => $target['layout']];
            });
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
     * One page of the site audit: the selectors list every layout, only this page is loaded and audited.
     *
     * @param list<array<string, mixed>> $selectors every layout on the site, in a stable order
     * @param array<string, mixed> $arguments
     * @param callable(array<string, mixed>): ?array{label: string, layout: array<string, mixed>} $resolve
     * @return array<string, mixed>
     */
    public static function sitePage(YoothemeLayoutAuditor $auditor, array $selectors, array $arguments, callable $resolve): array
    {
        $total = count($selectors);
        $offset = max(0, (int) ($arguments['offset'] ?? 0));
        $limit = max(1, min(self::SITE_MAX_LIMIT, (int) ($arguments['limit'] ?? self::SITE_DEFAULT_LIMIT)));
        $layouts = [];
        $unreadable = 0;

        foreach (array_slice($selectors, $offset, $limit) as $selector) {
            $resolved = $resolve($selector);

            if ($resolved === null) {
                $unreadable++;
                continue;
            }

            $layouts[] = ['selector' => $selector] + $resolved;
        }

        $report = self::siteReport($auditor, $layouts, (int) ($arguments['max_per_layout'] ?? self::SITE_MAX_PER_LAYOUT));
        $next = $offset + $limit < $total ? $offset + $limit : null;

        return [
            'scope' => 'site',
            'layouts_total' => $total,
            'offset' => $offset,
            'next_offset' => $next,
            'layouts_unreadable' => $unreadable,
        ] + $report + [
            'note' => $next === null
                ? 'Every layout on the site is in this or an earlier page. Audit one layout (scope=layout) for every finding and its heading outline.'
                : "Totals cover this page only. Call again with offset={$next} for the next layouts.",
        ];
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
            'layouts_audited' => count($layouts),
            'layouts_clean' => $clean,
            'totals' => $totals,
            'layouts' => $rows,
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
