<?php
/** In-memory workflow tests; real Joomla/MySQL integration is a separate lab check. */
declare(strict_types=1);
$src = dirname(__DIR__) . '/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/';
foreach (['ToolInterface', 'AbstractTool', 'ContentLayoutProcessorInterface', 'YooThemeLayoutProcessor', 'ContentTranslateTool'] as $name) { require_once $src . $name . '.php'; }
$count = 0;
function verify(bool $ok, string $label): void { global $count; if (!$ok) { throw new RuntimeException($label); } $count++; }
class TranslationFixture extends Mirasai\Library\Tool\ContentTranslateTool {
    public array $articles = [];
    public array $groups = [];
    public array $custom = [];
    public array $menus = [];
    public array $writes = [];
    public string $failAt = '';
    public bool $renderWorks = false;
    private array $backup = [];
    public bool $implicitCommit = false;
    public bool $lockHeld = false;
    public function __construct() {
        $this->articles[1] = ['id'=>1,'asset_id'=>10,'title'=>'Origen','alias'=>'origen','language'=>'ca-ES','catid'=>7,
            'state'=>1,'introtext'=>'<p>Hola</p>','fulltext'=>'','created'=>'2001-01-02 03:04:05','created_by'=>42,
            'access'=>3,'publish_up'=>'2001-02-03 00:00:00','publish_down'=>null,'metadesc'=>'','metakey'=>''];
    }
    protected function languageExists(string $lang): bool { return $lang === 'es-ES'; }
    protected function generateAlias(string $title): string { return strtolower(str_replace(' ', '-', $title)); }
    protected function translationRow(string $table, int $id, bool $lock = false): ?array {
        if ($table === '#__content') { return $this->articles[$id] ?? null; }
        if ($table === '#__categories') { return ['id'=>$id,'extension'=>'com_content','language'=>'es-ES']; }
        if ($table === '#__menu') { return $this->menus[$id] ?? null; }
        return null;
    }
    protected function translationAssociationGroup(int $id, string $context, bool $lock = false): array { return $this->groups[$context][$id] ?? []; }
    protected function translationCustomFields(int $id, bool $lock): array { return $this->custom[$id] ?? []; }
    protected function translationSourceMenus(int $id, bool $lock): array { return array_values(array_filter($this->menus, fn($m) => $this->translationArticleIdFromLink($m['link']) === $id && $m['language'] === 'ca-ES')); }
    protected function acquireTranslationLock(int $sourceId): string { $this->lockHeld = true; return 'fixture'; }
    protected function releaseTranslationLock(string $name): void { $this->lockHeld = false; }
    protected function beginTranslation(): void { $this->backup = [$this->articles, $this->groups]; }
    protected function commitTranslation(): void { if ($this->failAt === 'commit') { throw new RuntimeException('injected commit failure'); } }
    protected function rollbackTranslation(): void { [$this->articles, $this->groups] = $this->backup; }
    protected function duplicateArticle(array $source, array $fields): int {
        $id = max(array_keys($this->articles)) + 1;
        $this->articles[$id] = array_merge($source, $fields, ['id'=>$id,'asset_id'=>0]);
        $this->writes[] = 'insert'; return $id;
    }
    protected function updateArticle(int $id, array $fields): void { $this->articles[$id] = array_merge($this->articles[$id], $fields); $this->writes[] = 'update'; }
    protected function createAssetForContent(int $id, string $title): void {
        if ($this->implicitCommit) { $this->backup = [$this->articles, $this->groups]; }
        if ($this->failAt === 'asset') { throw new RuntimeException('injected asset failure'); }
        $this->articles[$id]['asset_id'] = 1000 + $id;
    }
    protected function createAssociation(int $sourceId, int $id, string $context = 'com_content.item'): void {
        if ($this->failAt === 'associations') { throw new RuntimeException('injected association failure'); }
        $g = [['id'=>$sourceId,'key'=>'test','language'=>'ca-ES'],['id'=>$id,'key'=>'test','language'=>'es-ES']];
        $this->groups[$context][$sourceId] = $g; $this->groups[$context][$id] = $g;
    }
    protected function ensureWorkflowAssociation(int $id, string $extension, ?int $sourceItemId = null): void {
        if ($this->failAt === 'workflow') { throw new RuntimeException('injected workflow failure'); }
    }
    protected function applyTranslationMenu(array $plan, int $id, array $fields, array $args): array {
        if ($this->failAt === 'menu') { throw new RuntimeException('injected menu failure'); }
        return ['action'=>'unchanged'];
    }
    protected function renderTranslation(string $fulltext, ?string $language = null): array {
        return $this->renderWorks ? ['result'=>['status'=>'success'], 'introtext'=>'<p>Hola</p>', 'fulltext'=>$fulltext]
            : ['result'=>['status'=>'skipped']];
    }
}
function requestArgs(): array { return ['source_id'=>1,'target_language'=>'es-ES','translated_title'=>'Destino','translated_introtext'=>'<p>Hola traducido</p>','target_category_id'=>7]; }
function applyPreview(TranslationFixture $tool, array $args): array {
    $preview = $tool->handle($args);
    verify(isset($preview['etag']), 'preview available: ' . json_encode($preview));
    return $tool->handle($args + ['dry_run'=>false,'confirm_guarded_write'=>true,'if_match'=>$preview['etag']]);
}
$t = new TranslationFixture(); $args = requestArgs(); $p = $t->handle($args);
verify($p['write_performed'] === false && $t->writes === [], 'preview never writes');
verify($p['preview']['after']['state'] === 0, 'published source defaults to new draft');
verify($p['preview']['menu']['action'] === 'none', 'menus untouched by default');
$r = applyPreview($t, $args);
verify($r['status'] === 'complete' && $r['article_id'] === 2, 'plain creation complete');
verify($t->articles[2]['created'] === $t->articles[1]['created'] && $t->articles[2]['access'] === 3 && $t->articles[2]['created_by'] === 42, 'editorial metadata forwarded');
$r = $t->handle($args + ['dry_run'=>false,'confirm_guarded_write'=>true,'if_match'=>$p['etag']]);
verify(isset($r['error']) && count($t->articles) === 2, 'stale create cannot duplicate');
$t = new TranslationFixture(); $t->articles[1]['fulltext'] = '<p>Contingut català</p>';
verify(isset($t->handle(requestArgs())['error']) && $t->writes === [], 'plain source fulltext requires an explicit translation');
foreach (['', '0'] as $translatedFulltext) {
    $t = new TranslationFixture(); $t->articles[1]['fulltext'] = '<p>Contingut català</p>';
    $r = applyPreview($t, requestArgs() + ['translated_fulltext'=>$translatedFulltext]);
    verify($r['status'] === 'complete' && $t->articles[2]['fulltext'] === $translatedFulltext, 'explicit empty or zero plain fulltext never copies source text');
}
$t = new TranslationFixture(); $t->articles[9] = array_merge($t->articles[1], ['id'=>9,'language'=>'es-ES','alias'=>'url-established','state'=>2]);
$args = requestArgs() + ['target_id'=>9,'overwrite'=>true]; $p = $t->handle($args);
verify($p['preview']['after']['alias'] === 'url-established' && $p['preview']['after']['state'] === 2, 'existing alias and archived state preserved');
$t->articles[9]['title'] = 'Concurrent edit';
$r = $t->handle($args + ['dry_run'=>false,'confirm_guarded_write'=>true,'if_match'=>$p['etag']]);
verify($r['code'] === 'stale_etag' && $t->writes === [], 'concurrent target refused');
$p = $t->handle($args); $t->articles[1]['access'] = 4;
$r = $t->handle($args + ['dry_run'=>false,'confirm_guarded_write'=>true,'if_match'=>$p['etag']]);
verify($r['code'] === 'stale_etag' && $t->writes === [], 'concurrent source refused');
$r = applyPreview($t, $args);
verify($r['action'] === 'updated' && $r['state'] === 2, 'unassociated target updated and associated');
$t->groups['com_content.item'][1] = [['id'=>10,'language'=>'es-ES','key'=>'different']];
verify(isset($t->handle($args)['error']), 'conflicting associated destination refused');
$t->groups['com_content.item'][1] = [];
verify(isset($t->handle($args)['error']), 'target group is never silently merged');
foreach (['asset','associations','workflow','menu','commit'] as $phase) {
    $t = new TranslationFixture(); $t->failAt = $phase; $r = applyPreview($t, requestArgs());
    verify($r['status'] === 'needs_reconciliation' && $r['article_id'] === 2, 'partial effect keeps ID: '.$phase);
    verify($r['article_exists_after_error'] === false && count($t->articles) === 1, 'fixture rollback restores article: '.$phase);
}
$t = new TranslationFixture();
$t->articles[1]['fulltext'] = '<!-- {"type":"text","props":{"content":"Hola"}} -->';
$args = requestArgs() + ['yootheme_text_replacements'=>['root.content'=>'Hola traducido']];
$r = applyPreview($t, $args);
verify($r['status'] === 'partial' && $r['state'] === 0, 'missing fallback yields partial draft');
$t = new TranslationFixture(); $t->articles[1]['fulltext'] = '<!-- {"type":"text","props":{"content":"Hola"}} -->';
$r = applyPreview($t, $args + ['state'=>1]);
verify(isset($r['error']) && $t->writes === [], 'missing fallback refuses publication before mutation');
$t->renderWorks = true; $r = applyPreview($t, $args + ['state'=>1]);
verify($r['status'] === 'complete' && $r['state'] === 1, 'verified fallback permits explicit publication');
$t = new TranslationFixture(); $t->custom[1] = [['field_id'=>5,'value_hash'=>'text-value']];
$r = applyPreview($t, requestArgs());
verify($r['status'] === 'partial' && $r['parity_warnings'][0]['code'] === 'custom_fields_require_review', 'custom fields never silently dropped');
$t = new TranslationFixture(); $t->menus = [
    5=>['id'=>5,'language'=>'ca-ES','client_id'=>0,'type'=>'component','published'=>1,'alias'=>'origen','link'=>'index.php?view=article&option=com_content&id=1&Itemid=5#part'],
    6=>['id'=>6,'language'=>'es-ES','client_id'=>0,'type'=>'component','published'=>1,'alias'=>'destino','link'=>'index.php?option=com_content&view=article&id=1&Itemid=6#part'],
];
$p = $t->handle(requestArgs() + ['menu_id'=>6]);
verify($p['preview']['menu']['action'] === 'repoint' && $p['preview']['menu']['target']['alias'] === 'destino', 'explicit ES to CA menu accepted with identity preserved');
verify(isset($t->handle(requestArgs() + ['menu_id'=>6,'create_menu'=>true])['error']), 'ambiguous menu actions refused');
$t->menus[6]['link'] = 'index.php?option=com_users&view=user&id=1';
verify(isset($t->handle(requestArgs() + ['menu_id'=>6])['error']), 'numeric ID from another component is not an article');
$t->menus[6]['link'] = 'index.php?option=com_content&view=article&id=20';
$t->articles[20] = array_merge($t->articles[1], ['id'=>20]);
verify(isset($t->handle(requestArgs() + ['menu_id'=>6])['error']), 'menu to another live article refused');
verify(isset($t->handle(requestArgs() + ['dry_run'=>false])['error']), 'apply needs explicit confirmation and token');
$t = new TranslationFixture(); $t->failAt = 'asset'; $t->implicitCommit = true;
$args = requestArgs(); $preview = $t->handle($args); $writeArgs = $args + ['dry_run'=>false,'confirm_guarded_write'=>true,'if_match'=>$preview['etag']];
$r = $t->handle($writeArgs);
verify($r['article_exists_after_error'] === true && !$t->lockHeld, 'implicit Table commit is reported and lock released after failure');
verify(count($t->articles) === 2 && count($t->groups['com_content.item'][1]) === 2, 'association survives implicit commit with partial article');
$r = $t->handle($writeArgs);
verify(isset($r['error']) && count($t->articles) === 2, 'retry after implicit commit cannot duplicate creation');
$t->failAt = '';
$r = applyPreview($t, $args + ['target_id'=>2,'overwrite'=>true]);
verify($r['status'] === 'complete' && $t->articles[2]['asset_id'] > 0 && count($t->articles) === 2, 'explicit recovery repairs the missing asset using the known target ID');

