<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

class TemplateReadTool extends AbstractTool
{
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

        $templates = $this->loadYoothemeTemplates();
        $template = $templates[$key] ?? null;

        if (!is_array($template)) {
            return ['error' => "Template {$key} not found."];
        }

        $layout = $this->getYoothemeTemplateLayout($template);
        $translatableNodes = $this->findYoothemeTemplateTranslatableNodes($template);

        return [
            'key' => $key,
            'name' => $this->getYoothemeTemplateName($template),
            'type' => is_string($template['type'] ?? null) ? $template['type'] : '',
            'language' => $this->getYoothemeTemplateLanguage($template) ?: '*',
            'dynamic_only' => $translatableNodes === [],
            'has_static_text' => $translatableNodes !== [],
            'assignment_fingerprint' => $this->buildYoothemeTemplateAssignmentFingerprint($template),
            'query' => is_array($template['query'] ?? null) ? $template['query'] : [],
            'params' => is_array($template['params'] ?? null) ? $template['params'] : [],
            'layout' => $layout,
            'translatable_nodes' => $translatableNodes,
            'raw_template' => $template,
        ];
    }
}
