<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

use Mirasai\Library\Mcp\MirasaiCollectToolsEvent;

class ToolRegistry
{
    /**
     * Static metadata for core tools so the admin dashboard can summarize them
     * without instantiating every lazy tool on page load.
     *
     * @var array<string, array{description: string, destructive: bool}>
     */
    private const CORE_TOOL_METADATA = [
        'system/info' => ['description' => 'Returns comprehensive Joomla runtime information: CMS version, PHP version, DB engine, installed languages, active extensions with status, template summary (name, style ID, language assignments), YOOtheme version, environment detection, and MirasAI capabilities.', 'destructive' => false],
        'content/list' => ['description' => 'Lists articles with their language, category, publication state, and existing translation associations.', 'destructive' => false],
        'content/read' => ['description' => 'Reads a single article by ID. Returns title, language, introtext, metadesc, metakey, and category.', 'destructive' => false],
        'content/translate' => ['description' => 'Creates or updates a translated version of an article. YOU must provide the translated content.', 'destructive' => false],
        'content/translate-batch' => ['description' => 'Translates multiple articles to a target language in a single call.', 'destructive' => false],
        'content/check-links' => ['description' => 'Scans translated articles for internal links pointing to articles that lack a translation in the same language. Reports broken links and optionally rewrites them to point to the translated version when available.', 'destructive' => false],
        'content/audit-multilingual' => ['description' => 'Scans the entire Joomla site and returns a structured diagnostic of multilingual completeness. Reports gaps in articles, menus, modules, categories, metadata, language switcher, and theme areas. Each gap includes the MCP tool call needed to fix it.', 'destructive' => false],
        'category/translate' => ['description' => 'Creates a translated version of a Joomla category. YOU must provide translated_title (and optionally translated_description).', 'destructive' => false],
        'site/setup-language-switcher' => ['description' => 'Checks if a language switcher exists and, if not, creates and publishes a mod_languages module in the appropriate position. Detects YOOtheme theme positions automatically.', 'destructive' => false],
        'sandbox/status' => ['description' => 'Returns the current state of the MirasAI sandbox: whether it is active, its state (ok/loading/crashed/safe_mode), loaded files, crashed files, and environment detection.', 'destructive' => false],
        'file/read' => ['description' => 'Reads the content of a file anywhere under the Joomla root directory. Returns the raw file content as text.', 'destructive' => false],
        'file/write' => ['description' => 'Write content to a file in the sandbox directory (media/mirasai/sandbox/). PHP files are syntax-validated before writing. Supports overwrite and append modes, and UTF-8 or base64 encoding.', 'destructive' => true],
        'file/edit' => ['description' => 'Replace a string in a file within the sandbox directory. By default, old_string must appear exactly once. Set replace_all=true for multiple replacements. PHP files are syntax-validated after editing.', 'destructive' => true],
        'file/delete' => ['description' => 'Delete a file from the sandbox directory (media/mirasai/sandbox/).', 'destructive' => true],
        'file/list' => ['description' => 'List directory contents anywhere under the Joomla root. Default depth=1 (non-recursive). Max depth=3, max 500 entries.', 'destructive' => false],
        'sandbox/execute-php' => ['description' => 'Execute PHP code in a sandboxed environment with transaction wrapping.', 'destructive' => true],
        'db/query' => ['description' => 'Execute read-only SQL queries (SELECT, SHOW) via the Joomla database layer.', 'destructive' => false],
        'db/schema' => ['description' => 'Inspect database table structure. Returns column names, types, nullability, keys, and defaults.', 'destructive' => false],
        'elevation/status' => ['description' => 'Check the current elevation status for destructive tools.', 'destructive' => false],
    ];

    /**
     * Stores either a live ToolInterface instance (already resolved)
     * or a class-string (lazy — instantiated on first access).
     *
     * @var array<string, ToolInterface|class-string<ToolInterface>>
     */
    private array $tools = [];

    /**
     * Parallel array tracking the origin of each tool.
     *
     * Key: tool name, Value: provider identifier ('core' for built-in tools,
     * or the ToolProviderInterface::getId() string for plugin-provided tools).
     *
     * @var array<string, string>
     */
    private array $providers = [];

