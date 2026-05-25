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
require_once $pluginSrc . '/Tool/TemplateSummaryTool.php';
require_once $pluginSrc . '/Tool/TemplateElementTypesTool.php';
require_once $pluginSrc . '/Tool/TemplateElementSchemaTool.php';
require_once $pluginSrc . '/Tool/TemplateSourceTypesTool.php';
require_once $pluginSrc . '/Tool/TemplateElementListTool.php';
require_once $pluginSrc . '/Tool/TemplateElementReadTool.php';
require_once $pluginSrc . '/Tool/TemplateElementSourceSupportTrait.php';
require_once $pluginSrc . '/Tool/TemplateElementSourceReadTool.php';
require_once $pluginSrc . '/Tool/TemplateElementSourcePreviewTool.php';
require_once $pluginSrc . '/Tool/AbstractTemplateElementWriteTool.php';
require_once $pluginSrc . '/Tool/TemplateElementSourceSetTool.php';
require_once $pluginSrc . '/Tool/TemplateElementSourceDeleteTool.php';
require_once $pluginSrc . '/Tool/TemplateElementAddTool.php';
require_once $pluginSrc . '/Tool/TemplateElementUpdatePropsTool.php';
require_once $pluginSrc . '/Tool/TemplateElementMoveTool.php';
require_once $pluginSrc . '/Tool/TemplateElementCloneTool.php';
require_once $pluginSrc . '/Tool/TemplateElementDeleteTool.php';
require_once $pluginSrc . '/Tool/TemplateReadTool.php';
require_once $pluginSrc . '/Tool/TemplateTranslateTool.php';

return new \Mirasai\Plugin\Mirasai\Yootheme\YooThemeToolProvider();
