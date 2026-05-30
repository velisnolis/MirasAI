<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class SystemInfoTool extends AbstractTool
{
    public function getName(): string
    {
        return 'system/info';
    }

    public function getDescription(): string
    {
        return 'Returns WordPress runtime information: versions, environment, active theme, active plugins, post types, and MirasAI host metadata.';
    }

    public function getSurface(): string
    {
        return 'essential';
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $theme = wp_get_theme();
        $activePlugins = function_exists('get_plugins') ? get_plugins() : [];
        $activePluginFiles = (array) get_option('active_plugins', []);
        $yootheme = new YoothemeWpHelper();

        return [
            'product' => 'MirasAI',
            'host_platform' => 'wordpress',
            'host_contract_version' => MIRASAI_WP_CONTRACT_VERSION,
            'mirasai_version' => MIRASAI_WP_VERSION,
            'wordpress' => [
                'version' => get_bloginfo('version'),
                'site_url' => site_url(),
                'home_url' => home_url(),
                'is_multisite' => is_multisite(),
                'environment_type' => function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production',
            ],
            'php' => [
                'version' => PHP_VERSION,
            ],
            'theme' => [
                'name' => $theme->get('Name'),
                'stylesheet' => get_stylesheet(),
                'template' => get_template(),
                'version' => $theme->get('Version'),
            ],
            'yootheme' => $yootheme->status(),
            'plugins' => [
                'active_count' => count($activePluginFiles),
                'active' => array_values(array_map(
                    static function (string $pluginFile) use ($activePlugins): array {
                        $data = $activePlugins[$pluginFile] ?? [];

                        return [
                            'file' => $pluginFile,
                            'name' => (string) ($data['Name'] ?? $pluginFile),
                            'version' => (string) ($data['Version'] ?? ''),
                        ];
                    },
                    $activePluginFiles
                )),
            ],
            'post_types' => $this->postTypes(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function postTypes(): array
    {
        $types = [];

        foreach (get_post_types(['show_in_rest' => true], 'objects') as $name => $postType) {
            $types[] = [
                'name' => (string) $name,
                'label' => (string) $postType->label,
                'public' => (bool) $postType->public,
                'hierarchical' => (bool) $postType->hierarchical,
            ];
        }

        return $types;
    }
}
