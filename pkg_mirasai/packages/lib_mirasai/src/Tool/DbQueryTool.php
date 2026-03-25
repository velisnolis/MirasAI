<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

/**
 * db/query — Execute read-only SQL queries via the Joomla DB layer.
 *
 * Only SELECT and SHOW queries are allowed. Default limit: 500 rows, max: 5000.
 * Response size capped at 5MB to prevent OOM.
 */
class DbQueryTool extends AbstractTool
{
    private const DEFAULT_LIMIT = 500;
    private const MAX_LIMIT = 5000;
    private const MAX_RESPONSE_BYTES = 5 * 1024 * 1024; // 5MB

    /**
     * Patterns that indicate a write/modify operation.
     */
    private const WRITE_PATTERNS = [
        '/^\s*(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|TRUNCATE|REPLACE|RENAME|GRANT|REVOKE|LOCK|UNLOCK)\b/i',
    ];

    public function getName(): string
    {
        return 'db/query';
    }

    public function getDescription(): string
    {
        return 'Execute read-only SQL queries (SELECT, SHOW) via the Joomla database layer. '
            . 'Write operations are blocked. Default limit: 500 rows (max: 5000). '
            . 'Use #__ as the table prefix — it resolves automatically. '
            . 'Example: "SELECT id, title, language FROM #__content WHERE state = 1 LIMIT 10". '
            . 'Use db/schema first to discover table structure.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sql' => [
                    'type' => 'string',
                    'description' => 'SQL query to execute. Only SELECT and SHOW are allowed. Use #__ for Joomla table prefix.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of rows to return (default: 500, max: 5000)',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                ],
            ],
            'required' => ['sql'],
        ];
    }

    public function handle(array $arguments): array
    {
        $sql = trim($arguments['sql'] ?? '');
        $limit = min((int) ($arguments['limit'] ?? self::DEFAULT_LIMIT), self::MAX_LIMIT);

        if ($sql === '') {
            return ['error' => 'Missing required parameter: sql'];
        }

        // Security check: block write operations
        if ($this->isWriteQuery($sql)) {
            return ['error' => 'Only read-only queries (SELECT, SHOW) are allowed. Write operations are blocked.'];
        }

        try {
            // Replace Joomla table prefix
            $sql = str_replace('#__', $this->db->getPrefix(), $sql);

            // Strip any user-provided LIMIT clause
            $sql = preg_replace('/\bLIMIT\s+\d+(\s*,\s*\d+)?\s*$/i', '', $sql);
            $sql = rtrim($sql, "; \t\n\r");

            // SHOW statements don't support LIMIT — execute as-is
            $isShow = preg_match('/^\s*SHOW\b/i', $sql);

            if ($isShow) {
                $this->db->setQuery($sql);
            } else {
                // Execute with limit + 1 to detect truncation
                $this->db->setQuery($sql, 0, $limit + 1);
            }

            $rows = $this->db->loadAssocList();

            if ($rows === null) {
                $rows = [];
            }

            $truncated = !$isShow && \count($rows) > $limit;

            if ($truncated) {
                $rows = \array_slice($rows, 0, $limit);
            }

            // Check response size
            $json = json_encode($rows, JSON_UNESCAPED_UNICODE);

            if ($json !== false && \strlen($json) > self::MAX_RESPONSE_BYTES) {
                return [
                    'error' => 'Response exceeds 5MB size limit. Use specific column names instead of SELECT *, or reduce the row limit.',
                    'row_count' => \count($rows),
                    'estimated_size_mb' => round(\strlen($json) / 1024 / 1024, 1),
                ];
            }

            return [
                'rows' => $rows,
                'row_count' => \count($rows),
                'truncated' => $truncated,
            ];
        } catch (\Throwable $e) {
            return [
                'error' => 'Query execution failed: ' . $e->getMessage(),
            ];
        }
    }

    public function getPermissions(): array
    {
        return [
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ];
    }

    /**
     * Check if a SQL query is a write operation.
     */
    private function isWriteQuery(string $sql): bool
    {
        // Strip comments
        $cleaned = preg_replace('/\/\*.*?\*\//s', '', $sql) ?? $sql;
        $cleaned = preg_replace('/--[^\n]*/', '', $cleaned);
        $cleaned = trim($cleaned);

        foreach (self::WRITE_PATTERNS as $pattern) {
            if (preg_match($pattern, $cleaned)) {
                return true;
            }
        }

        return false;
    }
}
