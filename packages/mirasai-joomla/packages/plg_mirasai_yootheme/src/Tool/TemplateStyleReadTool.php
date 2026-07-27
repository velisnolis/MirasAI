<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Mirasai\Library\Tool\AbstractTool;

class TemplateStyleReadTool extends AbstractTool
{
    public function getName(): string
    {
        return 'template/style-read';
    }

    public function getDescription(): string
    {
        return 'Reads the Joomla YOOtheme Pro Style state: active style and variation, variable overrides, custom Less, available styles, compiled CSS freshness, and locally stored fonts. Read-only. The YOOtheme API key is never returned.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'include_custom_less' => [
                    'type' => 'boolean',
                    'description' => 'Include the full custom Less source. Defaults to false; only its size is reported.',
                ],
                'include_overrides' => [
                    'type' => 'boolean',
                    'description' => 'Include the full map of Less variable overrides. Defaults to true.',
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
        $helper = new YoothemeStyleHelper($this->db);
        $status = $helper->status();

        if (!$status['installed']) {
            return ['error' => 'YOOtheme Pro is not installed.', 'code' => 'yootheme_missing'];
        }

        if (!$status['active'] || !is_int($status['template_style_id'])) {
            return [
                'error' => 'YOOtheme Pro is installed but is not the active Joomla site template.',
                'code' => 'yootheme_inactive',
            ];
        }

        $templateStyleId = $status['template_style_id'];
        $config = $helper->loadConfig($templateStyleId);
        $compiled = $helper->compiledState($templateStyleId);
        $overrides = $helper->overrides($config);

        if (($arguments['include_overrides'] ?? true) === false) {
            unset($overrides['less']);
        }

        if (($arguments['include_custom_less'] ?? false) !== true) {
            unset($overrides['custom_less']);
        }

        return [
            'yootheme' => $status,
            'template_style_id' => $templateStyleId,
            'active' => $helper->activeStyle($config),
            'overrides' => $overrides,
            'available' => $helper->availableStyles(),
            'compiled' => $compiled,
            'fonts' => $helper->fonts(),
            'storage' => [
                'table' => '#__template_styles',
                'row_id' => $templateStyleId,
                'path' => 'params.config',
                'format' => 'json_string',
            ],
            'warnings' => $this->warnings($compiled),
            'etag' => $helper->etag($config, $compiled),
        ];
    }

    /**
     * @param array<string, mixed> $compiled
     * @return list<string>
     */
    private function warnings(array $compiled): array
    {
        $warnings = [];

        if (($compiled['present'] ?? false) !== true) {
            return ['No compiled stylesheet found. The site has no Style CSS to serve.'];
        }

        if (($compiled['stale_sources'] ?? false) === true) {
            $warnings[] = sprintf(
                'The compiled CSS predates its own Less sources (newest: %s at %s). YOOtheme compiles in the browser, so the site keeps serving the older CSS until the customizer is opened and saved, or the style is recompiled.',
                (string) ($compiled['newest_source'] ?? 'unknown'),
                (string) ($compiled['newest_source_mtime'] ?? 'unknown')
            );
        }

        if (($compiled['stale_version'] ?? false) === true) {
            $warnings[] = sprintf(
                'The compiled CSS was produced by YOOtheme %s but the theme now runs %s.',
                (string) ($compiled['compiled_version'] ?? 'unknown'),
                (string) ($compiled['theme_version'] ?? 'unknown')
            );
        }

        return $warnings;
    }
}
