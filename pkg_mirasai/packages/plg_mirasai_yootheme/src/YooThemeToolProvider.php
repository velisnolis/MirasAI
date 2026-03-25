<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme;

use Mirasai\Library\Tool\ContentLayoutProcessorInterface;
use Mirasai\Library\Tool\ToolInterface;
use Mirasai\Library\Tool\ToolProviderInterface;
use Mirasai\Library\Tool\YooThemeLayoutProcessor;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\MenuMigrateThemeToModulesTool;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateListTool;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateReadTool;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateTranslateTool;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\ThemeExtractToModulesTool;

class YooThemeToolProvider implements ToolProviderInterface
{
    public function getId(): string
    {
        return 'mirasai.yootheme';
    }

    public function getName(): string
    {
        return 'MirasAI YOOtheme';
    }

    /**
     * Available when YOOtheme Pro is installed.
     * Checks the extensions table for the yootheme system plugin.
     */
    public function isAvailable(): bool
    {
        // Fast check: see if the YOOtheme class is loaded (already booted)
        if (class_exists('YOOtheme\Builder', false)) {
            return true;
        }

        // DB check: yootheme system plugin must be published
        try {
            $db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__extensions'))
                ->where('element = ' . $db->quote('yootheme'))
                ->where('folder = ' . $db->quote('system'))
                ->where('enabled = 1');

            return (int) $db->setQuery($query)->loadResult() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    public function getToolNames(): array
    {
        return [
            'theme/extract-to-modules',
            'menu/migrate-theme-to-modules',
            'template/list',
            'template/read',
            'template/translate',
        ];
    }

    public function createTool(string $name): ToolInterface
    {
        return match ($name) {
            'theme/extract-to-modules'      => new ThemeExtractToModulesTool(),
            'menu/migrate-theme-to-modules' => new MenuMigrateThemeToModulesTool(),
            'template/list'                 => new TemplateListTool(),
            'template/read'                 => new TemplateReadTool(),
            'template/translate'            => new TemplateTranslateTool(),
            default                         => throw new \InvalidArgumentException("Unknown tool: {$name}"),
        };
    }

    public function getContentLayoutProcessor(): ?ContentLayoutProcessorInterface
    {
        return new YooThemeLayoutProcessor();
    }
}
