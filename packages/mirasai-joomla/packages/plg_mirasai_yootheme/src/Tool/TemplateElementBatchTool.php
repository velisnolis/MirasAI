<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Mirasai\Library\Tool\ToolArgumentValidator;

class TemplateElementBatchTool extends AbstractTemplateElementWriteTool
{
    private const MAX_OPERATIONS = 100;

    /** op => the tool that performs it, with its own checks */
    private const OPERATIONS = [
        'add' => TemplateElementAddTool::class,
        'update_props' => TemplateElementUpdatePropsTool::class,
        'move' => TemplateElementMoveTool::class,
        'clone' => TemplateElementCloneTool::class,
        'delete' => TemplateElementDeleteTool::class,
        'replace_children' => TemplateElementReplaceChildrenTool::class,
        'source_set' => TemplateElementSourceSetTool::class,
        'source_delete' => TemplateElementSourceDeleteTool::class,
    ];

    /** Arguments that belong to the batch as a whole, never to one operation. */
    private const BATCH_ONLY = ['if_match', 'dry_run', 'confirm_guarded_write'];

    public function getName(): string
    {
        return 'template/element-batch';
    }

    public function getDescription(): string
    {
        return 'Applies several element changes to one YOOtheme Builder layout in a single guarded write, under one if_match: one read, one preview and one write instead of three calls per change. operations run in order and each one sees the layout as the previous ones left it, so a path in a later operation is the path after the earlier ones (a delete shifts the siblings behind it). All or nothing: if any operation fails, nothing is written and the error names the operation. Each operation takes the arguments of its single tool (template/element-add, element-update-props, element-move, element-clone, element-delete, element-replace-children, element-source-set, element-source-delete) without the target, if_match, dry_run and confirm_guarded_write, which are given once for the batch. Supports templates, article layouts, and YOOtheme Builder modules.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['if_match', 'operations'],
            'properties' => array_merge($this->targetSelectorSchema(), [
                'operations' => [
                    'type' => 'array',
                    'description' => 'Changes to apply in order, at most ' . self::MAX_OPERATIONS . '. Each is an object with op (' . implode(', ', array_keys(self::OPERATIONS)) . ') plus the arguments of that tool, for example {"op":"delete","path":"root>section[2]"} or {"op":"update_props","path":"root>section[0]>row[0]","props":{"layout":"1-2,1-2"}}.',
                    'items' => [
                        'type' => 'object',
                        'required' => ['op'],
                        'properties' => [
                            'op' => ['type' => 'string', 'enum' => array_keys(self::OPERATIONS)],
                        ],
                        'additionalProperties' => true,
                    ],
                ],
                'if_match' => ['type' => 'string', 'description' => 'Required current layout etag. Stale values are rejected before any write.'],
                'dry_run' => ['type' => 'boolean', 'description' => 'If true, validate every operation and preview without writing.'],
                'confirm_guarded_write' => ['type' => 'boolean', 'description' => 'Required for the real write after review. Not required when dry_run=true.'],
            ]),
        ];
    }

    public function handle(array $arguments): array
    {
        return $this->mutateTemplateElement($arguments, function (array $layout, array $arguments): array {
            $operations = $arguments['operations'] ?? null;

            if (!is_array($operations) || !array_is_list($operations) || $operations === []) {
                return ['error' => 'operations must be a non-empty list.', 'code' => 'invalid_operations'];
            }

            if (count($operations) > self::MAX_OPERATIONS) {
                return [
                    'error' => 'A batch takes at most ' . self::MAX_OPERATIONS . ' operations; got ' . count($operations) . '.',
                    'code' => 'too_many_operations',
                ];
            }

            $results = [];

            foreach ($operations as $index => $operation) {
                $result = $this->applyOperation($layout, $operation);

                if (isset($result['error'])) {
                    return $result + [
                        'failed_operation' => $index,
                        'op' => is_array($operation) && is_string($operation['op'] ?? null) ? $operation['op'] : null,
                        'operations_before_failure' => $results,
                        'action_required' => 'Nothing was written: a batch is all or nothing. Paths in an operation are read against the layout as the operations before it left it.',
                    ];
                }

                $layout = $result['layout'];
                unset($result['layout']);
                $results[] = ['index' => $index, 'op' => $operation['op']] + $result;
            }

            return [
                'operation_count' => count($results),
                'operations' => $results,
                'layout' => $layout,
            ];
        });
    }

    /**
     * @param array<string, mixed> $layout
     * @return array<string, mixed> the tool's mutation, with the changed layout under layout, or an error
     */
    private function applyOperation(array $layout, mixed $operation): array
    {
        if (!is_array($operation) || array_is_list($operation)) {
            return ['error' => 'Each operation must be an object with op.', 'code' => 'invalid_operation'];
        }

        $op = $operation['op'] ?? null;

        if (!is_string($op) || !isset(self::OPERATIONS[$op])) {
            return [
                'error' => 'op must be one of: ' . implode(', ', array_keys(self::OPERATIONS)) . '.',
                'code' => 'invalid_operation',
                'accepted_values' => array_keys(self::OPERATIONS),
            ];
        }

        unset($operation['op']);
        $class = self::OPERATIONS[$op];
        $tool = new $class();
        $rejection = ToolArgumentValidator::validate($tool->getName(), $this->operationSchema($tool), $operation);

        if ($rejection !== null) {
            $rejection['action_required'] = 'Nothing was applied. The target, if_match, dry_run and confirm_guarded_write belong to the batch, not to an operation.';

            return $rejection;
        }

        $result = $tool->applyToLayout($layout, $operation);

        if (!isset($result['error']) && !is_array($result['layout'] ?? null)) {
            return ['error' => "Internal error: {$op} did not return a layout.", 'code' => 'missing_mutation_layout'];
        }

        return $result;
    }

    /**
     * The tool's own schema without what the batch gives once for all operations.
     *
     * @return array<string, mixed>
     */
    private function operationSchema(AbstractTemplateElementWriteTool $tool): array
    {
        $schema = $tool->getInputSchema();
        $batchOnly = array_merge(array_keys($this->targetSelectorSchema()), self::BATCH_ONLY);

        if (is_array($schema['properties'] ?? null)) {
            $schema['properties'] = array_diff_key($schema['properties'], array_flip($batchOnly));
        }

        if (is_array($schema['required'] ?? null)) {
            $schema['required'] = array_values(array_diff($schema['required'], $batchOnly));
        }

        return $schema;
    }
}
