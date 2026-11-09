<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class TemplateAuditTool extends AbstractTool
{
    public function getName(): string
    {
        return 'template/audit';
    }

    public function getDescription(): string
    {
        return 'Audits one stored YOOtheme layout and lists findings by severity, each with its element path and a fix: '
            . 'image files that do not exist and list elements (grid, slideshow…) with no items (error); no h1, a second h1 '
            . 'and skipped heading levels (warning); images without alternative text and headings over 70 characters (notice). '
            . 'Also returns the heading outline. It reads the saved layout: values from Dynamic Sources are not judged, '
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
        $target = $helper->resolveTarget($arguments);

        if (isset($target['error'])) {
            return $target;
        }

        $root = defined('ABSPATH') ? rtrim((string) ABSPATH, '/') : '';
        $auditor = new YoothemeLayoutAuditor(
            static fn (string $type): array => $helper->loadElementDefinition($type),
            static fn (string $image): ?bool => self::localFileExists($root, $image),
        );

        return [
            'storage' => $target['storage'],
            'id' => $target['id'],
            'label' => $target['label'],
            'etag' => $target['etag'],
        ] + $auditor->audit($target['layout']);
    }

    /** null when the value is not a local path this check can see (a URL, a data URI). */
    public static function localFileExists(string $root, string $image): ?bool
    {
        if ($root === '' || preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $image) === 1) {
            return null;
        }

        $path = ltrim((string) preg_replace('/[?#].*$/', '', $image), '/');

        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        return is_file($root . '/' . rawurldecode($path));
    }
}
