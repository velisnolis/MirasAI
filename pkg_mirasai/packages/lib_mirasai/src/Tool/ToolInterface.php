<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

interface ToolInterface
{
    public function getName(): string;

    public function getDescription(): string;

    /**
     * JSON Schema for input parameters.
     *
     * @return array<string, mixed>
     */
    public function getInputSchema(): array;

    /**
     * Execute the tool with given arguments.
     *
     * @param  array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array;

    /**
     * Permission model.
     *
     * risk_level is canonical: read, safe_write, guarded_write, dangerous_exec.
     * readonly/destructive/requires_elevation may be derived internally while
     * the four-level model rolls through the UI.
     *
     * @return array<string, mixed>
     */
    public function getPermissions(): array;

    /**
     * Serialize this tool to MCP tools/list format.
     *
     * Includes name, description, inputSchema, and metadata with risk hints.
     *
     * @return array<string, mixed>
     */
    public function toMcpTool(): array;
}
