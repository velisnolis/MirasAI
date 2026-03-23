<?php

declare(strict_types=1);

namespace Mirasai\Library\Sandbox;

use Joomla\CMS\Factory;

/**
 * Detect whether the current Joomla site is a staging/dev environment
 * or a production site.
 *
 * Any positive signal → staging.  No signal → assume production.
 *
 * Gated tools (destructive=true) are blocked on production.
 */
class EnvironmentGuard
{
    private const STAGING_DOMAIN_PATTERNS = [
        'staging.',
        'dev.',
        '.test',
        '.local',
        'localhost',
    ];

    private const PRIVATE_IP_PREFIXES = [
        '127.',
        '10.',
        '192.168.',
    ];

    /**
     * @var bool|null  Cached result for the duration of the request.
     */
    private static ?bool $isStaging = null;

    /**
     * Returns true when the site appears to be a staging/dev environment.
     */
    public static function isStaging(): bool
    {
        if (self::$isStaging !== null) {
            return self::$isStaging;
        }

        self::$isStaging = self::detect();

        return self::$isStaging;
    }

    /**
     * Returns true when the site appears to be production.
     */
    public static function isProduction(): bool
    {
        return !self::isStaging();
    }

    /**
     * Assert that the current environment is staging.
     *
     * @throws \RuntimeException if the environment is production.
     */
    public static function assertStaging(): void
    {
        if (self::isProduction()) {
            throw new \RuntimeException(
                'This tool is only available on staging/development environments. '
                . 'If this is a staging site, set environment_override = "staging" in '
                . 'MirasAI component configuration.'
            );
        }
    }

    /**
     * Reset the cached detection result. Useful for testing.
     */
    public static function reset(): void
    {
        self::$isStaging = null;
    }

    /**
     * Run all detection signals.
     */
    private static function detect(): bool
    {
        // 1. Explicit config override (highest priority)
        if (self::checkConfigOverride()) {
            return true;
        }

        // 2. Domain patterns
        if (self::checkDomain()) {
            return true;
        }

        // 3. Private IP
        if (self::checkPrivateIp()) {
            return true;
        }

        // 4. Joomla debug flag
        if (self::checkDebug()) {
            return true;
        }

        // 5. Joomla error_reporting level
        if (self::checkErrorReporting()) {
            return true;
        }

        // No signal matched → assume production
        return false;
    }

    private static function checkConfigOverride(): bool
    {
        try {
            $app = Factory::getApplication();
            $params = $app->bootComponent('com_mirasai')->getComponentParametersByView('dashboard');

            if ($params && $params->get('environment_override') === 'staging') {
                return true;
            }
        } catch (\Throwable) {
            // Component not installed or params not available — skip
        }

        // Also check Joomla global config for a fallback
        try {
            $config = Factory::getApplication()->getConfig();
            if ($config->get('mirasai_environment_override') === 'staging') {
                return true;
            }
        } catch (\Throwable) {
            // ignore
        }

        return false;
    }

    private static function checkDomain(): bool
    {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        $host = strtolower($host);

        foreach (self::STAGING_DOMAIN_PATTERNS as $pattern) {
            if (str_contains($host, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private static function checkPrivateIp(): bool
    {
        $ip = $_SERVER['SERVER_ADDR'] ?? '';

        if ($ip === '') {
            return false;
        }

        // Check simple prefixes
        foreach (self::PRIVATE_IP_PREFIXES as $prefix) {
            if (str_starts_with($ip, $prefix)) {
                return true;
            }
        }

        // Check 172.16.0.0/12 range
        if (str_starts_with($ip, '172.')) {
            $parts = explode('.', $ip);
            if (isset($parts[1])) {
                $second = (int) $parts[1];
                if ($second >= 16 && $second <= 31) {
                    return true;
                }
            }
        }

        // IPv6 loopback
        if ($ip === '::1') {
            return true;
        }

        return false;
    }

    private static function checkDebug(): bool
    {
        try {
            return (bool) Factory::getApplication()->get('debug', false);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function checkErrorReporting(): bool
    {
        try {
            $level = Factory::getApplication()->get('error_reporting', '');

            return \in_array($level, ['maximum', 'development'], true);
        } catch (\Throwable) {
            return false;
        }
    }
}
