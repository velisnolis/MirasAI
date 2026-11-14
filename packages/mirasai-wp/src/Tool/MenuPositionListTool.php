<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class MenuPositionListTool extends AbstractTool
{
    public function getName(): string
    {
        return 'menu/position-list';
    }

    public function getDescription(): string
    {
        return 'Lists the menu positions of the active theme (navbar, dialog-mobile… on YOOtheme) with the menu assigned to '
            . 'each, and the menus that exist. A site with no menu in any position can show no header at all. etag is the '
            . 'if_match of menu/position-assign. It does not list or edit menu items.';
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array
    {
        return ['yootheme_active' => (bool) (new YoothemeWpHelper())->status()['active']] + self::state();
    }

    /**
     * @return array{stylesheet: string, positions: list<array<string, mixed>>, menus: list<array<string, mixed>>, etag: string}
     */
    public static function state(): array
    {
        $menus = [];

        foreach (wp_get_nav_menus() as $menu) {
            $menus[(int) $menu->term_id] = [
                'id' => (int) $menu->term_id,
                'name' => (string) $menu->name,
                'items' => (int) $menu->count,
            ];
        }

        $assigned = self::assigned();
        $positions = [];

        foreach (get_registered_nav_menus() as $position => $label) {
            $menuId = $assigned[(string) $position] ?? null;
            $positions[] = [
                'position' => (string) $position,
                'label' => (string) $label,
                'menu_id' => $menuId,
                'menu_name' => $menuId !== null ? ($menus[$menuId]['name'] ?? null) : null,
            ];
        }

        return [
            'stylesheet' => (string) get_stylesheet(),
            'positions' => $positions,
            'menus' => array_values($menus),
            'etag' => self::etag($assigned),
        ];
    }

    /**
     * Position => menu ID, as the active theme stores it. WordPress leaves a 0 behind when a menu
     * is deleted; that is the same as no menu.
     *
     * @return array<string, int>
     */
    public static function assigned(): array
    {
        $assigned = [];

        foreach (get_nav_menu_locations() as $position => $menuId) {
            if ((int) $menuId > 0) {
                $assigned[(string) $position] = (int) $menuId;
            }
        }

        ksort($assigned);

        return $assigned;
    }

    /**
     * The Style etag does not move when a menu is assigned, so the assignments carry their own.
     *
     * @param array<string, int> $assigned
     */
    public static function etag(array $assigned): string
    {
        $encoded = wp_json_encode(['stylesheet' => (string) get_stylesheet(), 'positions' => $assigned]);

        return hash('sha256', is_string($encoded) ? $encoded : '');
    }
}
