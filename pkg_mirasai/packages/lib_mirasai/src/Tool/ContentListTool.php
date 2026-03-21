<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

class ContentListTool extends AbstractTool
{
    public function getName(): string
    {
        return 'content/list';
    }

    public function getDescription(): string
    {
        return 'Lists articles with their language, category, publication state, and existing translation associations. Use to discover which articles need translation.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'language' => [
                    'type' => 'string',
                    'description' => 'Filter by language code (e.g. ca-ES). Omit to list all.',
                ],
                'category_id' => [
                    'type' => 'integer',
                    'description' => 'Filter by category ID.',
                ],
                'has_yootheme' => [
                    'type' => 'boolean',
                    'description' => 'If true, only return articles with YOOtheme Builder content.',
                ],
            ],
        ];
    }

    public function handle(array $arguments): array
    {
        $query = $this->db->getQuery(true)
            ->select([
                'c.id',
                'c.title',
                'c.alias',
                'c.language',
                'c.state',
                'c.catid',
                'cat.title AS category_title',
                'LENGTH(c.introtext) AS introtext_length',
                'LENGTH(' . $this->db->quoteName('c.fulltext') . ') AS fulltext_length',
            ])
            ->from($this->db->quoteName('#__content', 'c'))
            ->join('LEFT', $this->db->quoteName('#__categories', 'cat') . ' ON cat.id = c.catid')
            ->order('c.id ASC');

        if (!empty($arguments['language'])) {
            $query->where('c.language = :lang')
                ->bind(':lang', $arguments['language']);
        }

        if (!empty($arguments['category_id'])) {
            $catId = (int) $arguments['category_id'];
            $query->where('c.catid = :catid')
                ->bind(':catid', $catId, \Joomla\Database\ParameterType::INTEGER);
        }

        $rows = $this->db->setQuery($query)->loadAssocList();
        $articles = [];

        foreach ($rows as $row) {
            $hasYootheme = str_contains($row['fulltext_length'] > 0 ? $this->getFulltextStart((int) $row['id']) : '', '<!-- {');

            if (!empty($arguments['has_yootheme']) && !$hasYootheme) {
                continue;
            }

            $articles[] = [
                'id' => (int) $row['id'],
                'title' => $row['title'],
                'alias' => $row['alias'],
                'language' => $row['language'],
                'state' => (int) $row['state'],
                'category_id' => (int) $row['catid'],
                'category_title' => $row['category_title'],
                'has_yootheme_builder' => $hasYootheme,
                'associations' => $this->getAssociations((int) $row['id']),
            ];
        }

        return ['articles' => $articles, 'total' => count($articles)];
    }

    /**
     * @return array<string, int>
     */
    private function getAssociations(int $articleId): array
    {
        $query = $this->db->getQuery(true)
            ->select(['a2.id', 'c.language'])
            ->from($this->db->quoteName('#__associations', 'a1'))
            ->join('INNER', $this->db->quoteName('#__associations', 'a2') . ' ON a1.' . $this->db->quoteName('key') . ' = a2.' . $this->db->quoteName('key') . ' AND a2.id != a1.id')
            ->join('INNER', $this->db->quoteName('#__content', 'c') . ' ON c.id = a2.id')
            ->where('a1.context = ' . $this->db->quote('com_content.item'))
            ->where('a2.context = ' . $this->db->quote('com_content.item'))
            ->where('a1.id = :aid')
            ->bind(':aid', $articleId, \Joomla\Database\ParameterType::INTEGER);

        $rows = $this->db->setQuery($query)->loadAssocList();
        $result = [];

        foreach ($rows as $row) {
            $result[$row['language']] = (int) $row['id'];
        }

        return $result;
    }

    private function getFulltextStart(int $articleId): string
    {
        $query = $this->db->getQuery(true)
            ->select('SUBSTRING(' . $this->db->quoteName('fulltext') . ', 1, 10) AS ft_start')
            ->from($this->db->quoteName('#__content'))
            ->where('id = :id')
            ->bind(':id', $articleId, \Joomla\Database\ParameterType::INTEGER);

        return (string) $this->db->setQuery($query)->loadResult();
    }
}
