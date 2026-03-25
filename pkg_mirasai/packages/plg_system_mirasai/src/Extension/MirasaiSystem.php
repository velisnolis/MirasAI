<?php

declare(strict_types=1);

namespace Mirasai\Plugin\System\Mirasai\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\SubscriberInterface;
use Mirasai\Library\Mcp\JoomlaApiTokenAuthenticator;
use Mirasai\Library\Mcp\McpHandler;
use Mirasai\Library\Sandbox\SandboxLoader;
use Mirasai\Library\Tool\ToolRegistry;

final class MirasaiSystem extends CMSPlugin implements SubscriberInterface
{
    private const MCP_PATH = '/api/v1/mirasai/mcp';

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterInitialise' => 'onAfterInitialise',
        ];
    }

    public function onAfterInitialise(): void
    {
        $app = $this->getApplication();

        // --- Sandbox boot (runs on ALL request types) ---
        $this->bootSandbox($app);

        // --- Safe mode clear via URL param (requires admin session + CSRF token) ---
        $this->handleSafeModeClear($app);

        // --- MCP handling (API requests only) ---
        if (!$app instanceof \Joomla\CMS\Application\ApiApplication) {
            return;
        }

        $uri = Uri::getInstance();
        $path = $uri->getPath();

        // Normalize path — remove base path prefix
        $base = Uri::base(true);
        if ($base && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        if ($path !== self::MCP_PATH) {
            return;
        }

        // Authenticate via Joomla API token
        $token = $app->getInput()->server->get('HTTP_X_JOOMLA_TOKEN', '', 'STRING');

        if (!$token) {
            $this->sendJson(['error' => 'Missing X-Joomla-Token header'], 401);

            return;
        }

        // Validate token
        $user = $this->authenticateToken($token);

        if (!$user) {
            $this->sendJson(['error' => 'Invalid API token'], 401);

            return;
        }

        $method = $app->getInput()->getMethod();

        if ($method === 'GET') {
            $this->handleSse();
        } elseif ($method === 'POST') {
            $this->handleJsonRpc();
        } else {
            $this->sendJson(['error' => 'Method not allowed'], 405);
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

    /**
     * @return \Joomla\CMS\User\User|null
     */
    private function authenticateToken(string $token): ?\Joomla\CMS\User\User
    {
        return JoomlaApiTokenAuthenticator::authenticate($token);
    }

    private function buildHandler(): McpHandler
    {
        // Load all enabled plugins in the 'mirasai' group so they can subscribe
        // to the onMirasaiCollectTools event fired by ToolRegistry::collectProviders().
        PluginHelper::importPlugin('mirasai');

        return new McpHandler(ToolRegistry::buildDefault());
    }

    /**
     * Boot the sandbox loader early in the Joomla lifecycle.
     *
     * This runs on every request type (frontend, admin, API) to ensure
     * crash detection works regardless of how the site is accessed.
     */
    private function bootSandbox($app): void
    {
        try {
            $loader = new SandboxLoader();
            $loader->boot();

            // Store the loader instance so sandbox/status tool can access it
            $app->getInput()->set('_mirasai_sandbox_loader', serialize([
                'state' => $loader->getState(),
                'loaded_files' => $loader->getLoadedFiles(),
                'crashed_files' => $loader->getCrashedFiles(),
            ]));
        } catch (\Throwable) {
            // Sandbox boot must never crash the site
        }
    }

    /**
     * Handle ?mirasai_safe_mode=clear URL param.
     *
     * Requires: active admin session with core.admin + valid CSRF token.
     */
    private function handleSafeModeClear($app): void
    {
        $input = $app->getInput();

        if ($input->get('mirasai_safe_mode') !== 'clear') {
            return;
        }

        try {
            // Require valid CSRF token
            if (!Session::checkToken('get')) {
                return;
            }

            // Require admin session with core.admin
            $user = $app->getIdentity();

            if (!$user || $user->guest || !$user->authorise('core.admin')) {
                return;
            }

            $loader = new SandboxLoader();
            $loader->clearSafeMode();
        } catch (\Throwable) {
            // Silently ignore errors
        }
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