require_once $src . 'ContentTranslateBatchTool.php';
class TranslationBatchFixture extends Mirasai\Library\Tool\ContentTranslateBatchTool {
    public TranslationFixture $tool;
    public function __construct() { $this->tool = new TranslationFixture(); }
    protected function translationTool(): Mirasai\Library\Tool\ContentTranslateTool { return $this->tool; }
}
$batch = new TranslationBatchFixture();
$item = requestArgs(); unset($item['target_language']);
$batchArgs = ['target_language'=>'es-ES','articles'=>[$item]];
$p = $batch->handle($batchArgs);
verify($p['counts']['previewed'] === 1 && $batch->tool->writes === [], 'batch defaults to preview and preserves per-item token');
$batchArgs['articles'][0]['if_match'] = $p['results'][0]['etag'];
$batch->tool->failAt = 'asset';
$r = $batch->handle($batchArgs + ['dry_run'=>false,'confirm_guarded_write'=>true]);
verify($r['counts']['failed'] === 1 && $r['results'][0]['article_id'] === 2 && isset($r['results'][0]['attempted_effects']), 'batch retains partial failure IDs and effects');
verify(isset($batch->handle($batchArgs + ['fix_links'=>true])['error']), 'batch cannot mutate unrelated site links');

foreach (['ToolArgumentValidator', 'ToolRegistry'] as $name) { require_once $src . $name . '.php'; }
require_once dirname($src) . '/Mcp/McpHandler.php';
$registry = new Mirasai\Library\Tool\ToolRegistry();
$fixture = new TranslationFixture();
$registry->register($fixture);
$handler = new Mirasai\Library\Mcp\McpHandler($registry);
$response = $handler->handleRequest(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>'content/translate','arguments'=>requestArgs()]]);
verify(($response['result']['structuredContent']['dry_run'] ?? null) === true && $fixture->writes === [], 'MCP omitted dry_run follows the safe preview default');

echo "All {$count} Joomla translation workflow assertions passed.\n";
