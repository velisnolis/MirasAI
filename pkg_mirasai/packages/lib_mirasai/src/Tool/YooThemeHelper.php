<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Template and style-level helpers for YOOtheme Pro.
 *
 * Handles reading/writing #__template_styles params, YOOtheme custom_data
 * (templates), and template metadata (name, language, fingerprint, etc.).
 *
 * NOTE: This file lives temporarily in lib_mirasai while the plugin architecture
 * refactor is in progress. It will move to plg_mirasai_yootheme/src/ in Phase 4.
 */
class YooThemeHelper
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    // -------------------------------------------------------------------------
    // Template style helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the active site-side YOOtheme template style ID.
     */
    public function resolveActiveStyleId(): ?int
    {
        $query = $this->db->getQuery(true)
            ->select('id')
            ->from($this->db->quoteName('#__template_styles'))
            ->where('template = ' . $this->db->quote('yootheme'))
            ->where('client_id = 0')
            ->where('home = 1');

        $result = $this->db->setQuery($query)->loadResult();

        return $result ? (int) $result : null;
    }

    /**
     * Check that a style ID points at a site-side YOOtheme style.
     */
    public function isYoothemeSiteStyle(int $styleId): bool
    {
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__template_styles'))
            ->where('id = :id')
            ->where('template = ' . $this->db->quote('yootheme'))
            ->where('client_id = 0')
            ->bind(':id', $styleId, ParameterType::INTEGER);

        return (int) $this->db->setQuery($query)->loadResult() > 0;
    }

    /**
     * Load the raw params array for a template style.
     *
     * @return array<string, mixed>|null
     */
    public function loadStyleParams(int $styleId): ?array
    {
        $query = $this->db->getQuery(true)
            ->select('params')
            ->from($this->db->quoteName('#__template_styles'))
            ->where('id = :id')
            ->bind(':id', $styleId, ParameterType::INTEGER);

        $paramsJson = $this->db->setQuery($query)->loadResult();

        if (!is_string($paramsJson) || $paramsJson === '') {
            return null;
        }

        $params = json_decode($paramsJson, true);

        return is_array($params) ? $params : null;
    }

    /**
     * Persist the raw params array for a template style.
     *
     * @param array<string, mixed> $params
     */
    public function writeStyleParams(int $styleId, array $params): void
    {
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__template_styles'))
            ->set(
                $this->db->quoteName('params') . ' = ' . $this->db->quote(
                    json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                )
            )
            ->where('id = :id')
            ->bind(':id', $styleId, ParameterType::INTEGER);

        $this->db->setQuery($query)->execute();
    }

    /**
     * Load the decoded YOOtheme config payload stored inside template style params.
     *
     * @return array<string, mixed>|null
     */
    public function loadStyleConfig(int $styleId): ?array
    {
        $params = $this->loadStyleParams($styleId);

        if ($params === null) {
            return null;
        }

        $configJson = $params['config'] ?? null;

        if (!is_string($configJson) || $configJson === '') {
            return [];
        }

        $config = json_decode($configJson, true);

        return is_array($config) ? $config : [];
    }

    // -------------------------------------------------------------------------
    // System custom_data helpers (templates)
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    public function loadSystemCustomData(): ?array
    {
        $query = $this->db->getQuery(true)
            ->select('custom_data')
            ->from($this->db->quoteName('#__extensions'))
            ->where('element = ' . $this->db->quote('yootheme'))
            ->where('folder = ' . $this->db->quote('system'));

        $customDataJson = $this->db->setQuery($query)->loadResult();

        if (!is_string($customDataJson) || $customDataJson === '') {
            return null;
        }

        $data = json_decode($customDataJson, true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function writeSystemCustomData(array $data): void
    {
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__extensions'))
            ->set(
                $this->db->quoteName('custom_data') . ' = ' . $this->db->quote(
                    json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                )
            )
            ->where('element = ' . $this->db->quote('yootheme'))
            ->where('folder = ' . $this->db->quote('system'));

        $this->db->setQuery($query)->execute();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function loadTemplates(): array
    {
        $data = $this->loadSystemCustomData();
        $templates = $data['templates'] ?? [];

        return is_array($templates) ? $templates : [];
    }

    /**
     * @param array<string, array<string, mixed>> $templates
     */
    public function writeTemplates(array $templates): void
    {
        $data = $this->loadSystemCustomData() ?? [];
        $data['templates'] = $templates;
        $this->writeSystemCustomData($data);
    }

    // -------------------------------------------------------------------------
    // Template metadata helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $template
     */
    public function getTemplateName(array $template): string
    {
        $name = $template['name'] ?? '';

        return is_string($name) ? $name : '';
    }

    /**
     * @param array<string, mixed> $template
     */
    public function getTemplateLanguage(array $template): string
    {
        $query = $template['query'] ?? null;

        if (!is_array($query)) {
            return '';
        }

        $lang = $query['lang'] ?? '';

        return is_string($lang) ? trim($lang) : '';
    }

    /**
     * @param array<string, mixed> $template
     */
    public function setTemplateLanguage(array &$template, string $language): void
    {
        if (!isset($template['query']) || !is_array($template['query'])) {
            $template['query'] = [];
        }

        $template['query']['lang'] = $language;
    }

    /**
     * @param array<string, mixed> $template
     * @return array<string, mixed>|null
     */
    public function getTemplateLayout(array $template): ?array
    {
        $layout = $template['layout'] ?? null;

        return is_array($layout) ? $layout : null;
    }

    /**
     * @param array<string, mixed> $template
     * @param array<string, mixed> $layout
     */
    public function setTemplateLayout(array &$template, array $layout): void
    {
        $template['layout'] = $layout;
    }

    /**
     * Return all translatable text nodes in a template layout.
     *
     * @param array<string, mixed> $template
     * @return list<array{path: string, node_type: string, field: string, text: string, format: string}>
     */
    public function findTemplateTranslatableNodes(array $template): array
    {
        $layout = $this->getTemplateLayout($template);

        if ($layout === null) {
            return [];
        }

        return (new YooThemeLayoutProcessor())->findTranslatableNodes($layout);
    }

    /**
     * @param array<string, mixed> $template
     */
    public function templateHasStaticText(array $template): bool
    {
        return $this->findTemplateTranslatableNodes($template) !== [];
    }

    /**
     * Build a stable fingerprint of a template's assignment criteria,
     * excluding mutable fields (name, layout, status, language).
     *
     * @param array<string, mixed> $template
     */
    public function buildTemplateAssignmentFingerprint(array $template): string
    {
        $copy = $template;
        unset($copy['name'], $copy['layout'], $copy['status']);

        if (isset($copy['query']) && is_array($copy['query'])) {
            unset($copy['query']['lang']);
        }

        $copy = $this->sortRecursive($copy);

        return hash(
            'sha256',
            json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
        );
    }

    public function generateStorageKey(int $length = 8): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $max = strlen($alphabet) - 1;
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $alphabet[random_int(0, $max)];
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function sortRecursive(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursive($item);
            }
        }

        ksort($value);

        return $value;
    }
}
