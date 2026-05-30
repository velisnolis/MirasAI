<?php
/**
 * Standalone provider bootstrap for plg_mirasai_example.
 * Loaded by ToolRegistry::scanFilesystemProviders() in mcp-endpoint.php standalone mode.
 */

declare(strict_types=1);

defined('_JEXEC') or define('_JEXEC', 1);

require_once __DIR__ . '/src/ExampleToolProvider.php';
require_once __DIR__ . '/src/Tool/ExamplePingTool.php';

return new \Mirasai\Plugin\Mirasai\Example\ExampleToolProvider();
