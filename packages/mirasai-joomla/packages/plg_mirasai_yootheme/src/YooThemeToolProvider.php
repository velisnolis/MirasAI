<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme;

use Mirasai\Library\Tool\ContentLayoutProcessorInterface;
use Mirasai\Library\Tool\ToolInterface;
use Mirasai\Library\Tool\ToolProviderInterface;
use Mirasai\Library\Tool\YooThemeLayoutProcessor;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateElementAddTool;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateElementCloneTool;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateElementDeleteTool;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\MenuMigrateThemeToModulesTool;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateElementListTool;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateElementMoveTool;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateElementReadTool;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateElementSchemaTool;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateElementSourceDeleteTool;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateElementSourcePreviewTool;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateElementSourceReadTool;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateElementSourceSetTool;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateElementTypesTool;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateElementUpdatePropsTool;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateListTool;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateReadTool;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateSourceTypesTool;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateSummaryTool;
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
            'template/summary',
            'template/element-types',
            'template/element-schema',
            'template/source-types',
            'template/element-list',
            'template/element-read',
            'template/element-source-read',
            'template/element-source-preview',
            'template/element-source-set',
            'template/element-source-delete',
            'template/element-add',
            'template/element-update-props',
            'template/element-move',
            'template/element-clone',
            'template/element-delete',
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
            'template/summary'              => new TemplateSummaryTool(),
            'template/element-types'        => new TemplateElementTypesTool(),
            'template/element-schema'       => new TemplateElementSchemaTool(),
            'template/source-types'         => new TemplateSourceTypesTool(),
            'template/element-list'         => new TemplateElementListTool(),
            'template/element-read'         => new TemplateElementReadTool(),
            'template/element-source-read'  => new TemplateElementSourceReadTool(),
            'template/element-source-preview' => new TemplateElementSourcePreviewTool(),
            'template/element-source-set'   => new TemplateElementSourceSetTool(),
            'template/element-source-delete' => new TemplateElementSourceDeleteTool(),
            'template/element-add'          => new TemplateElementAddTool(),
            'template/element-update-props' => new TemplateElementUpdatePropsTool(),
            'template/element-move'         => new TemplateElementMoveTool(),
            'template/element-clone'        => new TemplateElementCloneTool(),
            'template/element-delete'       => new TemplateElementDeleteTool(),
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
