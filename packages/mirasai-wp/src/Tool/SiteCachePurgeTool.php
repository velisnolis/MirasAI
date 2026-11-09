<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

use Mirasai\WordPress\Checks\CachePurger;

class SiteCachePurgeTool extends AbstractTool
{
    public function getName(): string
    {
        return 'site/cache-purge';
    }

    public function getDescription(): string
    {
        return 'Empties the page caches this host knows (WP Rocket, IONOS Performance) and the persistent object cache, the way '
            . 'each plugin\'s own «Clear cache» button does. Builder and Style writes do not do this: call it when '
            . 'site/public-fetch shows an old copy. state=unknown means a page cache drop-in was found that this tool cannot empty. '
            . 'A CDN in front of the site (Cloudflare) is not purged. Nothing is lost: pages are rebuilt as visitors request them, '
            . 'but a large site may preload for a while. dry_run=true only lists what would be emptied.';
    }

    public function getPermissions(): array
    {
        return [
            'risk_level' => self::RISK_SAFE_WRITE,
            'idempotent' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'dry_run' => [
                    'type' => 'boolean',
                    'description' => 'List the caches that would be emptied without emptying them. Defaults to false.',
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array
    {
        if (($arguments['dry_run'] ?? false) === true) {
            return ['dry_run' => true] + CachePurger::detect();
        }

        return ['dry_run' => false] + CachePurger::purge();
    }
}
