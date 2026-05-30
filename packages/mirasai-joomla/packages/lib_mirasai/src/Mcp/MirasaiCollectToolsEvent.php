<?php

declare(strict_types=1);

namespace Mirasai\Library\Mcp;

use Joomla\CMS\Event\AbstractEvent;
use Mirasai\Library\Tool\ToolProviderInterface;

/**
 * Event fired by ToolRegistry to allow plugins to register tool providers.
 *
 * Plugins listen to 'onMirasaiCollectTools' and call addProvider() to
 * inject their own tools into the MCP registry.
 *
 * Example plugin handler:
 *   public function onMirasaiCollectTools(MirasaiCollectToolsEvent $event): void
 *   {
 *       $event->addProvider(new MyToolProvider());
 *   }
 */
class MirasaiCollectToolsEvent extends AbstractEvent
{
    /** @var list<ToolProviderInterface> */
    private array $providers = [];

    public function addProvider(ToolProviderInterface $provider): void
    {
        $this->providers[] = $provider;
    }

    /**
     * @return list<ToolProviderInterface>
     */
    public function getProviders(): array
    {
        return $this->providers;
    }
}
