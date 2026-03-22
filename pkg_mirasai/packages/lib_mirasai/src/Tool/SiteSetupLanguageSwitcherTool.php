<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

use Joomla\Database\ParameterType;

class SiteSetupLanguageSwitcherTool extends AbstractTool
{
    public function getName(): string
    {
        return 'site/setup-language-switcher';
    }

    public function getDescription(): string
    {
        return 'Checks if a language switcher exists and, if not, creates and publishes a mod_languages module in the appropriate position. Detects YOOtheme theme positions automatically.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'position' => [
                    'type' => 'string',
                    'description' => 'Module position to publish the language switcher to. If omitted, auto-detects from the theme (toolbar-right, header, navbar).',
                ],
                'style' => [
                    'type' => 'string',
                    'description' => 'Display style: "flags" (flag icons), "text" (language names), or "both". Default: "flags".',
                ],
            ],
        ];
    }

    public function getPermissions(): array
    {
        return [
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ];
    }

    public function handle(array $arguments): array
    {
        // Check if one already exists
        $query = $this->db->getQuery(true)
            ->select(['id', 'title', 'position', 'published'])
            ->from($this->db->quoteName('#__modules'))
            ->where('module = ' . $this->db->quote('mod_languages'))
            ->where('client_id = 0');

        $existing = $this->db->setQuery($query)->loadAssoc();

        if ($existing && (int) $existing['published'] === 1) {
            return [
                'action' => 'already_exists',
                'module_id' => (int) $existing['id'],
                'position' => $existing['position'],
                'hint' => 'Language switcher already published at position "' . $existing['position'] . '".',
            ];
        }

        // If exists but unpublished, publish it
        if ($existing) {
            $query = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__modules'))
                ->set($this->db->quoteName('published') . ' = 1')
                ->where('id = ' . (int) $existing['id']);

            $this->db->setQuery($query)->execute();

            return [
                'action' => 'published',
                'module_id' => (int) $existing['id'],
                'position' => $existing['position'],
                'hint' => 'Existing language switcher was unpublished. Now published.',
            ];
        }

        // Create new mod_languages
        $position = $arguments['position'] ?? $this->detectBestPosition();
        $style = $arguments['style'] ?? 'flags';

        $params = [
            'header_text' => '',
            'footer_text' => '',
            'dropdown' => '0',
            'image' => $style === 'text' ? '0' : '1',
            'inline' => '1',
            'show_active' => '1',
            'full_name' => $style === 'flags' ? '0' : '1',
            'layout' => '_:default',
            'moduleclass_sfx' => '',
            'cache' => '0',
            'cache_time' => '900',
            'cachemode' => 'itemid',
        ];

        $paramsJson = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Get the extensions ID for mod_languages
        $query = $this->db->getQuery(true)
            ->select('extension_id')
            ->from($this->db->quoteName('#__extensions'))
            ->where('element = ' . $this->db->quote('mod_languages'))
            ->where('type = ' . $this->db->quote('module'));

        $extId = (int) $this->db->setQuery($query)->loadResult();

        // Insert module
        $columns = [
            'title', 'note', 'content', 'ordering', 'position',
            'checked_out', 'checked_out_time', 'publish_up', 'publish_down',
            'published', 'module', 'access', 'showtitle', 'params',
            'client_id', 'language',
        ];

        $values = [
            $this->db->quote('Language Switcher'),
            $this->db->quote(''),
            $this->db->quote(''),
            0,
            $this->db->quote($position),
            'NULL',
            'NULL',
            'NULL',
            'NULL',
            1,
            $this->db->quote('mod_languages'),
            1,
            0,
            $this->db->quote($paramsJson),
            0,
            $this->db->quote('*'),
        ];

        $query = 'INSERT INTO ' . $this->db->quoteName('#__modules')
            . ' (' . implode(',', array_map([$this->db, 'quoteName'], $columns)) . ')'
            . ' VALUES (' . implode(',', $values) . ')';

        $this->db->setQuery($query)->execute();

        $moduleId = (int) $this->db->insertid();

        // Assign to all pages (menu_id = 0 means all pages)
        $query = $this->db->getQuery(true)
            ->insert($this->db->quoteName('#__modules_menu'))
            ->columns(['moduleid', 'menuid'])
            ->values($moduleId . ', 0');

        $this->db->setQuery($query)->execute();

        return [
            'action' => 'created',
            'module_id' => $moduleId,
            'position' => $position,
            'style' => $style,
            'hint' => "Language switcher created at position \"{$position}\" and published on all pages.",
        ];
    }

    private function detectBestPosition(): string
    {
        // Check if YOOtheme is installed and detect its positions
        $query = $this->db->getQuery(true)
            ->select('params')
            ->from($this->db->quoteName('#__template_styles'))
            ->where('template = ' . $this->db->quote('yootheme'));

        $params = $this->db->setQuery($query)->loadResult();

        if ($params) {
            $config = json_decode($params, true);
            $configInner = isset($config['config']) ? json_decode($config['config'], true) : [];

            // YOOtheme positions — prefer toolbar, then header
            if (isset($configInner['menu']['positions']['toolbar-right'])) {
                return 'toolbar-right';
            }

            if (isset($configInner['menu']['positions']['toolbar-left'])) {
                return 'toolbar-left';
            }
        }

        // Fallback for YOOtheme
        return 'toolbar-right';
    }
}
