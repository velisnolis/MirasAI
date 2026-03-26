<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Rereplacer;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Mirasai\Library\Tool\ContentLayoutProcessorInterface;
use Mirasai\Library\Tool\ToolInterface;
use Mirasai\Library\Tool\ToolProviderInterface;
use Mirasai\Plugin\Mirasai\Rereplacer\Tool\ConditionsListTool;
use Mirasai\Plugin\Mirasai\Rereplacer\Tool\ConditionsReadTool;
use Mirasai\Plugin\Mirasai\Rereplacer\Tool\RereplacerAttachConditionTool;
use Mirasai\Plugin\Mirasai\Rereplacer\Tool\RereplacerCapabilitiesTool;
use Mirasai\Plugin\Mirasai\Rereplacer\Tool\RereplacerCreateItemSimpleTool;
use Mirasai\Plugin\Mirasai\Rereplacer\Tool\RereplacerListItemsTool;
use Mirasai\Plugin\Mirasai\Rereplacer\Tool\RereplacerPreviewMatchScopeTool;
use Mirasai\Plugin\Mirasai\Rereplacer\Tool\RereplacerPublishItemTool;
use Mirasai\Plugin\Mirasai\Rereplacer\Tool\RereplacerReadItemTool;
use Mirasai\Plugin\Mirasai\Rereplacer\Tool\RereplacerUpdateItemSimpleTool;

class RereplacerToolProvider implements ToolProviderInterface
{
    public function getId(): string
    {
        return 'mirasai.rereplacer';
    }

    public function getName(): string
    {
        return 'MirasAI ReReplacer';
    }

    public function isAvailable(): bool
    {
        return $this->extensionEnabled('com_rereplacer', 'component')
            && $this->extensionEnabled('rereplacer', 'plugin', 'system');
    }

    public function getToolNames(): array
    {
        $tools = [
            'rereplacer/capabilities',
            'rereplacer/list-items',
            'rereplacer/read-item',
            'rereplacer/create-item-simple',
            'rereplacer/update-item-simple',
            'rereplacer/publish-item',
            'rereplacer/preview-match-scope',
        ];

        if ($this->extensionEnabled('com_conditions', 'component')) {
            $tools[] = 'conditions/list';
            $tools[] = 'conditions/read';
        }

        if ($this->isRereplacerPro() && $this->extensionEnabled('com_conditions', 'component')) {
            $tools[] = 'rereplacer/attach-condition';
        }

        return $tools;
    }

    public function createTool(string $name): ToolInterface
    {
        return match ($name) {
            'rereplacer/capabilities' => new RereplacerCapabilitiesTool(),
            'rereplacer/list-items' => new RereplacerListItemsTool(),
            'rereplacer/read-item' => new RereplacerReadItemTool(),
            'rereplacer/create-item-simple' => new RereplacerCreateItemSimpleTool(),
            'rereplacer/update-item-simple' => new RereplacerUpdateItemSimpleTool(),
            'rereplacer/publish-item' => new RereplacerPublishItemTool(),
            'conditions/list' => new ConditionsListTool(),
            'conditions/read' => new ConditionsReadTool(),
            'rereplacer/attach-condition' => new RereplacerAttachConditionTool(),
            'rereplacer/preview-match-scope' => new RereplacerPreviewMatchScopeTool(),
            default => throw new \InvalidArgumentException("Unknown tool: {$name}"),
        };
    }

    public function getContentLayoutProcessor(): ?ContentLayoutProcessorInterface
    {
        return null;
    }

    private function extensionEnabled(string $element, string $type, string $folder = ''): bool
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__extensions'))
                ->where('element = ' . $db->quote($element))
                ->where('type = ' . $db->quote($type))
                ->where('enabled = 1');

            if ($folder !== '') {
                $query->where('folder = ' . $db->quote($folder));
            }

            return (int) $db->setQuery($query)->loadResult() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function isRereplacerPro(): bool
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select('manifest_cache')
                ->from($db->quoteName('#__extensions'))
                ->where('element = ' . $db->quote('com_rereplacer'))
                ->where('type = ' . $db->quote('component'))
                ->where('enabled = 1');

            $manifest = (string) $db->setQuery($query)->loadResult();

            if ($manifest === '') {
                return false;
            }

            $decoded = json_decode($manifest, true);
            $version = is_array($decoded) ? (string) ($decoded['version'] ?? '') : '';

            return stripos($version, 'PRO') !== false;
        } catch (\Throwable) {
            return false;
        }
    }
}
