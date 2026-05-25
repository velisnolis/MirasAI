<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Mirasai\Library\Tool\AbstractTool;
use Mirasai\Library\Tool\YooThemeElementNavigator;
use Mirasai\Library\Tool\YooThemeHelper;

class TemplateElementReadTool extends AbstractTool
{
    private YooThemeHelper $yooHelper;

    public function __construct()
    {
        parent::__construct();
        $this->yooHelper = new YooThemeHelper($this->db);
    }

    public function getName(): string
    {
        return 'template/element-read';
    }

    public function getDescription(): string
    {
        return 'Reads one element from a YOOtheme Builder template by the path returned from template/element-list. Returns metadata plus the element object. By default children are omitted to keep responses compact.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'key' => [
                    'type' => 'string',
                    'description' => 'Template storage key as returned by template/list.',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Element path as returned by template/element-list, for example root>section[0]>row[0].',
                ],
                'include_children' => [
                    'type' => 'boolean',
                    'description' => 'If true, include the full child subtree. Defaults to false.',
                ],
            ],
            'required' => ['key', 'path'],
        ];
    }

    public function handle(array $arguments): array
    {
        $key = trim((string) ($arguments['key'] ?? ''));
        $path = trim((string) ($arguments['path'] ?? ''));
        $includeChildren = !empty($arguments['include_children']);

        if ($key === '' || $path === '') {
            return ['error' => 'key and path are required.'];
        }

        $templates = $this->yooHelper->loadTemplates();
        $template = $templates[$key] ?? null;

        if (!is_array($template)) {
            return ['error' => "Template {$key} not found."];
        }

        $layout = $this->yooHelper->getTemplateLayout($template);

        if ($layout === null) {
            return ['error' => "Template {$key} has no layout."];
        }

        $result = (new YooThemeElementNavigator())->findElement($layout, $path);

        if ($result === null) {
            return ['error' => "Element path {$path} not found in template {$key}."];
        }

        $element = $result['element'];

        if (!$includeChildren) {
            unset($element['children']);
        }

        return [
            'key' => $key,
            'name' => $this->yooHelper->getTemplateName($template),
            'etag' => $this->yooHelper->buildTemplateEtag($template),
            'metadata' => $result['metadata'],
            'include_children' => $includeChildren,
            'element' => $element,
        ];
    }
}
