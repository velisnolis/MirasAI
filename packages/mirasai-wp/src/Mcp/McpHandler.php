<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Mcp;

use Mirasai\WordPress\Tool\AbstractTool;
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
            'instructions' => 'MirasAI WordPress host. Use system/info and system/diagnose to inspect the site. Use content/list and content/read for read-only content discovery. Writes and dangerous execution are not implemented in this MVP.',
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

        try {
            $result = $tool->handle($arguments);

            return $this->wrapToolResult($result, isset($result['error']));
        } catch (\Throwable $e) {
            return $this->wrapToolResult([
                'error' => $e->getMessage(),
                'type' => get_class($e),
            ], true);
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
