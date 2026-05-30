<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class ContentTranslateBatchTool extends AbstractTool
{
    private ContentTranslateTool $translateTool;

    public function __construct(?ContentTranslateTool $translateTool = null)
    {
        $this->translateTool = $translateTool ?? new ContentTranslateTool();
    }

    public function getName(): string
    {
        return 'content/translate-batch';
    }

    public function getDescription(): string
    {
        return 'Creates or updates multiple translated WordPress posts/pages by delegating each item to content/translate. Defaults to dry_run=true and requires confirm_safe_write=true for batch writes.';
    }

    public function getSurface(): string
    {
        return 'essential';
    }

    /**
     * @return array<string, mixed>
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['translations'],
            'properties' => [
                'target_language' => [
                    'type' => 'string',
                    'description' => 'Default target WPML/Polylang language code for all items. Individual items may override it.',
                ],
                'translations' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 25,
                    'description' => 'Translation work items. Each item accepts the same fields as content/translate.',
                    'items' => [
                        'type' => 'object',
                        'required' => ['source_id', 'translated_title', 'if_match'],
                        'properties' => [
                            'source_id' => ['type' => 'integer'],
                            'target_language' => ['type' => 'string'],
                            'translated_title' => ['type' => 'string'],
                            'translated_slug' => ['type' => 'string'],
                            'translated_content' => ['type' => 'string'],
                            'translated_excerpt' => ['type' => 'string'],
                            'translated_layout' => [
                                'oneOf' => [
                                    ['type' => 'object'],
                                    ['type' => 'string'],
                                ],
                            ],
                            'yootheme_text_replacements' => [
                                'oneOf' => [
                                    ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                                    ['type' => 'array', 'items' => ['type' => 'object']],
                                ],
                            ],
                            'status' => ['type' => 'string'],
                            'overwrite' => ['type' => 'boolean'],
                            'copy_terms' => ['type' => 'boolean'],
                            'if_match' => ['type' => 'string'],
                            'dry_run' => ['type' => 'boolean'],
                        ],
                    ],
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Default target post status for items that omit status.',
                ],
                'overwrite' => [
                    'type' => 'boolean',
                    'description' => 'Default overwrite value for items that omit overwrite.',
                ],
                'copy_terms' => [
                    'type' => 'boolean',
                    'description' => 'Default copy_terms value for items that omit copy_terms.',
                ],
                'dry_run' => [
                    'type' => 'boolean',
                    'description' => 'Defaults to true. Set false only when ready to write.',
                ],
                'confirm_safe_write' => [
                    'type' => 'boolean',
                    'description' => 'Required when dry_run=false at batch or item level.',
                ],
                'stop_on_error' => [
                    'type' => 'boolean',
                    'description' => 'Defaults to true. If false, later items continue after item errors.',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getPermissions(): array
    {
        return [
            'risk_level' => self::RISK_SAFE_WRITE,
            'idempotent' => false,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array
    {
        $items = $arguments['translations'] ?? null;

        if (!is_array($items) || $items === [] || !array_is_list($items)) {
            return [
                'error' => 'translations must be a non-empty array of translation work items.',
                'code' => 'invalid_translations',
            ];
        }

        if (count($items) > 25) {
            return [
                'error' => 'content/translate-batch accepts at most 25 items per call.',
                'code' => 'batch_too_large',
                'max_items' => 25,
            ];
        }

        $batchDryRun = array_key_exists('dry_run', $arguments) ? !empty($arguments['dry_run']) : true;
        $confirmSafeWrite = !empty($arguments['confirm_safe_write']);
        $stopOnError = array_key_exists('stop_on_error', $arguments) ? !empty($arguments['stop_on_error']) : true;
        $defaults = $this->defaults($arguments);

        if (!$batchDryRun && !$confirmSafeWrite) {
            return [
                'error' => 'Batch writes require confirm_safe_write=true. Omit dry_run or set dry_run=true to preview only.',
                'code' => 'confirm_safe_write_required',
                'dry_run' => true,
            ];
        }

        $results = [];
        $errors = 0;
        $writes = 0;
        $dryRuns = 0;

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $results[] = $this->itemError($index, 'Each translation item must be an object.', 'invalid_translation_item');
                $errors++;

                if ($stopOnError) {
                    break;
                }

                continue;
            }

            $itemArguments = $this->mergeItemDefaults($defaults, $item, $batchDryRun);

            if (empty($itemArguments['dry_run']) && !$confirmSafeWrite) {
                $results[] = $this->itemError(
                    $index,
                    'Item writes require confirm_safe_write=true.',
                    'confirm_safe_write_required'
                );
                $errors++;

                if ($stopOnError) {
                    break;
                }

                continue;
            }

            $result = $this->translateTool->handle($itemArguments);
            $result['index'] = $index;

            if (isset($result['error'])) {
                $errors++;
            }

            if (!empty($result['write_performed'])) {
                $writes++;
            }

            if (!empty($result['dry_run'])) {
                $dryRuns++;
            }

            $results[] = $result;

            if (isset($result['error']) && $stopOnError) {
                break;
            }
        }

        return [
            'dry_run' => $batchDryRun,
            'write_performed' => $writes > 0,
            'requested_count' => count($items),
            'processed_count' => count($results),
            'success_count' => count($results) - $errors,
            'error_count' => $errors,
            'write_count' => $writes,
            'dry_run_count' => $dryRuns,
            'stopped_on_error' => $stopOnError && $errors > 0 && count($results) < count($items),
            'results' => $results,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function defaults(array $arguments): array
    {
        $defaults = [];

        foreach (['target_language', 'status', 'overwrite', 'copy_terms'] as $key) {
            if (array_key_exists($key, $arguments)) {
                $defaults[$key] = $arguments[$key];
            }
        }

        return $defaults;
    }

    /**
     * @param array<string, mixed> $defaults
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function mergeItemDefaults(array $defaults, array $item, bool $batchDryRun): array
    {
        $merged = $item + $defaults;

        if ($batchDryRun) {
            $merged['dry_run'] = true;
            return $merged;
        }

        if (!array_key_exists('dry_run', $merged)) {
            $merged['dry_run'] = false;
        }

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    private function itemError(int $index, string $message, string $code): array
    {
        return [
            'index' => $index,
            'error' => $message,
            'code' => $code,
            'write_performed' => false,
        ];
    }
}
