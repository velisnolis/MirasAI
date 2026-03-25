<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

class ContentReadTool extends AbstractTool
{
    public function getName(): string
    {
        return 'content/read';
    }

    public function getDescription(): string
    {
        return 'Reads a single article by ID. Returns title, language, introtext, metadesc, metakey, and category. '
            . 'For standard articles: introtext and fulltext contain the article HTML. '
            . 'For YOOtheme Builder articles (has_yootheme_builder=true): returns yootheme_translatable_nodes — '
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
            'category_id' => (int) $row['catid'],
            'category_title' => $row['category_title'],
            'created' => $row['created'],
            'modified' => $row['modified'],
            'metadesc' => $row['metadesc'],
            'metakey' => $row['metakey'],
            'introtext' => $row['introtext'],
            'has_yootheme_builder' => false,
            'yootheme_layout' => null,
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
                    $result['yootheme_translatable_nodes'] = $processor->findTranslatableNodes($layout);
                }
            }
        } else {
            $result['fulltext'] = $fulltext;
        }

        return $result;
    }
}
