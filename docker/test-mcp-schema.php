<?php
/**
 * Test: MCP tool schema serialization.
 *
 * Run from the repo root:
 *   php docker/test-mcp-schema.php
 */

declare(strict_types=1);

$libSrc = dirname(__DIR__) . '/packages/mirasai-joomla/packages/lib_mirasai/src';
$yoothemeToolSrc = dirname(__DIR__) . '/packages/mirasai-joomla/packages/plg_mirasai_yootheme/src/Tool';

require_once $libSrc . '/Tool/ToolInterface.php';
require_once $libSrc . '/Tool/AbstractTool.php';
require_once $libSrc . '/Tool/YooThemeHelper.php';
require_once $libSrc . '/Tool/YooThemeElementNavigator.php';
require_once $yoothemeToolSrc . '/AbstractTemplateElementWriteTool.php';
require_once $yoothemeToolSrc . '/TemplateElementAddTool.php';
require_once $yoothemeToolSrc . '/TemplateElementUpdatePropsTool.php';
require_once $yoothemeToolSrc . '/TemplateElementMoveTool.php';
require_once $yoothemeToolSrc . '/TemplateElementCloneTool.php';
require_once $yoothemeToolSrc . '/TemplateElementDeleteTool.php';
require_once $yoothemeToolSrc . '/TemplateElementReplaceChildrenTool.php';
require_once $yoothemeToolSrc . '/TemplateElementBatchTool.php';
require_once $yoothemeToolSrc . '/TemplateElementSourceSupportTrait.php';
require_once $yoothemeToolSrc . '/TemplateElementSourceSetTool.php';
require_once $yoothemeToolSrc . '/TemplateReadTool.php';
require_once $yoothemeToolSrc . '/TemplateElementListTool.php';

use Mirasai\Library\Tool\AbstractTool;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\AbstractTemplateElementWriteTool;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateElementAddTool;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateElementBatchTool;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateElementCloneTool;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateElementDeleteTool;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateElementListTool;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateElementMoveTool;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateElementReplaceChildrenTool;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateElementSourceSetTool;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateElementUpdatePropsTool;
use Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateReadTool;

$passed = 0;
$failed = 0;

function expect(string $label, mixed $actual, mixed $expected): void
{
    global $passed, $failed;

    if ($actual === $expected) {
        echo "[PASS] {$label}\n";
        $passed++;

        return;
    }

    echo "[FAIL] {$label}\n";
    echo "       Expected: " . var_export($expected, true) . "\n";
    echo "       Actual:   " . var_export($actual, true) . "\n";
    $failed++;
}

/**
 * @param class-string $class
 * @return array<string, mixed>
 */
function schemaForToolWithoutConstructor(string $class): array
{
    $tool = (new ReflectionClass($class))->newInstanceWithoutConstructor();

    return $tool->getInputSchema();
}

final class EmptyPropertiesTool extends AbstractTool
{
    public function __construct() {}
    public function getName(): string { return 'test/empty-properties'; }
    public function getDescription(): string { return 'test'; }
    public function getInputSchema(): array { return ['type' => 'object', 'properties' => []]; }
    public function handle(array $arguments): array { return []; }
}

final class MissingPropertiesTool extends AbstractTool
{
    public function __construct() {}
    public function getName(): string { return 'test/missing-properties'; }
    public function getDescription(): string { return 'test'; }
    public function getInputSchema(): array { return ['type' => 'object']; }
    public function handle(array $arguments): array { return []; }
}

final class GuardedWriteTool extends AbstractTool
{
    public function __construct() {}
    public function getName(): string { return 'test/guarded-write'; }
    public function getDescription(): string { return 'test'; }
    public function getInputSchema(): array { return ['type' => 'object', 'properties' => []]; }
    public function getPermissions(): array { return ['risk_level' => self::RISK_GUARDED_WRITE, 'idempotent' => false]; }
    public function handle(array $arguments): array { return []; }
}

final class DangerousExecTool extends AbstractTool
{
    public function __construct() {}
    public function getName(): string { return 'test/dangerous-exec'; }
    public function getDescription(): string { return 'test'; }
    public function getInputSchema(): array { return ['type' => 'object', 'properties' => []]; }
    public function getPermissions(): array { return ['risk_level' => self::RISK_DANGEROUS_EXEC, 'idempotent' => false]; }
    public function handle(array $arguments): array { return []; }
}

echo "\n=== MCP schema normalization ===\n";

