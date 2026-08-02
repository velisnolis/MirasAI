<?php
/**
 * Contract tests for YoothemePropsValidator on both hosts.
 *
 * A select prop set to a value the element does not offer used to be written
 * without a word, and only surfaced later as a red border in the Builder. The
 * field metadata below is the real `section` definition read from a live site
 * on 1 August 2026, so the accepted values here are the ones YOOtheme ships.
 *
 * Run from the repo root:
 *   php docker/test-yootheme-props.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Tool/YoothemePropsValidator.php';
require_once dirname(__DIR__) . '/packages/mirasai-joomla/packages/plg_mirasai_yootheme/src/Tool/YoothemePropsValidator.php';

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

$sectionFields = [
    'style' => [
        'type' => 'select',
        'options' => ['None' => '', 'Default' => 'default', 'Muted' => 'muted', 'Primary' => 'primary', 'Secondary' => 'secondary'],
    ],
    'padding_bottom' => [
        'type' => 'select',
        'options' => ['X-Small' => 'xsmall', 'Small' => 'small', 'Default' => '', 'Large' => 'large', 'X-Large' => 'xlarge', 'None' => 'none'],
    ],
    'vertical_align' => [
        'type' => 'select',
        'options' => ['Top' => '', 'Middle' => 'middle', 'Bottom' => 'bottom'],
    ],
    'title' => ['type' => 'text'],
    'preserve_color' => ['type' => 'checkbox'],
    // Options the loader cannot enumerate must be left alone rather than guessed at.
    'dynamic_choice' => ['type' => 'select', 'options' => 'YOOtheme\\Some\\Service'],
    'grouped_choice' => ['type' => 'select', 'options' => ['Group' => ['A' => 'a']]],
];

$validators = [
    'wp' => \Mirasai\WordPress\Tool\YoothemePropsValidator::class,
    'joomla' => \Mirasai\Plugin\Mirasai\Yootheme\Tool\YoothemePropsValidator::class,
];

foreach ($validators as $host => $validator) {
    // The case from the backlog: padding_bottom has no "medium".
    $rejection = $validator::validate('section', $sectionFields, ['padding_bottom' => 'medium']);

    check("{$host}: an unoffered select value is rejected", $rejection['code'] ?? null, 'invalid_prop_value');
    check("{$host}: the rejection names the prop", $rejection['issues'][0]['prop'] ?? null, 'padding_bottom');
    check(
        "{$host}: the rejection lists what the element offers",
        $rejection['issues'][0]['accepted_values'] ?? null,
        ['xsmall', 'small', '', 'large', 'xlarge', 'none']
    );

    check(
        "{$host}: an offered value passes",
        $validator::validate('section', $sectionFields, ['padding_bottom' => 'large']),
        null
    );

    // "" is a real choice on several YOOtheme selects, not an absence.
    check(
        "{$host}: the empty string is a value like any other",
        $validator::validate('section', $sectionFields, ['padding_bottom' => '', 'vertical_align' => '']),
        null
    );

    check(
        "{$host}: several bad values are all reported",
        count($validator::validate('section', $sectionFields, [
            'style' => 'fancy',
            'padding_bottom' => 'medium',
        ])['issues'] ?? []),
        2
    );

    // Everything the validator is not sure about must pass through untouched.
    check(
        "{$host}: free-text props are not judged",
        $validator::validate('section', $sectionFields, ['title' => 'anything at all']),
        null
    );
    check(
        "{$host}: props the element does not declare are not judged",
        $validator::validate('section', $sectionFields, ['fs_custom_thing' => 'whatever']),
        null
    );
    check(
        "{$host}: unenumerable options are not judged",
        $validator::validate('section', $sectionFields, ['dynamic_choice' => 'whatever']),
        null
    );
    check(
        "{$host}: grouped options are not judged",
        $validator::validate('section', $sectionFields, ['grouped_choice' => 'a']),
        null
    );
    // Dynamic source bindings arrive as objects where a string usually goes.
    check(
        "{$host}: a bound value is not judged",
        $validator::validate('section', $sectionFields, [
            'padding_bottom' => ['name' => 'spacing', 'filters' => []],
        ]),
        null
    );
    check(
        "{$host}: an element with no fields is not judged",
        $validator::validate('fs_grid', [], ['anything' => 'goes']),
        null
    );
}

if ($failed > 0) {
    echo "\n{$failed} prop validation test(s) failed.\n";
    exit(1);
}

echo "\nAll {$passed} prop validation tests passed.\n";
