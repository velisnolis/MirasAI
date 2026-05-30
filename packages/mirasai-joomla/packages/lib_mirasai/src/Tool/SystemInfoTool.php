<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

use Joomla\CMS\Factory;
use Joomla\CMS\Version;
use Mirasai\Library\Mirasai;
use Mirasai\Library\Sandbox\EnvironmentGuard;

class SystemInfoTool extends AbstractTool
{
    public function getName(): string
    {
        return 'system/info';
    }

    public function getDescription(): string
    {
        return 'Returns comprehensive Joomla runtime information: CMS version, PHP version, DB engine, installed languages, active extensions with status, template summary (name, style ID, language assignments), YOOtheme version, environment detection, and MirasAI capabilities.';
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
            'db_engine' => $this->db->getServerType() . ' ' . $this->db->getVersion(),
            'db_prefix' => $this->db->getPrefix(),
            'environment' => EnvironmentGuard::isStaging() ? 'staging' : 'production',
            'languages' => $this->getLanguages(),
            'default_language' => Factory::getApplication()->get('language', 'en-GB'),
            'extensions' => $this->getExtensions(),
            'template' => $this->getTemplateSummary(),
            'yootheme' => $this->getYoothemeInfo(),
            'mirasai_version' => Mirasai::VERSION,
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
     * @return list<array{name: string, type: string, enabled: bool}>
     */
    private function getExtensions(): array
    {
        try {
            $query = $this->db->getQuery(true)
                ->select(['name', 'type', 'enabled', 'element'])
                ->from($this->db->quoteName('#__extensions'))
                ->where($this->db->quoteName('type') . ' IN ('
                    . $this->db->quote('component') . ','
                    . $this->db->quote('plugin') . ','
                    . $this->db->quote('module') . ')')
                ->where($this->db->quoteName('client_id') . ' IN (0, 1)')
                ->order('type, name');

            $rows = $this->db->setQuery($query)->loadAssocList();
            $result = [];

            foreach ($rows as $row) {
                $result[] = [
                    'name' => $row['name'],
                    'element' => $row['element'],
                    'type' => $row['type'],
                    'enabled' => (bool) $row['enabled'],
                ];
            }

            return $result;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array{name: string|null, style_id: int|null, language_assignments: array<string, int>}
     */
    private function getTemplateSummary(): array
    {
        try {
            $query = $this->db->getQuery(true)
                ->select(['id', 'title', 'template', 'home'])
                ->from($this->db->quoteName('#__template_styles'))
                ->where($this->db->quoteName('client_id') . ' = 0')
                ->order('home DESC, id ASC');

            $rows = $this->db->setQuery($query)->loadAssocList();

            if (!$rows) {
                return ['name' => null, 'style_id' => null, 'language_assignments' => []];
            }

            $defaultRow = $rows[0];
            $assignments = [];

            foreach ($rows as $row) {
                if ($row['home'] !== '0') {
                    // home field contains language code or '1' for default
                    $lang = $row['home'] === '1' ? '*' : $row['home'];
                    $assignments[$lang] = (int) $row['id'];
                }
            }

            return [
                'name' => $defaultRow['template'],
                'style_id' => (int) $defaultRow['id'],
                'language_assignments' => $assignments,
            ];
        } catch (\Throwable) {
            return ['name' => null, 'style_id' => null, 'language_assignments' => []];
        }
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
