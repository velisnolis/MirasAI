<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

use Mirasai\Library\Sandbox\ElevationService;
use Mirasai\Library\Sandbox\EnvironmentGuard;

/**
 * Read-only MCP tool for checking elevation status.
 *
 * Works on both staging and production. Lets the agent check
 * whether destructive tools are available before attempting them.
 */
class ElevationStatusTool extends AbstractTool
{
    public function getName(): string
    {
        return 'elevation/status';
    }

    public function getDescription(): string
    {
        return 'Check the current elevation status for destructive tools. '
            . 'Returns whether elevation is active, remaining time, allowed scopes, '
            . 'and recent audit entries. Use this before attempting destructive operations on production.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => (object) [],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array
    {
        // Detect environment
        if (EnvironmentGuard::isStaging()) {
            return [
                'environment' => 'staging',
                'elevation' => [
                    'state' => 'not_required',
                    'message' => 'All tools are available on staging — no elevation needed.',
                ],
            ];
        }

        if (!EnvironmentGuard::isProduction()) {
            return [
                'environment' => 'unknown',
                'elevation' => [
                    'state' => 'unknown',
                    'message' => 'Could not determine environment. Treated as production — destructive tools are blocked.',
                ],
            ];
        }

        // Production — check elevation
        $elevation = new ElevationService();
        $grant = $elevation->getActiveGrant();

        if ($grant === null || !$grant->isActive()) {
            return [
                'environment' => 'production',
                'elevation' => [
                    'state' => 'inactive',
                    'message' => 'Destructive tools are BLOCKED. Ask the site administrator to activate elevation in the Joomla admin panel (Components → MirasAI → Elevation).',
                ],
            ];
        }

        // Active grant
        $remainingMinutes = (int) ceil($grant->getRemainingSeconds() / 60);

        $response = [
            'environment' => 'production',
            'elevation' => [
                'state' => 'active',
                'grant_id' => $grant->id,
                'remaining_minutes' => $remainingMinutes,
                'remaining_seconds' => $grant->getRemainingSeconds(),
                'scopes' => $grant->scopes,
                'reason' => $grant->reason,
                'use_count' => $grant->useCount,
            ],
        ];

        // Include recent audit entries if any
        $audit = $elevation->getAuditLog($grant->id);
        if (!empty($audit)) {
            $response['recent_audit'] = \array_slice($audit, 0, 10);
        }

        return $response;
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
