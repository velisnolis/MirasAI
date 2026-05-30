<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Admin;

use Mirasai\WordPress\Tool\RuntimeSettings;
use Mirasai\WordPress\Tool\SystemDiagnoseTool;
use Mirasai\WordPress\Tool\ToolRegistry;

class DashboardPage
{
    private const MENU_SLUG = 'mirasai';
    private const APP_PASSWORD_PREFIX = 'MirasAI';

    public static function register(): void
    {
        add_action('admin_init', [self::class, 'handleActions']);
        add_action('admin_menu', [self::class, 'registerMenu']);
        add_action('admin_post_mirasai_toggle_dangerous', [self::class, 'handleDangerousToggle']);
        add_action('admin_bar_menu', [self::class, 'registerAdminBar'], 999);
        add_action('admin_head', [self::class, 'renderAdminBarAssets']);
        add_action('wp_head', [self::class, 'renderAdminBarAssets']);
    }

    public static function registerMenu(): void
    {
        add_menu_page(
            __('MirasAI', 'mirasai'),
            __('MirasAI', 'mirasai'),
            'manage_options',
            self::MENU_SLUG,
            [self::class, 'render'],
            'dashicons-superhero-alt',
            3
        );
    }

    public static function handleActions(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (($_POST['mirasai_create_app_password'] ?? null) !== null) {
            check_admin_referer('mirasai_create_app_password');
            $created = self::createApplicationPassword();

            if (is_wp_error($created)) {
                set_transient(self::noticeTransientKey(), [
                    'type' => 'error',
                    'message' => $created->get_error_message(),
                ], 60);
                wp_safe_redirect(self::pageUrl());
                exit;
            }

            set_transient(self::noticeTransientKey(), [
                'type' => 'success',
                'message' => __('Application password created. Copy it now; WordPress will not show it again.', 'mirasai'),
                'password' => $created,
            ], 300);
            wp_safe_redirect(self::pageUrl());
            exit;
        }

        if (($_POST['mirasai_revoke_app_password'] ?? null) !== null) {
            $uuid = isset($_POST['mirasai_app_password_uuid']) && is_string($_POST['mirasai_app_password_uuid'])
                ? sanitize_text_field(wp_unslash($_POST['mirasai_app_password_uuid']))
                : '';

            if ($uuid === '') {
                return;
            }

            check_admin_referer('mirasai_revoke_app_password_' . $uuid);
            \WP_Application_Passwords::delete_application_password(get_current_user_id(), $uuid);
            set_transient(self::noticeTransientKey(), [
                'type' => 'success',
                'message' => __('Application password revoked.', 'mirasai'),
            ], 60);
            wp_safe_redirect(self::pageUrl());
            exit;
        }
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $notice = get_transient(self::noticeTransientKey());
        delete_transient(self::noticeTransientKey());

        $registry = ToolRegistry::buildDefault();
        $diagnose = (new SystemDiagnoseTool($registry))->handle([]);
        $endpoint = rest_url('mirasai/v1/mcp');
        $username = wp_get_current_user()->user_login;
        $newPassword = is_array($notice) && isset($notice['password']) && is_string($notice['password'])
            ? $notice['password']
            : null;
        $displayPassword = $newPassword ?? 'YOUR_APPLICATION_PASSWORD';
        $basicValue = base64_encode($username . ':' . $displayPassword);
        $serverName = self::serverName();
        $tools = is_array($diagnose['tools'] ?? null) ? $diagnose['tools'] : [];
        $auth = is_array($diagnose['endpoint'] ?? null) ? $diagnose['endpoint'] : [];
        $services = is_array($diagnose['services'] ?? null) ? $diagnose['services'] : [];
        $yootheme = is_array($services['yootheme'] ?? null) ? $services['yootheme'] : [];
        $yoothemeCounts = is_array($yootheme['layout_counts'] ?? null) ? $yootheme['layout_counts'] : [];
        $yoothemePostTypes = is_array($yoothemeCounts['post_types'] ?? null) ? $yoothemeCounts['post_types'] : [];
        $yoothemePostStateCounts = is_array($yootheme['post_state_counts'] ?? null) ? $yootheme['post_state_counts'] : [];
        $yoothemePostStateTypes = is_array($yoothemePostStateCounts['post_types'] ?? null) ? $yoothemePostStateCounts['post_types'] : [];
        $multilingual = is_array($services['multilingual'] ?? null) ? $services['multilingual'] : [];
        $translationProvider = is_array($multilingual['provider'] ?? null) ? $multilingual['provider'] : [];
        $translationLanguages = is_array($multilingual['languages'] ?? null) ? $multilingual['languages'] : [];
        $dangerous = RuntimeSettings::dangerousExecStatus();
        $dangerousEnabled = RuntimeSettings::isDangerousExecEnabled();
        $looksProduction = RuntimeSettings::looksLikeProduction();

        ?>
        <div class="wrap mirasai-admin">
            <style>
                .mirasai-admin .mirasai-hero {
                    background: #fff;
                    border: 1px solid #c3c4c7;
                    border-left: 4px solid #2271b1;
                    margin: 16px 0;
                    padding: 18px 20px;
                }
                .mirasai-admin .mirasai-grid {
                    display: grid;
                    grid-template-columns: repeat(3, minmax(0, 1fr));
                    gap: 14px;
                    margin: 16px 0;
                }
                .mirasai-admin .mirasai-card {
                    background: #fff;
                    border: 1px solid #dcdcde;
                    border-radius: 4px;
                    padding: 16px;
                }
                .mirasai-admin .mirasai-card h2,
                .mirasai-admin .mirasai-card h3 {
                    margin-top: 0;
                }
                .mirasai-admin code.mirasai-code {
                    display: block;
                    white-space: pre-wrap;
                    background: #1d2327;
                    color: #f0f0f1;
                    padding: 14px;
                    border-radius: 4px;
                    overflow: auto;
                }
                .mirasai-admin .mirasai-password {
                    display: flex;
                    gap: 8px;
                    align-items: center;
                    max-width: 760px;
                }
                .mirasai-admin .mirasai-password code {
                    flex: 1;
                    padding: 10px;
                    background: #f6f7f7;
                    border: 1px solid #dcdcde;
                }
                .mirasai-admin .mirasai-muted {
                    color: #646970;
                }
                .mirasai-admin .mirasai-ok {
                    color: #008a20;
                    font-weight: 600;
                }
                .mirasai-admin .mirasai-warn {
                    color: #b26200;
                    font-weight: 600;
                }
                .mirasai-admin .mirasai-stat-list {
                    display: grid;
                    gap: 6px;
                    margin: 12px 0 0;
                }
                .mirasai-admin .mirasai-stat-row {
                    display: flex;
                    justify-content: space-between;
                    gap: 12px;
                    border-top: 1px solid #f0f0f1;
                    padding-top: 6px;
                }
                .mirasai-admin .mirasai-stat-row strong {
                    color: #1d2327;
                }
                .mirasai-admin .mirasai-disclosure {
                    margin-top: 12px;
                }
                .mirasai-admin .mirasai-disclosure summary {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    color: #646970;
                    cursor: pointer;
                    list-style: none;
                }
                .mirasai-admin .mirasai-disclosure summary::-webkit-details-marker {
                    display: none;
                }
                .mirasai-admin .mirasai-disclosure summary::before {
                    content: "›";
                    display: inline-block;
                    font-size: 16px;
                    line-height: 1;
                    transform: rotate(0deg);
                    transition: transform 120ms ease-in-out;
                }
                .mirasai-admin .mirasai-disclosure[open] summary::before {
                    transform: rotate(90deg);
                }
                .mirasai-admin .mirasai-disclosure summary:hover {
                    color: #2271b1;
                }
                @media (max-width: 1100px) {
                    .mirasai-admin .mirasai-grid {
                        grid-template-columns: repeat(2, minmax(0, 1fr));
                    }
                }
                @media (max-width: 782px) {
                    .mirasai-admin .mirasai-grid {
                        grid-template-columns: 1fr;
                    }
                    .mirasai-admin .mirasai-password {
                        display: block;
                    }
                    .mirasai-admin .mirasai-password code {
                        display: block;
                        margin-bottom: 8px;
                    }
                }
            </style>

            <h1><?php esc_html_e('MirasAI', 'mirasai'); ?></h1>

            <?php if (is_array($notice) && isset($notice['message'], $notice['type'])): ?>
                <div class="notice notice-<?php echo esc_attr((string) $notice['type']); ?> is-dismissible">
                    <p><?php echo esc_html((string) $notice['message']); ?></p>
                </div>
            <?php endif; ?>

            <section class="mirasai-hero">
                <h2><?php esc_html_e('Connect this WordPress site to an AI client', 'mirasai'); ?></h2>
                <p class="mirasai-muted">
                    <?php esc_html_e('Create a WordPress Application Password, copy the MCP endpoint, then add the config to Codex, Claude, Cursor, VS Code, or another MCP client.', 'mirasai'); ?>
                </p>
                <p>
                    <strong><?php esc_html_e('Endpoint:', 'mirasai'); ?></strong>
                    <code><?php echo esc_html($endpoint); ?></code>
                </p>
                <p>
                    <strong><?php esc_html_e('Update feed:', 'mirasai'); ?></strong>
                    <code><?php echo esc_html(MIRASAI_WP_UPDATE_FEED_URL); ?></code>
                </p>
            </section>

            <section class="mirasai-grid" aria-label="<?php esc_attr_e('Status', 'mirasai'); ?>">
                <div class="mirasai-card">
                    <h3><?php esc_html_e('Endpoint', 'mirasai'); ?></h3>
                    <p class="mirasai-ok"><?php esc_html_e('Ready', 'mirasai'); ?></p>
                    <p class="mirasai-muted"><?php echo esc_html($endpoint); ?></p>
                    <p class="mirasai-muted"><?php echo esc_html(MIRASAI_WP_UPDATE_FEED_URL); ?></p>
                </div>
                <div class="mirasai-card">
                    <h3><?php esc_html_e('Tools', 'mirasai'); ?></h3>
                    <p><strong><?php echo esc_html((string) ($tools['count'] ?? count($registry->summarize()))); ?></strong> <?php esc_html_e('registered tools', 'mirasai'); ?></p>
                    <p class="mirasai-muted"><?php esc_html_e('Read, safe write, guarded write, and dangerous status surfaces are exposed through MCP metadata.', 'mirasai'); ?></p>
                </div>
                <div class="mirasai-card">
                    <h3><?php esc_html_e('Authentication', 'mirasai'); ?></h3>
                    <p class="<?php echo wp_is_application_passwords_available() ? 'mirasai-ok' : 'mirasai-warn'; ?>">
                        <?php echo wp_is_application_passwords_available()
                            ? esc_html__('Application Passwords available', 'mirasai')
                            : esc_html__('Application Passwords unavailable', 'mirasai'); ?>
                    </p>
                    <p class="mirasai-muted"><?php echo esc_html((string) ($auth['recommended_auth'] ?? 'wordpress_application_password')); ?></p>
                </div>
                <div class="mirasai-card">
                    <h3><?php esc_html_e('YOOtheme', 'mirasai'); ?></h3>
                    <p class="<?php echo !empty($yootheme['active']) ? 'mirasai-ok' : 'mirasai-warn'; ?>">
                        <?php echo !empty($yootheme['active']) ? esc_html__('Active', 'mirasai') : esc_html__('Not active', 'mirasai'); ?>
                    </p>
                    <details class="mirasai-disclosure">
                        <summary>
                            <span><?php echo esc_html((string) ($yootheme['layout_count'] ?? 0)); ?> <?php esc_html_e('layouts detected', 'mirasai'); ?></span>
                        </summary>
                        <div class="mirasai-stat-list" aria-label="<?php esc_attr_e('YOOtheme layout storage counts', 'mirasai'); ?>">
                            <div class="mirasai-stat-row">
                                <span><?php esc_html_e('Templates', 'mirasai'); ?></span>
                                <strong><?php echo esc_html((string) ($yoothemeCounts['template'] ?? 0)); ?></strong>
                            </div>
                            <div class="mirasai-stat-row">
                                <span><?php esc_html_e('Pages/posts with YOOtheme', 'mirasai'); ?></span>
                                <strong><?php echo esc_html((string) ($yootheme['post_state_count'] ?? 0)); ?></strong>
                            </div>
                            <div class="mirasai-stat-row">
                                <span><?php esc_html_e('Builder widgets', 'mirasai'); ?></span>
                                <strong><?php echo esc_html((string) ($yoothemeCounts['widget'] ?? 0)); ?></strong>
                            </div>
                        </div>
                        <?php if ($yoothemePostStateTypes !== []): ?>
                            <p class="mirasai-muted">
                                <?php
                                echo esc_html(implode(', ', array_map(
                                    static fn(string $type, int $count): string => $type . ': ' . $count,
                                    array_keys($yoothemePostStateTypes),
                                    array_map('intval', array_values($yoothemePostStateTypes))
                                )));
                                ?>
                            </p>
                        <?php endif; ?>
                        <?php if (($yoothemeCounts['post'] ?? 0) !== ($yootheme['post_state_count'] ?? 0) || $yoothemePostTypes !== []): ?>
                            <p class="mirasai-muted">
                                <?php
                                printf(
                                    esc_html__('Editable post layouts: %s', 'mirasai'),
                                    esc_html((string) ($yoothemeCounts['post'] ?? 0))
                                );
                                ?>
                            </p>
                        <?php endif; ?>
                    </details>
                </div>
                <div class="mirasai-card">
                    <h3><?php esc_html_e('Multilingual', 'mirasai'); ?></h3>
                    <p class="<?php echo !empty($translationProvider['active']) ? 'mirasai-ok' : 'mirasai-warn'; ?>">
                        <?php echo esc_html((string) ($translationProvider['name'] ?? 'none')); ?>
                    </p>
                    <p class="mirasai-muted"><?php echo esc_html((string) count($translationLanguages)); ?> <?php esc_html_e('languages', 'mirasai'); ?></p>
                </div>
                <div class="mirasai-card">
                    <h3><?php esc_html_e('Sandbox', 'mirasai'); ?></h3>
                    <p class="<?php echo $dangerousEnabled ? 'mirasai-warn' : 'mirasai-ok'; ?>">
                        <?php echo $dangerousEnabled
                            ? esc_html__('Controls enabled', 'mirasai')
                            : esc_html__('Controls disabled', 'mirasai'); ?>
                    </p>
                    <p class="mirasai-muted">
                        <?php echo esc_html(RuntimeSettings::relativeSandboxDir()); ?>
                        · <?php echo esc_html((string) $dangerous['state']); ?>
                    </p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" <?php echo !$dangerousEnabled && $looksProduction ? 'onsubmit="return confirm(\'' . esc_js(__('This looks like a production site. Dangerous execution controls should only be enabled when you explicitly need them. Continue?', 'mirasai')) . '\');"' : ''; ?>>
                        <?php wp_nonce_field('mirasai_toggle_dangerous'); ?>
                        <input type="hidden" name="action" value="mirasai_toggle_dangerous" />
                        <input type="hidden" name="mirasai_target" value="<?php echo $dangerousEnabled ? 'off' : 'on'; ?>" />
                        <button type="submit" class="button button-small">
                            <?php echo $dangerousEnabled
                                ? esc_html__('Disable controls', 'mirasai')
                                : esc_html__('Enable controls', 'mirasai'); ?>
                        </button>
                    </form>
                </div>
            </section>

            <section class="mirasai-card">
                <h2><?php esc_html_e('1. Create an Application Password', 'mirasai'); ?></h2>
                <p><?php esc_html_e('This creates a revocable WordPress credential for the current administrator. It is shown once.', 'mirasai'); ?></p>

                <?php if ($newPassword !== null): ?>
                    <div class="mirasai-password">
                        <code id="mirasai-new-password"><?php echo esc_html($newPassword); ?></code>
                        <button type="button" class="button" data-copy-target="mirasai-new-password"><?php esc_html_e('Copy', 'mirasai'); ?></button>
                    </div>
                    <p class="mirasai-warn"><?php esc_html_e('Save it now. WordPress will not show this password again.', 'mirasai'); ?></p>
                <?php endif; ?>

                <form method="post">
                    <?php wp_nonce_field('mirasai_create_app_password'); ?>
                    <input
                        type="text"
                        name="mirasai_app_password_name"
                        class="regular-text"
                        maxlength="70"
                        placeholder="<?php esc_attr_e('Optional label, for example Codex laptop', 'mirasai'); ?>"
                    />
                    <button
                        type="submit"
                        name="mirasai_create_app_password"
                        class="button button-primary"
                        <?php disabled(!wp_is_application_passwords_available()); ?>>
                        <?php esc_html_e('Create Application Password', 'mirasai'); ?>
                    </button>
                </form>

                <?php self::renderPasswordsTable(); ?>
            </section>

            <section class="mirasai-card">
                <h2><?php esc_html_e('2. Configure your MCP client', 'mirasai'); ?></h2>
                <p><?php esc_html_e('Use the snippet below after replacing the placeholder password. If you are unsure where to paste it, pass this section to your AI agent and ask it to add or verify the MirasAI MCP server for you.', 'mirasai'); ?></p>

                <h3><?php esc_html_e('Codex / Claude Code', 'mirasai'); ?></h3>
                <code class="mirasai-code" id="mirasai-codex-config"><?php echo esc_html(self::shellCommand($serverName, $endpoint, $basicValue)); ?></code>
                <p><button type="button" class="button" data-copy-target="mirasai-codex-config"><?php esc_html_e('Copy', 'mirasai'); ?></button></p>

                <h3><?php esc_html_e('JSON clients', 'mirasai'); ?></h3>
                <code class="mirasai-code" id="mirasai-json-config"><?php echo esc_html(self::jsonConfig($serverName, $endpoint, $basicValue)); ?></code>
                <p><button type="button" class="button" data-copy-target="mirasai-json-config"><?php esc_html_e('Copy', 'mirasai'); ?></button></p>
            </section>

            <script>
            document.querySelectorAll('[data-copy-target]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var target = document.getElementById(button.getAttribute('data-copy-target'));
                    if (!target) {
                        return;
                    }
                    navigator.clipboard.writeText(target.textContent).then(function () {
                        var previous = button.textContent;
                        button.textContent = '<?php echo esc_js(__('Copied', 'mirasai')); ?>';
                        setTimeout(function () { button.textContent = previous; }, 1200);
                    });
                });
            });
            </script>
        </div>
        <?php
    }

    public static function handleDangerousToggle(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage MirasAI settings.', 'mirasai'));
        }

        check_admin_referer('mirasai_toggle_dangerous');

        $target = isset($_REQUEST['mirasai_target']) && is_string($_REQUEST['mirasai_target'])
            ? sanitize_text_field(wp_unslash($_REQUEST['mirasai_target']))
            : '';

        if ($target === 'on') {
            RuntimeSettings::enableDangerousExec();
        }

        if ($target === 'off') {
            RuntimeSettings::disableDangerousExec();
        }

        $redirect = wp_get_referer();
        if (!is_string($redirect) || $redirect === '') {
            $redirect = self::pageUrl();
        }

        wp_safe_redirect($redirect);
        exit;
    }

    public static function registerAdminBar(\WP_Admin_Bar $wpAdminBar): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $dangerousEnabled = RuntimeSettings::isDangerousExecEnabled();

        $wpAdminBar->add_node([
            'id' => 'mirasai-wp-status',
            'title' => $dangerousEnabled
                ? esc_html__('MirasAI Danger ON', 'mirasai')
                : esc_html__('MirasAI', 'mirasai'),
            'href' => self::pageUrl(),
            'meta' => [
                'class' => $dangerousEnabled ? 'mirasai-wp-danger-on' : 'mirasai-wp-danger-off',
            ],
        ]);

        $wpAdminBar->add_node([
            'id' => 'mirasai-wp-status-label',
            'parent' => 'mirasai-wp-status',
            'title' => $dangerousEnabled
                ? esc_html__('Dangerous controls: On', 'mirasai')
                : esc_html__('Dangerous controls: Off', 'mirasai'),
            'href' => false,
        ]);

        $wpAdminBar->add_node([
            'id' => 'mirasai-wp-dashboard',
            'parent' => 'mirasai-wp-status',
            'title' => esc_html__('Dashboard', 'mirasai'),
            'href' => self::pageUrl(),
        ]);

        $wpAdminBar->add_node([
            'id' => 'mirasai-wp-danger-toggle',
            'parent' => 'mirasai-wp-status',
            'title' => $dangerousEnabled
                ? esc_html__('Disable dangerous controls', 'mirasai')
                : esc_html__('Enable dangerous controls', 'mirasai'),
            'href' => wp_nonce_url(
                admin_url('admin-post.php?action=mirasai_toggle_dangerous&mirasai_target=' . ($dangerousEnabled ? 'off' : 'on')),
                'mirasai_toggle_dangerous'
            ),
            'meta' => [
                'class' => $dangerousEnabled ? 'mirasai-wp-danger-toggle-off' : 'mirasai-wp-danger-toggle-on',
            ],
        ]);
    }

    public static function renderAdminBarAssets(): void
    {
        if (!current_user_can('manage_options') || !is_admin_bar_showing()) {
            return;
        }

        $confirmMessage = RuntimeSettings::looksLikeProduction()
            ? __('This looks like a production site. Dangerous execution controls should only be enabled when you explicitly need them. Continue?', 'mirasai')
            : __('Dangerous execution controls are for sandbox and server-operation tools. Continue?', 'mirasai');
        ?>
        <style>
        #wp-admin-bar-mirasai-wp-status.mirasai-wp-danger-on > .ab-item {
            background: #b32d2e !important;
            color: #fff !important;
        }
        #wp-admin-bar-mirasai-wp-status-label > .ab-item {
            cursor: default;
            font-weight: 600;
        }
        </style>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            var toggle = document.querySelector('#wp-admin-bar-mirasai-wp-danger-toggle.mirasai-wp-danger-toggle-on > .ab-item');
            if (!toggle) {
                return;
            }
            toggle.addEventListener('click', function (event) {
                if (!window.confirm(<?php echo wp_json_encode($confirmMessage); ?>)) {
                    event.preventDefault();
                }
            });
        });
        </script>
        <?php
    }

    /**
     * @return string|\WP_Error
     */
    private static function createApplicationPassword()
    {
        if (!wp_is_application_passwords_available()) {
            return new \WP_Error(
                'application_passwords_unavailable',
                __('Application Passwords require HTTPS or WP_ENVIRONMENT_TYPE=local.', 'mirasai')
            );
        }

        $label = isset($_POST['mirasai_app_password_name']) && is_string($_POST['mirasai_app_password_name'])
            ? sanitize_text_field(wp_unslash($_POST['mirasai_app_password_name']))
            : '';
        $name = $label !== '' ? self::APP_PASSWORD_PREFIX . ' - ' . $label : self::APP_PASSWORD_PREFIX;
        $existingNames = array_map(
            static fn(array $item): string => (string) ($item['name'] ?? ''),
            \WP_Application_Passwords::get_user_application_passwords(get_current_user_id())
        );

        $normalizedExistingNames = array_map('strtolower', $existingNames);

        if (in_array(strtolower($name), $normalizedExistingNames, true)) {
            $index = 2;
            while (in_array(strtolower($name . ' ' . $index), $normalizedExistingNames, true)) {
                $index++;
            }
            $name .= ' ' . $index;
        }

        $result = \WP_Application_Passwords::create_new_application_password(get_current_user_id(), [
            'name' => $name,
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        return is_array($result) && is_string($result[0] ?? null)
            ? $result[0]
            : new \WP_Error('application_password_create_failed', __('WordPress did not return a plaintext application password.', 'mirasai'));
    }

    private static function renderPasswordsTable(): void
    {
        $passwords = array_values(array_filter(
            \WP_Application_Passwords::get_user_application_passwords(get_current_user_id()),
            static fn(array $item): bool => stripos((string) ($item['name'] ?? ''), self::APP_PASSWORD_PREFIX) === 0
        ));

        if ($passwords === []) {
            echo '<p class="mirasai-muted">' . esc_html__('No MirasAI application passwords for this user yet.', 'mirasai') . '</p>';
            return;
        }

        echo '<table class="widefat striped" style="margin-top:16px;"><thead><tr>';
        echo '<th>' . esc_html__('Name', 'mirasai') . '</th>';
        echo '<th>' . esc_html__('Created', 'mirasai') . '</th>';
        echo '<th>' . esc_html__('Last used', 'mirasai') . '</th>';
        echo '<th>' . esc_html__('Actions', 'mirasai') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($passwords as $password) {
            $uuid = (string) ($password['uuid'] ?? '');
            $created = isset($password['created']) ? wp_date('Y-m-d H:i', (int) $password['created']) : __('Unknown', 'mirasai');
            $lastUsed = isset($password['last_used']) ? wp_date('Y-m-d H:i', (int) $password['last_used']) : __('Never', 'mirasai');
            echo '<tr>';
            echo '<td><strong>' . esc_html((string) ($password['name'] ?? '')) . '</strong></td>';
            echo '<td>' . esc_html($created) . '</td>';
            echo '<td>' . esc_html($lastUsed) . '</td>';
            echo '<td><form method="post" onsubmit="return confirm(\'' . esc_js(__('Revoke this password?', 'mirasai')) . '\');">';
            wp_nonce_field('mirasai_revoke_app_password_' . $uuid);
            echo '<input type="hidden" name="mirasai_app_password_uuid" value="' . esc_attr($uuid) . '" />';
            echo '<button type="submit" name="mirasai_revoke_app_password" class="button button-small">' . esc_html__('Revoke', 'mirasai') . '</button>';
            echo '</form></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private static function shellCommand(string $serverName, string $endpoint, string $basicValue): string
    {
        return implode(" \\\n  ", [
            'codex mcp add --transport http ' . escapeshellarg($serverName) . ' ' . escapeshellarg($endpoint),
            '--header ' . escapeshellarg('Authorization: Basic ' . $basicValue),
        ]);
    }

    private static function jsonConfig(string $serverName, string $endpoint, string $basicValue): string
    {
        return (string) wp_json_encode([
            'mcpServers' => [
                $serverName => [
                    'type' => 'http',
                    'url' => $endpoint,
                    'headers' => [
                        'Authorization' => 'Basic ' . $basicValue,
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private static function serverName(): string
    {
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $slug = sanitize_title($host !== '' ? $host : 'wordpress');

        return $slug !== '' ? 'mirasai-' . $slug : 'mirasai-wordpress';
    }

    private static function pageUrl(): string
    {
        return admin_url('admin.php?page=' . self::MENU_SLUG);
    }

    private static function noticeTransientKey(): string
    {
        return 'mirasai_admin_notice_' . get_current_user_id();
    }
}
