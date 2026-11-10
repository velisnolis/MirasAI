<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

/**
 * A row's layout prop against the widths of its columns (F-044).
 *
 * The page and the Builder read different fields. The row template takes the
 * widths from each column's width_* and uses layout only to force
 * uk-child-width-1-1 when it is empty. The Builder's row panel reads layout: an
 * empty one shows «Whole», and «Edit layout» rewrites every column from it
 * (columnWidths() in the Builder: first segment width_medium, then after «|»
 * width_small, then width_default; the other widths are dropped).
 *
 * Mirrored in mirasai-wp. Both copies must stay free of WordPress and
 * Joomla calls so docker/test-row-layout.php can run them as plain PHP.
 */
final class YoothemeRowLayout
{
    private const BREAKPOINTS = ['medium', 'small', 'default'];

    /**
     * The layouts the installed Builder offers, null when the row definition gives none.
     *
     * @param array<string, mixed> $rowDefinition
     * @return list<string>|null
     */
    public static function options(array $rowDefinition): ?array
    {
        $options = $rowDefinition['fields']['layout']['options'] ?? null;

        if (!is_array($options) || $options === []) {
            return null;
        }

        return array_values(array_filter(array_map('strval', array_keys($options)), static fn (string $key): bool => $key !== ''));
    }

    /**
     * The layout value that describes the columns as they are stored.
     *
     * @param array<string, mixed> $row
     */
    public static function fromColumns(array $row): string
    {
        $segments = self::columnSegments(self::columns($row));
        $last = 0;

        foreach ($segments as $index => $values) {
            if (implode('', $values) !== '') {
                $last = $index;
            }
        }

        return implode('|', array_map(static fn (array $values): string => implode(',', $values), array_slice($segments, 0, $last + 1)));
    }

    /**
     * null when the Builder will show this row as it renders; otherwise what is wrong and the layout to set.
     *
     * @param array<string, mixed> $row
     * @param list<string>|null $options from options(), null when unknown
     * @return array{code: string, reason: string, columns: int, layout: string, columns_layout: string, suggested_layout: ?string}|null
     */
    public static function check(array $row, ?array $options): ?array
    {
        $columns = self::columns($row);

        if (count($columns) < 2) {
            return null;
        }

        $props = is_array($row['props'] ?? null) ? $row['props'] : [];
        $layout = is_string($props['layout'] ?? null) ? trim($props['layout']) : '';

        if ($layout === '') {
            // Columns without any width stack on the page, and the Builder shows them stacked as «Whole» too.
            if (implode('', array_merge(...self::columnSegments($columns))) === '') {
                return null;
            }

            $reason = 'missing';
        } elseif ($options !== null && !in_array($layout, $options, true)) {
            $reason = 'not_an_option';
        } elseif (!self::fits($layout, $columns)) {
            $reason = 'differs';
        } else {
            return null;
        }

        return [
            'code' => 'row_layout_mismatch',
            'reason' => $reason,
            'columns' => count($columns),
            'layout' => $layout,
            'columns_layout' => self::fromColumns($row),
            'suggested_layout' => self::suggest($columns, $options),
        ];
    }

    /**
     * The row with layout set, when it is missing and the columns match one layout.
     *
     * @param array<string, mixed> $row
     * @param list<string>|null $options
     * @return array<string, mixed>|null null when there is nothing safe to fill
     */
    public static function filled(array $row, ?array $options): ?array
    {
        $issue = self::check($row, $options);

        if ($issue === null || $issue['reason'] !== 'missing' || $issue['suggested_layout'] === null) {
            return null;
        }

        $props = is_array($row['props'] ?? null) ? $row['props'] : [];
        $props['layout'] = $issue['suggested_layout'];
        $row['props'] = $props;

        return $row;
    }

    /**
     * One sentence for a finding or a warning.
     *
     * @param array<string, mixed> $issue from check()
     */
    public static function message(array $issue): string
    {
        $count = $issue['columns'];

        return match ($issue['reason']) {
            'missing' => "Row with {$count} columns and no layout: the page shows the column widths, the Builder shows «Whole» and «Edit layout» resets them.",
            'not_an_option' => "Row layout «{$issue['layout']}» is not one the Builder offers, so its panel cannot show it.",
            default => "Row layout «{$issue['layout']}» does not match its columns ({$issue['columns_layout']}); «Edit layout» in the Builder would rewrite the column widths.",
        };
    }

    /**
     * What to do about it.
     *
     * @param array<string, mixed> $issue from check()
     */
    public static function fix(array $issue): string
    {
        if ($issue['suggested_layout'] === null) {
            return 'The column widths match no Builder layout. Give every column a width_medium from one of the Builder layouts, then set the row layout to it.';
        }

        return "Set layout to «{$issue['suggested_layout']}» on this row with template/element-update-props.";
    }

    /**
     * Does layout describe these columns? Every column needs its width_medium from the first segment.
     * A later segment may be missing from the columns: the page then keeps the earlier width, and the
     * Builder fills it in when the layout is edited. A width the layout does not carry may not be there.
     *
     * @param list<array<string, mixed>> $columns
     */
    private static function fits(string $layout, array $columns): bool
    {
        $wanted = explode('|', $layout);

        if (count($wanted) > count(self::BREAKPOINTS)) {
            return false;
        }

        foreach (self::columnSegments($columns) as $index => $values) {
            $segment = $wanted[$index] ?? null;

            if ($segment === null) {
                if (implode('', $values) !== '') {
                    return false;
                }

                continue;
            }

            $widths = explode(',', $segment);

            if (count($widths) !== count($columns) || in_array('', $widths, true)) {
                return false;
            }

            if ($index === 0 ? $values !== $widths : (implode('', $values) !== '' && $values !== $widths)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $columns
     * @param list<string>|null $options
     */
    private static function suggest(array $columns, ?array $options): ?string
    {
        $own = self::fromColumns(['children' => $columns]);
        $candidates = $options ?? [$own];

        if (in_array($own, $candidates, true) && self::fits($own, $columns)) {
            return $own;
        }

        foreach ($candidates as $candidate) {
            if (self::fits($candidate, $columns)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $columns
     * @return list<list<string>> one list of widths per breakpoint, in Builder order
     */
    private static function columnSegments(array $columns): array
    {
        $segments = [];

        foreach (self::BREAKPOINTS as $breakpoint) {
            $segments[] = array_map(static function (array $column) use ($breakpoint): string {
                $value = $column['props']['width_' . $breakpoint] ?? '';

                return is_string($value) ? trim($value) : '';
            }, $columns);
        }

        return $segments;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    private static function columns(array $row): array
    {
        $children = is_array($row['children'] ?? null) ? $row['children'] : [];

        return array_values(array_filter($children, 'is_array'));
    }
}
