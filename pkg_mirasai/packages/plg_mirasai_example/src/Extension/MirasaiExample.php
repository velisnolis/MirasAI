<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Example\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use Mirasai\Library\Mcp\MirasaiCollectToolsEvent;
use Mirasai\Plugin\Mirasai\Example\ExampleToolProvider;

/**
 * Example MirasAI plugin — registers a single demo tool.
 *
 * Copy this plugin as a starting point for your own MirasAI plugins.
 * See docs/plugin-developer-guide.md for full documentation.
 */
final class MirasaiExample extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onMirasaiCollectTools' => 'onMirasaiCollectTools',
        ];
    }

    public function onMirasaiCollectTools(MirasaiCollectToolsEvent $event): void
    {
        $event->addProvider(new ExampleToolProvider());
    }
}
