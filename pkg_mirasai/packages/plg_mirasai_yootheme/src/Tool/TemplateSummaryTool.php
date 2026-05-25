<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Mirasai\Library\Tool\AbstractTool;
use Mirasai\Library\Tool\YooThemeHelper;
use Mirasai\Library\Tool\YooThemeLayoutSummarizer;

class TemplateSummaryTool extends AbstractTool
{
    private YooThemeHelper $yooHelper;

    public function __construct()
    {
        parent::__construct();
        $this->yooHelper = new YooThemeHelper($this->db);
    }

    public function getName(): string
    {
        return 'template/summary';
    }

    public function getDescription(): string
    {
        return 'Returns a compact YOOtheme Builder template overview: element counts by type, max nesting depth, '
            . 'source binding count, named landmarks, translatable node count, assignment fingerprint, and current etag. '
            . 'Use this before template/read when you need orientation without fetching the full layout JSON.';
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
            'etag' => $this->yooHelper->buildTemplateEtag($template),
            'assignment_fingerprint' => $this->yooHelper->buildTemplateAssignmentFingerprint($template),
            'has_layout' => $layout !== null,
            'has_static_text' => $translatableNodes !== [],
            'dynamic_only' => $translatableNodes === [],
            'translatable_node_count' => count($translatableNodes),
            'text_formats' => $this->countTextFormats($translatableNodes),
            'layout_summary' => $layout !== null
                ? (new YooThemeLayoutSummarizer())->summarize($layout)
                : null,
        ];
    }

    /**
     * @param list<array{path: string, node_type: string, field: string, replacement_key: string, text: string, format: string}> $nodes
     * @return array<string, int>
     */
    private function countTextFormats(array $nodes): array
    {
        $counts = [];

        foreach ($nodes as $node) {
            $format = is_string($node['format'] ?? null) ? $node['format'] : 'unknown';
            $counts[$format] = ($counts[$format] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }
}
