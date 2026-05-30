<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class SystemDiagnoseTool extends AbstractTool
{
    private ToolRegistry $registry;

    public function __construct(ToolRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function getName(): string
    {
        return 'system/diagnose';
    }

    public function getDescription(): string
    {
        return 'Runs a compact MirasAI WordPress diagnostic: endpoint readiness, auth configuration, tool counts, WordPress environment, and key runtime warnings.';
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
        $tools = $this->registry->summarize();
        $translations = new WordPressTranslationHelper();
        $acf = new AcfHelper();
        $yootheme = new YoothemeWpHelper();
        $yoothemeLayouts = $yootheme->listLayouts(['template', 'post', 'widget']);
        $yoothemePostStates = $yootheme->listYoothemePostStateRows();

        return [
            'ok' => true,
            'product' => 'MirasAI',
            'host_platform' => 'wordpress',
            'host_contract_version' => MIRASAI_WP_CONTRACT_VERSION,
            'mirasai_version' => MIRASAI_WP_VERSION,
            'endpoint' => [
                'route' => '/wp-json/mirasai/v1/mcp',
                'recommended_auth' => 'wordpress_application_password',
                'fallback_auth_header' => 'X-MirasAI-Token',
                'application_passwords_available' => function_exists('wp_is_application_passwords_available') ? wp_is_application_passwords_available() : null,
                'required_capability' => 'manage_options',
                'fallback_token_configured' => self::isTokenConfigured(),
                'fallback_token_sources' => self::configuredTokenSources(),
            ],
            'wordpress' => [
                'version' => get_bloginfo('version'),
                'environment_type' => function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production',
                'is_multisite' => is_multisite(),
            ],
            'tools' => [
                'count' => count($tools),
                'essential_count' => count(array_filter($tools, static fn(array $tool): bool => $tool['surface'] === 'essential')),
                'risk_levels' => $this->riskLevelCounts($tools),
            ],
            'services' => [
                'multilingual' => [
                    'provider' => $translations->provider(),
                    'languages' => $translations->languages(),
                    'status_tool' => 'content/audit-multilingual',
                    'check_links_tool' => 'content/check-links',
                ],
                'wordpress_abilities' => [
                    'available' => function_exists('wp_get_abilities'),
                    'list_tool' => 'wp/abilities/list',
                    'call_tool' => 'wp/ability-call',
                    'call_policy' => 'readonly_or_safe_write_allowlist',
                    'safe_write_allowlist_count' => count(WordPressAbilityPolicy::safeWriteAllowlist()),
                    'blocklist_count' => count(WordPressAbilityPolicy::blocklist()),
                ],
                'acf' => array_merge($acf->status(), [
                    'status_tool' => 'acf/status',
                    'field_groups_list_tool' => 'acf/field-groups/list',
                    'field_group_read_tool' => 'acf/field-group/read',
                    'post_fields_read_tool' => 'acf/post-fields/read',
                    'cpt_list_tool' => 'acf/cpt/list',
                    'taxonomy_list_tool' => 'acf/taxonomy/list',
                ]),
                'yootheme' => array_merge($yootheme->status(), [
                    'layout_count' => count($yoothemeLayouts),
                    'layout_counts' => $this->layoutCounts($yoothemeLayouts),
                    'post_state_count' => count($yoothemePostStates),
                    'post_state_counts' => $this->postStateCounts($yoothemePostStates),
                ]),
                'sandbox' => [
                    'status_tool' => 'sandbox/status',
                    'implemented' => true,
                    'execute_tool' => RuntimeSettings::isDangerousExecEnabled() ? 'sandbox/execute-php' : null,
                    'dangerous_exec_available' => RuntimeSettings::isDangerousExecEnabled(),
                    'safe_mode' => RuntimeSettings::sandboxSafeModeActive(),
                    'sandbox_dir' => RuntimeSettings::relativeSandboxDir(),
                ],
                'elevation' => [
                    'status_tool' => 'elevation/status',
                    'implemented' => true,
                    'dangerous_exec_available' => RuntimeSettings::isDangerousExecEnabled(),
                    'state' => RuntimeSettings::dangerousExecStatus()['state'],
                ],
            ],
            'warnings' => $this->warnings(),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{post_types: array<string, int>, editable_layout_count: int}
     */
    private function postStateCounts(array $rows): array
    {
        $counts = [
            'post_types' => [],
            'editable_layout_count' => 0,
        ];

        foreach ($rows as $row) {
            $postType = is_string($row['post_type'] ?? null) && $row['post_type'] !== ''
                ? (string) $row['post_type']
                : 'post';
            $counts['post_types'][$postType] = ($counts['post_types'][$postType] ?? 0) + 1;

            if (!empty($row['has_editable_layout'])) {
                $counts['editable_layout_count']++;
            }
        }

        ksort($counts['post_types']);

        return $counts;
    }

    /**
     * @param list<array<string, mixed>> $layouts
     * @return array{template: int, post: int, widget: int, post_types: array<string, int>}
     */
    private function layoutCounts(array $layouts): array
    {
        $counts = [
            'template' => 0,
            'post' => 0,
            'widget' => 0,
            'post_types' => [],
        ];

        foreach ($layouts as $layout) {
            $storage = is_string($layout['storage'] ?? null) ? (string) $layout['storage'] : '';

            if (array_key_exists($storage, $counts) && is_int($counts[$storage])) {
                $counts[$storage]++;
            }

            if ($storage === 'post') {
                $postType = is_string($layout['post_type'] ?? null) && $layout['post_type'] !== ''
                    ? (string) $layout['post_type']
                    : 'post';
                $counts['post_types'][$postType] = ($counts['post_types'][$postType] ?? 0) + 1;
            }
        }

        ksort($counts['post_types']);

        return $counts;
    }

    public static function isTokenConfigured(): bool
    {
        return self::configuredTokenSources() !== [];
    }

    /**
     * @return list<string>
     */
    public static function configuredTokenSources(): array
    {
        $sources = [];

        if (defined('MIRASAI_WP_TOKEN') && is_string(MIRASAI_WP_TOKEN) && MIRASAI_WP_TOKEN !== '') {
            $sources[] = 'constant';
        }

        $envToken = getenv('MIRASAI_WP_TOKEN');
        if (is_string($envToken) && $envToken !== '') {
            $sources[] = 'env';
        }

        $optionHash = get_option('mirasai_wp_token_hash', '');
        if (is_string($optionHash) && $optionHash !== '') {
            $sources[] = 'option_hash';
        }

        return $sources;
    }

    /**
     * @param list<array{name: string, risk_level: string, surface: string}> $tools
     * @return array<string, int>
     */
    private function riskLevelCounts(array $tools): array
    {
        $counts = [
            'read' => 0,
            'safe_write' => 0,
            'guarded_write' => 0,
            'dangerous_exec' => 0,
        ];

        foreach ($tools as $tool) {
            $risk = $tool['risk_level'];
            if (!array_key_exists($risk, $counts)) {
                $risk = 'read';
            }

            $counts[$risk]++;
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    private function warnings(): array
    {
        $warnings = [];

        if (function_exists('wp_is_application_passwords_available') && !wp_is_application_passwords_available()) {
            $warnings[] = 'WordPress Application Passwords are not available. They require HTTPS or WP_ENVIRONMENT_TYPE=local.';
        }

        if (!self::isTokenConfigured()) {
            $warnings[] = 'No fallback MirasAI token is configured. This is acceptable when using WordPress Application Passwords.';
        }

        if (!is_ssl() && (function_exists('wp_get_environment_type') && wp_get_environment_type() !== 'local')) {
            $warnings[] = 'The site is not using HTTPS. Use HTTPS for remote MCP access.';
        }

        return $warnings;
    }
}
