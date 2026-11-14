<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\ParameterType;

/**
 * Publishes or unpublishes an existing article, and nothing else. It does not create, delete,
 * trash or archive, and it never writes title, alias, category or content. Widening it is a
 * product decision, not a patch: docs/todo-016-decision-note-2026-11-13.md.
 *
 * Mirrors mirasai-wp's ContentUpdateTool, where a page also has a parent and a slug. In Joomla
 * the URL of an article comes from its menu item, which this tool does not touch.
 */
class ContentUpdateTool extends AbstractTool
{
    private const ARGUMENTS = ['id', 'state', 'if_match', 'dry_run', 'confirm_guarded_write'];

    private const STATES = [0, 1];

    public function getName(): string
    {
        return 'content/update';
    }

    public function getDescription(): string
    {
        return 'Publishes (state 1) or unpublishes (state 0) an existing article, and nothing else: no trash, no archive, no '
            . 'title, alias, category or content, no new articles. It refuses an article that is open for editing, and a site '
            . 'with workflows enabled, where the state moves by transition in Joomla. It writes the field and cleans the '
            . 'content cache; Smart Search is not told, and the preview says so when it is on. The preview lists the menu '
            . 'items that point to the article; it does not change them. if_match is the etag of content/read. Check '
            . 'afterwards with site/public-fetch.';
    }

