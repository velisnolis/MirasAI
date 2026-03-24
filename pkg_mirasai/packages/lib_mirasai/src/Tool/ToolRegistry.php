<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    /**
     * Build a registry pre-populated with all available tools.
     *
     * Every MCP entrypoint should use this method so the tool list
     * stays consistent across standalone, system plugin, webservices
     * plugin and component controller.
     */
    public static function buildDefault(): self
    {
        $registry = new self();

        $registry->register(new SystemInfoTool());
        $registry->register(new ContentListTool());
        $registry->register(new ContentReadTool());
        $registry->register(new ContentTranslateTool());
        $registry->register(new ContentTranslateBatchTool());
        $registry->register(new ContentCheckLinksTool());
        $registry->register(new ContentAuditMultilingualTool());
        $registry->register(new CategoryTranslateTool());
        $registry->register(new SiteSetupLanguageSwitcherTool());
        $registry->register(new ThemeExtractToModulesTool());
        $registry->register(new MenuMigrateThemeToModulesTool());
        $registry->register(new TemplateListTool());
        $registry->register(new TemplateReadTool());
        $registry->register(new TemplateTranslateTool());
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

        return $registry;
    }

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
