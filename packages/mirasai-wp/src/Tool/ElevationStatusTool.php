<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class ElevationStatusTool extends AbstractTool
{
    public function getName(): string
    {
        return 'elevation/status';
    }

    public function getDescription(): string
    {
        return 'Checks whether dangerous execution elevation is enabled for the MirasAI WordPress host. The control plane exists, but dangerous_exec tools are not registered yet.';
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
        $dangerous = RuntimeSettings::dangerousExecStatus();

        return [
            'environment' => function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production',
            'looks_like_production' => RuntimeSettings::looksLikeProduction(),
            'elevation' => [
                'implemented' => false,
                'state' => $dangerous['state'],
                'domain_lock' => $dangerous['domain_lock'],
                'current_domain' => $dangerous['current_domain'],
                'message' => 'dangerous_exec controls are implemented in the admin UI, but no dangerous_exec tools are registered yet.',
            ],
            'dangerous_exec' => [
                'implemented' => false,
                'configured_enabled' => $dangerous['configured_enabled'],
                'available' => $dangerous['available'],
                'tools' => [],
            ],
        ];
    }
}
