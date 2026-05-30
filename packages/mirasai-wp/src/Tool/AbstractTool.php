<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

abstract class AbstractTool implements ToolInterface
{
    public const RISK_READ = 'read';
    public const RISK_SAFE_WRITE = 'safe_write';
    public const RISK_GUARDED_WRITE = 'guarded_write';
    public const RISK_DANGEROUS_EXEC = 'dangerous_exec';

    /**
     * @return array<string, mixed>
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getPermissions(): array
    {
        return [
            'risk_level' => self::RISK_READ,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toMcpTool(): array
    {
        $permissions = self::normalizePermissions($this->getPermissions());
        $schema = $this->getInputSchema();

        if (!isset($schema['type'])) {
            $schema['type'] = 'object';
        }

        if (!array_key_exists('properties', $schema)) {
            $schema['properties'] = new \stdClass();
        }

        return [
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'inputSchema' => $schema,
            'annotations' => [
                'readOnlyHint' => $permissions['readonly'],
                'destructiveHint' => $permissions['destructive'],
                'idempotentHint' => $permissions['idempotent'],
                'openWorldHint' => false,
            ],
            'metadata' => [
                'risk_level' => $permissions['risk_level'],
                'workflow_hint' => self::workflowHintForRiskLevel($permissions['risk_level']),
                'surface' => $this->getSurface(),
                'platforms' => ['wordpress'],
            ],
        ];
    }

    public function getSurface(): string
    {
        return 'advanced';
    }

    /**
     * @param array<string, mixed> $permissions
     * @return array{risk_level: string, readonly: bool, destructive: bool, requires_elevation: bool, idempotent: bool}
     */
    public static function normalizePermissions(array $permissions): array
    {
        $riskLevel = is_string($permissions['risk_level'] ?? null)
            ? (string) $permissions['risk_level']
            : self::RISK_READ;

        if (!in_array($riskLevel, self::riskLevels(), true)) {
            $riskLevel = self::RISK_READ;
        }

        return [
            'risk_level' => $riskLevel,
            'readonly' => $riskLevel === self::RISK_READ,
            'destructive' => in_array($riskLevel, [self::RISK_GUARDED_WRITE, self::RISK_DANGEROUS_EXEC], true),
            'requires_elevation' => $riskLevel === self::RISK_DANGEROUS_EXEC,
            'idempotent' => in_array($riskLevel, [self::RISK_READ, self::RISK_GUARDED_WRITE], true),
        ];
    }

    /**
     * @return list<string>
     */
    public static function riskLevels(): array
    {
        return [
            self::RISK_READ,
            self::RISK_SAFE_WRITE,
            self::RISK_GUARDED_WRITE,
            self::RISK_DANGEROUS_EXEC,
        ];
    }

    public static function workflowHintForRiskLevel(string $riskLevel): string
    {
        return match ($riskLevel) {
            self::RISK_SAFE_WRITE => 'validate_then_apply',
            self::RISK_GUARDED_WRITE => 'dry_run_confirm_if_match',
            self::RISK_DANGEROUS_EXEC => 'elevation_required',
            default => 'direct',
        };
    }
}
