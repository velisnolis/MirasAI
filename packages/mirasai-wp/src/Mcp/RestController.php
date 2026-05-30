<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Mcp;

class RestController
{
    private McpHandler $handler;

    public function __construct(McpHandler $handler)
    {
        $this->handler = $handler;
    }

    public function registerRoutes(): void
    {
        register_rest_route('mirasai/v1', '/mcp', [
            'methods' => 'POST',
            'callback' => [$this, 'handle'],
            'permission_callback' => [$this, 'authorize'],
        ]);
    }

    /**
     * @param mixed $request
     * @return bool|\WP_Error
     */
    public function authorize($request)
    {
        if (is_user_logged_in()) {
            if (current_user_can('manage_options')) {
                return true;
            }

            return new \WP_Error(
                'mirasai_insufficient_capability',
                'The authenticated WordPress user must have manage_options capability.',
                ['status' => 403]
            );
        }

        $token = $this->header($request, 'x-mirasai-token');

        if ($token === '') {
            return new \WP_Error(
                'mirasai_missing_auth',
                'Authenticate with a WordPress Application Password or provide X-MirasAI-Token.',
                ['status' => 401]
            );
        }

        if (!$this->isValidToken($token)) {
            return new \WP_Error('mirasai_invalid_token', 'Invalid MirasAI token.', ['status' => 401]);
        }

        return true;
    }

    /**
     * @param mixed $request
     * @return \WP_REST_Response
     */
    public function handle($request): \WP_REST_Response
    {
        $payload = $request->get_json_params();

        if (!is_array($payload) || !isset($payload['method'])) {
            return new \WP_REST_Response([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32700,
                    'message' => 'Parse error',
                ],
                'id' => null,
            ], 400);
        }

        $response = $this->handler->handleRequest($payload);

        if ($response === null) {
            return new \WP_REST_Response(null, 204);
        }

        return new \WP_REST_Response($response, 200);
    }

    private function isValidToken(string $token): bool
    {
        foreach ($this->configuredTokens() as $configuredToken) {
            if (hash_equals($configuredToken, $token)) {
                return true;
            }
        }

        $optionHash = get_option('mirasai_wp_token_hash', '');
        if (is_string($optionHash) && $optionHash !== '' && wp_check_password($token, $optionHash)) {
            return true;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function configuredTokens(): array
    {
        $tokens = [];

        if (defined('MIRASAI_WP_TOKEN') && is_string(MIRASAI_WP_TOKEN) && MIRASAI_WP_TOKEN !== '') {
            $tokens[] = MIRASAI_WP_TOKEN;
        }

        $envToken = getenv('MIRASAI_WP_TOKEN');
        if (is_string($envToken) && $envToken !== '') {
            $tokens[] = $envToken;
        }

        return $tokens;
    }

    /**
     * @param mixed $request
     */
    private function header($request, string $name): string
    {
        $value = $request->get_header($name);

        return is_string($value) ? trim($value) : '';
    }
}
