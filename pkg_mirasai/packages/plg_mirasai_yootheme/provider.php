<?php
/**
 * Standalone provider bootstrap for plg_mirasai_yootheme.
 *
 * Loaded by ToolRegistry::scanFilesystemProviders() in standalone mode
 * (i.e. mcp-endpoint.php running without Joomla's plugin system).
 *
 * This file must:
 * 1. Require all plugin class files (the autoloader isn't available here).
 * 2. Return a ToolProviderInterface instance.
 */

declare(strict_types=1);

defined('_JEXEC') or define('_JEXEC', 1);

$pluginSrc = __DIR__ . '/src';

require_once $pluginSrc . '/YooThemeToolProvider.php';
require_once $pluginSrc . '/Tool/ThemeExtractToModulesTool.php';
require_once $pluginSrc . '/Tool/MenuMigrateThemeToModulesTool.php';
require_once $pluginSrc . '/Tool/TemplateListTool.php';
require_once $pluginSrc . '/Tool/TemplateReadTool.php';
require_once $pluginSrc . '/Tool/TemplateTranslateTool.php';

return new \Mirasai\Plugin\Mirasai\Yootheme\YooThemeToolProvider();
