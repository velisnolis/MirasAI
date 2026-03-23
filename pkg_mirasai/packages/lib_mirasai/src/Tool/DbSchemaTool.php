<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

/**
 * db/schema — Inspect table structure via SHOW COLUMNS / INFORMATION_SCHEMA.
 */
class DbSchemaTool extends AbstractTool
{
    public function getName(): string
    {
        return 'db/schema';
    }

    public function getDescription(): string
    {
        return 'Inspect database table structure. Returns column names, types, nullability, keys, and defaults. '
            . 'Use #__ for the Joomla table prefix (e.g., #__content).';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'table' => [
                    'type' => 'string',
                    'description' => 'Table name (use #__ for Joomla prefix, e.g., #__content)',
                ],
            ],
            'required' => ['table'],
        ];
    }

    public function handle(array $arguments): array
    {
        $table = trim($arguments['table'] ?? '');

        if ($table === '') {
            return ['error' => 'Missing required parameter: table'];
        }

        // Replace Joomla table prefix
        $table = str_replace('#__', $this->db->getPrefix(), $table);

        try {
            $this->db->setQuery('SHOW COLUMNS FROM ' . $this->db->quoteName($table));
            $columns = $this->db->loadAssocList();

            if ($columns === null || $columns === []) {
                return ['error' => 'Table not found or has no columns: ' . $table];
            }

            // Also get indexes
            $this->db->setQuery('SHOW INDEX FROM ' . $this->db->quoteName($table));
            $indexes = $this->db->loadAssocList();

            // Get table status for row count estimate
            $this->db->setQuery(
                'SELECT TABLE_ROWS, DATA_LENGTH, AUTO_INCREMENT '
                . 'FROM INFORMATION_SCHEMA.TABLES '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '
                . $this->db->quote($table)
            );
            $stats = $this->db->loadAssoc();

            return [
                'table' => $table,
                'columns' => $columns,
                'indexes' => $indexes ?: [],
                'stats' => [
                    'estimated_rows' => $stats ? (int) $stats['TABLE_ROWS'] : null,
                    'data_size_bytes' => $stats ? (int) $stats['DATA_LENGTH'] : null,
                    'auto_increment' => $stats ? ($stats['AUTO_INCREMENT'] !== null ? (int) $stats['AUTO_INCREMENT'] : null) : null,
                ],
            ];
        } catch (\Throwable $e) {
            return ['error' => 'Schema inspection failed: ' . $e->getMessage()];
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
}
