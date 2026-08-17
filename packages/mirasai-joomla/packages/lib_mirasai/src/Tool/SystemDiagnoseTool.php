<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

use Joomla\CMS\Version;
use Mirasai\Library\Mirasai;
use Mirasai\Library\Sandbox\ElevationService;
use Mirasai\Library\Sandbox\EnvironmentGuard;

class SystemDiagnoseTool extends AbstractTool
{
    public function getName(): string
    {
        return 'system/diagnose';
    }

    public function getDescription(): string
    {
        return 'Runs a compact MirasAI diagnostic and the agent playbook: environment, tools, YOOtheme counts, elevation, and which channel (this host, local router, mcp2cli, SSH) to use for each job. Call this first after install. Do not trial-and-error YOOtheme Style writes.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
        ];
    }

    public function handle(array $arguments): array
    {
        $registry = ToolRegistry::buildDefault();
        $toolNames = $registry->names();
        $toolSummary = $registry->toToolSummaryList();
        $providerSummaries = array_values($registry->getProviderSummaryMap());
        $registryWarnings = $registry->getWarnings();
        $yootheme = $this->getYoothemeDiagnostics();
        $environment = EnvironmentGuard::isStaging() ? 'staging' : 'production';
        $elevation = $this->getElevationDiagnostics($environment);

        $checks = [];
        $this->addCheck($checks, 'mirasai_core_tools', $this->hasTools($toolNames, [
            'system/info',
            'system/diagnose',
            'content/list',
            'content/read',
            'elevation/status',
        ]), 'Core MirasAI tools are registered.');
        $this->addCheck($checks, 'provider_warnings', $registryWarnings === [], 'No provider registration warnings.', $registryWarnings);
        $this->addCheck($checks, 'yootheme_addon', !$yootheme['installed'] || $this->hasTools($toolNames, [
            'template/list',
            'template/summary',
            'template/element-types',
            'template/element-schema',
            'template/source-types',
            'template/style-read',
            'template/style-sources',
            'template/style-update',
            'template/element-list',
            'template/element-read',
            'template/element-source-read',
            'template/element-source-preview',
            'template/element-source-set',
            'template/element-source-delete',
            'template/element-add',
            'template/element-update-props',
            'template/element-move',
            'template/element-clone',
            'template/element-delete',
            'template/read',
        ]), 'YOOtheme addon tools are registered when YOOtheme is installed.');
        $this->addCheck($checks, 'production_elevation', $environment !== 'production' || !$elevation['active'], 'Production elevation is inactive by default.', $elevation);

        $status = $this->overallStatus($checks);

        return [
            'status' => $status,
            'mirasai_version' => Mirasai::VERSION,
            'host_contract_version' => Mirasai::CONTRACT_VERSION,
            'cms' => [
                'name' => 'Joomla',
                'version' => (new Version())->getShortVersion(),
                'php_version' => PHP_VERSION,
            ],
            'environment' => $environment,
            'registry' => [
                'tool_count' => count($toolNames),
                'key_tools' => $this->keyToolMap($toolNames),
                'risk_levels' => $this->riskLevelCounts($toolSummary),
                'providers' => $providerSummaries,
                'warnings' => $registryWarnings,
            ],
            'yootheme' => $yootheme,
            'elevation' => $elevation,
            'playbook' => AgentPlaybook::build(),
            'checks' => $checks,
        ];
    }

    /**
     * @return array{installed: bool, template_version: string|null, system_plugin_enabled: bool, template_count: int, yootheme_article_count: int}
     */
    private function getYoothemeDiagnostics(): array
    {
        $templatePath = JPATH_ROOT . '/templates/yootheme/templateDetails.xml';
        $version = null;

        if (is_file($templatePath)) {
            $xml = simplexml_load_file($templatePath);
            $version = $xml ? (string) $xml->version : null;
        }

        return [
            'installed' => is_file($templatePath),
            'template_version' => $version,
            'system_plugin_enabled' => $this->isPluginEnabled('system', 'yootheme'),
            'template_count' => $this->countYoothemeTemplates(),
            'yootheme_article_count' => $this->countYoothemeArticles(),
        ];
    }

    /**
     * @return array{active: bool, remaining_seconds: int|null, scopes: list<string>}
     */
    private function getElevationDiagnostics(string $environment): array
    {
        if ($environment !== 'production') {
            return [
                'active' => false,
                'remaining_seconds' => null,
                'scopes' => [],
            ];
        }

        try {
            $grant = (new ElevationService())->getActiveGrant();

            if ($grant === null || !$grant->isActive()) {
                return [
                    'active' => false,
                    'remaining_seconds' => null,
                    'scopes' => [],
                ];
            }

            return [
                'active' => true,
                'remaining_seconds' => $grant->getRemainingSeconds(),
                'scopes' => $grant->scopes,
            ];
        } catch (\Throwable) {
            return [
                'active' => false,
                'remaining_seconds' => null,
                'scopes' => [],
            ];
        }
    }

    private function isPluginEnabled(string $folder, string $element): bool
    {
        try {
            $query = $this->db->getQuery(true)
                ->select('COUNT(*)')
                ->from($this->db->quoteName('#__extensions'))
                ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
                ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote($folder))
                ->where($this->db->quoteName('element') . ' = ' . $this->db->quote($element))
                ->where($this->db->quoteName('enabled') . ' = 1');

            return (int) $this->db->setQuery($query)->loadResult() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function countYoothemeTemplates(): int
    {
        try {
            $query = $this->db->getQuery(true)
                ->select($this->db->quoteName('custom_data'))
                ->from($this->db->quoteName('#__extensions'))
                ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
                ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('system'))
                ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('yootheme'));

            $customData = $this->db->setQuery($query)->loadResult();

            if (!is_string($customData) || $customData === '') {
                return 0;
            }

            $data = json_decode($customData, true);
            $templates = is_array($data) ? ($data['templates'] ?? []) : [];

            return is_array($templates) ? count($templates) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function countYoothemeArticles(): int
    {
        try {
            $query = $this->db->getQuery(true)
                ->select('COUNT(*)')
                ->from($this->db->quoteName('#__content'))
                ->where(
                    '('
                    . $this->db->quoteName('fulltext') . ' LIKE ' . $this->db->quote('<!-- {%')
                    . ' OR '
                    . $this->db->quoteName('introtext') . ' LIKE ' . $this->db->quote('<!-- {%')
                    . ')'
                );

            return (int) $this->db->setQuery($query)->loadResult();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param list<string> $toolNames
     * @param list<string> $required
     */
    private function hasTools(array $toolNames, array $required): bool
    {
        foreach ($required as $name) {
            if (!in_array($name, $toolNames, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $toolNames
     * @return array<string, bool>
     */
    private function keyToolMap(array $toolNames): array
    {
        $keys = [
            'system/info',
            'system/diagnose',
            'content/read',
            'template/list',
            'template/summary',
            'template/element-types',
            'template/element-schema',
            'template/source-types',
            'template/style-read',
            'template/style-sources',
            'template/style-update',
            'template/element-list',
            'template/element-read',
            'template/element-source-read',
            'template/element-source-preview',
            'template/element-source-set',
            'template/element-source-delete',
            'template/element-add',
            'template/element-update-props',
            'template/element-move',
            'template/element-clone',
            'template/element-delete',
            'template/translate',
            'elevation/status',
        ];
        $map = [];

        foreach ($keys as $name) {
            $map[$name] = in_array($name, $toolNames, true);
        }

        return $map;
    }

    /**
     * @param list<array<string, mixed>> $tools
     * @return array<string, int>
     */
    private function riskLevelCounts(array $tools): array
    {
        $counts = [
            self::RISK_READ => 0,
            self::RISK_SAFE_WRITE => 0,
            self::RISK_GUARDED_WRITE => 0,
            self::RISK_DANGEROUS_EXEC => 0,
        ];

        foreach ($tools as $tool) {
            $riskLevel = is_string($tool['risk_level'] ?? null) ? $tool['risk_level'] : self::RISK_READ;

            if (!array_key_exists($riskLevel, $counts)) {
                $riskLevel = self::RISK_READ;
            }

            $counts[$riskLevel]++;
        }

        return $counts;
    }

    /**
     * @param list<array<string, mixed>> $checks
     */
    private function addCheck(array &$checks, string $name, bool $ok, string $message, mixed $details = null): void
    {
        $check = [
            'name' => $name,
            'ok' => $ok,
            'severity' => $ok ? 'ok' : 'warning',
            'message' => $message,
        ];

        if ($details !== null) {
            $check['details'] = $details;
        }

        $checks[] = $check;
    }

    /**
     * @param list<array<string, mixed>> $checks
     */
    private function overallStatus(array $checks): string
    {
        foreach ($checks as $check) {
            if (empty($check['ok'])) {
                return 'warning';
            }
        }

        return 'ok';
    }
}
