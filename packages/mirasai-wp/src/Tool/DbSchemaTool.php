<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class DbSchemaTool extends AbstractTool
{
    public function getName(): string
    {
        return 'db/schema';
    }

    public function getDescription(): string
    {
        return 'Inspect WordPress database table structure. Returns columns, indexes, and approximate table stats. Use {prefix} or #__ for the WordPress table prefix.';
    }

    /**
     * @return array<string, mixed>
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['table'],
            'properties' => [
                'table' => [
                    'type' => 'string',
                    'description' => 'Table name. Use {prefix} or #__ for the WordPress table prefix, e.g. {prefix}posts.',
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

        $table = isset($arguments['table']) && is_string($arguments['table']) ? trim($arguments['table']) : '';

        if ($table === '') {
            return [
                'error' => 'Missing required parameter: table',
                'code' => 'invalid_arguments',
            ];
        }

        $table = str_replace(['#__', '{prefix}'], (string) $wpdb->prefix, $table);

        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return [
                'error' => 'Invalid table name. Only letters, numbers, and underscores are allowed after prefix expansion.',
                'code' => 'invalid_table_name',
            ];
        }

        $columns = $wpdb->get_results('SHOW COLUMNS FROM `' . esc_sql($table) . '`', ARRAY_A);

        if ($wpdb->last_error !== '') {
            return [
                'error' => 'Schema inspection failed: ' . $wpdb->last_error,
                'code' => 'schema_failed',
                'table' => $table,
            ];
        }

        if (!is_array($columns) || $columns === []) {
            return [
                'error' => 'Table not found or has no columns: ' . $table,
                'code' => 'not_found',
                'table' => $table,
            ];
        }

        $indexes = $wpdb->get_results('SHOW INDEX FROM `' . esc_sql($table) . '`', ARRAY_A);
        $stats = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT TABLE_ROWS, DATA_LENGTH, AUTO_INCREMENT FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table
            ),
            ARRAY_A
        );

        return [
            'table' => $table,
            'columns' => $columns,
            'indexes' => is_array($indexes) ? $indexes : [],
            'stats' => [
                'estimated_rows' => is_array($stats) && $stats['TABLE_ROWS'] !== null ? (int) $stats['TABLE_ROWS'] : null,
                'data_size_bytes' => is_array($stats) && $stats['DATA_LENGTH'] !== null ? (int) $stats['DATA_LENGTH'] : null,
                'auto_increment' => is_array($stats) && $stats['AUTO_INCREMENT'] !== null ? (int) $stats['AUTO_INCREMENT'] : null,
            ],
        ];
    }
}
