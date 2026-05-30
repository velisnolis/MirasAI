<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class SandboxStatusTool extends AbstractTool
{
    public function getName(): string
    {
        return 'sandbox/status';
    }

    public function getDescription(): string
    {
        return 'Returns the current state of the MirasAI WordPress sandbox, including dangerous_exec readiness and safe-mode state.';
    }

    /**
     * @return array<string, mixed>
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array
    {
        return [
            'active' => RuntimeSettings::isDangerousExecEnabled(),
            'implemented' => true,
            'state' => RuntimeSettings::sandboxSafeModeActive()
                ? 'safe_mode'
                : (RuntimeSettings::isDangerousExecEnabled() ? 'ready' : 'disabled'),
            'environment' => function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production',
            'sandbox_dir' => RuntimeSettings::relativeSandboxDir(),
            'sandbox_files' => RuntimeSettings::sandboxFiles(),
            'autoload_files' => [],
            'loaded_files' => [],
            'safe_mode_marker' => RuntimeSettings::sandboxSafeModeActive() ? '.crashed' : null,
            'php_lint_available' => RuntimeSettings::isPhpLintAvailable(),
            'dangerous_exec' => RuntimeSettings::dangerousExecStatus(),
            'message' => RuntimeSettings::isDangerousExecEnabled()
                ? 'sandbox/execute-php is available for this domain.'
                : 'sandbox/execute-php is implemented but hidden until dangerous_exec controls are enabled for this domain.',
        ];
    }
}