$emptyJson = json_encode((new EmptyPropertiesTool())->toMcpTool(), JSON_THROW_ON_ERROR);
$emptyDecoded = json_decode($emptyJson, true, flags: JSON_THROW_ON_ERROR);
expect('empty properties serializes as JSON object', $emptyDecoded['inputSchema']['properties'], []);
expect('empty properties raw JSON contains object', str_contains($emptyJson, '"properties":{}'), true);

$missingJson = json_encode((new MissingPropertiesTool())->toMcpTool(), JSON_THROW_ON_ERROR);
$missingDecoded = json_decode($missingJson, true, flags: JSON_THROW_ON_ERROR);
expect('missing properties is added as JSON object', $missingDecoded['inputSchema']['properties'], []);
expect('missing properties raw JSON contains object', str_contains($missingJson, '"properties":{}'), true);

$guardedDecoded = (new GuardedWriteTool())->toMcpTool();
expect('guarded write exposes risk level', $guardedDecoded['metadata']['risk_level'] ?? null, AbstractTool::RISK_GUARDED_WRITE);
expect('guarded write exposes workflow hint', $guardedDecoded['metadata']['workflow_hint'] ?? null, 'dry_run_confirm_if_match');
expect('guarded write omits legacy destructive hint', $guardedDecoded['metadata']['destructive'] ?? null, null);
expect('guarded write does not require elevation', $guardedDecoded['metadata']['requires_elevation'] ?? null, null);
expect('guarded write exposes annotations readOnlyHint', $guardedDecoded['annotations']['readOnlyHint'] ?? null, false);
expect('guarded write exposes annotations destructiveHint', $guardedDecoded['annotations']['destructiveHint'] ?? null, true);
expect('guarded write exposes annotations idempotentHint', $guardedDecoded['annotations']['idempotentHint'] ?? null, false);
expect('guarded write exposes annotations openWorldHint', $guardedDecoded['annotations']['openWorldHint'] ?? null, true);

$dangerousDecoded = (new DangerousExecTool())->toMcpTool();
expect('dangerous exec exposes risk level', $dangerousDecoded['metadata']['risk_level'] ?? null, AbstractTool::RISK_DANGEROUS_EXEC);
expect('dangerous exec exposes workflow hint', $dangerousDecoded['metadata']['workflow_hint'] ?? null, 'elevation_required');
expect('dangerous exec requires elevation', $dangerousDecoded['metadata']['requires_elevation'] ?? null, true);
expect('dangerous exec exposes annotations destructiveHint', $dangerousDecoded['annotations']['destructiveHint'] ?? null, true);

$readOnlyDecoded = (new EmptyPropertiesTool())->toMcpTool();
expect('read tool exposes annotations readOnlyHint', $readOnlyDecoded['annotations']['readOnlyHint'] ?? null, true);
expect('read tool exposes annotations destructiveHint', $readOnlyDecoded['annotations']['destructiveHint'] ?? null, false);
expect('read tool exposes annotations idempotentHint', $readOnlyDecoded['annotations']['idempotentHint'] ?? null, true);

echo "\n=== Joomla YOOtheme element write schemas ===\n";

$joomlaElementWriteSchemas = [
    // parent_path stopped being required when before_path/after_path became
    // able to determine the parent on their own.
    'element-add' => [TemplateElementAddTool::class, ['if_match', 'element']],
    'element-update-props' => [TemplateElementUpdatePropsTool::class, ['path', 'if_match', 'props']],
    'element-move' => [TemplateElementMoveTool::class, ['path', 'if_match']],
    'element-clone' => [TemplateElementCloneTool::class, ['path', 'if_match']],
    'element-delete' => [TemplateElementDeleteTool::class, ['path', 'if_match']],
    'element-replace-children' => [TemplateElementReplaceChildrenTool::class, ['if_match', 'children']],
    'element-batch' => [TemplateElementBatchTool::class, ['if_match', 'operations']],
    'element-source-set' => [TemplateElementSourceSetTool::class, ['if_match']],
];

foreach ($joomlaElementWriteSchemas as $name => [$class, $required]) {
    $schema = schemaForToolWithoutConstructor($class);
    $properties = $schema['properties'] ?? [];

    expect("joomla {$name} schema exposes key selector", array_key_exists('key', $properties), true);
    expect("joomla {$name} schema exposes article_id selector", array_key_exists('article_id', $properties), true);
    expect("joomla {$name} schema exposes module_id selector", array_key_exists('module_id', $properties), true);
    expect("joomla {$name} schema does not require key", in_array('key', $schema['required'] ?? [], true), false);
    expect("joomla {$name} schema required fields", $schema['required'] ?? [], $required);
}

