<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Event\Cache\AfterPurgeEvent;
use Joomla\CMS\Factory;

class SiteCachePurgeTool extends AbstractTool
{
    public function getName(): string
    {
        return 'site/cache-purge';
    }

    public function getDescription(): string
    {
        return 'Empties every Joomla cache group, the same as System → Clear Cache → Delete All, including the page cache of the '
            . 'core System - Page Cache plugin. Builder writes already clear the YOOtheme and page groups; call it when '
            . 'site/public-fetch still shows an old copy. Page caches of other extensions (JCH Optimize page cache, for example) keep '
            . 'their own storage: they are listed under unsupported and make state=unknown. A CDN in front of the site is not purged. '
            . 'Nothing is lost: pages are rebuilt as visitors request them. dry_run=true only lists what would be emptied.';
    }

    public function getPermissions(): array
    {
        return [
            'risk_level' => self::RISK_SAFE_WRITE,
            'readonly' => false,
            'destructive' => false,
            'requires_elevation' => false,
            'idempotent' => true,
        ];
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'dry_run' => [
                    'type' => 'boolean',
                    'description' => 'List the cache groups and page cache plugins without emptying anything. Defaults to false.',
                ],
            ],
        ];
    }

    public function handle(array $arguments): array
    {
        $app = Factory::getApplication();
        $plugins = $this->pageCachePlugins();
        $unsupported = array_values(array_diff($plugins, ['cache']));
        $corePageCache = in_array('cache', $plugins, true);
        $cache = Factory::getContainer()->get(CacheControllerFactoryInterface::class)
            ->createCacheController('output', ['defaultgroup' => '', 'caching' => true]);
        $all = $cache->getAll();
        $groups = is_array($all) ? array_map('strval', array_keys($all)) : [];
        sort($groups);

        if (($arguments['dry_run'] ?? false) === true) {
            return [
                'dry_run' => true,
                'version' => 1,
                'store' => (string) $app->get('cache_handler', 'file'),
                'groups' => $groups,
                'core_page_cache' => $corePageCache,
                'unsupported' => $unsupported,
            ];
        }

        $failures = [];

        foreach ($groups as $group) {
            try {
                if ($cache->clean($group) === false) {
                    $failures[] = $group;
                    continue;
                }
            } catch (\Throwable) {
                $failures[] = $group;
                continue;
            }

            // What com_cache does after each group, so extensions that listen can follow.
            if (class_exists(AfterPurgeEvent::class)) {
                $app->getDispatcher()->dispatch('onAfterPurge', new AfterPurgeEvent('onAfterPurge', ['subject' => $group]));
            }
        }

        $page = self::pageState($failures !== [], $unsupported !== [], $corePageCache);

        return [
            'dry_run' => false,
            'version' => 1,
            'state' => $page === 'unsupported' || $page === 'error' ? 'unknown' : 'pass',
            'page' => $page,
            'purged' => $failures === [] ? ['joomla_cache'] : [],
            'groups_cleaned' => count($groups) - count($failures),
            'failures' => $failures,
            'unsupported' => $unsupported,
            'object' => 'none',
        ];
    }

    /** Same order as the WordPress host: an error wins, then a cache this tool cannot empty. */
    public static function pageState(bool $failed, bool $unsupported, bool $corePageCache): string
    {
        if ($failed) {
            return 'error';
        }

        if ($unsupported) {
            return 'unsupported';
        }

        return $corePageCache ? 'purged' : 'none';
    }

    /**
     * Enabled system plugins whose element names a cache; «cache» is Joomla's own page cache.
     *
     * @return list<string>
     */
    private function pageCachePlugins(): array
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('element'))
            ->from($this->db->quoteName('#__extensions'))
            ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
            ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('system'))
            ->where($this->db->quoteName('enabled') . ' = 1')
            ->where($this->db->quoteName('element') . ' LIKE ' . $this->db->quote('%cache%'));

        $elements = $this->db->setQuery($query)->loadColumn() ?: [];
        $elements = array_values(array_map('strval', $elements));
        sort($elements);

        return $elements;
    }
}
