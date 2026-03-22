<?php

declare(strict_types=1);

namespace Mirasai\Plugin\WebServices\Mirasai\Extension;

use Joomla\CMS\Event\Application\BeforeApiRouteEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\SubscriberInterface;
use Mirasai\Library\Mcp\JoomlaApiTokenAuthenticator;
use Mirasai\Library\Mcp\McpHandler;
use Mirasai\Library\Tool\CategoryTranslateTool;
use Mirasai\Library\Tool\ContentAuditMultilingualTool;
use Mirasai\Library\Tool\ContentCheckLinksTool;
use Mirasai\Library\Tool\ContentListTool;
use Mirasai\Library\Tool\MenuMigrateThemeToModulesTool;
use Mirasai\Library\Tool\SiteSetupLanguageSwitcherTool;
use Mirasai\Library\Tool\TemplateListTool;
use Mirasai\Library\Tool\TemplateReadTool;
use Mirasai\Library\Tool\TemplateTranslateTool;
use Mirasai\Library\Tool\ThemeExtractToModulesTool;
use Mirasai\Library\Tool\ContentReadTool;
use Mirasai\Library\Tool\ContentTranslateBatchTool;
use Mirasai\Library\Tool\ContentTranslateTool;
use Mirasai\Library\Tool\SystemInfoTool;
use Mirasai\Library\Tool\ToolRegistry;

final class MirasaiWebservices extends CMSPlugin implements SubscriberInterface
{
    private const MCP_PATH = '/v1/mirasai/mcp';

    public static function getSubscribedEvents(): array
    {
        return [
            'onBeforeApiRoute' => 'onBeforeApiRoute',
        ];
    }

    public function onBeforeApiRoute(BeforeApiRouteEvent $event): void
    {
        $uri = Uri::getInstance();
        $path = $uri->getPath();

        $base = Uri::base(true);

        if ($base && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        if ($path !== self::MCP_PATH) {
            return;
        }

        // Authenticate via Joomla API token
        $app = $this->getApplication();
        $token = $app->getInput()->server->get('HTTP_X_JOOMLA_TOKEN', '', 'STRING');

        if (!$token) {
            $this->sendJson(['jsonrpc' => '2.0', 'error' => ['code' => -32000, 'message' => 'Missing X-Joomla-Token header'], 'id' => null], 401);

            return;
        }

        $user = $this->authenticateToken($token);

        if (!$user) {
            $this->sendJson(['jsonrpc' => '2.0', 'error' => ['code' => -32000, 'message' => 'Invalid API token'], 'id' => null], 401);

            return;
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method === 'POST') {
            $this->handleJsonRpc();
        } elseif ($method === 'GET') {
            $this->handleSse();
        } else {
            $this->sendJson(['jsonrpc' => '2.0', 'error' => ['code' => -32000, 'message' => 'Method not allowed'], 'id' => null], 405);
        }
    }

    private function handleJsonRpc(): void
    {
        $input = file_get_contents('php://input');
        $request = json_decode($input ?: '', true);

        if (!$request || !isset($request['method'])) {
            $this->sendJson([
                'jsonrpc' => '2.0',
                'error' => ['code' => -32700, 'message' => 'Parse error'],
                'id' => null,
            ], 400);

            return;
        }

        $handler = $this->buildHandler();
        $response = $handler->handleRequest($request);

        if (empty($response)) {
            http_response_code(204);
            $this->getApplication()->close();

            return;
        }

        $this->sendJson($response);
    }

    private function handleSse(): void
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

        echo "event: endpoint\ndata: " . self::MCP_PATH . "\n\n";
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

        $this->getApplication()->close();
    }

    private function authenticateToken(string $token): ?\Joomla\CMS\User\User
    {
        return JoomlaApiTokenAuthenticator::authenticate($token);
    }

    private function buildHandler(): McpHandler
    {
        $registry = new ToolRegistry();
        $registry->register(new SystemInfoTool());
        $registry->register(new ContentListTool());
        $registry->register(new ContentReadTool());
        $registry->register(new ContentTranslateTool());
        $registry->register(new ContentTranslateBatchTool());
        $registry->register(new ContentCheckLinksTool());
        $registry->register(new ContentAuditMultilingualTool());
        $registry->register(new CategoryTranslateTool());
        $registry->register(new SiteSetupLanguageSwitcherTool());
        $registry->register(new ThemeExtractToModulesTool());
        $registry->register(new MenuMigrateThemeToModulesTool());
        $registry->register(new TemplateListTool());
        $registry->register(new TemplateReadTool());
        $registry->register(new TemplateTranslateTool());

        return new McpHandler($registry);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function sendJson(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->getApplication()->close();
    }
}
