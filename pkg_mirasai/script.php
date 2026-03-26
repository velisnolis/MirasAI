<?php

declare(strict_types=1);

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScript;
use Joomla\Database\DatabaseInterface;

class Pkg_mirasaiInstallerScript extends InstallerScript
{
    protected $minimumJoomla = '5.0.0';
    protected $minimumPhp = '8.1.0';

    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type === 'install' || $type === 'update') {
            $this->enablePlugins();
            $this->cleanupLegacyAddonPluginRows();
        }

        return true;
    }

    private function enablePlugins(): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $plugins = [
            ['element' => 'mirasai', 'folder' => 'system'],
            ['element' => 'mirasai', 'folder' => 'webservices'],
        ];

        foreach ($plugins as $plugin) {
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('element') . ' = ' . $db->quote($plugin['element']))
                ->where($db->quoteName('folder') . ' = ' . $db->quote($plugin['folder']))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'));

            $db->setQuery($query)->execute();
        }
    }

    private function cleanupLegacyAddonPluginRows(): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $legacyMap = [
            'mirasai_yootheme' => 'yootheme',
        ];

        foreach ($legacyMap as $legacyElement => $canonicalElement) {
            $query = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('mirasai'))
                ->where($db->quoteName('element') . ' = ' . $db->quote($canonicalElement));

            $canonicalExists = (int) $db->setQuery($query)->loadResult() > 0;

            if (!$canonicalExists) {
                continue;
            }

            $query = $db->getQuery(true)
                ->delete($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('mirasai'))
                ->where($db->quoteName('element') . ' = ' . $db->quote($legacyElement));

            $db->setQuery($query)->execute();
        }
    }
}
