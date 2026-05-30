<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class WordPressAbilityPolicy
{
    private const SAFE_WRITE_ALLOWLIST = [
        'ai/alt-text-generation',
        'ai/comment-analysis',
        'ai/content-classification',
        'ai/content-resizing',
        'ai/editorial-notes',
        'ai/excerpt-generation',
        'ai/get-post-details',
        'ai/get-post-terms',
        'ai/meta-description',
        'ai/summarization',
        'ai/title-generation',
    ];

    private const BLOCKLIST = [
        'ai/editorial-updates',
        'mcp-adapter/execute-ability',
    ];

    /**
     * @param array<string, mixed> $annotations
     * @return array{allowed: bool, policy: string, risk_level: string, message: string}
     */
    public static function forAbility(string $name, array $annotations): array
    {
        if (in_array($name, self::BLOCKLIST, true)) {
            return [
                'allowed' => false,
                'policy' => 'blocked',
                'risk_level' => AbstractTool::RISK_SAFE_WRITE,
                'message' => 'Ability execution blocked by MirasAI policy.',
            ];
        }

        if (($annotations['destructive'] ?? null) === true) {
            return [
                'allowed' => false,
                'policy' => 'blocked_destructive',
                'risk_level' => AbstractTool::RISK_SAFE_WRITE,
                'message' => 'Ability execution blocked because it declares destructive=true.',
            ];
        }

        if (($annotations['readonly'] ?? null) === true) {
            return [
                'allowed' => true,
                'policy' => 'readonly_annotation',
                'risk_level' => AbstractTool::RISK_READ,
                'message' => 'Allowed by readonly annotation.',
            ];
        }

        if (in_array($name, self::SAFE_WRITE_ALLOWLIST, true)) {
            return [
                'allowed' => true,
                'policy' => 'safe_write_allowlist',
                'risk_level' => AbstractTool::RISK_SAFE_WRITE,
                'message' => 'Allowed by MirasAI safe_write allowlist.',
            ];
        }

        return [
            'allowed' => false,
            'policy' => 'not_allowlisted',
            'risk_level' => AbstractTool::RISK_SAFE_WRITE,
            'message' => 'Ability execution blocked because it is neither readonly nor in the MirasAI safe_write allowlist.',
        ];
    }

    /**
     * @return list<string>
     */
    public static function safeWriteAllowlist(): array
    {
        return self::SAFE_WRITE_ALLOWLIST;
    }

    /**
     * @return list<string>
     */
    public static function blocklist(): array
    {
        return self::BLOCKLIST;
    }
}