// Every tool that puts an element somewhere has to offer the same placement
// vocabulary. When only element-move had it, callers reached for after_path on
// element-add, got a rejection, and fell back to appending plus a guessed index.
$placementSchemas = [
    'element-move' => [TemplateElementMoveTool::class, ['target_parent_path', 'before_path', 'after_path']],
    'element-add' => [TemplateElementAddTool::class, ['parent_path', 'before_path', 'after_path']],
    'element-clone' => [TemplateElementCloneTool::class, ['before_path', 'after_path']],
];

foreach ($placementSchemas as $name => [$class, $placements]) {
    $properties = schemaForToolWithoutConstructor($class)['properties'] ?? [];

    foreach ($placements as $placement) {
        expect(
            "joomla {$name} schema exposes {$placement} placement",
            array_key_exists($placement, $properties),
            true,
        );
    }
}

expect(
    'joomla element-update-props uses shared write target resolver',
    is_subclass_of(TemplateElementUpdatePropsTool::class, AbstractTemplateElementWriteTool::class),
    true,
);

foreach ([TemplateReadTool::class, TemplateElementListTool::class] as $class) {
    $short = (new ReflectionClass($class))->getShortName();
    $mode = schemaForToolWithoutConstructor($class)['properties']['mode'] ?? [];

    expect("joomla {$short} schema exposes mode", array_key_exists('enum', $mode), true);
    expect("joomla {$short} mode enum", $mode['enum'] ?? [], ['full', 'outline', 'bindings_only']);
}

$sourceSetProperties = schemaForToolWithoutConstructor(TemplateElementSourceSetTool::class)['properties'] ?? [];

foreach (['leaves', 'rebind_disabled'] as $batchProperty) {
    expect("joomla element-source-set schema exposes {$batchProperty}", array_key_exists($batchProperty, $sourceSetProperties), true);
}

require_once $libSrc . '/Tool/ContentTranslateTool.php';
require_once $libSrc . '/Tool/ContentTranslateBatchTool.php';
$translate = schemaForToolWithoutConstructor(\Mirasai\Library\Tool\ContentTranslateTool::class);
foreach (['dry_run', 'if_match', 'confirm_guarded_write', 'target_id', 'state', 'create_menu', 'menu_id', 'source_menu_id'] as $field) {
    expect("joomla translate exposes {$field}", isset($translate['properties'][$field]), true);
}
expect('joomla translate defaults to preview', $translate['properties']['dry_run']['default'], true);
$batch = schemaForToolWithoutConstructor(\Mirasai\Library\Tool\ContentTranslateBatchTool::class);
expect('joomla batch carries per-item CAS', isset($batch['properties']['articles']['items']['properties']['if_match']), true);
expect('joomla batch refuses site-wide fix_links', $batch['properties']['fix_links']['enum'], [false]);

require_once $libSrc . '/Tool/ContentCheckLinksTool.php';
$links = schemaForToolWithoutConstructor(\Mirasai\Library\Tool\ContentCheckLinksTool::class);
expect('joomla links defaults to preview', $links['properties']['dry_run']['default'], true);
expect('joomla links exposes scoped CAS', isset($links['properties']['if_match']), true);

