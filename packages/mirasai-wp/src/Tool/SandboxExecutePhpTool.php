<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

/**
 * sandbox/execute-php — Execute PHP code in-process with transaction wrapping.
 *
 * This is not an isolated security sandbox. Code runs in the WordPress PHP
 * worker via eval(), with access to the WordPress runtime.
 */
class SandboxExecutePhpTool extends AbstractTool
{
    public function getName(): string
    {
        return 'sandbox/execute-php';
    }

    public function getDescription(): string
    {
        return 'Execute PHP code in-process with DB transaction wrapping. This is not a security sandbox. '
            . 'The code runs in the WordPress PHP worker via eval(), with access to the WordPress runtime. '
            . 'The DB transaction is committed on success or rolled back on caught errors. '
            . 'A 30-second time limit is attempted but can be bypassed by executed code. Warnings and notices are captured. '
            . 'IMPORTANT: DDL statements (CREATE TABLE, ALTER TABLE) auto-commit and cannot be rolled back. '
            . 'Do not mix DDL and DML in a single call. Requires confirm_execute_php=true and enabled dangerous_exec controls.';
    }

    /**
     * @return array<string, mixed>
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['code', 'confirm_execute_php'],
            'properties' => [
                'code' => [
                    'type' => 'string',
                    'description' => 'PHP code to execute without <?php tags. The code has access to WordPress functions and $wpdb.',
                ],
                'confirm_execute_php' => [
                    'type' => 'boolean',
                    'description' => 'Must be true to acknowledge this is in-process PHP execution, not an isolated sandbox.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array
    {
        global $wpdb;

        if (!RuntimeSettings::isDangerousExecEnabled()) {
            return [
                'error' => 'sandbox/execute-php requires dangerous_exec controls to be enabled for the current domain.',
                'code' => 'dangerous_exec_not_enabled',
                'dangerous_exec' => RuntimeSettings::dangerousExecStatus(),
            ];
        }

        if (RuntimeSettings::sandboxSafeModeActive()) {
            return [
                'error' => 'WordPress sandbox safe mode is active because a previous execution crashed.',
                'code' => 'sandbox_safe_mode_active',
                'safe_mode_marker' => '.crashed',
                'sandbox_dir' => RuntimeSettings::relativeSandboxDir(),
            ];
        }

        $code = isset($arguments['code']) && is_string($arguments['code']) ? $arguments['code'] : '';
        if (($arguments['confirm_execute_php'] ?? null) !== true) {
            return [
                'error' => 'sandbox/execute-php requires confirm_execute_php=true.',
                'code' => 'execute_php_confirmation_required',
                'safety_note' => 'This tool runs PHP in-process via eval(); it is transaction-wrapped but not isolated.',
            ];
        }

        if (trim($code) === '') {
            return [
                'error' => 'Missing required parameter: code',
                'code' => 'invalid_arguments',
            ];
        }

        if (!$this->hasWpdb($wpdb)) {
            return [
                'error' => 'WordPress database handle $wpdb is unavailable.',
                'code' => 'wpdb_unavailable',
            ];
        }

        $startTime = hrtime(true);
        $errors = [];

        set_error_handler(
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

                return true;
            }
        );

        $transactionStarted = $this->startTransaction($wpdb, $errors);
        $shutdownActive = false;

        if ($transactionStarted) {
            $shutdownActive = true;
            register_shutdown_function(static function () use ($wpdb, &$shutdownActive): void {
                if (!$shutdownActive) {
                    return;
                }

                $error = error_get_last();
                if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
                    @$wpdb->query('ROLLBACK');
                    @file_put_contents(RuntimeSettings::sandboxDir(true) . '.crashed', gmdate('c') . "\n");
                }
            });
        }

        $previousTimeLimit = ini_get('max_execution_time');
        @set_time_limit(30);

        $returnValue = null;
        $exception = null;
        $output = '';

        ob_start();

        try {
            $returnValue = eval($code);
        } catch (\Throwable $e) {
            $exception = [
                'class' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];
        }

        $output = ob_get_clean() ?: '';

        if ($transactionStarted) {
            if ($exception !== null) {
                $this->rollbackTransaction($wpdb, $errors);
            } else {
                $this->commitTransaction($wpdb, $errors);
            }

            $shutdownActive = false;
        }

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
            $response['code'] = 'execution_failed';
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPermissions(): array
    {
        return [
            'risk_level' => self::RISK_DANGEROUS_EXEC,
        ];
    }

    private function hasWpdb(mixed $wpdb): bool
    {
        return is_object($wpdb) && method_exists($wpdb, 'query');
    }

    /**
     * @param list<array<string, mixed>> $errors
     */
    private function startTransaction(object $wpdb, array &$errors): bool
    {
        $result = $wpdb->query('START TRANSACTION');
        if ($result === false) {
            $errors[] = [
                'type' => 'warning',
                'message' => 'Could not start DB transaction: ' . (string) ($wpdb->last_error ?? ''),
                'file' => '',
                'line' => 0,
            ];

            return false;
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $errors
     */
    private function commitTransaction(object $wpdb, array &$errors): void
    {
        $result = $wpdb->query('COMMIT');
        if ($result === false) {
            $errors[] = [
                'type' => 'warning',
                'message' => 'Transaction commit failed: ' . (string) ($wpdb->last_error ?? ''),
                'file' => '',
                'line' => 0,
            ];
        }
    }

    /**
     * @param list<array<string, mixed>> $errors
     */
    private function rollbackTransaction(object $wpdb, array &$errors): void
    {
        $result = $wpdb->query('ROLLBACK');
        if ($result === false) {
            $errors[] = [
                'type' => 'warning',
                'message' => 'Transaction rollback failed: ' . (string) ($wpdb->last_error ?? ''),
                'file' => '',
                'line' => 0,
            ];
        }
    }

    /**
     * @return mixed
     */
    private function serializeReturnValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            return array_map([$this, 'serializeReturnValue'], $value);
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }

            if ($value instanceof \JsonSerializable) {
                return $value->jsonSerialize();
            }

            return '[object ' . get_class($value) . ']';
        }

        if (is_resource($value)) {
            return '[resource]';
        }

        return null;
    }
}
