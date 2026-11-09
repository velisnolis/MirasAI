<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class TemplateElementSourcePreviewTool extends TemplateReadTool
{
    public function getName(): string
    {
        return 'template/element-source-preview';
    }

    public function getDescription(): string
    {
        return 'Previews setting a YOOtheme Dynamic Source binding on one element without writing. Accepts either a raw source object or shorthand source_name/query_field/field_mappings. warnings lists bound props whose field path goes through a list field (binding_through_list_field): YOOtheme leaves them empty outside an element repeated over that list, and a !! condition on one hides the element on every page.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['path'],
            'properties' => array_merge($this->targetSelectorSchema(), $this->sourceInputProperties(), [
                'path' => ['type' => 'string', 'description' => 'Element path returned by template/element-list.'],
                'if_match' => ['type' => 'string', 'description' => 'Optional current layout etag. Stale values are reported in the preview.'],
                'include_raw' => ['type' => 'boolean', 'description' => 'Include raw source payloads in the response. Defaults to false.'],
            ]),
        ];
    }

    public function handle(array $arguments): array
    {
        $response = $this->preview($arguments);

        if (isset($response['error'])) {
            return $response;
        }

        $source = $this->normalizeSourceArgument($arguments);
        $warnings = is_array($source) && !isset($source['error']) ? $this->listFieldWarnings($source) : [];

        if ($warnings !== []) {
            $response['warnings'] = $warnings;
        }

        return $response;
    }

    /**
     * Bound props that go through a list field (F-042). Asks template/source-types for the query's result
     * type and the field types; when the source schema cannot be read, it says nothing rather than guess.
     *
     * @param array<string, mixed> $source
     * @return list<array<string, string>>
     */
    private function listFieldWarnings(array $source): array
    {
        $queryPath = SourceListPaths::queryPath($source);

        if ($queryPath === '' || !is_array($source['props'] ?? null) || $source['props'] === []) {
            return [];
        }

        try {
            $sourceTypes = new TemplateSourceTypesTool();
            $hints = $sourceTypes->handle(['source_name' => $queryPath, 'include_binding_hints' => true]);
            $resultType = $hints['binding_hints']['query']['result_type'] ?? null;

            if (!is_string($resultType) || $resultType === '') {
                return [];
            }

            $types = SourceListPaths::typeMap($sourceTypes->handle(['kind' => 'object', 'include_fields' => true]));

            return SourceListPaths::check($source, $resultType, $types);
        } catch (\Throwable) {
            return [];
        }
    }

    private function preview(array $arguments): array
    {
        $path = trim((string) ($arguments['path'] ?? ''));
        $ifMatch = trim((string) ($arguments['if_match'] ?? ''));
        $includeRaw = !empty($arguments['include_raw']);

        if ($path === '') {
            return ['error' => 'path is required.', 'code' => 'missing_path'];
        }

        $source = $this->normalizeSourceArgument($arguments);

        if (isset($source['error'])) {
            return $source;
        }

        $helper = new YoothemeWpHelper();
        $target = $helper->resolveTarget($arguments);

        if (isset($target['error'])) {
            return $target;
        }

        $current = (new YoothemeElementNavigator())->findElement($target['layout'], $path);

        if ($current === null) {
            return ['error' => "Element path {$path} not found.", 'code' => 'element_not_found'];
        }

        $updated = (new YoothemeElementNavigator())->setElementSource($target['layout'], $path, $source);

        if (isset($updated['error'])) {
            return $updated;
        }

        $before = $this->summarizeBinding($current['element']);
        $after = $this->summarizeBinding($updated['element']);

        if (!$includeRaw) {
            unset($before['raw_source'], $after['raw_source']);
        }

        return [
            'storage' => $target['storage'],
            'id' => $target['id'],
            'label' => $target['label'],
            'path' => $path,
            'dry_run' => true,
            'action' => 'preview',
            'etag_matches' => $ifMatch === '' ? null : hash_equals($target['etag'], $ifMatch),
            'old_etag' => $target['etag'],
            'metadata' => $updated['metadata'],
            'before' => $before,
            'after' => $after,
            'note' => 'No changes were written. Retry template/element-source-set with confirm_guarded_write=true and the current if_match to apply.',
        ];
    }
}
