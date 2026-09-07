<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

/** Each item keeps its own preview token and complete recovery result. */
class ContentTranslateBatchTool extends AbstractTool
{
    public function getName(): string { return 'content/translate-batch'; }
    public function getDescription(): string
    {
        return 'Previews or applies up to 25 article translations. Each item uses the content/translate contract and its own if_match. Defaults to dry_run=true; never fixes links across the site. Partial results retain their IDs and effects.';
    }
    public function getInputSchema(): array
    {
        $item = (new \ReflectionClass(ContentTranslateTool::class))->newInstanceWithoutConstructor()->getInputSchema();
        unset($item['properties']['target_language'], $item['properties']['dry_run'], $item['properties']['confirm_guarded_write']);
        $item['required'] = ['source_id', 'translated_title'];
        return ['type' => 'object', 'properties' => [
            'target_language' => ['type' => 'string'],
            'articles' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 25, 'items' => $item],
            'overwrite' => ['type' => 'boolean', 'default' => false],
            'dry_run' => ['type' => 'boolean', 'default' => true],
            'confirm_guarded_write' => ['type' => 'boolean'],
            'fix_links' => ['type' => 'boolean', 'enum' => [false], 'description' => 'Legacy false only. Preview and apply link changes separately with an explicit article scope.'],
        ], 'required' => ['target_language', 'articles']];
    }
    public function getPermissions(): array { return ['risk_level' => self::RISK_GUARDED_WRITE, 'idempotent' => false]; }
    protected function translationTool(): ContentTranslateTool { return new ContentTranslateTool(); }
    public function handle(array $arguments): array
    {
        $items = $arguments['articles'] ?? [];
        $lang = $arguments['target_language'] ?? '';
        $dryRun = ($arguments['dry_run'] ?? true) === true;
        if (!is_array($items) || $items === [] || count($items) > 25 || $lang === '' || !empty($arguments['fix_links'])) {
            return ['error' => 'Provide target_language and 1–25 articles; automatic site-wide fix_links is no longer supported.'];
        }
        if (!$dryRun && ($arguments['confirm_guarded_write'] ?? false) !== true) {
            return ['error' => 'Batch apply requires confirm_guarded_write=true.'];
        }
        $ids = [];
        foreach ($items as $item) {
            if (!is_array($item) || empty($item['source_id']) || isset($ids[$item['source_id']])) {
                return ['error' => 'Each item needs a unique source_id. No items were executed.'];
            }
            $ids[$item['source_id']] = true;
            if (!$dryRun && empty($item['if_match'])) {
                return ['error' => 'Every apply item requires its own preview if_match. No items were executed.'];
            }
        }
        $tool = $this->translationTool();
        $results = [];
        $counts = ['previewed' => 0, 'complete' => 0, 'partial' => 0, 'failed' => 0];
        foreach ($items as $item) {
            $args = $item + ['overwrite' => ($arguments['overwrite'] ?? false) === true];
            $args['target_language'] = $lang;
            $args['dry_run'] = $dryRun;
            $args['confirm_guarded_write'] = ($arguments['confirm_guarded_write'] ?? false) === true;
            $result = $tool->handle($args);
            $result['source_id'] = (int) $item['source_id'];
            $key = isset($result['error']) ? 'failed' : ($dryRun ? 'previewed' : ($result['status'] ?? 'partial'));
            $counts[$key]++;
            $results[] = $result;
        }
        return ['target_language' => $lang, 'dry_run' => $dryRun, 'total' => count($items), 'counts' => $counts, 'results' => $results];
    }
}
