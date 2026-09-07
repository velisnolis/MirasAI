<?php
declare(strict_types=1);
$src = dirname(__DIR__) . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/';
foreach (['ToolInterface','AbstractTool','ContentLayoutProcessorInterface','YooThemeLayoutProcessor','YooThemeTranslationRenderer','ContentCheckLinksTool'] as $name) { require_once $src.$name.'.php'; }
$count = 0;
function assertLink(bool $ok, string $label): void { global $count; if (!$ok) { throw new RuntimeException($label); } $count++; }
class LinkFixture extends Mirasai\Library\Tool\ContentCheckLinksTool {
    public bool $hasMenu = true;
    public function __construct() {}
    public function id(string $url): ?int { return $this->articleIdFromLink($url); }
    public function rewrite(string $url): ?array { return $this->checkAndFixLink(7, 'es-ES', $url, true); }
    protected function linkMenuItem(int $id): ?array { return ['id'=>$id,'language'=>'ca-ES']; }
    protected function linkTargetArticle(int $id): ?array { return ['id'=>$id,'title'=>'Origen','language'=>'ca-ES']; }
    protected function findTranslation(int $id, string $lang, string $context = 'com_content.item'): ?int {
        return $context === 'com_content.item' ? 70 : ($this->hasMenu ? 90 : null);
    }
}
$t = new LinkFixture();
$url = 'index.php?option=com_content&view=article&id=7:origen&Itemid=9&print=1#equip';
assertLink($t->id($url) === 7, 'numeric article suffix parsed');
assertLink($t->rewrite($url)['new_link'] === 'index.php?option=com_content&view=article&id=70&Itemid=90&print=1#equip', 'only verified article/menu IDs change; query and fragment preserved');
$escaped = str_replace('&', '&amp;', $url);
assertLink($t->id($escaped) === 7, 'HTML entity query parsed');
assertLink($t->rewrite($escaped)['new_link'] === 'index.php?option=com_content&amp;view=article&amp;id=70&amp;Itemid=90&amp;print=1#equip', 'HTML escaping preserved');
foreach (['https://external.test/'.$url, '//external.test/'.$url, 'index.php?option=com_users&view=user&id=7', '/ca/equip#x', str_replace('#equip', '&id=8#equip', $url), 'index.php?option=com_content&view=article&i%64=7', 'index.php?option=com_content&view=article&id=7%3Aalias'] as $bad) {
    assertLink($t->id($bad) === null, 'unverified route refused: '.$bad);
}
$fragmentUrl = 'index.php?option=com_content&view=article&id=7#section&Itemid=999&id=55';
assertLink($t->rewrite($fragmentUrl)['new_link'] === 'index.php?option=com_content&view=article&id=70#section&Itemid=999&id=55', 'fragment IDs remain opaque');
$t->hasMenu = false;
assertLink($t->rewrite($url)['status'] === 'review', 'missing menu association blocks rewrite');
$t->hasMenu = true;
$layout = ['type'=>'panel','props'=>['link'=>$url,'content'=>'<a href="'.$escaped.'">Equip</a><div data-href="'.$escaped.'"></div><script>const s = `<a href="'.$escaped.'">`;</script>','css'=>'a[href="'.$escaped.'"] { color: red; }','image'=>'/ca/image.png'],
    'children'=>[['type'=>'panel','props'=>['link'=>'/ca/contacte#equip']]]];
$method = new ReflectionMethod($t, 'scanArticle');
$article = ['id'=>100,'title'=>'Destí','introtext'=>'old','fulltext_raw'=>'<!-- '.json_encode($layout).' -->'];
$r = $method->invoke($t, $article, 'es-ES', false);
assertLink($r['fixed_count'] === 0 && $r['outcome']['write_performed'] === false, 'report performs no writes and counts no fixes');
assertLink(count($r['issues']) === 3, 'navigation prop, href and unresolved SEF covered; CSS/image excluded');
assertLink($r['issues'][0]['status'] === 'fixable' && isset($r['issues'][0]['new_link']), 'preview exposes literal URL map');
assertLink($r['issues'][2]['status'] === 'review', 'SEF unresolved explicitly');
$r = $method->invoke($t, $article, 'es-ES', true, 'stale');
assertLink($r['outcome']['error'] === 'stale_etag', 'stale link preview refused');
$preview = $method->invoke($t, $article, 'es-ES', false);
$r = $method->invoke($t, $article, 'es-ES', true, $preview['etag']);
assertLink($r['outcome']['error'] === 'fallback_unverified' && $r['fixed_count'] === 0, 'missing runtime renderer cannot write stale fallback');
echo "All {$count} translation link assertions passed.\n";
