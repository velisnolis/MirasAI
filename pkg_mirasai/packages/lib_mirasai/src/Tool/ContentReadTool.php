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
        return 'Reads a single article by ID, including full content, YOOtheme Builder layout JSON (if present), and metadata.';
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

        if (str_starts_with(trim($fulltext), '<!-- {')) {
            $result['has_yootheme_builder'] = true;
            $json = $this->extractYoothemeJson($fulltext);

            if ($json !== null) {
                $layout = json_decode($json, true);

                if ($layout !== null) {
                    $result['yootheme_layout'] = $layout;
                    $result['yootheme_translatable_nodes'] = $this->findTranslatableNodes($layout);
                }
            }
        } else {
            $result['fulltext'] = $fulltext;
        }

        return $result;
    }

    private function extractYoothemeJson(string $fulltext): ?string
    {
        $fulltext = trim($fulltext);

        if (!str_starts_with($fulltext, '<!-- ')) {
            return null;
        }

        $end = strrpos($fulltext, ' -->');

        if ($end === false) {
            return null;
        }

        return substr($fulltext, 5, $end - 5);
    }

    private const TEXT_PROPS = [
        'content', 'title', 'meta', 'subtitle', 'text', 'video_title',
        'link_text', 'label', 'description', 'caption', 'alt',
        'button_text', 'heading', 'footer', 'header', 'placeholder',
    ];

    private const CONFIG_PROPS = [
        'title_position', 'title_style', 'title_element', 'title_decoration',
        'image_position', 'image_effect', 'meta_align', 'id', 'class',
        'title_rotation', 'title_breakpoint', 'heading_style', 'height',
        'width', 'style', 'animation', 'name', 'status', 'source',
    ];

    /**
     * @param  array<string, mixed> $node
     * @param  string               $path
     * @return list<array{path: string, node_type: string, field: string, text: string, format: string}>
     */
    private function findTranslatableNodes(array $node, string $path = 'root'): array
    {
        $results = [];
        $props = $node['props'] ?? [];
        $nodeType = $node['type'] ?? 'unknown';

        foreach ($props as $key => $value) {
            if (!is_string($value) || strlen(trim($value)) < 2) {
                continue;
            }

            if (in_array($key, self::CONFIG_PROPS, true)) {
                continue;
            }

            $isTextProp = in_array($key, self::TEXT_PROPS, true);
            $looksLikeText = strlen($value) > 15
                && str_contains($value, ' ')
                && !preg_match('/^(http|\/|#|images\/|uk-|el-)/', $value)
                && !preg_match('/(px|vh|vw|%|\{)/', $value);

            if ($isTextProp || $looksLikeText) {
                $format = 'plain';

                if (preg_match('/<[a-z][\s>]/i', $value)) {
                    $format = 'html';
                }

                $results[] = [
                    'path' => $path,
                    'node_type' => $nodeType,
                    'field' => $key,
                    'text' => $value,
                    'format' => $format,
                ];
            }
        }

        foreach ($node['children'] ?? [] as $i => $child) {
            $childType = $child['type'] ?? 'unknown';
            $childPath = "{$path}>{$childType}[{$i}]";
            $results = array_merge($results, $this->findTranslatableNodes($child, $childPath));
        }

        return $results;
    }
}
