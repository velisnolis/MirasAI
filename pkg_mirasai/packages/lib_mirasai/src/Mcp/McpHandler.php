<?php

declare(strict_types=1);

namespace Mirasai\Library\Mcp;

use Mirasai\Library\Tool\ToolRegistry;

class McpHandler
{
    private ToolRegistry $registry;

    public function __construct(ToolRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Handle a JSON-RPC request and return the response.
     *
     * @param  array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function handleRequest(array $request): array
    {
        $method = $request['method'] ?? '';
        $params = $request['params'] ?? [];
        $id = $request['id'] ?? null;

        $result = match ($method) {
            'initialize' => $this->handleInitialize($params),
            'notifications/initialized' => null,
            'tools/list' => $this->handleToolsList(),
            'tools/call' => $this->handleToolsCall($params),
            'ping' => ['status' => 'ok'],
            default => $this->errorResponse(-32601, "Method not found: {$method}"),
        };

        if ($result === null) {
            return [];
        }

        if (isset($result['error'])) {
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
    private function handleInitialize(array $params): array
    {
        return [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => 'MirasAI',
                'version' => '0.1.0',
            ],
            'instructions' => 'MirasAI is an MCP server for Joomla. It provides tools for content translation (including YOOtheme Builder layouts), content management, and system inspection. Use system/info to discover the Joomla environment. Use content/list to find articles and their translation status. Use content/read to inspect article content and YOOtheme layouts. Use content/translate to create translations.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function handleToolsList(): array
    {
        return [
            'tools' => $this->registry->toMcpToolsList(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function handleToolsCall(array $params): array
    {
        $toolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        $tool = $this->registry->get($toolName);

        if (!$tool) {
            return $this->errorResponse(-32602, "Unknown tool: {$toolName}");
        }

        try {
            $result = $tool->handle($arguments);

            // Check if the tool returned an error
            if (isset($result['error'])) {
                return [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                        ],
                    ],
                    'isError' => true,
                ];
            }

            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                    ],
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode([
                            'error' => $e->getMessage(),
                            'type' => get_class($e),
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                ],
                'isError' => true,
            ];
        }
    }

    /**
     * @return array{error: array{code: int, message: string}}
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
