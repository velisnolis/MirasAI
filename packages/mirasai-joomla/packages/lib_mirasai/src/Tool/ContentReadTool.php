<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

class ContentReadTool extends AbstractTool
{
    public function getName(): string
    {
        return 'content/read';
    }

    public function getDescription(): string
    {
        return 'Reads a single article by ID. Returns title, language, introtext, metadesc, metakey, category, and the etag '
            . 'content/update and template/layout-init take as if_match. '
            . 'For standard articles: introtext and fulltext contain the article HTML. '
            . 'For YOOtheme Builder articles (has_yootheme_builder=true): returns yootheme_layout_summary and yootheme_translatable_nodes — '
            . 'an array of {path, field, replacement_key, text, format}. '
            . 'Use each node\'s replacement_key as the key in yootheme_text_replacements when calling content/translate.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'integer',
                    'description' => 'Article ID to read.',
                ],
            ],
            'required' => ['id'],
        ];
    }

    public function handle(array $arguments): array
    {
        $id = (int) ($arguments['id'] ?? 0);

        if ($id <= 0) {
            return ['error' => 'Article ID is required.'];
        }

        $query = $this->db->getQuery(true)
            ->select([
                'c.id', 'c.title', 'c.alias', 'c.language', 'c.state',
                'c.catid', 'c.introtext',
                $this->db->quoteName('c.fulltext', 'fulltext_raw'),
                'c.created', 'c.modified', 'c.metadesc', 'c.metakey',
                'cat.title AS category_title',
            ])
            ->from($this->db->quoteName('#__content', 'c'))
            ->join('LEFT', $this->db->quoteName('#__categories', 'cat') . ' ON cat.id = c.catid')
            ->where('c.id = :id')
            ->bind(':id', $id, \Joomla\Database\ParameterType::INTEGER);

        $row = $this->db->setQuery($query)->loadAssoc();

        if (!$row) {
            return ['error' => "Article {$id} not found."];
        }

        $result = [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'alias' => $row['alias'],
            'language' => $row['language'],
            'state' => (int) $row['state'],
            'etag' => self::contentEtag(['fulltext' => $row['fulltext_raw']] + $row),
            'category_id' => (int) $row['catid'],
            'category_title' => $row['category_title'],
            'created' => $row['created'],
            'modified' => $row['modified'],
            'metadesc' => $row['metadesc'],
            'metakey' => $row['metakey'],
            'introtext' => $row['introtext'],
            'has_yootheme_builder' => false,
            'yootheme_layout' => null,
            'yootheme_layout_summary' => null,
        ];

        $fulltext = $row['fulltext_raw'] ?? '';
        $processor = new YooThemeLayoutProcessor();

        if ($processor->detectLayout($fulltext)) {
            $result['has_yootheme_builder'] = true;
            $json = $processor->extractJson($fulltext);

            if ($json !== null) {
                $layout = json_decode($json, true);

                if (is_array($layout)) {
                    $result['yootheme_layout'] = $layout;
                    $result['yootheme_layout_summary'] = (new YooThemeLayoutSummarizer())->summarize($layout);
                    $result['yootheme_translatable_nodes'] = $processor->findTranslatableNodes($layout);
                    $result['translation_coverage_warnings'] = $processor->getTranslationCoverageWarnings($layout);
                }
            }
        } else {
            $result['fulltext'] = $fulltext;
        }

        return $result;
    }

    /**
     * The article as the tools that take the content etag need it, or null when it does not exist.
     *
     * @return array<string, mixed>|null
     */
    public static function loadForEtag(DatabaseInterface $db, int $id): ?array
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName([
                'id', 'title', 'alias', 'language', 'state', 'catid', 'introtext', 'fulltext', 'modified',
                'checked_out', 'publish_up', 'publish_down',
            ]))
            ->from($db->quoteName('#__content'))
            ->where('id = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        $article = $db->setQuery($query)->loadAssoc();

        return is_array($article) ? $article : null;
    }

    /**
     * Mirrors mirasai-wp's contentEtag(): what an article says and where it sits, so a write planned
     * on an older read is refused.
     *
     * @param array<string, mixed> $article
     */
    public static function contentEtag(array $article): string
    {
        $value = [];

        foreach (['id', 'title', 'alias', 'language', 'state', 'catid', 'introtext', 'fulltext', 'modified'] as $field) {
            $value[$field] = (string) ($article[$field] ?? '');
        }

        return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }
}
