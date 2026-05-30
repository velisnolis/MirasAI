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
    private const ALLOWED_QUERY_PATTERN = '/^\s*(SELECT|SHOW)\b/i';

    /**
     * Read-looking SQL features that are still unsafe or non-observational.
     */
    private const BLOCKED_PATTERNS = [
        '/;\s*\S+/s',                    // multiple statements
        '/\bINTO\s+OUTFILE\b/i',
        '/\bINTO\s+DUMPFILE\b/i',
        '/\bLOAD_FILE\s*\(/i',
        '/\bSLEEP\s*\(/i',
        '/\bBENCHMARK\s*\(/i',
        '/\bGET_LOCK\s*\(/i',
        '/\bRELEASE_LOCK\s*\(/i',
        '/\bFOR\s+UPDATE\b/i',
        '/\bLOCK\s+IN\s+SHARE\s+MODE\b/i',
        '/\bINTO\b(?!\s*@)/i',          // allow SELECT ... INTO @var? No: block everything except SHOW handled above
        '/\bSET\s+@/i',
        '/\bPREPARE\b/i',
        '/\bEXECUTE\b/i',
        '/\bDEALLOCATE\b/i',
        '/\bHANDLER\b/i',
        '/\bDO\b\s+/i',
        '/\bCALL\b\s+/i',
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

        $validationError = $this->validateQuery($sql);

        if ($validationError !== null) {
            return ['error' => $validationError];
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

    private function validateQuery(string $sql): ?string
    {
        $cleaned = $this->stripSqlComments($sql);
        $trimmed = trim($cleaned);

        if ($trimmed === '') {
            return 'Query is empty after removing comments.';
        }

        if (!preg_match(self::ALLOWED_QUERY_PATTERN, $trimmed)) {
            return 'Only single SELECT or SHOW queries are allowed.';
        }

        foreach (self::BLOCKED_PATTERNS as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                return 'This query uses a blocked SQL feature. Only observational SELECT/SHOW queries are allowed.';
            }
        }

        return null;
    }

    private function stripSqlComments(string $sql): string
    {
        $cleaned = preg_replace('/\/\*.*?\*\//s', '', $sql) ?? $sql;
        $cleaned = preg_replace('/--[^\n]*/', '', $cleaned);
        $cleaned = preg_replace('/#[^\n]*/', '', $cleaned) ?? $cleaned;

        return trim($cleaned);
    }
}
