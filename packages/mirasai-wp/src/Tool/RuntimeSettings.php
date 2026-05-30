<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class RuntimeSettings
{
    private const OPTION_DANGEROUS_ENABLED = 'mirasai_wp_dangerous_exec_enabled';
    private const OPTION_DANGEROUS_DOMAIN = 'mirasai_wp_dangerous_exec_domain';
    private const OPTION_DANGEROUS_ENABLED_BY = 'mirasai_wp_dangerous_exec_enabled_by';
    private const OPTION_DANGEROUS_ENABLED_AT = 'mirasai_wp_dangerous_exec_enabled_at';

    public static function enableDangerousExec(): void
    {
        update_option(self::OPTION_DANGEROUS_ENABLED, '1', false);
        update_option(self::OPTION_DANGEROUS_DOMAIN, self::currentHost(), false);
        update_option(self::OPTION_DANGEROUS_ENABLED_BY, get_current_user_id(), false);
        update_option(self::OPTION_DANGEROUS_ENABLED_AT, time(), false);
    }

    public static function disableDangerousExec(): void
    {
        delete_option(self::OPTION_DANGEROUS_ENABLED);
        delete_option(self::OPTION_DANGEROUS_DOMAIN);
        delete_option(self::OPTION_DANGEROUS_ENABLED_BY);
        delete_option(self::OPTION_DANGEROUS_ENABLED_AT);
    }

    public static function isDangerousExecEnabled(): bool
    {
        $enabled = get_option(self::OPTION_DANGEROUS_ENABLED, false);
        if ($enabled !== '1' && $enabled !== true) {
            return false;
        }

        return self::dangerousExecDomain() === self::currentHost();
    }

    public static function isDangerousExecConfigured(): bool
    {
        $enabled = get_option(self::OPTION_DANGEROUS_ENABLED, false);

        return $enabled === '1' || $enabled === true;
    }

    public static function dangerousExecDomain(): string
    {
        $domain = get_option(self::OPTION_DANGEROUS_DOMAIN, '');

        return is_string($domain) ? $domain : '';
    }

    /**
     * @return array<string, mixed>
     */
    public static function dangerousExecStatus(): array
    {
        $configured = self::isDangerousExecConfigured();
        $domain = self::dangerousExecDomain();
        $currentHost = self::currentHost();
        $enabledAt = get_option(self::OPTION_DANGEROUS_ENABLED_AT, null);

        return [
            'implemented' => true,
            'configured_enabled' => $configured,
            'available' => self::isDangerousExecEnabled(),
            'state' => match (true) {
                !$configured => 'disabled',
                $domain !== $currentHost => 'domain_mismatch',
                default => 'enabled',
            },
            'domain_lock' => $domain,
            'current_domain' => $currentHost,
            'enabled_by_user_id' => (int) get_option(self::OPTION_DANGEROUS_ENABLED_BY, 0),
            'enabled_at' => is_numeric($enabledAt) ? gmdate('c', (int) $enabledAt) : null,
            'message' => 'Dangerous execution controls gate sandbox/execute-php for the current domain.',
        ];
    }

    public static function sandboxDir(bool $ensureExists = false): string
    {
        $dir = rtrim(WP_CONTENT_DIR, '/\\') . '/mirasai-sandbox/';

        if ($ensureExists && !is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        return $dir;
    }

    public static function relativeSandboxDir(): string
    {
        return 'wp-content/mirasai-sandbox';
    }

    /**
     * @return list<string>
     */
    public static function sandboxFiles(): array
    {
        $dir = self::sandboxDir(false);
        if (!is_dir($dir)) {
            return [];
        }

        $files = scandir($dir);
        if ($files === false) {
            return [];
        }

        $result = [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || is_dir($dir . $file)) {
                continue;
            }

            $result[] = $file;
        }

        sort($result);

        return $result;
    }

    public static function sandboxSafeModeActive(): bool
    {
        return file_exists(self::sandboxDir(false) . '.crashed');
    }

    public static function isPhpLintAvailable(): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open(PHP_BINARY . ' -l', $descriptors, $pipes);
        if (!is_resource($process)) {
            return false;
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $exitCode = proc_close($process);

        return $exitCode === 0 || $exitCode === 255;
    }

    public static function looksLikeProduction(): bool
    {
        $environment = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
        if (in_array($environment, ['local', 'development', 'staging'], true)) {
            return false;
        }

        $host = strtolower(self::currentHost());
        if ($host === '') {
            return true;
        }

        $colonPos = strpos($host, ':');
        if ($colonPos !== false) {
            $host = substr($host, 0, $colonPos);
        }

        if (!str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        $segments = explode('.', $host);
        $tld = end($segments);
        if (in_array($tld, ['dev', 'local', 'staging', 'test', 'example', 'invalid', 'backup'], true)) {
            return false;
        }

        foreach (['dev', 'local', 'test', 'staging', 'stage', 'stg', 'wp-staging', 'wpstaging', 'development', 'preview'] as $needle) {
            if (in_array($needle, $segments, true)) {
                return false;
            }
        }

        return true;
    }

    private static function currentHost(): string
    {
        $host = wp_parse_url(home_url(), PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : '';
    }
}
