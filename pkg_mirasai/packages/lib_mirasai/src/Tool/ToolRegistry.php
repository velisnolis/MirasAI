<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

use Mirasai\Library\Mcp\MirasaiCollectToolsEvent;

class ToolRegistry
{
    /**
     * Stores either a live ToolInterface instance (already resolved)
     * or a class-string (lazy — instantiated on first access).
     *
     * @var array<string, ToolInterface|class-string<ToolInterface>>
     */
    private array $tools = [];

    /**
     * Build a registry pre-populated with the 19 core (non-YOOtheme) tools,
     * then collect additional tools from installed plugins via collectProviders().
     *
     * Core tools are registered lazily (class-string only). They are
     * instantiated on demand — i.e., only when a tool is actually called or
     * its schema is serialised for tools/list. A simple ping or elevation
     * check no longer pays the cost of constructing all 19+ tool objects.
     *
     * Every MCP entrypoint should use this method so the tool list stays
     * consistent across standalone, system plugin, webservices plugin and
     * component controller.
     */
    public static function buildDefault(): self
    {
        $registry = new self();

        // Core tools — registered lazily by class name.
        $registry->registerLazy('system/info',                 SystemInfoTool::class);
        $registry->registerLazy('content/list',                ContentListTool::class);
        $registry->registerLazy('content/read',                ContentReadTool::class);
        $registry->registerLazy('content/translate',           ContentTranslateTool::class);
        $registry->registerLazy('content/translate-batch',     ContentTranslateBatchTool::class);
        $registry->registerLazy('content/check-links',         ContentCheckLinksTool::class);
        $registry->registerLazy('content/audit-multilingual',  ContentAuditMultilingualTool::class);
        $registry->registerLazy('category/translate',          CategoryTranslateTool::class);
        $registry->registerLazy('site/setup-language-switcher', SiteSetupLanguageSwitcherTool::class);
        $registry->registerLazy('sandbox/status',              SandboxStatusTool::class);
        $registry->registerLazy('file/read',                   FileReadTool::class);
        $registry->registerLazy('file/write',                  FileWriteTool::class);
        $registry->registerLazy('file/edit',                   FileEditTool::class);
        $registry->registerLazy('file/delete',                 FileDeleteTool::class);
        $registry->registerLazy('file/list',                   FileListTool::class);
        $registry->registerLazy('sandbox/execute-php',         SandboxExecutePhpTool::class);
        $registry->registerLazy('db/query',                    DbQueryTool::class);
        $registry->registerLazy('db/schema',                   DbSchemaTool::class);
        $registry->registerLazy('elevation/status',            ElevationStatusTool::class);

        // Discover and register tools from plugins.
        // Plugin tools go through ToolProviderInterface::createTool() which is
        // already a factory; they arrive as live instances and are stored as-is.
        $registry->collectProviders();

        return $registry;
    }

    /**
     * Register a tool lazily by class name.
     *
     * The tool is only instantiated the first time get($name) is called.
     * Prefer this over register() for tools whose constructors touch I/O
     * (DB connections, file system, HTTP) to avoid paying that cost on
     * every request regardless of which tools are actually used.
     *
     * @param class-string<ToolInterface> $class
     */
    public function registerLazy(string $name, string $class): void
    {
        $this->tools[$name] = $class;
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

    /**
     * Register a live tool instance (eager).
     *
     * Used by collectProviders() for plugin-provided tools that arrive as
     * already-constructed instances from ToolProviderInterface::createTool().
     */
    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->getName()] = $tool;
    }

    /**
     * Resolve and return a tool by name, instantiating lazily if needed.
     *
     * Returns null when the name is not registered.
     */
    public function get(string $name): ?ToolInterface
    {
        $entry = $this->tools[$name] ?? null;

        if ($entry === null) {
            return null;
        }

        // Lazy entry: class-string — instantiate once and cache.
        if (is_string($entry)) {
            $this->tools[$name] = new $entry();
        }

        /** @var ToolInterface */
        return $this->tools[$name];
    }

    /**
     * Check whether a tool name is registered (lazy or eager).
     */
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->tools);
    }

    /**
     * Return all tool names registered in this registry.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    /**
     * Return all tools as live instances, resolving any lazy entries.
     *
     * @return array<string, ToolInterface>
     */
    public function all(): array
    {
        foreach (array_keys($this->tools) as $name) {
            $this->get($name); // Resolve lazy entries in-place.
        }

        /** @var array<string, ToolInterface> */
        return $this->tools;
    }

    /**
     * Return all tools in MCP tools/list format.
     *
     * Iterates tool names and resolves each lazily, so only tools whose
     * schemas are actually needed get instantiated.
     *
     * @return list<array<string, mixed>>
     */
    public function toMcpToolsList(): array
    {
        $list = [];

        foreach (array_keys($this->tools) as $name) {
            $tool = $this->get($name);

            if ($tool !== null) {
                $list[] = $tool->toMcpTool();
            }
        }

        return $list;
    }
}