    public function getPermissions(): array
    {
        return [
            'risk_level' => self::RISK_GUARDED_WRITE,
            'idempotent' => false,
        ];
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'ID of an existing article.'],
                'state' => ['type' => 'integer', 'enum' => self::STATES, 'description' => '1 publishes the article; 0 takes it away from visitors.'],
                'if_match' => ['type' => 'string', 'description' => 'etag from content/read.'],
                'dry_run' => ['type' => 'boolean', 'description' => 'Preview without writing.'],
                'confirm_guarded_write' => ['type' => 'boolean', 'description' => 'Required with dry_run=false.'],
            ],
            'required' => ['id', 'state', 'if_match'],
        ];
    }

    public function handle(array $arguments): array
    {
        $id = (int) ($arguments['id'] ?? 0);
        $ifMatch = trim((string) ($arguments['if_match'] ?? ''));
        $dryRun = ($arguments['dry_run'] ?? null) === true;

        if ($id <= 0 || $ifMatch === '') {
            return ['error' => 'id, state and if_match are required.', 'code' => 'missing_arguments'];
        }

        $state = self::requestedState($arguments);

        if (is_array($state)) {
            return $state;
        }

        if (!$dryRun && ($arguments['confirm_guarded_write'] ?? null) !== true) {
            return [
                'error' => 'This is a guarded write. Retry with dry_run=true first, then confirm_guarded_write=true if the preview is correct.',
                'code' => 'guarded_write_confirmation_required',
            ];
        }

        $article = ContentReadTool::loadForEtag($this->db, $id);

        if ($article === null) {
            return ['error' => "Article {$id} not found.", 'code' => 'not_found'];
        }

        $current = (int) $article['state'];

        if (!in_array($current, self::STATES, true)) {
            return [
                'error' => 'content/update leaves a trashed or archived article alone.',
                'code' => 'unsupported_current_state',
                'state' => $current,
            ];
        }

        $etag = ContentReadTool::contentEtag($article);

        if (!hash_equals($etag, $ifMatch)) {
            return [
                'error' => 'The article changed. Call content/read again and retry with its etag.',
                'code' => 'stale_etag',
                'expected_etag' => $etag,
                'provided_etag' => $ifMatch,
            ];
        }

        if ((int) ComponentHelper::getParams('com_content')->get('workflow_enabled', 0) === 1) {
            return [
                'error' => 'This site publishes articles through workflows: the state moves by a transition in Joomla, not here.',
                'code' => 'workflow_enabled',
            ];
        }

        if ((int) ($article['checked_out'] ?? 0) > 0) {
            return [
                'error' => 'The article is open for editing in Joomla. Close or check it in there first.',
                'code' => 'checked_out',
                'checked_out_by' => (int) $article['checked_out'],
            ];
        }

        $response = [
            'dry_run' => $dryRun,
            'id' => $id,
            'title' => (string) $article['title'],
            'language' => (string) $article['language'],
            'would_change' => $current !== $state,
            'changes' => $current !== $state ? ['state' => ['before' => $current, 'after' => $state]] : [],
            'menu_items' => $this->menuItems($id),
            'warnings' => self::warnings($article, $state, gmdate('Y-m-d H:i:s')),
        ];

        if ($current !== $state && PluginHelper::isEnabled('content', 'finder')) {
            $response['warnings'][] = [
                'code' => 'search_index_not_updated',
                'message' => 'Smart Search is not told about the change: its results keep the old state until the index is rebuilt in Joomla.',
            ];
        }

        if ($dryRun) {
            return $response + [
                'action' => 'preview',
                'etag' => $etag,
                'note' => 'No changes were written. Retry with confirm_guarded_write=true and the same if_match.',
            ];
        }

        if ($current === $state) {
            return $response + ['action' => 'unchanged', 'etag' => $etag];
        }

        $refusal = $this->writeState($id, $current, $state);
        $written = ContentReadTool::loadForEtag($this->db, $id);

        if ($refusal !== null || $written === null || (int) $written['state'] !== $state) {
            return [
                'error' => 'The state was not written. ' . ($refusal ?? 'Read the article again with content/read.'),
                'code' => 'update_failed',
            ];
        }

        return $response + [
            'action' => 'updated',
            'old_etag' => $etag,
            'new_etag' => ContentReadTool::contentEtag($written),
            'next' => 'site/public-fetch with the URL of the article; if it shows the old page, site/cache-purge.',
        ];
    }

    /**
     * The state the call asks for, or an error. What the tool does not write is refused here as
     * well as by the schema, so a direct call cannot widen it.
     *
     * @param array<string, mixed> $arguments
     * @return int|array<string, mixed>
     */
    public static function requestedState(array $arguments): int|array
    {
        $unknown = array_values(array_diff(array_map('strval', array_keys($arguments)), self::ARGUMENTS));

        if ($unknown !== []) {
            return [
                'error' => 'content/update only changes the state. Refused: ' . implode(', ', $unknown) . '.',
                'code' => 'unsupported_field',
                'fields' => $unknown,
            ];
        }

        $state = $arguments['state'] ?? null;

        if (is_float($state) && floor($state) === $state) {
            $state = (int) $state;
        }

        if (!in_array($state, self::STATES, true)) {
            return [
                'error' => 'state must be 0 (unpublished) or 1 (published). content/update does not trash or archive.',
                'code' => 'invalid_state',
            ];
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $article
     * @param string $now UTC, as Joomla stores dates
     * @return list<array{code: string, message: string}>
     */
    public static function warnings(array $article, int $state, string $now): array
    {
        $warnings = [];
        $current = (int) ($article['state'] ?? 0);

        if ($current === 1 && $state === 0) {
            $warnings[] = [
                'code' => 'no_longer_public',
                'message' => 'Visitors get a 404 at its URL. Menu items and links that point to it stay as they are.',
            ];
        }

        $set = static fn (mixed $date): bool => is_string($date) && $date !== '' && !str_starts_with($date, '0000-00-00');
        $up = $article['publish_up'] ?? null;
        $down = $article['publish_down'] ?? null;

        if ($state === 1 && (($set($up) && $up > $now) || ($set($down) && $down <= $now))) {
            $warnings[] = [
                'code' => 'outside_publish_window',
                'message' => 'The article has a start or finish publishing date that keeps it hidden now. Published, visitors still do not see it.',
            ];
        }

        return $warnings;
    }

    /**
     * Site menu items that open this article. The preview shows them; nothing here writes them.
     *
     * @return list<array{id: int, title: string, menutype: string, published: int}>
     */
    private function menuItems(int $id): array
    {
        $link = 'index.php?option=com_content&view=article&id=' . $id;
        $prefix = $link . '&%';
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName(['id', 'title', 'menutype', 'published']))
            ->from($this->db->quoteName('#__menu'))
            ->where($this->db->quoteName('client_id') . ' = 0')
            ->where('(' . $this->db->quoteName('link') . ' = :link OR ' . $this->db->quoteName('link') . ' LIKE :prefix)')
            ->bind(':link', $link)
            ->bind(':prefix', $prefix)
            ->order($this->db->quoteName('id'));
        $items = [];

        foreach ($this->db->setQuery($query)->loadAssocList() ?: [] as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'menutype' => (string) $row['menutype'],
                'published' => (int) $row['published'],
            ];
        }

        return $items;
    }

    /**
     * The state and nothing else, as the other content tools of this host write: the MCP endpoint
     * runs without a Joomla identity, so the article model would refuse. The WHERE repeats what
     * was checked, so a change in between writes nothing. Returns why it did not happen, or null.
     */
    private function writeState(int $id, int $current, int $state): ?string
    {
        try {
            $query = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__content'))
                ->set($this->db->quoteName('state') . ' = :state')
                ->where($this->db->quoteName('id') . ' = :id')
                ->where($this->db->quoteName('state') . ' = :current')
                ->where('(' . $this->db->quoteName('checked_out') . ' IS NULL OR ' . $this->db->quoteName('checked_out') . ' = 0)')
                ->bind(':state', $state, ParameterType::INTEGER)
                ->bind(':id', $id, ParameterType::INTEGER)
                ->bind(':current', $current, ParameterType::INTEGER);

            $this->db->setQuery($query)->execute();

            if ($this->db->getAffectedRows() !== 1) {
                return 'The article changed while it was being written.';
            }
        } catch (\Throwable $exception) {
            return $exception->getMessage();
        }

        foreach (['com_content', 'page'] as $group) {
            try {
                Factory::getCache($group)->clean();
            } catch (\Throwable) {
                // The state is written; site/cache-purge empties what is left.
            }
        }

        return null;
    }
}
