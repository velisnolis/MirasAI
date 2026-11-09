<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

/**
 * Which template YOOtheme applies, as TemplateHelper::match() decides it: the
 * first template in storage order that is not disabled, has the page's type
 * and whose conditions (query) match. A template without conditions matches
 * every page of its type, so it hides every later one of that type.
 *
 * Mirrored in plg_mirasai_yootheme. Both copies must stay free of WordPress and
 * Joomla calls so docker/test-template-order.php can run them as plain PHP.
 */
final class TemplateOrder
{
    public static function isEnabled(array $template): bool
    {
        return ($template['status'] ?? '') !== 'disabled';
    }

    /**
     * The conditions that narrow a template, empty when it matches every page of its type.
     *
     * @param array<string, mixed> $template
     * @return array<string, mixed>
     */
    public static function conditions(array $template): array
    {
        $query = is_array($template['query'] ?? null) ? $template['query'] : [];

        return array_filter($query, static fn ($value): bool => !empty($value));
    }

    /**
     * @param array<string, mixed> $templates storage order
     * @return array<string, mixed>
     */
    public static function withStatus(array $templates, string $key, bool $enabled): array
    {
        if ($enabled) {
            unset($templates[$key]['status']);
        } else {
            $templates[$key]['status'] = 'disabled';
        }

        return $templates;
    }

    /**
     * A switched-off copy of one template, with its type, conditions, params and layout, placed right after it.
     *
     * @param array<string, mixed> $templates storage order
     * @return array<string, mixed>
     */
    public static function duplicated(array $templates, string $key, string $newKey, string $name): array
    {
        $copy = $templates[$key];
        $copy['name'] = $name;
        $copy['status'] = 'disabled';
        $result = [];

        foreach ($templates as $k => $template) {
            $result[$k] = $template;

            if ((string) $k === $key) {
                $result[$newKey] = $copy;
            }
        }

        return $result;
    }

    /**
     * Move one template to a position among the templates of its own type (0 = checked first).
     *
     * @param array<string, mixed> $templates storage order
     * @return array<string, mixed>
     */
    public static function moved(array $templates, string $key, int $position): array
    {
        $type = (string) ($templates[$key]['type'] ?? '');
        $sameType = array_values(array_filter(array_keys($templates), static fn ($k): bool => (string) $k !== $key && (string) ($templates[$k]['type'] ?? '') === $type));
        $position = max(0, min($position, count($sameType)));
        $before = $sameType[$position] ?? null;
        $moved = [];

        foreach ($templates as $k => $template) {
            if ((string) $k === $key) {
                continue;
            }

            if ($before !== null && (string) $k === (string) $before) {
                $moved[$key] = $templates[$key];
            }

            $moved[$k] = $template;
        }

        if ($before === null) {
            $moved[$key] = $templates[$key];
        }

        return $moved;
    }

    /**
     * What a template does among those of its type in a given order.
     *
     * @param array<string, mixed> $templates storage order
     * @return array<string, mixed>
     */
    public static function describe(array $templates, string $key): array
    {
        $target = $templates[$key];
        $type = (string) ($target['type'] ?? '');
        $order = [];
        $shadowedBy = [];
        $narrowedBy = [];
        $takesOverFrom = [];
        $seenTarget = false;

        foreach ($templates as $k => $template) {
            if (!is_array($template) || (string) ($template['type'] ?? '') !== $type) {
                continue;
            }

            $k = (string) $k;
            $row = [
                'key' => $k,
                'name' => (string) ($template['name'] ?? $k),
                'enabled' => self::isEnabled($template),
                'conditions' => (object) self::conditions($template),
            ];
            $order[] = $row;

            if ($k === $key) {
                $seenTarget = true;
                continue;
            }

            if (!$row['enabled']) {
                continue;
            }

            if (!$seenTarget) {
                if (self::conditions($template) === []) {
                    $shadowedBy[] = $k;
                } else {
                    $narrowedBy[] = $k;
                }
            } else {
                $takesOverFrom[] = ['key' => $k, 'pages' => self::conditions($target) === [] ? 'all' : 'those matching both'];
            }
        }

        $enabled = self::isEnabled($target);

        return [
            'type' => $type,
            'enabled' => $enabled,
            'conditions' => (object) self::conditions($target),
            'order' => $order,
            'applies' => !$enabled ? 'never: switched off' : ($shadowedBy !== [] ? 'never: an earlier template without conditions takes every page' : ($narrowedBy !== [] ? 'to the pages the earlier templates do not match' : (self::conditions($target) === [] ? 'to every page of this type' : 'to the pages its conditions match'))),
            'shadowed_by' => $shadowedBy,
            'earlier_with_conditions' => $narrowedBy,
            'takes_over_from' => $enabled && $shadowedBy === [] ? $takesOverFrom : [],
        ];
    }
}
