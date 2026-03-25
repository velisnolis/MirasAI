<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

use Mirasai\Library\Mcp\MirasaiCollectToolsEvent;

class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    /**
     * Build a registry pre-populated with the 18 core (non-YOOtheme) tools,
     * then collect additional tools from installed plugins via collectProviders().
     *
     * Every MCP entrypoint should use this method so the tool list stays
     * consistent across standalone, system plugin, webservices plugin and
     * component controller.
     */
    public static function buildDefault(): self
    {
        $registry = new self();

        // Core tools (always available, no external dependencies)
        $registry->register(new SystemInfoTool());
        $registry->register(new ContentListTool());
        $registry->register(new ContentReadTool());
        $registry->register(new ContentTranslateTool());
        $registry->register(new ContentTranslateBatchTool());
        $registry->register(new ContentCheckLinksTool());
        $registry->register(new ContentAuditMultilingualTool());
        $registry->register(new CategoryTranslateTool());
        $registry->register(new SiteSetupLanguageSwitcherTool());
        $registry->register(new SandboxStatusTool());
        $registry->register(new FileReadTool());
        $registry->register(new FileWriteTool());
        $registry->register(new FileEditTool());
        $registry->register(new FileDeleteTool());
        $registry->register(new FileListTool());
        $registry->register(new SandboxExecutePhpTool());
        $registry->register(new DbQueryTool());
        $registry->register(new DbSchemaTool());
        $registry->register(new ElevationStatusTool());
        // NOTE: SiteSetupLanguageSwitcherTool is kept in core (generic Joomla, not YOOtheme-specific)

        // Discover and register tools from plugins
        $registry->collectProviders();

        return $registry;
    }

    /**
     * Collect tool providers from installed MirasAI plugins.
     *
     * Strategy:
     * 1. Fire MirasaiCollectToolsEvent so Joomla-native plugins can respond
     *    (used by plg_mirasai_yootheme when loaded via PluginHelper::importPlugin).
     * 2. Always also scan the filesystem for plugins/mirasai/{plugin}/provider.php
     *    to cover cases where the Joomla event path produced no providers
     *    (e.g., the plugin is not yet loaded into the dispatcher).
     *    The has() guard ensures no duplicate tool registration.
     *
     * Invariants:
     * - Core tools registered in buildDefault() are never removed.
     * - A provider that throws is caught, logged, and skipped.
     * - Tool name conflicts: first-registered wins (core always wins).
     */
    public function collectProviders(): void
    {
        $eventProviders = $this->fireCollectToolsEvent() ?? [];
        $fsProviders    = $this->scanFilesystemProviders();

        // Merge: event providers first (higher priority), then filesystem providers.
        // Deduplicate by provider ID so the same plugin isn't processed twice
        // when both Joomla event and filesystem scan find the same provider.php.
        $seen      = [];
        $providers = [];

        foreach (array_merge($eventProviders, $fsProviders) as $provider) {
            $id = $provider->getId();

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id]   = true;
            $providers[] = $provider;
        }

        foreach ($providers as $provider) {
            try {
                if (!$provider->isAvailable()) {
                    continue;
                }

                foreach ($provider->getToolNames() as $toolName) {
                    if ($this->has($toolName)) {
                        // Core tool or earlier plugin already registered this name — skip.
                        trigger_error(
                            "MirasAI: tool name conflict '{$toolName}' from provider '{$provider->getId()}' — skipped.",
                            E_USER_WARNING,
                        );
                        continue;
                    }

                    $this->register($provider->createTool($toolName));
                }
            } catch (\Throwable $e) {
                trigger_error(
                    "MirasAI: provider '{$provider->getId()}' threw during registration: " . $e->getMessage(),
                    E_USER_WARNING,
                );
            }
        }
    }

    // ── Plugin discovery helpers ───────────────────────────────────────────────

    /**
     * Fire onMirasaiCollectTools via Joomla's EventDispatcher.
     * Returns null when Joomla is not bootstrapped (standalone mode).
     *
     * @return list<ToolProviderInterface>|null
     */
    private function fireCollectToolsEvent(): ?array
    {
        if (!class_exists(\Joomla\CMS\Factory::class, false)) {
            return null;
        }

        try {
            $app = \Joomla\CMS\Factory::getApplication();
            $event = new MirasaiCollectToolsEvent('onMirasaiCollectTools');
            $app->getDispatcher()->dispatch('onMirasaiCollectTools', $event);

            return $event->getProviders();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Scan JPATH_PLUGINS/mirasai/{plugin}/provider.php for standalone mode.
     * Each provider.php must return a ToolProviderInterface instance.
     *
     * @return list<ToolProviderInterface>
     */
    private function scanFilesystemProviders(): array
    {
        if (!defined('JPATH_PLUGINS')) {
            return [];
        }

        $providers = [];
        $providerFiles = glob(JPATH_PLUGINS . '/mirasai/*/provider.php') ?: [];

        foreach ($providerFiles as $file) {
            try {
                $provider = require $file;

                if ($provider instanceof ToolProviderInterface) {
                    $providers[] = $provider;
                }
            } catch (\Throwable $e) {
                trigger_error(
                    "MirasAI: failed to load provider from '{$file}': " . $e->getMessage(),
                    E_USER_WARNING,
                );
            }
        }

        return $providers;
    }

    // ── Registry operations ────────────────────────────────────────────────────

    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->getName()] = $tool;
    }

    public function get(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * @return array<string, ToolInterface>
     */
    public function all(): array
    {
        return $this->tools;
    }

    /**
     * Return all tools in MCP tools/list format.
     *
     * @return list<array<string, mixed>>
     */
    public function toMcpToolsList(): array
    {
        $list = [];

        foreach ($this->tools as $tool) {
            $list[] = $tool->toMcpTool();
        }

        return $list;
    }
}
