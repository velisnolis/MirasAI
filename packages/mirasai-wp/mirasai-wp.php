<?php

/**
 * Plugin Name: MirasAI
 * Plugin URI: https://miras.pro
 * Description: MirasAI host endpoint for WordPress. Exposes a small MCP-compatible HTTP surface for the local MirasAI router.
 * Version: 0.6.2
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: Miras
 * Text Domain: mirasai
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('MIRASAI_WP_VERSION', '0.6.2');
define('MIRASAI_WP_CONTRACT_VERSION', '1');
define('MIRASAI_WP_PLUGIN_FILE', __FILE__);
define('MIRASAI_WP_PLUGIN_DIR', __DIR__);
define('MIRASAI_WP_UPDATE_FEED_URL', 'https://raw.githubusercontent.com/velisnolis/MirasAI/main/updates/mirasai-wp.json');

require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/ToolInterface.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/AbstractTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/WordPressTranslationHelper.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/AcfHelper.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/YoothemeWpHelper.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/YoothemeLayoutSummarizer.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/YoothemeLayoutProcessor.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/YoothemeElementNavigator.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/RuntimeSettings.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/SystemInfoTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/SystemDiagnoseTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/ContentListTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/ContentReadTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/ContentTranslateTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/ContentTranslateBatchTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/ContentCheckLinksTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/ContentAuditMultilingualTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/TaxonomyTermListTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/TaxonomyTermTranslateTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/DbSchemaTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/DbQueryTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/FilePathValidator.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/FileListTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/FileReadTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/SandboxStatusTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/SandboxExecutePhpTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/ElevationStatusTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/WordPressAbilityPolicy.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/WpAbilitiesListTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/WpAbilityCallTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/TemplateListTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/TemplateReadTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/TemplateSummaryTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/TemplateElementListTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/TemplateElementReadTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/TemplateElementTypesTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/TemplateElementSchemaTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/TemplateElementSourceSupportTrait.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/TemplateSourceTypesTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/TemplateElementSourceReadTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/AbstractTemplateElementWriteTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/TemplateElementSourcePreviewTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/TemplateElementSourceSetTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/TemplateElementSourceDeleteTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/TemplateElementAddTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/TemplateElementUpdatePropsTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/TemplateElementMoveTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/TemplateElementCloneTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/TemplateElementDeleteTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/TemplateTranslateTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/TemplateWidgetTranslateTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/AcfStatusTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/AcfFieldGroupsListTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/AcfFieldGroupReadTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/AcfPostFieldsReadTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/AcfCptListTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/AcfTaxonomyListTool.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Tool/ToolRegistry.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Mcp/McpHandler.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Mcp/RestController.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Admin/DashboardPage.php';
require_once MIRASAI_WP_PLUGIN_DIR . '/src/Updater/GitHubFeedUpdater.php';

\Mirasai\WordPress\Admin\DashboardPage::register();
\Mirasai\WordPress\Updater\GitHubFeedUpdater::register();

add_action('rest_api_init', static function (): void {
    $registry = \Mirasai\WordPress\Tool\ToolRegistry::buildDefault();
    $handler = new \Mirasai\WordPress\Mcp\McpHandler($registry);
    $controller = new \Mirasai\WordPress\Mcp\RestController($handler);
    $controller->registerRoutes();
});
