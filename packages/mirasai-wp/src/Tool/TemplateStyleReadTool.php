<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class TemplateStyleReadTool extends AbstractTool
{
    public function getName(): string
    {
        return 'template/style-read';
    }

    public function getDescription(): string
    {
        return 'Reads the YOOtheme Pro Style state: active style and variation, variable overrides, custom Less, available styles from the theme and child theme, compiled CSS freshness, and locally stored fonts. Read-only. The YOOtheme API key is never returned.';
    }

    public function getSurface(): string
    {
        return 'essential';
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
        $helper = new YoothemeStyleHelper();
        $status = $helper->status();

        if (!$status['installed']) {
            return ['error' => 'YOOtheme Pro is not installed.', 'code' => 'yootheme_missing'];
        }

        if (!$status['active']) {
            return ['error' => 'YOOtheme Pro is installed but not the active template.', 'code' => 'yootheme_inactive'];
        }

        $config = $helper->loadConfig();
        $compiled = $helper->compiledState();
        $overrides = $helper->overrides($config);

        if (($arguments['include_overrides'] ?? true) === false) {
            unset($overrides['less']);
        }

        if (($arguments['include_custom_less'] ?? false) !== true) {
            unset($overrides['custom_less']);
        }

        return [
            'yootheme' => $status,
            'active' => $helper->activeStyle($config),
            'overrides' => $overrides,
            'available' => $helper->availableStyles(),
            'compiled' => $compiled,
            'fonts' => $helper->fonts(),
            'child_theme' => $helper->childTheme(),
            'storage' => [
                'option' => 'theme_mods_yootheme',
                'key' => 'config',
                'format' => 'json_string',
                'note' => 'Style configuration does not live in the "yootheme" option; that one holds Builder templates.',
            ],
            'warnings' => $this->warnings($compiled, $helper->childTheme()),
            'etag' => $helper->etag($config, $compiled),
        ];
    }

    /**
     * @param array<string, mixed> $compiled
     * @param array<string, mixed> $childTheme
     * @return list<string>
     */
    private function warnings(array $compiled, array $childTheme): array
    {
        $warnings = [];

        if (($compiled['present'] ?? false) !== true) {
            $warnings[] = 'No compiled stylesheet found. The site has no Style CSS to serve.';

            return $warnings;
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

        if (($childTheme['present'] ?? false) !== true) {
            $warnings[] = 'No child theme is active. Custom styles and portable brand Less have nowhere version-controllable to live, and would not survive a theme update.';
        }

        return $warnings;
    }
}
