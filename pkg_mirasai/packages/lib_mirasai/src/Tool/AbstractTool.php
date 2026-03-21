<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

abstract class AbstractTool implements ToolInterface
{
    protected DatabaseInterface $db;

    public function __construct()
    {
        $this->db = Factory::getContainer()->get(DatabaseInterface::class);
    }

    public function getPermissions(): array
    {
        return [
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ];
    }

    /**
     * Convert this tool to MCP tool format.
     *
     * @return array<string, mixed>
     */
    public function toMcpTool(): array
    {
        return [
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'inputSchema' => $this->getInputSchema(),
        ];
    }
}
