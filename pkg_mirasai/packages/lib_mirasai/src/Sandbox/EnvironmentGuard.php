<?php

declare(strict_types=1);

namespace Mirasai\Library\Sandbox;

use Joomla\CMS\Factory;

/**
 * Detect whether the current Joomla site is a staging environment
 * or a production site.
 *
 * Fail-closed: production is the default. Staging must be configured
 * explicitly via component params, Joomla global config, or MIRASAI_ENV.
 */
class EnvironmentGuard
{
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
        $mode = self::getConfiguredMode();

        if ($mode === 'staging') {
            return true;
        }

        if ($mode === 'production') {
            return false;
        }

        // Unknown / unset modes default to production.
        return false;
    }

    private static function getConfiguredMode(): ?string
    {
        try {
            $app = Factory::getApplication();
            $params = $app->bootComponent('com_mirasai')->getComponentParametersByView('dashboard');

            if ($params) {
                $value = strtolower(trim((string) $params->get('environment_override', '')));

                if (\in_array($value, ['production', 'staging'], true)) {
                    return $value;
                }
            }
        } catch (\Throwable) {
            // Component not installed or params not available — skip
        }

        try {
            $config = Factory::getApplication()->getConfig();
            $value = strtolower(trim((string) $config->get('mirasai_environment_override', '')));

            if (\in_array($value, ['production', 'staging'], true)) {
                return $value;
            }
        } catch (\Throwable) {
            // ignore
        }

        $env = strtolower(trim((string) ($_SERVER['MIRASAI_ENV'] ?? getenv('MIRASAI_ENV') ?: '')));

        if (\in_array($env, ['production', 'staging'], true)) {
            return $env;
        }

        return null;
    }
}
