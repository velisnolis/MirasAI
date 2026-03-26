<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Rereplacer\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use Mirasai\Library\Mcp\MirasaiCollectToolsEvent;
use Mirasai\Plugin\Mirasai\Rereplacer\RereplacerToolProvider;

final class MirasaiRereplacer extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onMirasaiCollectTools' => 'onMirasaiCollectTools',
        ];
    }

    public function onMirasaiCollectTools(MirasaiCollectToolsEvent $event): void
    {
        $event->addProvider(new RereplacerToolProvider());
    }
}
