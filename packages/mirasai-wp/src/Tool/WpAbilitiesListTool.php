<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class WpAbilitiesListTool extends AbstractTool
{
    public function getName(): string
    {
        return 'wp/abilities/list';
    }

    public function getDescription(): string
    {
        return 'Discovers WordPress Abilities API abilities registered by core, plugins, or themes. Read-only bridge toward native WordPress and plugin capabilities.';
    }

    /**
     * @return array<string, mixed>
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'namespace' => [
                    'type' => 'string',
                    'description' => 'Optional ability namespace prefix, for example acf.',
                ],
                'include_schemas' => [
                    'type' => 'boolean',
                    'description' => 'Include input and output schemas. Defaults to false.',
                ],
                'include_policy' => [
                    'type' => 'boolean',
                    'description' => 'Include MirasAI execution policy for each ability. Defaults to true.',
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
        if (!function_exists('wp_get_abilities')) {
            return [
                'available' => false,
                'wordpress_version' => get_bloginfo('version'),
                'abilities' => [],
                'count' => 0,
                'warnings' => [
                    'WordPress Abilities API is not available on this site. It requires WordPress 6.9 or later, or a compatible feature plugin.',
                ],
            ];
        }

        $namespace = isset($arguments['namespace']) && is_string($arguments['namespace']) && trim($arguments['namespace']) !== ''
            ? trim($arguments['namespace'])
            : null;
        $includeSchemas = !empty($arguments['include_schemas']);
        $includePolicy = !array_key_exists('include_policy', $arguments) || !empty($arguments['include_policy']);
        $abilities = wp_get_abilities();
        $rows = [];

        if (!is_array($abilities)) {
            $abilities = [];
        }

        foreach ($abilities as $key => $ability) {
            $row = $this->abilityRow((string) $key, $ability, $includeSchemas, $includePolicy);
            if ($namespace !== null && !str_starts_with($row['name'], $namespace . '/')) {
                continue;
            }

            $rows[] = $row;
        }

        usort($rows, static fn(array $left, array $right): int => strcmp($left['name'], $right['name']));

        return [
            'available' => true,
            'wordpress_version' => get_bloginfo('version'),
            'namespace' => $namespace,
            'include_policy' => $includePolicy,
            'policy' => [
                'call_tool' => 'wp/ability-call',
                'call_tool_risk_level' => self::RISK_SAFE_WRITE,
                'safe_write_allowlist' => WordPressAbilityPolicy::safeWriteAllowlist(),
                'blocklist' => WordPressAbilityPolicy::blocklist(),
            ],
            'count' => count($rows),
            'abilities' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function abilityRow(string $fallbackName, mixed $ability, bool $includeSchemas, bool $includePolicy): array
    {
        $name = $this->callStringMethod($ability, 'get_name') ?? $fallbackName;
        $meta = $this->callArrayMethod($ability, 'get_meta');
        $row = [
            'name' => $name,
            'label' => $this->callStringMethod($ability, 'get_label'),
            'description' => $this->callStringMethod($ability, 'get_description'),
            'category' => $this->callStringMethod($ability, 'get_category'),
            'meta' => $meta,
        ];

        if ($includePolicy) {
            $annotations = is_array($meta['annotations'] ?? null) ? $meta['annotations'] : [];
            $row['mirasai_policy'] = WordPressAbilityPolicy::forAbility($name, $annotations);
        }

        if ($includeSchemas) {
            $row['input_schema'] = $this->callArrayMethod($ability, 'get_input_schema');
            $row['output_schema'] = $this->callArrayMethod($ability, 'get_output_schema');
        }

        return $row;
    }

    private function callStringMethod(mixed $object, string $method): ?string
    {
        if (!is_object($object) || !method_exists($object, $method)) {
            return null;
        }

        $value = $object->{$method}();

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function callArrayMethod(mixed $object, string $method): ?array
    {
        if (!is_object($object) || !method_exists($object, $method)) {
            return null;
        }

        $value = $object->{$method}();

        return is_array($value) ? $value : null;
    }
}
