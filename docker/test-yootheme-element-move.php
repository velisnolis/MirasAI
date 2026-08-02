<?php
/**
 * Tests for positional element moves on both host navigators.
 *
 * append/prepend alone cannot express "put this between those two", which is
 * what composing a real page needs. These tests cover the placement itself and
 * the index bookkeeping: the element is removed before it is reinserted, so
 * every sibling after it shifts, and so can the reference path.
 *
 * Run from the repo root:
 *   php docker/test-yootheme-element-move.php
 */

declare(strict_types=1);

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $text): string
    {
        return strip_tags($text);
    }
}

require_once dirname(__DIR__) . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/YooThemeElementNavigator.php';
require_once dirname(__DIR__) . '/packages/mirasai-wp/src/Tool/YoothemeElementNavigator.php';

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

/**
 * @return array<string, mixed>
 */
function layoutWithSections(int $count): array
{
    $children = [];

    for ($index = 0; $index < $count; $index++) {
        $children[] = [
            'type' => 'section',
            'props' => ['title' => 'Section ' . $index],
            'children' => [],
        ];
    }

    return ['type' => 'layout', 'children' => $children];
}

/**
 * @param array<string, mixed> $layout
 * @return list<string>
 */
function sectionTitles(array $layout): array
{
    return array_map(
        static fn (array $child): string => (string) ($child['props']['title'] ?? ''),
        $layout['children']
    );
}

$navigators = [
    'joomla' => new \Mirasai\Library\Tool\YooThemeElementNavigator(),
    'wp' => new \Mirasai\WordPress\Tool\YoothemeElementNavigator(),
];

