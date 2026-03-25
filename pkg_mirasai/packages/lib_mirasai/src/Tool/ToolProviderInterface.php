<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

interface ToolProviderInterface
{
    /**
     * Unique identifier for this provider (e.g. 'mirasai.yootheme').
     */
    public function getId(): string;

    /**
     * Human-readable provider name for logging/debugging.
     */
    public function getName(): string;

    /**
     * Return false when prerequisites are missing (e.g. YOOtheme not installed).
     * Tools from unavailable providers are silently skipped.
     */
    public function isAvailable(): bool;

    /**
     * List of tool names this provider can create.
     *
     * @return list<string>
     */
    public function getToolNames(): array;

    /**
     * Instantiate a single tool by name.
     * Called only for names returned by getToolNames().
     */
    public function createTool(string $name): ToolInterface;

    /**
     * Return a processor for YOOtheme-style content layouts, or null if
     * this provider does not handle content layout processing.
     */
    public function getContentLayoutProcessor(): ?ContentLayoutProcessorInterface;
}
