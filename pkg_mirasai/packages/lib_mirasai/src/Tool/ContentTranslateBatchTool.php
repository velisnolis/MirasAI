<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

class ContentTranslateBatchTool extends AbstractTool
{
    public function getName(): string
    {
        return 'content/translate-batch';
    }

    public function getDescription(): string
    {
        return 'Translates multiple articles to a target language in a single call. Each article requires its own translated_title and yootheme_text_replacements. Returns per-article results with any link warnings. After all translations, runs check-links to fix any newly fixable internal links.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'target_language' => [
                    'type' => 'string',
                    'description' => 'Target language code (e.g. en-GB).',
                ],
                'articles' => [
                    'type' => 'array',
                    'description' => 'Array of articles to translate.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'source_id' => [
                                'type' => 'integer',
                                'description' => 'Source article ID.',
                            ],
                            'translated_title' => [
                                'type' => 'string',
                                'description' => 'Translated title.',
                            ],
                            'translated_alias' => [
                                'type' => 'string',
                                'description' => 'URL alias (auto-generated if omitted).',
                            ],
                            'yootheme_text_replacements' => [
                                'type' => 'object',
                                'description' => 'Map of "path.field" => "translated text".',
                                'additionalProperties' => ['type' => 'string'],
                            ],
                        ],
                        'required' => ['source_id', 'translated_title'],
                    ],
                ],
                'overwrite' => [
                    'type' => 'boolean',
                    'description' => 'If true, overwrites existing translations. Default: false.',
                ],
                'fix_links' => [
                    'type' => 'boolean',
                    'description' => 'If true, runs check-links in fix mode after all translations. Default: true.',
                ],
            ],
            'required' => ['target_language', 'articles'],
        ];
    }

    public function getPermissions(): array
    {
        return [
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ];
    }

    public function handle(array $arguments): array
    {
        $targetLang = $arguments['target_language'] ?? '';
        $articles = $arguments['articles'] ?? [];
        $overwrite = !empty($arguments['overwrite']);
        $fixLinks = $arguments['fix_links'] ?? true;

        if ($targetLang === '' || empty($articles)) {
            return ['error' => 'target_language and articles are required.'];
        }

        $translateTool = new ContentTranslateTool();
        $results = [];
        $succeeded = 0;
        $failed = 0;

        foreach ($articles as $i => $article) {
            $translateArgs = [
                'source_id' => $article['source_id'] ?? 0,
                'target_language' => $targetLang,
                'translated_title' => $article['translated_title'] ?? '',
                'overwrite' => $overwrite,
            ];

            if (!empty($article['translated_alias'])) {
                $translateArgs['translated_alias'] = $article['translated_alias'];
            }

            if (!empty($article['yootheme_text_replacements'])) {
                $translateArgs['yootheme_text_replacements'] = $article['yootheme_text_replacements'];
            }

            $result = $translateTool->handle($translateArgs);

            if (isset($result['error'])) {
                $failed++;
                $results[] = [
                    'source_id' => $article['source_id'] ?? 0,
                    'status' => 'error',
                    'error' => $result['error'],
                ];
            } else {
                $succeeded++;
                $results[] = [
                    'source_id' => $article['source_id'] ?? 0,
                    'status' => 'ok',
                    'article_id' => $result['article_id'] ?? null,
                    'title' => $result['title'] ?? '',
                    'action' => $result['action'] ?? '',
                    'menu_item' => $result['menu_item'] ?? null,
                    'link_warnings_count' => count($result['link_warnings'] ?? []),
                ];
            }
        }

        // Run check-links in fix mode
        $checkLinksResult = null;

        if ($fixLinks && $succeeded > 0) {
            $checkLinksTool = new ContentCheckLinksTool();
            $checkLinksResult = $checkLinksTool->handle([
                'language' => $targetLang,
                'fix' => true,
            ]);
        }

        return [
            'target_language' => $targetLang,
            'total' => count($articles),
            'succeeded' => $succeeded,
            'failed' => $failed,
            'results' => $results,
            'check_links' => $checkLinksResult ? [
                'fixed' => $checkLinksResult['total_fixed'] ?? 0,
                'broken' => $checkLinksResult['total_broken_links'] ?? 0,
            ] : null,
        ];
    }
}
