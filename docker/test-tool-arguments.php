<?php
/**
 * Contract tests for ToolArgumentValidator on both hosts.
 *
 * The rule under test: an argument a tool does not declare must stop the call,
 * not be dropped on the way to a success-looking response.
 *
 * Run from the repo root:
 *   php docker/test-tool-arguments.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Tool/ToolArgumentValidator.php';
require_once dirname(__DIR__) . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/ToolArgumentValidator.php';

$passed = 0;
$failed = 0;

function check(string $label, mixed $actual, mixed $expected): void
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

$moveSchema = [
    'type' => 'object',
    'required' => ['path', 'if_match'],
    'properties' => [
        'path' => ['type' => 'string'],
        'target_parent_path' => ['type' => 'string'],
        'before_path' => ['type' => 'string'],
        'after_path' => ['type' => 'string'],
        'if_match' => ['type' => 'string'],
        'position' => ['type' => 'string', 'enum' => ['append', 'prepend']],
        'dry_run' => ['type' => 'boolean'],
    ],
];

$bindingSchema = [
    'type' => 'object',
    'properties' => [
        'field_mappings' => [
            'type' => 'object',
            'additionalProperties' => [
                'type' => ['string', 'object'],
                'properties' => [
                    'name' => ['type' => 'string'],
                    'arguments' => ['type' => 'object'],
                    'directives' => ['type' => 'object'],
                    'filters' => ['type' => 'object'],
                ],
                'additionalProperties' => false,
            ],
        ],
        'query_arguments' => ['type' => 'object'],
    ],
];

// Both host copies must behave identically: a caller should not be able to tell
// WordPress from Joomla by how a bad argument is handled.
$validators = [
    'wp' => \Mirasai\WordPress\Tool\ToolArgumentValidator::class,
    'joomla' => \Mirasai\Library\Tool\ToolArgumentValidator::class,
];

foreach ($validators as $host => $validator) {
    // The case from the backlog: element-move answered `updated` for an
    // argument it never had, and moved nothing.
    $rejection = $validator::validate('template/element-move', $moveSchema, [
        'path' => 'root>section[3]',
        'target_parent_path' => 'root',
        'if_match' => 'abc',
        'target_index' => 1,
    ]);

    check("{$host}: unknown argument is rejected", $rejection['code'] ?? null, 'unknown_argument');
    check("{$host}: unknown argument is named", $rejection['issues'][0]['argument'] ?? null, 'target_index');
    check("{$host}: unknown argument suggests the real one", $rejection['issues'][0]['did_you_mean'] ?? null, 'target_parent_path');
    check("{$host}: rejection lists what the tool accepts", in_array('before_path', $rejection['accepted_arguments'] ?? [], true), true);

    check(
        "{$host}: a fully declared call passes",
        $validator::validate('template/element-move', $moveSchema, [
            'path' => 'root>section[3]',
            'before_path' => 'root>section[1]',
            'if_match' => 'abc',
            'dry_run' => true,
        ]),
        null
    );

    $badEnum = $validator::validate('template/element-move', $moveSchema, [
        'path' => 'root>section[3]',
        'if_match' => 'abc',
        'position' => 'middle',
    ]);

    check("{$host}: an out-of-enum value is rejected", $badEnum['code'] ?? null, 'invalid_argument_value');
    check("{$host}: the enum rejection names the argument", $badEnum['issues'][0]['argument'] ?? null, 'position');

    $wrongBoolean = $validator::validate('template/element-move', $moveSchema, [
        'path' => 'root>section[3]',
        'if_match' => 'abc',
        'dry_run' => 'false',
    ]);
    check("{$host}: a string is not accepted as a boolean", $wrongBoolean['issues'][0]['code'] ?? null, 'invalid_argument_type');

    // JSON Schema counts 12.0 as an integer; a client that serializes an id as
    // a float is not making the mistake this validator exists to catch.
    $integerSchema = [
        'type' => 'object',
        'properties' => ['post_id' => ['type' => 'integer']],
    ];
    check(
        "{$host}: an integral float is accepted as an integer",
        $validator::validate('demo/ids', $integerSchema, ['post_id' => 12.0]),
        null
    );
    $fractional = $validator::validate('demo/ids', $integerSchema, ['post_id' => 12.5]);
    check("{$host}: a fractional value is not an integer", $fractional['issues'][0]['code'] ?? null, 'invalid_argument_type');
    $stringId = $validator::validate('demo/ids', $integerSchema, ['post_id' => '12']);
    check("{$host}: a stringified id is still rejected", $stringId['issues'][0]['code'] ?? null, 'invalid_argument_type');

    $missingRequired = $validator::validate('template/element-move', $moveSchema, [
        'path' => 'root>section[3]',
    ]);
    check("{$host}: a missing required argument is rejected", $missingRequired['issues'][0]['code'] ?? null, 'missing_required_argument');
    check("{$host}: the missing argument is named", $missingRequired['issues'][0]['argument'] ?? null, 'if_match');
    // The envelope code is what a caller branches on, so it has to name the
    // most specific failure rather than fall back to a generic one.
    check("{$host}: the envelope code names the missing argument case", $missingRequired['code'] ?? null, 'missing_required_argument');
    check("{$host}: the envelope code names the type case", $wrongBoolean['code'] ?? null, 'invalid_argument_type');
    check("{$host}: the envelope code names the enum case", $badEnum['code'] ?? null, 'invalid_argument_value');

    // An unknown argument outranks everything else: it is the one the caller
    // most likely did not mean to send at all.
    $mixed = $validator::validate('template/element-move', $moveSchema, [
        'path' => 'root>section[3]',
        'position' => 'middle',
        'target_index' => 1,
    ]);
    check("{$host}: unknown outranks the other failures", $mixed['code'] ?? null, 'unknown_argument');

    // Nested maps: field_mappings values are mapping objects with a known shape.
    $badMapping = $validator::validate('template/element-source-set', $bindingSchema, [
        'field_mappings' => [
            'content' => ['name' => 'excerpt', 'formatters' => ['date' => 'd/m/Y']],
        ],
    ]);

    check("{$host}: unknown key inside a mapping object is rejected", $badMapping['code'] ?? null, 'unknown_argument');
    check(
        "{$host}: the nested rejection uses a qualified name",
        $badMapping['issues'][0]['argument'] ?? null,
        'field_mappings.content.formatters'
    );

    check(
        "{$host}: string shorthand mappings stay valid",
        $validator::validate('template/element-source-set', $bindingSchema, [
            'field_mappings' => ['content' => 'excerpt'],
        ]),
        null
    );

    check(
        "{$host}: filters is an accepted mapping key",
        $validator::validate('template/element-source-set', $bindingSchema, [
            'field_mappings' => ['date' => ['name' => 'date', 'filters' => ['date' => 'd/m/Y']]],
        ]),
        null
    );

    // Free-form objects must stay free-form: query_arguments declares no shape.
    check(
        "{$host}: an undeclared shape is not judged",
        $validator::validate('template/element-source-set', $bindingSchema, [
            'query_arguments' => ['iv_ambit_include_children' => 'include'],
        ]),
        null
    );

    // A tool that declares nothing cannot tell unknown from undocumented.
    check(
        "{$host}: a tool with no declared properties accepts anything",
        $validator::validate('system/diagnose', ['type' => 'object', 'properties' => []], ['anything' => 1]),
        null
    );

    check(
        "{$host}: additionalProperties true disables the unknown check",
        $validator::validate('demo/open', [
            'type' => 'object',
            'properties' => ['known' => ['type' => 'string']],
            'additionalProperties' => true,
        ], ['known' => 'a', 'extra' => 'b']),
        null
    );

    $arrayEnumSchema = [
        'type' => 'object',
        'properties' => [
            'modes' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'enum' => ['safe', 'strict']],
            ],
        ],
    ];
    $badArrayEnum = $validator::validate('demo/modes', $arrayEnumSchema, [
        'modes' => ['safe', 'surprise'],
    ]);
    check("{$host}: enum validation reaches scalar array items", $badArrayEnum['issues'][0]['code'] ?? null, 'invalid_argument_value');
    check("{$host}: array item rejection has an indexed path", $badArrayEnum['issues'][0]['argument'] ?? null, 'modes[1]');
}

if ($failed > 0) {
    echo "\n{$failed} tool argument test(s) failed.\n";
    exit(1);
}

echo "\nAll {$passed} tool argument tests passed.\n";
