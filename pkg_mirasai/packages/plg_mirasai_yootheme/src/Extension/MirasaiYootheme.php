<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use Mirasai\Library\Mcp\MirasaiCollectToolsEvent;
use Mirasai\Plugin\Mirasai\Yootheme\YooThemeToolProvider;

final class MirasaiYootheme extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onMirasaiCollectTools' => 'onMirasaiCollectTools',
        ];
    }

    public function onMirasaiCollectTools(MirasaiCollectToolsEvent $event): void
    {
        $event->addProvider(new YooThemeToolProvider());
    }
}