    /**
     * Lightweight metadata for tools, used by the admin dashboard.
     *
     * @var array<string, array{description: string, destructive: bool}>
     */
    private array $toolMetadata = [];

    /**
     * Provider registration summaries keyed by provider ID.
     *
     * @var array<string, array{id: string, name: string, available: bool, registered_tools: int}>
     */
    private array $providerSummaries = [];

    /**
     * Non-fatal registry warnings surfaced to the admin dashboard.
     *
     * @var list<string>
     */
    private array $warnings = [];

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
        $registry->registerLazy('system/info',                 SystemInfoTool::class, 'core', self::CORE_TOOL_METADATA['system/info']);
        $registry->registerLazy('content/list',                ContentListTool::class, 'core', self::CORE_TOOL_METADATA['content/list']);
        $registry->registerLazy('content/read',                ContentReadTool::class, 'core', self::CORE_TOOL_METADATA['content/read']);
        $registry->registerLazy('content/translate',           ContentTranslateTool::class, 'core', self::CORE_TOOL_METADATA['content/translate']);
        $registry->registerLazy('content/translate-batch',     ContentTranslateBatchTool::class, 'core', self::CORE_TOOL_METADATA['content/translate-batch']);
        $registry->registerLazy('content/check-links',         ContentCheckLinksTool::class, 'core', self::CORE_TOOL_METADATA['content/check-links']);
        $registry->registerLazy('content/audit-multilingual',  ContentAuditMultilingualTool::class, 'core', self::CORE_TOOL_METADATA['content/audit-multilingual']);
        $registry->registerLazy('category/translate',          CategoryTranslateTool::class, 'core', self::CORE_TOOL_METADATA['category/translate']);
        $registry->registerLazy('site/setup-language-switcher', SiteSetupLanguageSwitcherTool::class, 'core', self::CORE_TOOL_METADATA['site/setup-language-switcher']);
        $registry->registerLazy('sandbox/status',              SandboxStatusTool::class, 'core', self::CORE_TOOL_METADATA['sandbox/status']);
        $registry->registerLazy('file/read',                   FileReadTool::class, 'core', self::CORE_TOOL_METADATA['file/read']);
        $registry->registerLazy('file/write',                  FileWriteTool::class, 'core', self::CORE_TOOL_METADATA['file/write']);
        $registry->registerLazy('file/edit',                   FileEditTool::class, 'core', self::CORE_TOOL_METADATA['file/edit']);
        $registry->registerLazy('file/delete',                 FileDeleteTool::class, 'core', self::CORE_TOOL_METADATA['file/delete']);
        $registry->registerLazy('file/list',                   FileListTool::class, 'core', self::CORE_TOOL_METADATA['file/list']);
        $registry->registerLazy('sandbox/execute-php',         SandboxExecutePhpTool::class, 'core', self::CORE_TOOL_METADATA['sandbox/execute-php']);
        $registry->registerLazy('db/query',                    DbQueryTool::class, 'core', self::CORE_TOOL_METADATA['db/query']);
        $registry->registerLazy('db/schema',                   DbSchemaTool::class, 'core', self::CORE_TOOL_METADATA['db/schema']);
        $registry->registerLazy('elevation/status',            ElevationStatusTool::class, 'core', self::CORE_TOOL_METADATA['elevation/status']);

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
     * @param string $provider  Origin identifier ('core' for built-in, or provider ID for plugins)
     */
    public function registerLazy(string $name, string $class, string $provider = 'core', ?array $metadata = null): void
    {
        $this->tools[$name] = $class;
        $this->providers[$name] = $provider;

        if ($metadata !== null) {
            $this->toolMetadata[$name] = $metadata;
        }
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
            $providerId = $provider->getId();
            $registeredTools = 0;

            try {
                $available = $provider->isAvailable();
                $this->providerSummaries[$providerId] = [
                    'id' => $providerId,
                    'name' => $provider->getName(),
                    'available' => $available,
                    'registered_tools' => 0,
                    'plugin_element' => $this->inferPluginElement($providerId),
                ];

                if (!$available) {
                    continue;
                }

                foreach ($provider->getToolNames() as $toolName) {
                    if ($this->has($toolName)) {
                        // Core tool or earlier plugin already registered this name — skip.
                        $this->warn("MirasAI: tool name conflict '{$toolName}' from provider '{$providerId}' — skipped.");
                        continue;
                    }

                    $tool = $provider->createTool($toolName);
                    $this->register($tool, $providerId);
                    $this->toolMetadata[$toolName] = [
                        'description' => $tool->getDescription(),
                        'destructive' => !empty($tool->getPermissions()['destructive']),
                    ];
                    $registeredTools++;
                }

                $this->providerSummaries[$providerId]['registered_tools'] = $registeredTools;
            } catch (\Throwable $e) {
                $this->providerSummaries[$providerId] = [
                    'id' => $providerId,
                    'name' => $provider->getName(),
                    'available' => false,
                    'registered_tools' => $registeredTools,
                    'plugin_element' => $this->inferPluginElement($providerId),
                ];
                $this->warn("MirasAI: provider '{$providerId}' threw during registration: " . $e->getMessage());
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
            if (class_exists(\Joomla\CMS\Plugin\PluginHelper::class, false)) {
                \Joomla\CMS\Plugin\PluginHelper::importPlugin('mirasai');
            }

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
                $this->warn("MirasAI: failed to load provider from '{$file}': " . $e->getMessage());
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
     *
     * @param string $provider  Origin identifier (provider ID for plugins, 'core' for built-in)
     */
    public function register(ToolInterface $tool, string $provider = 'core'): void
    {
        $this->tools[$tool->getName()] = $tool;
        $this->providers[$tool->getName()] = $provider;
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
     * Return the provider identifier for a registered tool.
     *
     * Returns 'core' for built-in tools, or the ToolProviderInterface::getId()
     * string for plugin-provided tools. Returns 'unknown' if the tool is not
     * registered or has no provider recorded.
     */
    public function getProvider(string $name): string
    {
        return $this->providers[$name] ?? 'unknown';
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
     * Return a lightweight summary of all tools without instantiating them.
     *
     * For lazy (class-string) entries the description and destructive flag are
     * obtained by instantiating a temporary instance. For already-resolved
     * instances, the live object is used directly.
     *
     * This is designed for the admin dashboard where we need name, description,
     * provider, and destructive flag for all tools — but don't need to pay the
     * full cost of keeping 25+ tool objects in memory simultaneously.
     *
     * @return list<array{name: string, description: string, provider: string, destructive: bool}>
     */
    public function toToolSummaryList(): array
    {
        $list = [];

        foreach ($this->tools as $name => $entry) {
            try {
                $metadata = $this->getToolMetadata($name, $entry);
            } catch (\Throwable $e) {
                $this->warn("MirasAI: failed to summarize tool '{$name}': " . $e->getMessage());
                continue;
            }

            $list[] = [
                'name'        => $name,
                'description' => $metadata['description'],
                'provider'    => $this->providers[$name] ?? 'unknown',
                'destructive' => $metadata['destructive'],
            ];
        }

        return $list;
    }

    /**
     * @return array<string, array{id: string, name: string, available: bool, registered_tools: int, plugin_element: string}>
     */
    public function getProviderSummaryMap(): array
    {
        return $this->providerSummaries;
    }

    /**
     * @return list<string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
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

    /**
     * @param ToolInterface|class-string<ToolInterface> $entry
     * @return array{description: string, destructive: bool}
     */
    private function getToolMetadata(string $name, ToolInterface|string $entry): array
    {
        if (isset($this->toolMetadata[$name])) {
            return $this->toolMetadata[$name];
        }

        if (\is_string($entry)) {
            /** @var ToolInterface $tool */
            $tool = new $entry();
        } else {
            $tool = $entry;
        }

        $metadata = [
            'description' => $tool->getDescription(),
            'destructive' => !empty($tool->getPermissions()['destructive']),
        ];

        $this->toolMetadata[$name] = $metadata;

        return $metadata;
    }

    private function warn(string $message): void
    {
        $this->warnings[] = $message;
        trigger_error($message, E_USER_WARNING);
    }

    private function inferPluginElement(string $providerId): string
    {
        if (str_starts_with($providerId, 'mirasai.')) {
            return substr($providerId, strlen('mirasai.'));
        }

        return $providerId;
    }
}