// content/update and template/layout-init (TODO-016): the Joomla copies take only what the decision allows.
require_once $libSrc . '/Tool/ContentReadTool.php';
require_once $libSrc . '/Tool/ContentUpdateTool.php';
require_once $libSrc . '/Tool/ToolArgumentValidator.php';
require_once $yoothemeToolSrc . '/TemplateLayoutInitTool.php';
$update = \Mirasai\Library\Tool\ContentUpdateTool::class;
$updateSchema = schemaForToolWithoutConstructor($update);
expect('joomla content/update takes the state and no other field', array_keys($updateSchema['properties']), ['id', 'state', 'if_match', 'dry_run', 'confirm_guarded_write']);
expect('joomla content/update requires id, state and if_match', $updateSchema['required'], ['id', 'state', 'if_match']);
expect('joomla content/update is a guarded write', (new \ReflectionClass($update))->newInstanceWithoutConstructor()->getPermissions()['risk_level'], 'guarded_write');
foreach ([-2, 2, 3, '1', null, true] as $state) {
    $label = var_export($state, true);
    expect("joomla content/update schema refuses state {$label}", \Mirasai\Library\Tool\ToolArgumentValidator::validate('content/update', $updateSchema, ['id' => 5, 'state' => $state, 'if_match' => 'e']) !== null, true);
    expect("joomla content/update refuses state {$label} on a direct call", $update::requestedState(['id' => 5, 'state' => $state, 'if_match' => 'e'])['code'] ?? null, 'invalid_state');
}
foreach (['title', 'alias', 'catid', 'introtext', 'fulltext', 'featured'] as $field) {
    expect("joomla content/update schema refuses {$field}", \Mirasai\Library\Tool\ToolArgumentValidator::validate('content/update', $updateSchema, ['id' => 5, 'state' => 1, 'if_match' => 'e', $field => 'x'])['code'] ?? null, 'unknown_argument');
    expect("joomla content/update refuses {$field} on a direct call", $update::requestedState(['id' => 5, 'state' => 1, $field => 'x'])['code'] ?? null, 'unsupported_field');
}
expect('joomla content/update accepts 0 and 1', [$update::requestedState(['state' => 0]), $update::requestedState(['state' => 1.0])], [0, 1]);
$now = '2026-11-14 08:00:00';
expect('joomla content/update flags an unpublish', array_column($update::warnings(['state' => 1], 0, $now), 'code'), ['no_longer_public']);
expect('joomla content/update flags a start date in the future', array_column($update::warnings(['state' => 0, 'publish_up' => '2027-01-01 00:00:00'], 1, $now), 'code'), ['outside_publish_window']);
expect('joomla content/update flags a finish date in the past', array_column($update::warnings(['state' => 0, 'publish_down' => '2026-01-01 00:00:00'], 1, $now), 'code'), ['outside_publish_window']);
expect('joomla content/update takes null and zero dates as no window', $update::warnings(['state' => 0, 'publish_up' => '2026-01-01 00:00:00', 'publish_down' => null], 1, $now) + $update::warnings(['state' => 0, 'publish_down' => '0000-00-00 00:00:00'], 1, $now), []);

$read = \Mirasai\Library\Tool\ContentReadTool::class;
$article = ['id' => 5, 'title' => 'T', 'alias' => 't', 'language' => '*', 'state' => 0, 'catid' => 2, 'introtext' => '', 'fulltext' => '', 'modified' => $now, 'checked_out' => 0];
expect('joomla content etag ignores check-out', $read::contentEtag($article), $read::contentEtag(['checked_out' => 7] + $article));
foreach (['title' => 'U', 'alias' => 'u', 'state' => 1, 'catid' => 3, 'introtext' => 'x', 'fulltext' => 'x', 'modified' => '2026-11-14 08:00:01'] as $field => $value) {
    expect("joomla content etag moves with {$field}", $read::contentEtag([$field => $value] + $article) !== $read::contentEtag($article), true);
}

$init = \Mirasai\Plugin\Mirasai\Yootheme\Tool\TemplateLayoutInitTool::class;
$initSchema = schemaForToolWithoutConstructor($init);
expect('joomla template/layout-init takes no content', array_keys($initSchema['properties']), ['article_id', 'if_match', 'dry_run', 'confirm_guarded_write']);
expect('joomla template/layout-init requires article_id and if_match', $initSchema['required'], ['article_id', 'if_match']);
expect('joomla template/layout-init is a guarded write', (new \ReflectionClass($init))->newInstanceWithoutConstructor()->getPermissions()['risk_level'], 'guarded_write');
expect('joomla template/layout-init writes the empty layout with the YOOtheme version', $init::emptyLayout('5.0.53'), ['type' => 'layout', 'children' => [], 'version' => '5.0.53']);
expect('joomla template/layout-init takes a blank article', $init::notBlankReason($article), null);
expect('joomla template/layout-init takes whitespace as blank', $init::notBlankReason(['introtext' => " \n", 'fulltext' => ''] + $article), null);
expect('joomla template/layout-init refuses an article with introtext', $init::notBlankReason(['introtext' => '<p>x</p>'] + $article), 'introtext is not empty.');
expect('joomla template/layout-init refuses an article with a layout', $init::notBlankReason(['fulltext' => '<!-- {"type":"layout"} -->'] + $article), 'fulltext is not empty.');

if ($failed > 0) {
    echo "\n{$failed} schema test(s) failed.\n";
    exit(1);
}

echo "\nAll {$passed} schema tests passed.\n";
