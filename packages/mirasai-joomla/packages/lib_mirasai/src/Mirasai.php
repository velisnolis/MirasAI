<?php

declare(strict_types=1);

namespace Mirasai\Library;

/**
 * Central constants for the MirasAI library.
 *
 * Every consumer (McpHandler, SystemInfoTool, dashboard HtmlView) should
 * reference Mirasai::VERSION instead of hardcoding the version string.
 */
final class Mirasai
{
    public const VERSION = '0.8.1';
    public const CONTRACT_VERSION = '1';

    private function __construct() {}
}
