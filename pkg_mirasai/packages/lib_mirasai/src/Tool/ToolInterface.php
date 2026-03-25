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
     * Permission flags: readonly, destructive, idempotent.
     *
     * @return array<string, bool>
     */
    public function getPermissions(): array;

    /**
     * Serialize this tool to MCP tools/list format.
     *
     * Includes name, description, inputSchema, and optionally a metadata
     * object with permission hints (destructive, requires_elevation).
     *
     * @return array<string, mixed>
     */
    public function toMcpTool(): array;
}
