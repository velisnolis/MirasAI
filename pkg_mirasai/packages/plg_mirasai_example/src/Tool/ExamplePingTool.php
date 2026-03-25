<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Example\Tool;

use Mirasai\Library\Tool\AbstractTool;

/**
 * Minimal example MCP tool.
 *
 * Returns a pong response with basic site metadata.
 * Use this as a template for your own tools.
 */
class ExamplePingTool extends AbstractTool
{
    public function getName(): string
    {
        return 'example/ping';
    }

    public function getDescription(): string
    {
        return 'Example tool: returns a pong with the current Joomla site URL. Use this to verify the MirasAI plugin system is working.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'message' => [
                    'type' => 'string',
                    'description' => 'Optional message to echo back.',
                ],
            ],
        ];
    }

    public function handle(array $arguments): array
    {
        return [
            'pong' => true,
            'echo' => $arguments['message'] ?? null,
            'site_url' => \Joomla\CMS\Uri\Uri::root(),
            'joomla_version' => \Joomla\CMS\Version::RELEASE,
            'plugin' => 'plg_mirasai_example',
        ];
    }
}
