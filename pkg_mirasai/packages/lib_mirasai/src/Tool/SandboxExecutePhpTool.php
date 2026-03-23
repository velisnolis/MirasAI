<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

/**
 * sandbox/execute-php — Execute PHP code within a transaction-wrapped sandbox.
 *
 * Features:
 * - DB transaction wrapping (auto-rollback on error)
 * - 30s time limit via set_time_limit
 * - Output buffering and capture
 * - Error/warning capture via custom error handler
 * - Shutdown handler for fatal errors
 *
 * Limitations (documented in CEO plan):
 * - DDL statements auto-commit in MySQL (cannot be rolled back)
 * - eval() runs in-process (no true isolation)
 * - set_time_limit can be overridden by eval'd code
 * - Fatal errors (OOM) rely on implicit rollback via connection drop
 */
class SandboxExecutePhpTool extends AbstractTool
{
    public function getName(): string
    {
        return 'sandbox/execute-php';
    }

    public function getDescription(): string
    {
        return 'Execute PHP code in a sandboxed environment with transaction wrapping. '
            . 'The code runs inside a DB transaction that is committed on success or rolled back on error. '
            . 'A 30-second time limit is enforced. Warnings and notices are captured. '
            . 'IMPORTANT: DDL statements (CREATE TABLE, ALTER TABLE) auto-commit and cannot be rolled back. '
            . 'Do not mix DDL and DML in a single call. This tool is only available on staging environments.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'code' => [
                    'type' => 'string',
                    'description' => 'PHP code to execute (without <?php tags). '
                        . 'The code has access to the Joomla application context, '
                        . 'including Factory, DatabaseInterface, etc.',
                ],
            ],
            'required' => ['code'],
        ];
    }

    public function handle(array $arguments): array
    {
        $code = $arguments['code'] ?? '';

        if ($code === '') {
            return ['error' => 'Missing required parameter: code'];
        }

        $startTime = hrtime(true);

        // Collect warnings/notices
        $errors = [];
        $previousHandler = set_error_handler(
            static function (int $errno, string $errstr, string $errfile, int $errline) use (&$errors): bool {
                $typeMap = [
                    E_WARNING => 'warning',
                    E_NOTICE => 'notice',
                    E_DEPRECATED => 'deprecated',
                    E_USER_WARNING => 'warning',
                    E_USER_NOTICE => 'notice',
                    E_USER_DEPRECATED => 'deprecated',
                    E_STRICT => 'notice',
                ];

                $errors[] = [
                    'type' => $typeMap[$errno] ?? 'warning',
                    'message' => $errstr,
                    'file' => $errfile,
                    'line' => $errline,
                ];

                // Don't execute PHP internal error handler
                return true;
            }
        );

        // Start transaction
        $transactionStarted = false;

        try {
            $this->db->transactionStart();
            $transactionStarted = true;
        } catch (\Throwable $e) {
            // If we can't start a transaction, continue without one
            $errors[] = [
                'type' => 'warning',
                'message' => 'Could not start DB transaction: ' . $e->getMessage(),
                'file' => '',
                'line' => 0,
            ];
        }

        // Register shutdown handler for fatal errors
        $shutdownRegistered = false;

        if ($transactionStarted) {
            $db = $this->db;
            register_shutdown_function(static function () use ($db, &$shutdownRegistered): void {
                if (!$shutdownRegistered) {
                    return;
                }

                $error = error_get_last();

                if ($error !== null && \in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
                    try {
                        $db->transactionRollback();
                    } catch (\Throwable) {
                        // Connection may already be dead — MySQL will implicitly rollback
                    }
                }
            });
            $shutdownRegistered = true;
        }

        // Set time limit
        $previousTimeLimit = ini_get('max_execution_time');
        @set_time_limit(30);

        // Execute with output buffering
        $returnValue = null;
        $exception = null;
        $output = '';

        ob_start();

        try {
            $returnValue = eval($code);
        } catch (\Throwable $e) {
            $exception = [
                'class' => \get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];
        }

        $output = ob_get_clean() ?: '';

        // Commit or rollback
        if ($transactionStarted) {
            try {
                if ($exception !== null) {
                    $this->db->transactionRollback();
                } else {
                    $this->db->transactionCommit();
                }
            } catch (\Throwable $e) {
                $errors[] = [
                    'type' => 'warning',
                    'message' => 'Transaction finalization error: ' . $e->getMessage(),
                    'file' => '',
                    'line' => 0,
                ];
            }

            $shutdownRegistered = false;
        }

        // Restore error handler and time limit
        restore_error_handler();

        if ($previousTimeLimit !== false) {
            @set_time_limit((int) $previousTimeLimit);
        }

        $executionTimeMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        $response = [
            'return_value' => $this->serializeReturnValue($returnValue),
            'output' => $output,
            'errors' => $errors,
            'exception' => $exception,
            'execution_time_ms' => $executionTimeMs,
            'transaction' => $transactionStarted ? ($exception !== null ? 'rolled_back' : 'committed') : 'none',
        ];

        if ($exception !== null) {
            $response['error'] = 'Execution failed: ' . $exception['message'];
        }

        return $response;
    }

    public function getPermissions(): array
    {
        return [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ];
    }

    /**
     * Safely serialize a return value for JSON encoding.
     *
     * @return mixed
     */
    private function serializeReturnValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (\is_array($value)) {
            return array_map([$this, 'serializeReturnValue'], $value);
        }

        if (\is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }

            if ($value instanceof \JsonSerializable) {
                return $value->jsonSerialize();
            }

            return '[object ' . \get_class($value) . ']';
        }

        if (\is_resource($value)) {
            return '[resource]';
        }

        return '[unknown type]';
    }
}
