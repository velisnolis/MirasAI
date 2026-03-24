<?php

/**
 * MirasAI — Standalone MCP endpoint for Joomla.
 *
 * Place this file in the Joomla root directory.
 * Access via: https://yoursite.com/mcp-endpoint.php
 *
 * Authenticates via X-Joomla-Token header using the standard Joomla API token system.
 */

declare(strict_types=1);

define('_JEXEC', 1);
define('JPATH_BASE', __DIR__);

require __DIR__ . '/includes/defines.php';
require __DIR__ . '/includes/framework.php';

// Boot minimum Joomla
$container = \Joomla\CMS\Factory::getContainer();
$container->alias(\Joomla\Session\SessionInterface::class, 'session.web.site');
$app = $container->get(\Joomla\CMS\Application\SiteApplication::class);
\Joomla\CMS\Factory::$application = $app;

// Load the MirasAI library
require_once JPATH_LIBRARIES . '/mirasai/src/Tool/ToolInterface.php';
require_once JPATH_LIBRARIES . '/mirasai/src/Tool/AbstractTool.php';
require_once JPATH_LIBRARIES . '/mirasai/src/Tool/ToolRegistry.php';
require_once JPATH_LIBRARIES . '/mirasai/src/Tool/SystemInfoTool.php';
require_once JPATH_LIBRARIES . '/mirasai/src/Tool/ContentListTool.php';
require_once JPATH_LIBRARIES . '/mirasai/src/Tool/ContentReadTool.php';
require_once JPATH_LIBRARIES . '/mirasai/src/Tool/ContentTranslateTool.php';
require_once JPATH_LIBRARIES . '/mirasai/src/Tool/ContentTranslateBatchTool.php';
require_once JPATH_LIBRARIES . '/mirasai/src/Tool/ContentCheckLinksTool.php';
require_once JPATH_LIBRARIES . '/mirasai/src/Tool/ContentAuditMultilingualTool.php';
require_once JPATH_LIBRARIES . '/mirasai/src/Tool/CategoryTranslateTool.php';
require_once JPATH_LIBRARIES . '/mirasai/src/Tool/SiteSetupLanguageSwitcherTool.php';
require_once JPATH_LIBRARIES . '/mirasai/src/Tool/ThemeExtractToModulesTool.php';
require_once JPATH_LIBRARIES . '/mirasai/src/Tool/MenuMigrateThemeToModulesTool.php';
require_once JPATH_LIBRARIES . '/mirasai/src/Tool/TemplateListTool.php';
require_once JPATH_LIBRARIES . '/mirasai/src/Tool/TemplateReadTool.php';
require_once JPATH_LIBRARIES . '/mirasai/src/Tool/TemplateTranslateTool.php';
require_once JPATH_LIBRARIES . '/mirasai/src/Sandbox/EnvironmentGuard.php';
require_once JPATH_LIBRARIES . '/mirasai/src/Sandbox/SandboxLoader.php';
require_once JPATH_LIBRARIES . '/mirasai/src/Sandbox/PathValidator.php';
require_once JPATH_LIBRARIES . '/mirasai/src/Sandbox/ElevationGrant.php';
require_once JPATH_LIBRARIES . '/mirasai/src/Sandbox/ElevationService.php';
require_once JPATH_LIBRARIES . '/mirasai/src/Tool/SandboxStatusTool.php';
require_once JPATH_LIBRARIES . '/mirasai/src/Tool/FileReadTool.php';
require_once JPATH_LIBRARIES . '/mirasai/src/Tool/FileWriteTool.php';
require_once JPATH_LIBRARIES . '/mirasai/src/Tool/FileEditTool.php';
require_once JPATH_LIBRARIES . '/mirasai/src/Tool/FileDeleteTool.php';
require_once JPATH_LIBRARIES . '/mirasai/src/Tool/FileListTool.php';
require_once JPATH_LIBRARIES . '/mirasai/src/Tool/SandboxExecutePhpTool.php';
require_once JPATH_LIBRARIES . '/mirasai/src/Tool/DbQueryTool.php';
require_once JPATH_LIBRARIES . '/mirasai/src/Tool/DbSchemaTool.php';
require_once JPATH_LIBRARIES . '/mirasai/src/Tool/ElevationStatusTool.php';
require_once JPATH_LIBRARIES . '/mirasai/src/Mcp/JoomlaApiTokenAuthenticator.php';
require_once JPATH_LIBRARIES . '/mirasai/src/Mcp/McpHandler.php';

use Mirasai\Library\Mcp\JoomlaApiTokenAuthenticator;
use Mirasai\Library\Mcp\McpHandler;
use Mirasai\Library\Tool\ToolRegistry;

// --- Authentication ---
$token = $_SERVER['HTTP_X_JOOMLA_TOKEN'] ?? '';

if (!$token) {
    sendJson(['jsonrpc' => '2.0', 'error' => ['code' => -32000, 'message' => 'Missing X-Joomla-Token header'], 'id' => null], 401);
}

if (!JoomlaApiTokenAuthenticator::authenticate($token)) {
    sendJson(['jsonrpc' => '2.0', 'error' => ['code' => -32000, 'message' => 'Invalid API token'], 'id' => null], 401);
}

// --- Build handler ---
$handler = new McpHandler(ToolRegistry::buildDefault());

// --- Handle request ---
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    // SSE endpoint
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

    while (ob_get_level()) {
        ob_end_clean();
    }

    $endpoint = dirname($_SERVER['SCRIPT_NAME']) . '/mcp-endpoint.php';
    echo "event: endpoint\ndata: {$endpoint}\n\n";
    flush();

    $timeout = 300;
    $start = time();

    while ((time() - $start) < $timeout) {
        if (connection_aborted()) {
            break;
        }

        echo ": heartbeat\n\n";
        flush();
        sleep(15);
    }

    exit;
}

if ($method === 'POST') {
    $input = file_get_contents('php://input');
    $request = json_decode($input ?: '', true);

    if (!$request || !isset($request['method'])) {
        sendJson([
            'jsonrpc' => '2.0',
            'error' => ['code' => -32700, 'message' => 'Parse error'],
            'id' => null,
        ], 400);
    }

    $response = $handler->handleRequest($request);

    if (empty($response)) {
        http_response_code(204);
        exit;
    }

    sendJson($response);
}

sendJson(['jsonrpc' => '2.0', 'error' => ['code' => -32000, 'message' => 'Method not allowed'], 'id' => null], 405);

function sendJson(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
