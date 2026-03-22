<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

class TemplateListTool extends AbstractTool
{
    public function getName(): string
    {
        return 'template/list';
    }

    public function getDescription(): string
    {
        return 'Lists YOOtheme Builder templates stored in custom_data, including their assignment type, language filter, and whether they contain fixed translatable text.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'language' => [
                    'type' => 'string',
                    'description' => 'Optional language filter (e.g. ca-ES, es-ES). Use "*" to list templates shared across all languages.',
                ],
                'type' => [
                    'type' => 'string',
                    'description' => 'Optional YOOtheme template assignment type filter (e.g. com_content.article).',
                ],
                'has_static_text' => [
                    'type' => 'boolean',
                    'description' => 'If true, only return templates with fixed translatable text.',
                ],
            ],
        ];
    }

    public function handle(array $arguments): array
    {
        $templates = $this->loadYoothemeTemplates();
        $languageFilter = isset($arguments['language']) ? trim((string) $arguments['language']) : null;
        $typeFilter = isset($arguments['type']) ? trim((string) $arguments['type']) : null;
        $hasStaticFilter = array_key_exists('has_static_text', $arguments)
            ? (bool) $arguments['has_static_text']
            : null;

        $items = [];

        foreach ($templates as $key => $template) {
            if (!is_array($template)) {
                continue;
            }

            $language = $this->getYoothemeTemplateLanguage($template);
            $language = $language === '' ? '*' : $language;
            $type = is_string($template['type'] ?? null) ? (string) $template['type'] : '';
            $translatableNodes = $this->findYoothemeTemplateTranslatableNodes($template);
            $hasStaticText = $translatableNodes !== [];

            if ($languageFilter !== null && $languageFilter !== '' && $language !== $languageFilter) {
                continue;
            }

            if ($typeFilter !== null && $typeFilter !== '' && $type !== $typeFilter) {
                continue;
            }

            if ($hasStaticFilter !== null && $hasStaticText !== $hasStaticFilter) {
                continue;
            }

            $items[] = [
                'key' => (string) $key,
                'name' => $this->getYoothemeTemplateName($template),
                'type' => $type,
                'language' => $language,
                'dynamic_only' => !$hasStaticText,
                'has_static_text' => $hasStaticText,
                'translatable_node_count' => count($translatableNodes),
                'assignment_fingerprint' => $this->buildYoothemeTemplateAssignmentFingerprint($template),
            ];
        }

        return [
            'count' => count($items),
            'templates' => $items,
        ];
    }
}
