<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    public static function buildDefault(): self
    {
        $registry = new self();
        $registry->register(new SystemInfoTool());
        $registry->register(new SystemDiagnoseTool($registry));
        $registry->register(new ContentListTool());
        $registry->register(new ContentReadTool());
        $registry->register(new ContentTranslateTool());
        $registry->register(new ContentTranslateBatchTool());
        $registry->register(new ContentCheckLinksTool());
        $registry->register(new ContentAuditMultilingualTool());
        $registry->register(new TaxonomyTermListTool());
        $registry->register(new TaxonomyTermTranslateTool());
        $registry->register(new DbSchemaTool());
        $registry->register(new DbQueryTool());
        $registry->register(new FileListTool());
        $registry->register(new FileReadTool());
        $registry->register(new SandboxStatusTool());
        $registry->register(new ElevationStatusTool());
        $registry->register(new WpAbilitiesListTool());
        $registry->register(new WpAbilityCallTool());
        $registry->register(new TemplateListTool());
        $registry->register(new TemplateReadTool());
        $registry->register(new TemplateSummaryTool());
        $registry->register(new TemplateElementListTool());
        $registry->register(new TemplateElementReadTool());
        $registry->register(new TemplateElementTypesTool());
        $registry->register(new TemplateElementSchemaTool());
        $registry->register(new TemplateSourceTypesTool());
        $registry->register(new TemplateElementSourceReadTool());
        $registry->register(new TemplateElementSourcePreviewTool());
        $registry->register(new TemplateElementSourceSetTool());
        $registry->register(new TemplateElementSourceDeleteTool());
        $registry->register(new TemplateElementAddTool());
        $registry->register(new TemplateElementUpdatePropsTool());
        $registry->register(new TemplateElementMoveTool());
        $registry->register(new TemplateElementCloneTool());
        $registry->register(new TemplateElementDeleteTool());
        $registry->register(new TemplateTranslateTool());
        $registry->register(new TemplateWidgetTranslateTool());
        $registry->register(new AcfStatusTool());
        $registry->register(new AcfFieldGroupsListTool());
        $registry->register(new AcfFieldGroupReadTool());
        $registry->register(new AcfPostFieldsReadTool());
        $registry->register(new AcfCptListTool());
        $registry->register(new AcfTaxonomyListTool());

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

    /**
     * @return list<array<string, mixed>>
     */
    public function toMcpToolsList(?string $surface = null): array
    {
        $surface = $this->normalizeSurface($surface);
        $tools = [];

        foreach ($this->tools as $tool) {
            if ($surface !== null && $tool instanceof AbstractTool && $tool->getSurface() !== $surface) {
                continue;
            }

            if (!$tool instanceof AbstractTool) {
                continue;
            }

            $tools[] = $tool->toMcpTool();
        }

        return $tools;
    }

    /**
     * @return list<array{name: string, risk_level: string, surface: string}>
     */
    public function summarize(): array
    {
        $summary = [];

        foreach ($this->tools as $tool) {
            if (!$tool instanceof AbstractTool) {
                continue;
            }

            $permissions = AbstractTool::normalizePermissions($tool->getPermissions());
            $summary[] = [
                'name' => $tool->getName(),
                'risk_level' => $permissions['risk_level'],
                'surface' => $tool->getSurface(),
            ];
        }

        return $summary;
    }

    private function normalizeSurface(?string $surface): ?string
    {
        if ($surface === null || trim($surface) === '' || $surface === 'all') {
            return null;
        }

        return in_array($surface, ['essential', 'advanced'], true) ? $surface : null;
    }
}
