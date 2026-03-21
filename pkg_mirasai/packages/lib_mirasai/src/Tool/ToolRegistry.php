<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];

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
