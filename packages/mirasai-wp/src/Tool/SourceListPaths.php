<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

/**
 * Finds bound props whose field path goes through a list ([Type]).
 *
 * YOOtheme does not resolve a prop or a display condition through a list field on an element that is not
 * repeated over that list: the value stays empty and a «!!» condition hides the element on every page.
 * Seen with an ACF gallery on ambiens and reproduced with posts.singlePost categories and categories.name
 * (docs/yootheme-builder-findings.md F-042). The cause inside YOOtheme is not located.
 *
 * Mirrored in plg_mirasai_yootheme. Both copies must stay free of WordPress and Joomla calls so
 * docker/test-source-list-paths.php can run them as plain PHP.
 */
final class SourceListPaths
{
    /**
     * @param array<string, mixed> $source the binding: query plus props, each prop {name: "a.b"}
     * @param array<string, array<string, string>> $types type name => field name => GraphQL type ("[Category]", "String!")
     * @return list<array<string, string>>
     */
    public static function check(array $source, string $resultType, array $types): array
    {
        $warnings = [];

        foreach (is_array($source['props'] ?? null) ? $source['props'] : [] as $prop => $binding) {
            $path = is_array($binding) && is_string($binding['name'] ?? null) ? $binding['name'] : '';
            $type = $resultType;

            foreach ($path === '' ? [] : explode('.', $path) as $segment) {
                $fieldType = $types[$type][$segment] ?? null;

                if ($fieldType === null) {
                    break;
                }

                $fieldType = rtrim($fieldType, '!');

                if (str_starts_with($fieldType, '[')) {
                    $warnings[] = [
                        'code' => 'binding_through_list_field',
                        'prop' => (string) $prop,
                        'path' => $path,
                        'list_field' => $segment,
                        'list_type' => $fieldType,
                        'message' => sprintf(
                            '%s is bound to %s, which goes through the list field %s (%s). YOOtheme leaves such a value empty outside an element repeated over that list%s.',
                            (string) $prop,
                            $path,
                            $segment,
                            $fieldType,
                            $prop === '_condition' ? ', so this condition hides the element on every page' : ''
                        ),
                        'fix' => 'Bind a multiple-items element (its item) to the list, or use a text field the source offers for it (for example categoryString instead of categories).',
                    ];
                    break;
                }

                $type = rtrim(trim($fieldType, '[]'), '!');
            }
        }

        return $warnings;
    }

    /**
     * The type map from template/source-types with kind=object and include_fields=true.
     *
     * @param array<string, mixed> $sourceTypes
     * @return array<string, array<string, string>>
     */
    public static function typeMap(array $sourceTypes): array
    {
        $map = [];

        foreach (is_array($sourceTypes['types'] ?? null) ? $sourceTypes['types'] : [] as $type) {
            if (!is_array($type) || !is_string($type['name'] ?? null)) {
                continue;
            }

            foreach (is_array($type['fields'] ?? null) ? $type['fields'] : [] as $field) {
                if (is_array($field) && is_string($field['name'] ?? null) && is_string($field['type'] ?? null)) {
                    $map[$type['name']][$field['name']] = $field['type'];
                }
            }
        }

        return $map;
    }

    /** posts.singlePost from {query: {name: posts, field: {name: singlePost}}}. */
    public static function queryPath(array $source): string
    {
        $query = is_array($source['query'] ?? null) ? $source['query'] : [];
        $name = is_string($query['name'] ?? null) ? $query['name'] : '';
        $field = is_array($query['field'] ?? null) && is_string($query['field']['name'] ?? null) ? $query['field']['name'] : '';

        return $name === '' ? '' : ($field === '' ? $name : $name . '.' . $field);
    }
}
