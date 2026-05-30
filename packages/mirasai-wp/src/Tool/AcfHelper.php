<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class AcfHelper
{
    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $available = $this->isAvailable();
        $acfAbilities = $this->acfAbilities();

        return [
            'available' => $available,
            'version' => $this->version(),
            'pro' => $this->isPro(),
            'settings' => [
                'enable_acf_ai' => $this->setting('enable_acf_ai'),
                'enable_datastore' => $this->setting('enable_datastore'),
            ],
            'wordpress_abilities_api_available' => function_exists('wp_get_abilities'),
            'acf_abilities_count' => count($acfAbilities),
            'acf_abilities' => $acfAbilities,
        ];
    }

    public function isAvailable(): bool
    {
        return function_exists('acf')
            || function_exists('acf_get_field_groups')
            || defined('ACF_VERSION')
            || class_exists('ACF');
    }

    public function version(): ?string
    {
        if (defined('ACF_VERSION') && is_string(ACF_VERSION) && ACF_VERSION !== '') {
            return ACF_VERSION;
        }

        if (function_exists('acf')) {
            $acf = acf();
            if (is_object($acf) && isset($acf->version) && is_string($acf->version) && $acf->version !== '') {
                return $acf->version;
            }
        }

        return null;
    }

    public function isPro(): bool
    {
        return defined('ACF_PRO') || class_exists('acf_pro');
    }

    /**
     * @return list<string>
     */
    public function acfAbilities(): array
    {
        if (!function_exists('wp_get_abilities')) {
            return [];
        }

        $abilities = wp_get_abilities();
        if (!is_array($abilities)) {
            return [];
        }

        $names = [];
        foreach ($abilities as $key => $ability) {
            $name = is_object($ability) && method_exists($ability, 'get_name')
                ? $ability->get_name()
                : (string) $key;

            if (is_string($name) && str_starts_with($name, 'acf/')) {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fieldGroups(): array
    {
        if (!function_exists('acf_get_field_groups')) {
            return [];
        }

        $groups = acf_get_field_groups();
        if (!is_array($groups)) {
            return [];
        }

        return array_values(array_filter($groups, static fn($group): bool => is_array($group)));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fieldGroup(string|int $selector): ?array
    {
        if (!function_exists('acf_get_field_group')) {
            foreach ($this->fieldGroups() as $group) {
                if (($group['key'] ?? null) === $selector || (isset($group['ID']) && (int) $group['ID'] === (int) $selector)) {
                    return $group;
                }
            }

            return null;
        }

        $group = acf_get_field_group($selector);

        return is_array($group) ? $group : null;
    }

    /**
     * @param array<string, mixed>|string|int $group
     * @return list<array<string, mixed>>
     */
    public function fields(array|string|int $group): array
    {
        if (!function_exists('acf_get_fields')) {
            return [];
        }

        $fields = acf_get_fields($group);
        if (!is_array($fields)) {
            return [];
        }

        return array_values(array_filter($fields, static fn($field): bool => is_array($field)));
    }

    /**
     * @param array<string, mixed> $group
     * @return array<string, mixed>
     */
    public function summarizeGroup(array $group): array
    {
        return [
            'id' => isset($group['ID']) ? (int) $group['ID'] : null,
            'key' => is_string($group['key'] ?? null) ? $group['key'] : null,
            'title' => is_string($group['title'] ?? null) ? $group['title'] : null,
            'active' => array_key_exists('active', $group) ? (bool) $group['active'] : null,
            'location' => is_array($group['location'] ?? null) ? $group['location'] : [],
            'position' => is_string($group['position'] ?? null) ? $group['position'] : null,
            'style' => is_string($group['style'] ?? null) ? $group['style'] : null,
            'menu_order' => isset($group['menu_order']) ? (int) $group['menu_order'] : null,
            'description' => is_string($group['description'] ?? null) ? $group['description'] : null,
            'show_in_rest' => array_key_exists('show_in_rest', $group) ? (bool) $group['show_in_rest'] : null,
            'hide_on_screen' => is_array($group['hide_on_screen'] ?? null) ? $group['hide_on_screen'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    public function summarizeField(array $field, bool $includeValue = false): array
    {
        $summary = [
            'key' => is_string($field['key'] ?? null) ? $field['key'] : null,
            'name' => is_string($field['name'] ?? null) ? $field['name'] : null,
            'label' => is_string($field['label'] ?? null) ? $field['label'] : null,
            'type' => is_string($field['type'] ?? null) ? $field['type'] : null,
            'required' => array_key_exists('required', $field) ? (bool) $field['required'] : null,
            'instructions' => is_string($field['instructions'] ?? null) ? $field['instructions'] : null,
            'choices' => is_array($field['choices'] ?? null) ? $field['choices'] : null,
            'return_format' => is_string($field['return_format'] ?? null) ? $field['return_format'] : null,
            'default_value' => $this->safeValue($field['default_value'] ?? null),
        ];

        if (isset($field['sub_fields']) && is_array($field['sub_fields'])) {
            $summary['sub_fields'] = array_values(array_map(
                fn($subField): array => is_array($subField) ? $this->summarizeField($subField, false) : [],
                $field['sub_fields']
            ));
        }

        if (isset($field['layouts']) && is_array($field['layouts'])) {
            $summary['layouts'] = $this->summarizeLayouts($field['layouts']);
        }

        if ($includeValue) {
            $summary['value'] = $this->safeValue($field['value'] ?? null);
        }

        return array_filter($summary, static fn($value): bool => $value !== null);
    }

    private function setting(string $name): mixed
    {
        if (!function_exists('acf_get_setting')) {
            return null;
        }

        return acf_get_setting($name);
    }

    /**
     * @param array<string, mixed> $layouts
     * @return list<array<string, mixed>>
     */
    private function summarizeLayouts(array $layouts): array
    {
        $result = [];

        foreach ($layouts as $layout) {
            if (!is_array($layout)) {
                continue;
            }

            $result[] = [
                'key' => is_string($layout['key'] ?? null) ? $layout['key'] : null,
                'name' => is_string($layout['name'] ?? null) ? $layout['name'] : null,
                'label' => is_string($layout['label'] ?? null) ? $layout['label'] : null,
                'sub_fields' => isset($layout['sub_fields']) && is_array($layout['sub_fields'])
                    ? array_values(array_map(
                        fn($field): array => is_array($field) ? $this->summarizeField($field, false) : [],
                        $layout['sub_fields']
                    ))
                    : [],
            ];
        }

        return $result;
    }

    private function safeValue(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if (is_array($value)) {
            $encoded = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if (is_string($encoded) && strlen($encoded) <= 20000) {
                return $value;
            }

            return [
                'type' => 'array',
                'truncated' => true,
                'count' => count($value),
            ];
        }

        if ($value instanceof \WP_Post) {
            return [
                'type' => 'post',
                'id' => (int) $value->ID,
                'post_type' => (string) $value->post_type,
                'title' => get_the_title($value),
            ];
        }

        return [
            'type' => is_object($value) ? get_class($value) : gettype($value),
            'string' => is_object($value) && method_exists($value, '__toString') ? (string) $value : null,
        ];
    }

}
