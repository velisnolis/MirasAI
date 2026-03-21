<?php

declare(strict_types=1);

namespace Mirasai\Plugin\System\Mirasai\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\SubscriberInterface;
use Mirasai\Library\Mcp\McpHandler;
use Mirasai\Library\Tool\ContentListTool;
use Mirasai\Library\Tool\ContentReadTool;
use Mirasai\Library\Tool\ContentTranslateTool;
use Mirasai\Library\Tool\ContentCheckLinksTool;
use Mirasai\Library\Tool\SystemInfoTool;
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
        $hashedToken = base64_encode(hash('sha256', $token, true));
        $db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);

        $query = $db->getQuery(true)
            ->select('user_id')
            ->from($db->quoteName('#__user_profiles'))
            ->where($db->quoteName('profile_key') . ' = ' . $db->quote('joomlatoken.token'))
            ->where($db->quoteName('profile_value') . ' = :token')
            ->bind(':token', $hashedToken);

        $userId = $db->setQuery($query)->loadResult();

        if (!$userId) {
            return null;
        }

        // Verify the user has token enabled
        $query = $db->getQuery(true)
            ->select('profile_value')
            ->from($db->quoteName('#__user_profiles'))
            ->where($db->quoteName('user_id') . ' = :uid')
            ->where($db->quoteName('profile_key') . ' = ' . $db->quote('joomlatoken.enabled'))
            ->bind(':uid', $userId, \Joomla\Database\ParameterType::INTEGER);

        $enabled = $db->setQuery($query)->loadResult();

        if ((int) $enabled !== 1) {
            return null;
        }

        return \Joomla\CMS\Factory::getContainer()
            ->get(\Joomla\CMS\User\UserFactoryInterface::class)
            ->loadUserById((int) $userId);
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
