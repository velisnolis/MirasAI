<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

use Mirasai\Library\Sandbox\EnvironmentGuard;
use Mirasai\Library\Sandbox\SandboxLoader;

/**
 * sandbox/status — Reports the current state of the MirasAI sandbox.
 */
class SandboxStatusTool extends AbstractTool
{
    private SandboxLoader $loader;

    public function __construct(?SandboxLoader $loader = null)
    {
        parent::__construct();
        $this->loader = $loader ?? new SandboxLoader();
    }

    public function getName(): string
    {
        return 'sandbox/status';
    }

    public function getDescription(): string
    {
        return 'Returns the current state of the MirasAI sandbox: whether it is active, its state (ok/loading/crashed/safe_mode), loaded files, crashed files, and environment detection.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
        ];
    }

    public function handle(array $arguments): array
    {
        return [
            'active' => true,
            'state' => $this->loader->getState(),
            'environment' => EnvironmentGuard::isStaging() ? 'staging' : 'production',
            'sandbox_dir' => basename($this->loader->getSandboxDir()),
            'loaded_files' => $this->loader->getLoadedFiles(),
            'crashed_files' => $this->loader->getCrashedFiles(),
            'sandbox_files' => $this->loader->listSandboxFiles(),
            'php_lint_available' => $this->loader->isPhpLintAvailable(),
        ];
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