foreach ($navigators as $host => $navigator) {
    // The blocked case from the backlog: a separator stranded at the end of the
    // page that had to be dragged into place by hand.
    $result = $navigator->moveElementBeside(layoutWithSections(5), 'root>section[4]', 'root>section[2]', 'before');

    check("{$host}: before places the element ahead of its reference", sectionTitles($result['layout']), [
        'Section 0', 'Section 1', 'Section 4', 'Section 2', 'Section 3',
    ]);
    check("{$host}: before reports the new path", $result['new_path'], 'root>section[2]');
    check("{$host}: before reports where it came from", $result['old_path'], 'root>section[4]');

    $result = $navigator->moveElementBeside(layoutWithSections(5), 'root>section[4]', 'root>section[0]', 'after');

    check("{$host}: after places the element behind its reference", sectionTitles($result['layout']), [
        'Section 0', 'Section 4', 'Section 1', 'Section 2', 'Section 3',
    ]);
    check("{$host}: after reports the new path", $result['new_path'], 'root>section[1]');

    // Moving forwards: removing section[0] first shifts the reference down one,
    // so a naive implementation lands one slot too far.
    $result = $navigator->moveElementBeside(layoutWithSections(5), 'root>section[0]', 'root>section[3]', 'after');

    check("{$host}: a forward move accounts for its own removal", sectionTitles($result['layout']), [
        'Section 1', 'Section 2', 'Section 3', 'Section 0', 'Section 4',
    ]);
    check("{$host}: the forward move reports the settled path", $result['new_path'], 'root>section[3]');

    $result = $navigator->moveElementBeside(layoutWithSections(3), 'root>section[2]', 'root>section[0]', 'before');

    check("{$host}: before the first sibling lands at index 0", $result['new_path'], 'root>section[0]');
    check("{$host}: before the first sibling keeps the rest in order", sectionTitles($result['layout']), [
        'Section 2', 'Section 0', 'Section 1',
    ]);

    // The reference lives under a later sibling, so the reference's own parent
    // path shifts when the moved element is removed.
    $nested = [
        'type' => 'layout',
        'children' => [
            ['type' => 'section', 'props' => ['title' => 'Moving'], 'children' => []],
            ['type' => 'section', 'props' => ['title' => 'Host'], 'children' => [
                ['type' => 'row', 'props' => ['title' => 'Row A'], 'children' => []],
                ['type' => 'row', 'props' => ['title' => 'Row B'], 'children' => []],
            ]],
        ],
    ];

    $result = $navigator->moveElementBeside($nested, 'root>section[0]', 'root>section[1]>row[1]', 'before');

    // The reference parent was root>section[1] before the move and root>section[0]
    // after it. The last segment carries the moved element's own type, not the
    // reference's, so a section moved among rows reads as section[1].
    check("{$host}: a reference under a later sibling still resolves", $result['new_path'] ?? ($result['code'] ?? null), 'root>section[0]>section[1]');
    check(
        "{$host}: the moved element lands between the reference's siblings",
        array_map(
            static fn (array $child): string => (string) ($child['props']['title'] ?? ''),
            $result['layout']['children'][0]['children']
        ),
        ['Row A', 'Moving', 'Row B']
    );
    check("{$host}: the derived parent is reported", $result['reference_parent_path'], 'root>section[0]');

    // Refusals.
    check(
        "{$host}: an element cannot be placed relative to itself",
        $navigator->moveElementBeside(layoutWithSections(3), 'root>section[1]', 'root>section[1]', 'after')['code'] ?? null,
        'invalid_reference_path'
    );
    check(
        "{$host}: an element cannot be placed next to its own descendant",
        $navigator->moveElementBeside($nested, 'root>section[1]', 'root>section[1]>row[0]', 'after')['code'] ?? null,
        'invalid_reference_path'
    );
    check(
        "{$host}: root cannot be moved",
        $navigator->moveElementBeside(layoutWithSections(3), 'root', 'root>section[1]', 'after')['code'] ?? null,
        'invalid_path'
    );
    check(
        "{$host}: root is not a valid reference",
        $navigator->moveElementBeside(layoutWithSections(3), 'root>section[1]', 'root', 'after')['code'] ?? null,
        'invalid_reference_path'
    );
    check(
        "{$host}: a missing reference is reported as such",
        $navigator->moveElementBeside(layoutWithSections(3), 'root>section[1]', 'root>section[9]', 'after')['code'] ?? null,
        'reference_not_found'
    );
    check(
        "{$host}: a missing element is reported as such",
        $navigator->moveElementBeside(layoutWithSections(3), 'root>section[9]', 'root>section[1]', 'after')['code'] ?? null,
        'element_not_found'
    );

    // Adding and cloning must be able to land in the middle too. Composing a
    // page with add-then-move leaves a window where the layout is wrong, and
    // that window is where the 1 August incident happened: an index computed
    // against an assumed position deleted the wrong section.
    $result = $navigator->addElementBeside(
        layoutWithSections(4),
        'root>section[2]',
        ['type' => 'section', 'props' => ['title' => 'Inserted']],
        'before'
    );
    check("{$host}: addElementBeside inserts before its reference", sectionTitles($result['layout']), [
        'Section 0', 'Section 1', 'Inserted', 'Section 2', 'Section 3',
    ]);
    check("{$host}: addElementBeside reports the settled path", $result['metadata']['path'], 'root>section[2]');
    check("{$host}: addElementBeside reports the derived parent", $result['reference_parent_path'], 'root');

    $result = $navigator->addElementBeside(
        layoutWithSections(4),
        'root>section[3]',
        ['type' => 'section', 'props' => ['title' => 'Inserted']],
        'after'
    );
    check("{$host}: addElementBeside after the last sibling appends", sectionTitles($result['layout']), [
        'Section 0', 'Section 1', 'Section 2', 'Section 3', 'Inserted',
    ]);

    // The copy must land where asked, not next to its source.
    $result = $navigator->cloneElementBeside(layoutWithSections(4), 'root>section[0]', 'root>section[2]', 'after');
    check("{$host}: cloneElementBeside places the copy at the reference", sectionTitles($result['layout']), [
        'Section 0', 'Section 1', 'Section 2', 'Section 0', 'Section 3',
    ]);
    check("{$host}: cloneElementBeside keeps the source", $result['source_path'], 'root>section[0]');
    check("{$host}: cloneElementBeside reports where the copy landed", $result['new_path'], 'root>section[3]');

    // A clone placed before its own source pushes the source along; the
    // reported paths have to reflect the layout that actually exists now.
    $result = $navigator->cloneElementBeside(layoutWithSections(3), 'root>section[2]', 'root>section[0]', 'before');
    check("{$host}: a clone inserted ahead of its source", sectionTitles($result['layout']), [
        'Section 2', 'Section 0', 'Section 1', 'Section 2',
    ]);
    check("{$host}: the copy path is the inserted one", $result['new_path'], 'root>section[0]');

    check(
        "{$host}: addElementBeside refuses root as a reference",
        $navigator->addElementBeside(layoutWithSections(3), 'root', ['type' => 'section'], 'after')['code'] ?? null,
        'invalid_reference_path'
    );
    check(
        "{$host}: addElementBeside refuses a missing reference",
        $navigator->addElementBeside(layoutWithSections(3), 'root>section[9]', ['type' => 'section'], 'after')['code'] ?? null,
        'reference_not_found'
    );
    check(
        "{$host}: addElementBeside refuses an element without a type",
        $navigator->addElementBeside(layoutWithSections(3), 'root>section[1]', ['props' => []], 'after')['code'] ?? null,
        'invalid_element'
    );
    check(
        "{$host}: cloneElementBeside refuses a missing source",
        $navigator->cloneElementBeside(layoutWithSections(3), 'root>section[9]', 'root>section[1]', 'after')['code'] ?? null,
        'element_not_found'
    );

    // append/prepend must keep behaving exactly as before.
    $appended = $navigator->moveElement(layoutWithSections(3), 'root>section[0]', 'root', 'append');
    check("{$host}: append still appends", sectionTitles($appended['layout']), ['Section 1', 'Section 2', 'Section 0']);

    $prepended = $navigator->moveElement(layoutWithSections(3), 'root>section[2]', 'root', 'prepend');
    check("{$host}: prepend still prepends", sectionTitles($prepended['layout']), ['Section 2', 'Section 0', 'Section 1']);
}

if ($failed > 0) {
    echo "\n{$failed} element move test(s) failed.\n";
    exit(1);
}

echo "\nAll {$passed} element move tests passed.\n";
