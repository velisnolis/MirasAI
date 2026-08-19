<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Mirasai\Library\Tool\AbstractTool;
use Mirasai\Library\Tool\YooThemeElementNavigator;
use Mirasai\Library\Tool\YooThemeHelper;

class TemplateReadTool extends AbstractTool
{
    use TemplateElementSourceSupportTrait;

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
        return 'Reads a single YOOtheme Builder page template by key. Returns its assignment metadata, full layout JSON, '
            . 'and an array of translatable_nodes with replacement_key (same format as content/read\'s yootheme_translatable_nodes). '
            . 'Use the replacement_key values in template/translate\'s yootheme_text_replacements. '
            . 'mode=outline returns a nested type/path/title tree with no props; mode=bindings_only returns Dynamic Source bindings only. The etag is always the full layout.';
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
                'mode' => YooThemeElementNavigator::readModeSchemaProperty(),
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

        $mode = YooThemeElementNavigator::normalizeReadMode($arguments['mode'] ?? null);

        if (isset($mode['error'])) {
            return $mode;
        }

        $templates = $this->yooHelper->loadTemplates();
        $template = $templates[$key] ?? null;

        if (!is_array($template)) {
            return ['error' => "Template {$key} not found."];
        }

        $layout = $this->yooHelper->getTemplateLayout($template);
        $translatableNodes = $this->yooHelper->findTemplateTranslatableNodes($template);

        $response = [
            'key' => $key,
            'name' => $this->yooHelper->getTemplateName($template),
            'type' => is_string($template['type'] ?? null) ? $template['type'] : '',
            'language' => $this->yooHelper->getTemplateLanguage($template) ?: '*',
            'etag' => $this->yooHelper->buildTemplateEtag($template),
            'mode' => $mode['mode'],
            'dynamic_only' => $translatableNodes === [],
            'has_static_text' => $translatableNodes !== [],
            'assignment_fingerprint' => $this->yooHelper->buildTemplateAssignmentFingerprint($template),
            'query' => is_array($template['query'] ?? null) ? $template['query'] : [],
            'params' => is_array($template['params'] ?? null) ? $template['params'] : [],
            'layout' => $layout,
            'translatable_nodes' => $translatableNodes,
            'raw_template' => $template,
        ];

        if ($mode['mode'] === 'full') {
            return $response;
        }

        unset($response['layout'], $response['translatable_nodes'], $response['raw_template'], $response['params']);

        if ($mode['mode'] === 'outline') {
            $response['tree'] = (new YooThemeElementNavigator())->outlineTree(is_array($layout) ? $layout : ['type' => 'layout']);

            return $response;
        }

        $response['bindings'] = $this->bindingsOnlyFromLayout(
            new YooThemeElementNavigator(),
            is_array($layout) ? $layout : ['type' => 'layout'],
        );

        return $response;
    }
}
