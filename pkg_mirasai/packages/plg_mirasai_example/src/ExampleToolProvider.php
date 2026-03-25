<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Example;

use Mirasai\Library\Tool\ContentLayoutProcessorInterface;
use Mirasai\Library\Tool\ToolInterface;
use Mirasai\Library\Tool\ToolProviderInterface;
use Mirasai\Plugin\Mirasai\Example\Tool\ExamplePingTool;

/**
 * Example tool provider — always available, registers one demo tool.
 *
 * Replace `isAvailable()` with a real prerequisite check in your own plugin.
 */
class ExampleToolProvider implements ToolProviderInterface
{
    public function getId(): string
    {
        return 'mirasai.example';
    }

    public function getName(): string
    {
        return 'MirasAI Example';
    }

    public function isAvailable(): bool
    {
        // This example is always available.
        // In a real plugin, check that your extension is installed:
        //   return class_exists('MyExtension\Application', false);
        return true;
    }

    /**
     * @return list<string>
     */
    public function getToolNames(): array
    {
        return ['example/ping'];
    }

    public function createTool(string $name): ToolInterface
    {
        return match ($name) {
            'example/ping' => new ExamplePingTool(),
            default        => throw new \InvalidArgumentException("Unknown tool: {$name}"),
        };
    }

    public function getContentLayoutProcessor(): ?ContentLayoutProcessorInterface
    {
        return null;
    }
}
