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
    protected array $toolGroups = [];

    /** @var list<array<string, mixed>> */
    protected array $coreExtensions = [];

    /** @var list<array<string, mixed>> */
    protected array $addonPlugins = [];

    /** @var int */
    protected int $configuredLanguageCount = 0;

    /** @var array<string, array<string, mixed>> */
    protected array $addonProviderSummary = [];

    /** @var bool */
    protected bool $elevationActive = false;

    /** @var string */
    protected string $dashboardStatus = 'inactive';

    /** @var bool */
    protected bool $registryReady = false;

    /** @var int */
    protected int $registryWarningCount = 0;

    private const EXPECTED_CORE_EXTENSION_COUNT = 4;

    public function display($tpl = null): void
    {
        $this->mirasaiVersion = Mirasai::VERSION;
        $this->mcpEndpoint = rtrim(\Joomla\CMS\Uri\Uri::root(), '/') . '/api/v1/mirasai/mcp';
        $this->systemInfo = $this->getSystemInfo();
        $this->translationStats = $this->getTranslationStats();
        $this->configuredLanguageCount = $this->getConfiguredLanguageCount();

        // Extensions — separate core from addons
        [$this->coreExtensions, $this->addonPlugins] = $this->getExtensions();
        $this->allCoreEnabled = $this->checkAllCoreEnabled();

        // Tools — from ToolRegistry (dynamic, no hardcoding)
        $registry = $this->buildRegistry();
        $this->registryReady = $registry !== null;
        $this->toolSummary = $registry?->toToolSummaryList() ?? [];
        $this->toolsByDomain = $this->groupToolsByDomain($this->toolSummary);
        $this->addonProviderSummary = $this->buildAddonProviderSummary($registry);
        $this->toolGroups = $this->buildToolGroups();
        $this->registryWarningCount = $registry?->hasWarnings() ? count($registry->getWarnings()) : 0;
        $this->dashboardStatus = $this->determineDashboardStatus();

        // Elevation
        $this->elevationActive = $this->checkElevation();

        ToolbarHelper::title(Text::_('COM_MIRASAI_DASHBOARD_TITLE'), 'bolt');

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

        $languageQuery = $db->getQuery(true)
            ->select([
                $db->quoteName('lang_code', 'language'),
                $db->quoteName('title'),
            ])
            ->from($db->quoteName('#__languages'))
            ->where($db->quoteName('published') . ' = 1')
            ->order($db->quoteName('ordering'));

        /** @var list<array{language: string, title: string}> $languages */
        $languages = $db->setQuery($languageQuery)->loadAssocList() ?: [];

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

        /** @var list<array{language: string, total: string, with_yootheme: string}> $contentStats */
        $contentStats = $db->setQuery($query)->loadAssocList() ?: [];
        $contentByLanguage = [];

        foreach ($contentStats as $row) {
            $contentByLanguage[(string) $row['language']] = $row;
        }

        $stats = [];

        foreach ($languages as $language) {
            $code = (string) $language['language'];
            $row = $contentByLanguage[$code] ?? ['total' => 0, 'with_yootheme' => 0];
            $stats[] = [
                'language' => $code,
                'title' => (string) $language['title'],
                'total' => (int) $row['total'],
                'with_yootheme' => (int) $row['with_yootheme'],
            ];
        }

        if (isset($contentByLanguage['*'])) {
            $stats[] = [
                'language' => '*',
                'title' => Text::_('COM_MIRASAI_TRANSLATIONS_ALL_LANGUAGES'),
                'total' => (int) $contentByLanguage['*']['total'],
                'with_yootheme' => (int) $contentByLanguage['*']['with_yootheme'],
            ];
        }

        return $stats;
    }

    private function getConfiguredLanguageCount(): int
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__languages'))
            ->where($db->quoteName('published') . ' = 1');

        return (int) $db->setQuery($query)->loadResult();
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
        if (count($this->coreExtensions) !== self::EXPECTED_CORE_EXTENSION_COUNT) {
            return false;
        }

        foreach ($this->coreExtensions as $ext) {
            if (!(int) $ext['enabled']) {
                return false;
            }
        }

        return true;
    }

    private function buildRegistry(): ?ToolRegistry
    {
        try {
            return ToolRegistry::buildDefault();
        } catch (\Throwable) {
            return null;
        }
    }

    private function determineDashboardStatus(): string
    {
        if (!$this->allCoreEnabled || !$this->registryReady) {
            return 'inactive';
        }

        if ($this->registryWarningCount > 0) {
            return 'degraded';
        }

        return 'active';
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

    /**
     * @return list<array<string, mixed>>
     */
    private function buildToolGroups(): array
    {
        $groups = [];
        $coreTools = array_values(array_filter(
            $this->toolSummary,
            static fn (array $tool): bool => ($tool['provider'] ?? 'unknown') === 'core',
        ));

        $groups[] = [
            'id' => 'core',
            'title' => Text::_('COM_MIRASAI_TOOLS_CORE_SECTION'),
            'subtitle' => Text::_('COM_MIRASAI_TOOLS_CORE_HELPER'),
            'state' => $this->allCoreEnabled ? 'active' : 'disabled',
            'count' => count($coreTools),
            'provider_name' => Text::_('COM_MIRASAI_TOOLS_CORE'),
            'tools_by_domain' => $this->groupToolsByDomain($coreTools),
            'open' => true,
        ];

        foreach ($this->addonPlugins as $addon) {
            $element = (string) ($addon['element'] ?? '');
            $enabled = (bool) ((int) ($addon['enabled'] ?? 0));
            $providerInfo = $this->addonProviderSummary[$element] ?? [
                'provider_id' => '',
                'provider_name' => '',
                'available' => false,
                'registered_tools' => 0,
            ];

            $providerId = (string) ($providerInfo['provider_id'] ?? '');
            $addonTools = array_values(array_filter(
                $this->toolSummary,
                static fn (array $tool): bool => $providerId !== '' && ($tool['provider'] ?? 'unknown') === $providerId,
            ));

            $state = !$enabled ? 'disabled' : ((bool) ($providerInfo['available'] ?? false) ? 'active' : 'unavailable');
            $subtitle = match ($state) {
                'disabled' => Text::_('COM_MIRASAI_TOOLS_GROUP_DISABLED'),
                'unavailable' => Text::_('COM_MIRASAI_TOOLS_GROUP_UNAVAILABLE'),
                default => Text::_('COM_MIRASAI_TOOLS_GROUP_ACTIVE'),
            };

            $groups[] = [
                'id' => 'addon-' . $element,
                'title' => (string) ($providerInfo['provider_name'] ?: $element),
                'subtitle' => $subtitle,
                'state' => $state,
                'count' => count($addonTools),
                'provider_name' => (string) ($providerInfo['provider_name'] ?: $element),
                'plugin_element' => $element,
                'tools_by_domain' => $this->groupToolsByDomain($addonTools),
                'open' => false,
            ];
        }

        return $groups;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildAddonProviderSummary(?ToolRegistry $registry): array
    {
        $providerMap = $registry?->getProviderSummaryMap() ?? [];
        $summary = [];

        $providersByElement = [];

        foreach ($providerMap as $providerInfo) {
            $element = (string) ($providerInfo['plugin_element'] ?? '');

            if ($element !== '') {
                $providersByElement[$element] = $providerInfo;
            }
        }

        foreach ($this->addonPlugins as $addon) {
            $element = (string) ($addon['element'] ?? '');
            $providerInfo = $providersByElement[$element] ?? null;

            $summary[$element] = [
                'provider_id' => (string) ($providerInfo['id'] ?? ''),
                'provider_name' => (string) ($providerInfo['name'] ?? ''),
                'available' => (bool) ($providerInfo['available'] ?? false),
                'registered_tools' => (int) ($providerInfo['registered_tools'] ?? 0),
            ];
        }

        return $summary;
    }
}
