<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class TemplateListTool extends AbstractTool
{
    public function getName(): string
    {
        return 'template/list';
    }

    public function getDescription(): string
    {
        return 'Lists detected YOOtheme Builder layouts across WordPress templates, posts/pages, and YOOtheme Builder widgets.';
    }

    public function getSurface(): string
    {
        return 'essential';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'storages' => [
                    'type' => 'array',
                    'description' => 'Optional storage filter. Defaults to template, post, and widget.',
                    'items' => [
                        'type' => 'string',
                        'enum' => ['template', 'post', 'widget'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array
    {
        $storages = $this->normalizeStorages($arguments['storages'] ?? null);

        if (isset($storages['error'])) {
            return $storages;
        }

        $helper = new YoothemeWpHelper();
        $layouts = $helper->listLayouts($storages['storages']);

        return [
            'count' => count($layouts),
            'storages' => $storages['storages'],
            'collection_etag' => $helper->etag($helper->loadState()),
            'layouts' => $layouts,
        ];
    }

    /**
     * @return array{storages: list<string>}|array{error: string, code: string}
     */
    private function normalizeStorages(mixed $value): array
    {
        if ($value === null) {
            return ['storages' => ['template', 'post', 'widget']];
        }

        if (!is_array($value)) {
            return ['error' => 'storages must be an array.', 'code' => 'invalid_storages'];
        }

        $storages = [];
        foreach ($value as $storage) {
            if (!is_string($storage) || !in_array($storage, ['template', 'post', 'widget'], true)) {
                return ['error' => 'storages may only contain template, post, or widget.', 'code' => 'invalid_storages'];
            }

            if (!in_array($storage, $storages, true)) {
                $storages[] = $storage;
            }
        }

        return ['storages' => $storages === [] ? ['template', 'post', 'widget'] : $storages];
    }
}
