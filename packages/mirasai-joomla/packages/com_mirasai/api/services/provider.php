<?php

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ApiComponent;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->registerServiceProvider(new MVCFactory('\\Mirasai\\Component\\Mirasai\\Api'));
        $container->registerServiceProvider(new ComponentDispatcherFactory('\\Mirasai\\Component\\Mirasai\\Api'));

        $container->set(
            ComponentInterface::class,
            function (Container $container) {
                $component = new ApiComponent(
                    $container->get(ComponentDispatcherFactoryInterface::class),
                );

                $component->setMVCFactory($container->get(MVCFactoryInterface::class));

                return $component;
            },
        );
    }
};
