<?php

declare(strict_types=1);

namespace Mirasai\Component\Mirasai\Api\Controller;

use Joomla\CMS\MVC\Controller\ApiController;
use Mirasai\Library\Mcp\McpHandler;
use Mirasai\Library\Tool\ContentListTool;
use Mirasai\Library\Tool\ContentReadTool;
use Mirasai\Library\Tool\ContentTranslateTool;
use Mirasai\Library\Tool\ContentCheckLinksTool;
use Mirasai\Library\Tool\SystemInfoTool;
use Mirasai\Library\Tool\ToolRegistry;

class McpController extends ApiController
{
    protected $contentType = 'mcp';
    protected $default_view = 'mcp';

    /**
     * Handle POST requests — JSON-RPC over HTTP.
     * Overrides the parent completely to handle MCP protocol.
     */
    public function add()
    {
        $handler = $this->buildHandler();

        $input = file_get_contents('php://input');
        $request = json_decode($input ?: '', true);

        if (!$request || !isset($request['method'])) {
            header('Content-Type: application/json', true, 400);
            echo json_encode([
                'jsonrpc' => '2.0',
                'error' => ['code' => -32700, 'message' => 'Parse error'],
                'id' => null,
            ]);
            $this->app->close();

            return $this;
        }

        $response = $handler->handleRequest($request);

        if (empty($response)) {
            header('HTTP/1.1 204 No Content');
            $this->app->close();

            return $this;
        }

        header('Content-Type: application/json', true, 200);
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->app->close();

        return $this;
    }

    /**
     * Handle GET requests — SSE endpoint for MCP streaming.
     */
    public function displayList()
    {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        if (function_exists('apache_setenv')) {
            apache_setenv('no-gzip', '1');
        }

        while (ob_get_level()) {
            ob_end_clean();
        }

        $endpoint = '/api/v1/mirasai/mcp';
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

        $this->app->close();

        return $this;
    }

    private function buildHandler(): McpHandler
    {
        $registry = new ToolRegistry();
        $registry->register(new SystemInfoTool());
        $registry->register(new ContentListTool());
        $registry->register(new ContentReadTool());
        $registry->register(new ContentTranslateTool());
        $registry->register(new ContentCheckLinksTool());

        return new McpHandler($registry);
    }
}
