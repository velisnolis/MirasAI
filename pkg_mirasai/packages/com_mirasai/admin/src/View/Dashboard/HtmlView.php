<?php

declare(strict_types=1);

namespace Mirasai\Component\Mirasai\Administrator\View\Dashboard;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\Version;
use Joomla\Database\DatabaseInterface;
use Mirasai\Library\Mirasai;
use Mirasai\Library\Sandbox\ElevationService;
use Mirasai\Library\Tool\ToolRegistry;

class HtmlView extends BaseHtmlView
{
    /** @var array<string, mixed> */
    protected array $systemInfo = [];

    /** @var list<array<string, mixed>> */
    protected array $translationStats = [];

    /** @var string */
    protected string $mcpEndpoint = '';

    /** @var string */
    protected string $mirasaiVersion = '';

    /** @var bool */
    protected bool $allCoreEnabled = true;

    /** @var list<array{name: string, description: string, provider: string, destructive: bool}> */
    protected array $toolSummary = [];

    /** @var array<string, list<array{name: string, description: string, provider: string, destructive: bool}>> */
    protected array $toolsByDomain = [];

    /** @var list<array<string, mixed>> */
    protected array $coreExtensions = [];

    /** @var list<array<string, mixed>> */
    protected array $addonPlugins = [];

    /** @var bool */
    protected bool $elevationActive = false;

    public function display($tpl = null): void
    {
        $this->mirasaiVersion = Mirasai::VERSION;
        $this->mcpEndpoint = rtrim(\Joomla\CMS\Uri\Uri::root(), '/') . '/api/v1/mirasai/mcp';
        $this->systemInfo = $this->getSystemInfo();
        $this->translationStats = $this->getTranslationStats();

        // Extensions — separate core from addons
        [$this->coreExtensions, $this->addonPlugins] = $this->getExtensions();
        $this->allCoreEnabled = $this->checkAllCoreEnabled();

        // Tools — from ToolRegistry (dynamic, no hardcoding)
        $this->toolSummary = $this->getToolSummary();
        $this->toolsByDomain = $this->groupToolsByDomain($this->toolSummary);

        // Elevation
        $this->elevationActive = $this->checkElevation();

        ToolbarHelper::title('MirasAI', 'bolt');

        parent::display($tpl);
    }

    /**
     * @return array<string, mixed>
     */
    private function getSystemInfo(): array
    {
        $version = new Version();

        // YOOtheme
        $ytVersion = null;
        $ytPath = JPATH_ROOT . '/templates/yootheme/templateDetails.xml';
        if (file_exists($ytPath)) {
            $xml = simplexml_load_file($ytPath);
            $ytVersion = $xml ? (string) $xml->version : null;
        }

        return [
            'joomla_version' => $version->getShortVersion(),
            'php_version' => PHP_VERSION,
            'yootheme_version' => $ytVersion,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getTranslationStats(): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $query = $db->getQuery(true)
            ->select([
                'c.language',
                'COUNT(c.id) AS total',
                'SUM(CASE WHEN ' . $db->quoteName('c.fulltext') . ' LIKE ' . $db->quote('%<!-- {%') . ' THEN 1 ELSE 0 END) AS with_yootheme',
            ])
            ->from($db->quoteName('#__content', 'c'))
            ->where('c.state >= 0')
            ->group('c.language')
            ->order('c.language');

        return $db->setQuery($query)->loadAssocList() ?: [];
    }

    /**
     * Get extensions split into core and addon arrays.
     *
     * Core: library mirasai, plugin system/mirasai, plugin webservices/mirasai, component com_mirasai.
     * Addons: plugins in the 'mirasai' folder group.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function getExtensions(): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        // Core extensions — explicit type+folder+element checks
        $coreConditions = implode(' OR ', [
            '(' . $db->quoteName('type') . ' = ' . $db->quote('library') . ' AND ' . $db->quoteName('element') . ' = ' . $db->quote('mirasai') . ')',
            '(' . $db->quoteName('type') . ' = ' . $db->quote('plugin') . ' AND ' . $db->quoteName('folder') . ' = ' . $db->quote('system') . ' AND ' . $db->quoteName('element') . ' = ' . $db->quote('mirasai') . ')',
            '(' . $db->quoteName('type') . ' = ' . $db->quote('plugin') . ' AND ' . $db->quoteName('folder') . ' = ' . $db->quote('webservices') . ' AND ' . $db->quoteName('element') . ' = ' . $db->quote('mirasai') . ')',
            '(' . $db->quoteName('type') . ' = ' . $db->quote('component') . ' AND ' . $db->quoteName('element') . ' = ' . $db->quote('com_mirasai') . ')',
        ]);

        $query = $db->getQuery(true)
            ->select(['name', 'element', 'folder', 'type', 'enabled'])
            ->from($db->quoteName('#__extensions'))
            ->where('(' . $coreConditions . ')')
            ->order('type, name');

        $core = $db->setQuery($query)->loadAssocList() ?: [];

        // Addon plugins — 'mirasai' plugin group
        $query = $db->getQuery(true)
            ->select(['name', 'element', 'folder', 'type', 'enabled'])
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('mirasai'))
            ->order('name');

        $addons = $db->setQuery($query)->loadAssocList() ?: [];

        return [$core, $addons];
    }

    private function checkAllCoreEnabled(): bool
    {
        foreach ($this->coreExtensions as $ext) {
            if (!(int) $ext['enabled']) {
                return false;
            }
        }

        // Also check we found at least the minimum core extensions
        return count($this->coreExtensions) >= 2;
    }

    /**
     * @return list<array{name: string, description: string, provider: string, destructive: bool}>
     */
    private function getToolSummary(): array
    {
        try {
            $registry = ToolRegistry::buildDefault();

            return $registry->toToolSummaryList();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Group tools by their domain prefix (content/*, file/*, etc.).
     *
     * @param list<array{name: string, description: string, provider: string, destructive: bool}> $tools
     * @return array<string, list<array{name: string, description: string, provider: string, destructive: bool}>>
     */
    private function groupToolsByDomain(array $tools): array
    {
        $grouped = [];

        foreach ($tools as $tool) {
            $parts = explode('/', $tool['name'], 2);
            $domain = $parts[0] ?? 'other';
            $grouped[$domain][] = $tool;
        }

        return $grouped;
    }

    private function checkElevation(): bool
    {
        try {
            $elevation = new ElevationService();
            $grant = $elevation->getActiveGrant();

            return $grant !== null && $grant->isActive();
        } catch (\Throwable) {
            return false;
        }
    }
}
