<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class WpAbilityCallTool extends AbstractTool
{
    public function getName(): string
    {
        return 'wp/ability-call';
    }

    public function getDescription(): string
    {
        return 'Executes a registered WordPress Ability when it declares meta.annotations.readonly=true or is in MirasAI\'s explicit safe_write allowlist for non-destructive AI generation.';
    }

    /**
     * @return array<string, mixed>
     */
    public function getPermissions(): array
    {
        return [
            'risk_level' => self::RISK_SAFE_WRITE,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['name'],
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Ability name, for example core/get-site-info.',
                ],
                'input' => [
                    'type' => ['object', 'array', 'string', 'number', 'boolean', 'null'],
                    'description' => 'Optional ability input. It is validated by WordPress against the ability input schema.',
                ],
                'dry_run' => [
                    'type' => 'boolean',
                    'description' => 'Validate availability and MirasAI policy without executing the ability. Defaults to false.',
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
        if (!function_exists('wp_get_ability')) {
            return [
                'error' => 'WordPress Abilities API is not available.',
                'code' => 'abilities_api_unavailable',
            ];
        }

        $name = isset($arguments['name']) && is_string($arguments['name']) ? trim($arguments['name']) : '';
        if ($name === '') {
            return [
                'error' => 'Ability name is required.',
                'code' => 'invalid_arguments',
            ];
        }

        $ability = wp_get_ability($name);
        if (!is_object($ability)) {
            return [
                'error' => 'Ability not found.',
                'code' => 'ability_not_found',
                'name' => $name,
            ];
        }

        $meta = $this->callArrayMethod($ability, 'get_meta') ?? [];
        $annotations = is_array($meta['annotations'] ?? null) ? $meta['annotations'] : [];
        $policy = WordPressAbilityPolicy::forAbility($name, $annotations);
        $inputSchema = $this->callArrayMethod($ability, 'get_input_schema');
        $outputSchema = $this->callArrayMethod($ability, 'get_output_schema');
        $dryRun = !empty($arguments['dry_run']);

        if (!$policy['allowed']) {
            return [
                'error' => $policy['message'],
                'code' => 'ability_not_allowed',
                'name' => $name,
                'annotations' => $annotations,
                'policy' => $policy['policy'],
            ];
        }

        if ($dryRun) {
            return [
                'ok' => true,
                'dry_run' => true,
                'name' => $name,
                'would_execute' => true,
                'policy' => $policy['policy'],
                'risk_level' => $policy['risk_level'],
                'readonly' => ($annotations['readonly'] ?? null) === true,
                'idempotent' => ($annotations['idempotent'] ?? null) === true,
                'destructive' => ($annotations['destructive'] ?? null) === true,
                'meta' => $meta,
                'input_schema' => $inputSchema,
                'output_schema' => $outputSchema,
            ];
        }

        if (!method_exists($ability, 'execute')) {
            return [
                'error' => 'Ability object does not expose execute().',
                'code' => 'ability_not_executable',
                'name' => $name,
            ];
        }

        $input = array_key_exists('input', $arguments) ? $arguments['input'] : null;
        $result = $ability->execute($input);

        if (function_exists('is_wp_error') && is_wp_error($result)) {
            return [
                'error' => $result->get_error_message(),
                'code' => $result->get_error_code() ?: 'ability_error',
                'name' => $name,
                'data' => $result->get_error_data(),
            ];
        }

        return [
            'ok' => true,
            'name' => $name,
            'policy' => $policy['policy'],
            'risk_level' => $policy['risk_level'],
            'readonly' => ($annotations['readonly'] ?? null) === true,
            'idempotent' => ($annotations['idempotent'] ?? null) === true,
            'destructive' => ($annotations['destructive'] ?? null) === true,
            'result' => $this->normalizeValue($result),
        ];
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

    private function normalizeValue(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if (is_array($value)) {
            return $this->normalizeArray($value);
        }

        if ($value instanceof \WP_Post) {
            return [
                'type' => 'post',
                'id' => (int) $value->ID,
                'post_type' => (string) $value->post_type,
                'title' => get_the_title($value),
            ];
        }

        if ($value instanceof \WP_User) {
            return [
                'type' => 'user',
                'id' => (int) $value->ID,
                'login' => (string) $value->user_login,
                'display_name' => (string) $value->display_name,
            ];
        }

        if (is_object($value)) {
            $encoded = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($encoded)) {
                $decoded = json_decode($encoded, true);
                if (is_array($decoded)) {
                    return $this->normalizeArray($decoded);
                }
            }

            return [
                'type' => get_class($value),
                'string' => method_exists($value, '__toString') ? (string) $value : null,
            ];
        }

        return [
            'type' => gettype($value),
        ];
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private function normalizeArray(array $value): array
    {
        $encoded = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($encoded) && strlen($encoded) > 50000) {
            return [
                'type' => 'array',
                'truncated' => true,
                'count' => count($value),
            ];
        }

        $result = [];
        foreach ($value as $key => $item) {
            $result[$key] = $this->normalizeValue($item);
        }

        return $result;
    }
}
