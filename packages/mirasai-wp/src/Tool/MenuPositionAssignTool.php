<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

/**
 * Which menu sits in a YOOtheme position. It does not create menus or touch their items
 * (docs/todo-016-decision-note-2026-11-13.md).
 */
class MenuPositionAssignTool extends AbstractTool
{
    public function getName(): string
    {
        return 'menu/position-assign';
    }

    public function getDescription(): string
    {
        return 'Assigns an existing menu to a YOOtheme menu position (navbar, dialog-mobile…), or empties the position with '
            . 'menu_id 0. This changes the header or the mobile dialog of every page. It does not create menus or edit '
            . 'their items. Positions, menus and the if_match etag come from menu/position-list. Check the home page '
            . 'afterwards with site/public-fetch.';
    }

    public function getPermissions(): array
    {
        return [
            'risk_level' => self::RISK_GUARDED_WRITE,
            'idempotent' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'position' => ['type' => 'string', 'description' => 'Position as menu/position-list names it, for example navbar.'],
                'menu_id' => ['type' => 'integer', 'description' => 'ID of an existing menu from menu/position-list, or 0 to leave the position without a menu.'],
                'if_match' => ['type' => 'string', 'description' => 'etag from menu/position-list.'],
                'dry_run' => ['type' => 'boolean', 'description' => 'Preview without writing.'],
                'confirm_guarded_write' => ['type' => 'boolean', 'description' => 'Required with dry_run=false.'],
            ],
            'required' => ['position', 'menu_id', 'if_match'],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array
    {
        $position = trim((string) ($arguments['position'] ?? ''));
        $menuId = $arguments['menu_id'] ?? null;
        $ifMatch = trim((string) ($arguments['if_match'] ?? ''));
        $dryRun = ($arguments['dry_run'] ?? null) === true;

        if (is_float($menuId) && floor($menuId) === $menuId) {
            $menuId = (int) $menuId;
        }

        if ($position === '' || !is_int($menuId) || $menuId < 0 || $ifMatch === '') {
            return ['error' => 'position, menu_id (0 or a menu ID) and if_match are required.', 'code' => 'missing_arguments'];
        }

        if (!$dryRun && ($arguments['confirm_guarded_write'] ?? null) !== true) {
            return [
                'error' => 'This is a guarded write. Retry with dry_run=true first, then confirm_guarded_write=true if the preview is correct.',
                'code' => 'guarded_write_confirmation_required',
            ];
        }

        if (!(new YoothemeWpHelper())->status()['active']) {
            return [
                'error' => 'YOOtheme is not the active theme. This tool only assigns menus to YOOtheme positions.',
                'code' => 'yootheme_not_active',
            ];
        }

        $state = MenuPositionListTool::state();

        if (!hash_equals($state['etag'], $ifMatch)) {
            return [
                'error' => 'The menu assignments changed. Call menu/position-list again and retry with its etag.',
                'code' => 'stale_etag',
                'expected_etag' => $state['etag'],
                'provided_etag' => $ifMatch,
            ];
        }

        $positions = array_column($state['positions'], null, 'position');
        $menus = array_column($state['menus'], null, 'id');

        if (!isset($positions[$position])) {
            return [
                'error' => "The theme has no menu position {$position}.",
                'code' => 'position_not_found',
                'available_positions' => array_keys($positions),
            ];
        }

        if ($menuId > 0 && !isset($menus[$menuId])) {
            return [
                'error' => "Menu {$menuId} not found. This tool does not create menus.",
                'code' => 'menu_not_found',
                'available_menus' => $state['menus'],
            ];
        }

        $before = $positions[$position]['menu_id'];
        $after = $menuId > 0 ? $menuId : null;
        $warnings = [];

        if ($after !== null && $menus[$after]['items'] === 0) {
            $warnings[] = ['code' => 'menu_empty', 'message' => 'The menu has no items, so the position shows nothing.'];
        }

        $response = [
            'dry_run' => $dryRun,
            'position' => $position,
            'would_change' => $before !== $after,
            'before' => ['menu_id' => $before, 'menu_name' => $positions[$position]['menu_name']],
            'after' => ['menu_id' => $after, 'menu_name' => $after !== null ? $menus[$after]['name'] : null],
            'warnings' => $warnings,
        ];

        if ($dryRun) {
            return $response + [
                'action' => 'preview',
                'etag' => $state['etag'],
                'note' => 'No changes were written. Retry with confirm_guarded_write=true and the same if_match.',
            ];
        }

        if ($before === $after) {
            return $response + ['action' => 'unchanged', 'etag' => $state['etag']];
        }

        // What the menu screen and the REST API do: the whole map goes back into the theme mod.
        $locations = get_nav_menu_locations();

        if ($after !== null) {
            $locations[$position] = $after;
        } else {
            unset($locations[$position]);
        }

        set_theme_mod('nav_menu_locations', $locations);

        $assigned = MenuPositionListTool::assigned();

        if (($assigned[$position] ?? null) !== $after) {
            return [
                'error' => 'WordPress did not store the assignment. Call menu/position-list to see what is assigned now.',
                'code' => 'assignment_unverified',
            ];
        }

        return $response + [
            'action' => 'updated',
            'old_etag' => $state['etag'],
            'new_etag' => MenuPositionListTool::etag($assigned),
            'next' => 'site/public-fetch on the home page; if it shows the old header, site/cache-purge.',
        ];
    }
}
