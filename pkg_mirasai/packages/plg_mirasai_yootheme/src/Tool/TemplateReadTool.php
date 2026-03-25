<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Mirasai\Library\Tool\AbstractTool;
use Mirasai\Library\Tool\YooThemeHelper;

class TemplateReadTool extends AbstractTool
{
    private YooThemeHelper $yooHelper;

    public function __construct()
    {
        parent::__construct();
        $this->yooHelper = new YooThemeHelper($this->db);
    }

    public function getName(): string
    {
        return 'template/read';
    }

    public function getDescription(): string
    {
        return 'Reads a single YOOtheme Builder template from custom_data, including its assignment metadata, layout JSON, and fixed translatable text nodes.';
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
            ],
            'required' => ['key'],
        ];
    }

    public function handle(array $arguments): array
    {
        $key = trim((string) ($arguments['key'] ?? ''));

        if ($key === '') {
            return ['error' => 'Template key is required.'];
        }

        $templates = $this->yooHelper->loadTemplates();
        $template = $templates[$key] ?? null;

        if (!is_array($template)) {
            return ['error' => "Template {$key} not found."];
        }

        $layout = $this->yooHelper->getTemplateLayout($template);
        $translatableNodes = $this->yooHelper->findTemplateTranslatableNodes($template);

        return [
            'key' => $key,
            'name' => $this->yooHelper->getTemplateName($template),
            'type' => is_string($template['type'] ?? null) ? $template['type'] : '',
            'language' => $this->yooHelper->getTemplateLanguage($template) ?: '*',
            'dynamic_only' => $translatableNodes === [],
            'has_static_text' => $translatableNodes !== [],
            'assignment_fingerprint' => $this->yooHelper->buildTemplateAssignmentFingerprint($template),
            'query' => is_array($template['query'] ?? null) ? $template['query'] : [],
            'params' => is_array($template['params'] ?? null) ? $template['params'] : [],
            'layout' => $layout,
            'translatable_nodes' => $translatableNodes,
            'raw_template' => $template,
        ];
    }
}
