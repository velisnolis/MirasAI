<?php

declare(strict_types=1);

namespace Mirasai\Library\Mcp;

use Mirasai\Library\Sandbox\ElevationGrant;
use Mirasai\Library\Sandbox\ElevationService;
use Mirasai\Library\Sandbox\EnvironmentGuard;
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
        $environment = EnvironmentGuard::isStaging() ? 'staging' : 'production';

        $instructions = 'MirasAI is an MCP server for Joomla 5. It provides tools for content management, '
            . 'multilingual translation (including YOOtheme Builder layouts), site inspection, '
            . 'file operations, database queries, and PHP code execution in a sandboxed environment.'
            . "\n\n"
            . 'Getting started: Use system/info to discover the Joomla environment (version, extensions, '
            . 'languages, template). Use content/list to find articles and their translation status. '
            . 'Use db/schema to inspect table structures before writing queries with db/query.'
            . "\n\n"
            . 'Translation workflow:'
            . "\n"
            . '1. content/list — discover articles and their existing translations per language.'
            . "\n"
            . '2. content/read — read the source article. The response includes:'
            . "\n"
            . '   - introtext and fulltext for standard Joomla articles.'
            . "\n"
            . '   - yootheme_translatable_nodes (with replacement_key) for YOOtheme Builder articles.'
            . "\n"
            . '3. Translate the text yourself (the MCP server does NOT auto-translate).'
            . "\n"
            . '4. content/translate — create or update the translation:'
            . "\n"
            . '   - Standard articles: pass translated_title + translated_introtext (+ translated_fulltext if needed).'
            . "\n"
            . '   - YOOtheme articles: pass translated_title + yootheme_text_replacements '
            . '(use the replacement_key from step 2 as the key, translated text as the value).'
            . "\n"
            . '   The tool handles article creation, menu item, language associations, and asset permissions.'
            . "\n"
            . '5. content/audit-multilingual — verify completeness across all languages.'
            . "\n\n"
            . 'File operations: file/read and file/list work anywhere under the Joomla root. '
            . 'file/write, file/edit, and file/delete are restricted to the sandbox directory '
            . '(media/mirasai/sandbox/).'
            . "\n\n"
            . 'Sandbox: sandbox/execute-php runs PHP code with automatic DB transaction wrapping. '
            . 'On error, the transaction is rolled back. DDL statements (CREATE TABLE, ALTER TABLE) '
            . 'auto-commit and cannot be rolled back — do not mix DDL and DML in a single call. '
            . 'Use sandbox/status to check the sandbox state.'
            . "\n\n"
            . 'Current environment: ' . $environment . '. ';

        if ($environment === 'production') {
            $elevation = new ElevationService();
            $grant = $elevation->getActiveGrant();

            if ($grant !== null && $grant->isActive()) {
                $remaining = (int) ceil($grant->getRemainingSeconds() / 60);
                $scopes = implode(', ', $grant->scopes);
                $instructions .= "Elevated mode active. {$remaining} minutes remaining. "
                    . "Allowed tools: [{$scopes}]. All calls are being audited. "
                    . 'Use elevation/status to check elevation details.';
            } else {
                $instructions .= 'Destructive tools (file/write, file/edit, file/delete, sandbox/execute-php) '
                    . 'are BLOCKED on production. Ask the site administrator to activate elevation '
                    . 'in the Joomla admin panel (Components → MirasAI → Elevation). '
                    . 'Use elevation/status to check elevation state.';
            }
        } else {
            $instructions .= 'All tools are available on this staging environment.';
        }

        $instructions .= "\n\n"
            . 'System requirements: VPS, Docker, or dedicated hosting with shell_exec enabled.';

        return [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => 'MirasAI',
                'version' => '0.4.0',
            ],
            'instructions' => $instructions,
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

        // Environment guard: block destructive tools on production unless elevated
        $permissions = $tool->getPermissions();

        if (!empty($permissions['destructive']) && EnvironmentGuard::isProduction()) {
            $elevation = new ElevationService();

            if (!$elevation->isElevated($toolName)) {
                return [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => json_encode([
                                'error' => 'This tool requires elevation on production environments.',
                                'tool' => $toolName,
                                'environment' => 'production',
                                'action_required' => 'Ask the site administrator to activate elevation in the Joomla admin panel (Components → MirasAI → Elevation).',
                                'docs' => 'The administrator must select which tools to enable, set a duration, and acknowledge the risks.',
                            ], JSON_UNESCAPED_UNICODE),
                        ],
                    ],
                    'isError' => true,
                ];
            }

            // Elevation active — log before execution (result_summary = 'pending')
            $grant = $elevation->getActiveGrant();
            $argsSummary = $tool->getAuditSummary($arguments);
            $auditId = $elevation->logUsage($grant->id, $toolName, $argsSummary);

            try {
                $result = $tool->handle($arguments);
                $elevation->finalizeAuditEntry($auditId, isset($result['error']) ? 'error' : 'success');

                return $this->wrapResultWithElevation($result, $grant);
            } catch (\Throwable $e) {
                $elevation->finalizeAuditEntry($auditId, 'error');
                throw $e;
            }
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
     * Wrap a tool result with elevation metadata (_elevation key).
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function wrapResultWithElevation(array $result, ElevationGrant $grant): array
    {
        $result['_elevation'] = [
            'grant_id' => $grant->id,
            'remaining_minutes' => (int) ceil($grant->getRemainingSeconds() / 60),
            'scopes' => $grant->scopes,
        ];

        $isError = isset($result['error']);

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                ],
            ],
            'isError' => $isError,
        ];
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
