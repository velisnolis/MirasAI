<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

use Joomla\CMS\Factory;
use Joomla\CMS\Version;

class SystemInfoTool extends AbstractTool
{
    public function getName(): string
    {
        return 'system/info';
    }

    public function getDescription(): string
    {
        return 'Returns Joomla runtime information: version, PHP version, installed languages, active extensions, and database prefix.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
        ];
    }

    public function handle(array $arguments): array
    {
        $version = new Version();

        return [
            'cms' => 'Joomla',
            'cms_version' => $version->getShortVersion(),
            'php_version' => PHP_VERSION,
            'db_prefix' => $this->db->getPrefix(),
            'languages' => $this->getLanguages(),
            'default_language' => Factory::getApplication()->get('language', 'en-GB'),
            'yootheme' => $this->getYoothemeInfo(),
            'mirasai_version' => '0.1.0',
        ];
    }

    /**
     * @return list<array{lang_code: string, title: string, published: bool}>
     */
    private function getLanguages(): array
    {
        $query = $this->db->getQuery(true)
            ->select(['lang_code', 'title', 'published'])
            ->from($this->db->quoteName('#__languages'))
            ->order('ordering');

        $rows = $this->db->setQuery($query)->loadAssocList();
        $result = [];

        foreach ($rows as $row) {
            $result[] = [
                'lang_code' => $row['lang_code'],
                'title' => $row['title'],
                'published' => (bool) $row['published'],
            ];
        }

        return $result;
    }

    /**
     * @return array{installed: bool, version: string|null}
     */
    private function getYoothemeInfo(): array
    {
        $templatePath = JPATH_ROOT . '/templates/yootheme/templateDetails.xml';

        if (!file_exists($templatePath)) {
            return ['installed' => false, 'version' => null];
        }

        $xml = simplexml_load_file($templatePath);

        return [
            'installed' => true,
            'version' => $xml ? (string) $xml->version : null,
        ];
    }
}
