<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class DbQueryTool extends AbstractTool
{
    private const DEFAULT_LIMIT = 500;
    private const MAX_LIMIT = 5000;
    private const MAX_RESPONSE_BYTES = 5 * 1024 * 1024;
    private const ALLOWED_QUERY_PATTERN = '/^\s*(SELECT|SHOW)\b/i';
    private const BLOCKED_PATTERNS = [
        '/;\s*\S+/s',
        '/\bINTO\s+OUTFILE\b/i',
        '/\bINTO\s+DUMPFILE\b/i',
        '/\bLOAD_FILE\s*\(/i',
        '/\bSLEEP\s*\(/i',
        '/\bBENCHMARK\s*\(/i',
        '/\bGET_LOCK\s*\(/i',
        '/\bRELEASE_LOCK\s*\(/i',
        '/\bFOR\s+UPDATE\b/i',
        '/\bLOCK\s+IN\s+SHARE\s+MODE\b/i',
        '/\bINTO\b/i',
        '/:=/',
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
        return 'Execute read-only SQL queries (SELECT, SHOW) through WordPress wpdb. '
            . 'Write operations and unsafe read-looking features are blocked. '
            . 'Use {prefix} or #__ for the WordPress table prefix. Default limit: 500 rows, max: 5000.';
    }

    /**
     * @return array<string, mixed>
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['sql'],
            'properties' => [
                'sql' => [
                    'type' => 'string',
                    'description' => 'SQL query to execute. Only SELECT and SHOW are allowed. Use {prefix} or #__ for the WordPress table prefix.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                    'description' => 'Maximum number of rows to return. Default 500, max 5000.',
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array
    {
        global $wpdb;

        $sql = isset($arguments['sql']) && is_string($arguments['sql']) ? trim($arguments['sql']) : '';
        $limit = isset($arguments['limit']) ? max(1, min(self::MAX_LIMIT, (int) $arguments['limit'])) : self::DEFAULT_LIMIT;

        if ($sql === '') {
            return [
                'error' => 'Missing required parameter: sql',
                'code' => 'invalid_arguments',
            ];
        }

        $validationError = $this->validateQuery($sql);
        if ($validationError !== null) {
            return [
                'error' => $validationError,
                'code' => 'blocked_query',
            ];
        }

        $sql = $this->replacePrefix($sql, (string) $wpdb->prefix);
        $sql = preg_replace('/\bLIMIT\s+\d+(\s*,\s*\d+)?\s*$/i', '', $sql) ?? $sql;
        $sql = rtrim($sql, "; \t\n\r");
        $isShow = preg_match('/^\s*SHOW\b/i', $sql) === 1;
        $querySql = $isShow ? $sql : $sql . ' LIMIT ' . ($limit + 1);

        $rows = $wpdb->get_results($querySql, ARRAY_A);

        if ($wpdb->last_error !== '') {
            return [
                'error' => 'Query execution failed: ' . $wpdb->last_error,
                'code' => 'query_failed',
            ];
        }

        if (!is_array($rows)) {
            $rows = [];
        }

        $truncated = !$isShow && count($rows) > $limit;
        if ($truncated) {
            $rows = array_slice($rows, 0, $limit);
        }

        $json = wp_json_encode($rows, JSON_UNESCAPED_UNICODE);
        if (is_string($json) && strlen($json) > self::MAX_RESPONSE_BYTES) {
            return [
                'error' => 'Response exceeds 5MB size limit. Use specific column names instead of SELECT *, or reduce the row limit.',
                'code' => 'response_too_large',
                'row_count' => count($rows),
                'estimated_size_mb' => round(strlen($json) / 1024 / 1024, 1),
            ];
        }

        return [
            'rows' => $rows,
            'row_count' => count($rows),
            'truncated' => $truncated,
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
        $cleaned = preg_replace('/--[^\n]*/', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/#[^\n]*/', '', $cleaned) ?? $cleaned;

        return trim($cleaned);
    }

    private function replacePrefix(string $sql, string $prefix): string
    {
        return str_replace(['#__', '{prefix}'], $prefix, $sql);
    }
}
