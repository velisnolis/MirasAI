<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Mirasai\Library\Tool\AbstractTool;
use Mirasai\Library\Tool\YooThemeHelper;

class TemplateListTool extends AbstractTool
{
    private YooThemeHelper $yooHelper;

    public function __construct()
    {
        parent::__construct();
        $this->yooHelper = new YooThemeHelper($this->db);
    }

    public function getName(): string
    {
        return 'template/list';
    }

    public function getDescription(): string
    {
        return 'Lists YOOtheme Builder page templates (NOT articles — these are page-level layout overrides stored in the theme\'s custom_data). '
            . 'Templates control how specific pages look (e.g. a custom blog layout, a landing page template). '
            . 'Returns each template\'s key, assignment type, language filter, and whether it has translatable static text. '
            . 'Use template/read to inspect a specific template, then template/translate to create a language-specific copy.';
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
        $templates = $this->yooHelper->loadTemplates();
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

            $language = $this->yooHelper->getTemplateLanguage($template);
            $language = $language === '' ? '*' : $language;
            $type = is_string($template['type'] ?? null) ? (string) $template['type'] : '';
            $translatableNodes = $this->yooHelper->findTemplateTranslatableNodes($template);
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
                'name' => $this->yooHelper->getTemplateName($template),
                'type' => $type,
                'language' => $language,
                'dynamic_only' => !$hasStaticText,
                'has_static_text' => $hasStaticText,
                'translatable_node_count' => count($translatableNodes),
                'assignment_fingerprint' => $this->yooHelper->buildTemplateAssignmentFingerprint($template),
            ];
        }

        return [
            'count' => count($items),
            'templates' => $items,
        ];
    }
}
