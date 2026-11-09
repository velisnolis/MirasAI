<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Mcp;

use Mirasai\WordPress\Tool\AbstractTool;
use Mirasai\WordPress\Tool\RuntimeSettings;
use Mirasai\WordPress\Tool\ToolArgumentValidator;
use Mirasai\WordPress\Tool\ToolRegistry;

class McpHandler
{
    private ToolRegistry $registry;

    public function __construct(ToolRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>|null
     */
    public function handleRequest(array $request): ?array
    {
        $method = is_string($request['method'] ?? null) ? (string) $request['method'] : '';
        $params = is_array($request['params'] ?? null) ? (array) $request['params'] : [];
        $id = $request['id'] ?? null;

        $result = match ($method) {
            'initialize' => $this->handleInitialize(),
            'notifications/initialized' => null,
            'tools/list' => $this->handleToolsList($params),
            'tools/call' => $this->handleToolsCall($params),
            'ping' => ['status' => 'ok'],
            default => $this->errorResponse(-32601, "Method not found: {$method}"),
        };

        if ($result === null) {
            return null;
        }

        if (isset($result['error']) && is_array($result['error'])) {
            return [
                'jsonrpc' => '2.0',
                'error' => $result['error'],
                'id' => $id,
            ];
        }

        return [
            'jsonrpc' => '2.0',
            'result' => $result,
            'id' => $id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function handleInitialize(): array
    {
        return [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [
                'tools' => [
                    'listChanged' => false,
                ],
            ],
            'serverInfo' => [
                'name' => 'MirasAI',
                'version' => MIRASAI_WP_VERSION,
                'host_platform' => 'wordpress',
                'host_contract_version' => MIRASAI_WP_CONTRACT_VERSION,
            ],
            'instructions' => \Mirasai\WordPress\Tool\AgentPlaybook::initializeInstructions(),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function handleToolsList(array $params): array
    {
        $surface = isset($params['surface']) && is_string($params['surface'])
            ? (string) $params['surface']
            : null;

        return [
            'tools' => $this->registry->toMcpToolsList($surface),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function handleToolsCall(array $params): array
    {
        $toolName = isset($params['name']) && is_string($params['name']) ? (string) $params['name'] : '';
        $arguments = $params['arguments'] ?? [];

        if (!is_array($arguments)) {
            return $this->errorResponse(-32602, 'Tool arguments must be an object.');
        }

        $tool = $this->registry->get($toolName);
        if ($tool === null) {
            return $this->errorResponse(-32602, "Unknown tool: {$toolName}");
        }

        // Not a tool argument: the handler checks it below.

        $previewToken = $arguments[PreviewToken::ARGUMENT] ?? null;

        unset($arguments[PreviewToken::ARGUMENT]);


        $rejection = ToolArgumentValidator::validate($toolName, $tool->getInputSchema(), $arguments);

        if ($rejection !== null) {
            return $this->wrapToolResult($rejection, true);
        }

        $permissions = AbstractTool::normalizePermissions($tool->getPermissions());

        if ($permissions['risk_level'] === AbstractTool::RISK_GUARDED_WRITE
            && ($arguments['dry_run'] ?? null) !== true
            && ($arguments['confirm_guarded_write'] ?? null) !== true
        ) {
            return $this->wrapToolResult([
                'error' => 'This guarded_write tool requires explicit confirmation before applying changes.',
                'code' => 'guarded_write_confirmation_required',
                'tool' => $toolName,
                'risk_level' => $permissions['risk_level'],
                'workflow_hint' => AbstractTool::workflowHintForRiskLevel($permissions['risk_level']),
                'action_required' => 'Run a dry_run or preview first, then retry the exact write with confirm_guarded_write=true and a fresh if_match when the tool supports ETags.',
            ], true);
        }

        $isGuarded = $permissions['risk_level'] === AbstractTool::RISK_GUARDED_WRITE;

        if ($isGuarded && ($arguments['dry_run'] ?? null) !== true && is_string($previewToken)
            && !hash_equals(PreviewToken::for($toolName, $arguments), $previewToken)
        ) {
            return $this->wrapToolResult([
                'error' => 'These arguments are not the ones the dry run previewed. Run the dry run again with the arguments you mean to write.',
                'code' => 'preview_mismatch',
                'tool' => $toolName,
            ], true);
        }

        if ($permissions['risk_level'] === AbstractTool::RISK_DANGEROUS_EXEC
            && !RuntimeSettings::isDangerousExecEnabled()
        ) {
            return $this->wrapToolResult([
                'error' => 'This dangerous_exec tool requires dangerous execution controls to be enabled for the current domain.',
                'code' => 'dangerous_exec_not_enabled',
                'tool' => $toolName,
                'risk_level' => $permissions['risk_level'],
                'workflow_hint' => AbstractTool::workflowHintForRiskLevel($permissions['risk_level']),
                'dangerous_exec' => RuntimeSettings::dangerousExecStatus(),
            ], true);
        }

        $headers = self::requestHeaders();

        if ($permissions['risk_level'] !== AbstractTool::RISK_READ) {
            self::attributeToClient($headers);
        }

        try {
            $result = $tool->handle($arguments);
            self::recordWrite($toolName, $permissions['risk_level'], $arguments, $result, $headers);

            if ($isGuarded && ($arguments['dry_run'] ?? null) === true && !isset($result['error'])) {
                $result[PreviewToken::ARGUMENT] = PreviewToken::for($toolName, $arguments);
            }

            return $this->wrapToolResult($result, isset($result['error']));
        } catch (\Throwable $e) {
            return $this->wrapToolResult([
                'error' => $e->getMessage(),
                'type' => get_class($e),
            ], true);
        }
    }

    /** @return array<string, string> */
    private static function requestHeaders(): array
    {
        return [
            'x-mcp-client' => isset($_SERVER['HTTP_X_MCP_CLIENT']) ? (string) $_SERVER['HTTP_X_MCP_CLIENT'] : '',
            'user-agent' => isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '',
        ];
    }

    /**
     * Simple History marks an event as AI-initiated when it recognises the client. Every MCP write is
     * one, so name the client for anything it logs during this request, MirasAI's own entry included.
     *
     * @param array<string, string> $headers
     */
    private static function attributeToClient(array $headers): void
    {
        if (!function_exists('add_filter')) {
            return;
        }

        $client = McpActionRecord::client($headers);

        add_filter('simple_history/ai_initiator_origin', static function ($origin) use ($client) {
            return $origin ?? ['agent_name' => $client, 'detected_via' => 'header', 'application' => 'MirasAI'];
        });
    }

    /**
     * One Simple History entry per MCP write, when Simple History is active. Logging never fails the write.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $result
     * @param array<string, string> $headers
     */
    private static function recordWrite(string $tool, string $riskLevel, array $arguments, array $result, array $headers): void
    {
        if (!function_exists('has_action') || !has_action('simple_history_log')) {
            return;
        }

        $record = McpActionRecord::build($tool, $riskLevel, $arguments, $result, $headers);

        if ($record === null) {
            return;
        }

        try {
            do_action('simple_history_log', 'MirasAI ran {mirasai_tool} on {mirasai_target} for {mirasai_client}', [
                'mirasai_tool' => $record['tool'],
                'mirasai_risk_level' => $record['risk_level'],
                'mirasai_target' => $record['target'],
                'mirasai_client' => $record['client'],
                'mirasai_old_etag' => $record['old_etag'],
                'mirasai_new_etag' => $record['new_etag'],
            ], 'info');
        } catch (\Throwable) {
            // The write already happened; a logging failure must not turn it into an error.
        }
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function wrapToolResult(array $result, bool $isError = false): array
    {
        $response = [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $this->encodeToolResultText($result),
                ],
            ],
            'structuredContent' => $result,
        ];

        if ($isError) {
            $response['isError'] = true;
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function encodeToolResultText(array $result): string
    {
        $options = JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE;
        $encoded = wp_json_encode($result, $options);

        if (is_string($encoded)) {
            return $encoded;
        }

        $encoded = json_encode($this->normalizeForJson($result), $options);

        if (is_string($encoded)) {
            return $encoded;
        }

        return '{"error":"Unable to encode tool result text.","code":"json_encoding_failed"}';
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function normalizeForJson($value)
    {
        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeForJson($item);
            }

            return $normalized;
        }

        if (is_string($value)) {
            if (function_exists('wp_check_invalid_utf8')) {
                $value = wp_check_invalid_utf8($value, true);
            }

            $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);

            return is_string($cleaned) ? $cleaned : '';
        }

        if (is_float($value) && !is_finite($value)) {
            return null;
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return (string) $value;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function errorResponse(int $code, string $message): array
    {
        return [
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }
}
