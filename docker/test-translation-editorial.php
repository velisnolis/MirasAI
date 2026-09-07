<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/ContentLayoutProcessorInterface.php';
require_once $root . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/YooThemeLayoutProcessor.php';
require_once $root . '/packages/mirasai-wp/src/Tool/YoothemeLayoutProcessor.php';
$count = 0;
function check(bool $ok, string $label): void {
    global $count;
    if (!$ok) { throw new RuntimeException($label); }
    $count++;
}
foreach ([new Mirasai\Library\Tool\YooThemeLayoutProcessor(), new Mirasai\WordPress\Tool\YoothemeLayoutProcessor()] as $p) {
    $layout = ['type' => 'breadcrumbs', 'props' => [
        'title' => 'Títol dinàmic', 'image_alt' => 'A', 'link_aria_label' => 'Veure',
        'css' => '.el-title { color: red; }', 'custom_caption' => 'Un text editorial desconegut',
        'content' => '<p id="intro">Hola {{name}} <a href="/ca/?id=7#x">Món</a></p>',
    ], 'source' => ['query' => ['title' => 'Database query title'], 'props' => ['title' => [
        'name' => 'article.title', 'filters' => ['before' => 'Abans', 'after' => 'Després'],
    ]]]];
    $nodes = array_column($p->findTranslatableNodes($layout), null, 'replacement_key');
    check(isset($nodes['root.image_alt']), 'one-character editorial alt included');
    check(isset($nodes['root.link_aria_label']), 'short aria label included');
    check(!isset($nodes['root.css']), 'CSS excluded');
    check(!isset($nodes['root.title']), 'bound static prop excluded');
    check(!isset($nodes['root.source.query.title']), 'query parameters excluded');
    check(isset($nodes['root.source.props.title.filters.before']), 'filter prefix included');
    $warnings = $p->getTranslationCoverageWarnings($layout);
    check(in_array('unknown_text_field', array_column($warnings, 'code'), true), 'unknown field reported');
    check(in_array('implicit_home_text', array_column($warnings, 'code'), true), 'implicit breadcrumb label reported');
    $valid = ['root.content' => '<p id="intro">Hola {{name}} <a href="/ca/?id=7#x">Mundo</a></p>'];
    check($p->patchLayoutArray($layout, $valid)['props']['content'] === $valid['root.content'], 'editorial patch accepted');
    $rawBad = $layout; $rawBad['props']['css'] = 'body { color: blue; }';
    $refused = false;
    try { $p->validateTranslatedLayout($layout, $rawBad); } catch (InvalidArgumentException $e) { $refused = true; }
    check($refused, 'full-layout submission cannot bypass the editorial boundary');
    $disabled = ['type'=>'section','props'=>['status'=>'disabled'],'children'=>[['type'=>'switcher','props'=>['label'=>'A','meta'=>'Notícies']]]];
    $disabledNodes = $p->findTranslatableNodes($disabled);
    check(count($disabledNodes) === 2 && $disabledNodes[0]['disabled_by'] === 'root', 'disabled Switcher copy retained with context');
    foreach ([
        ['root.css' => 'body { color: blue; }'], ['root.custom_caption' => 'Traducció'],
        ['root.title' => 'Text'], ['root.missing' => 'Text'],
        ['root.source.props.title.name' => 'article.id'],
        ['root.content' => '<p id="intro">Hola {{name}} <a href="/es/">Mundo</a></p>'],
        ['root.content' => '<p id="intro">Hola <a href="/ca/?id=7#x">Mundo</a></p>'],
        ['root.content' => '<div id="intro">Hola {{name}} <a href="/ca/?id=7#x">Mundo</a></div>'],
    ] as $bad) {
        $refused = false;
        try { $p->patchLayoutArray($layout, $bad); } catch (InvalidArgumentException $e) { $refused = true; }
        check($refused, 'unsafe replacement refused: ' . array_key_first($bad));
    }
}
echo "All {$count} editorial translation assertions passed.\n";
