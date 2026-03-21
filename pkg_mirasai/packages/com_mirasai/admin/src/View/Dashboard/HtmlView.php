<?php

declare(strict_types=1);

namespace Mirasai\Component\Mirasai\Administrator\View\Dashboard;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\Version;
use Joomla\Database\DatabaseInterface;

class HtmlView extends BaseHtmlView
{
    /** @var array<string, mixed> */
    protected array $systemInfo = [];

    /** @var list<array<string, mixed>> */
    protected array $translationStats = [];

    /** @var string */
    protected string $mcpEndpoint = '';

    public function display($tpl = null): void
    {
        $this->systemInfo = $this->getSystemInfo();
        $this->translationStats = $this->getTranslationStats();
        $this->mcpEndpoint = rtrim(\Joomla\CMS\Uri\Uri::root(), '/') . '/api/v1/mirasai/mcp';

        ToolbarHelper::title('MirasAI', 'bolt');

        parent::display($tpl);
    }

    /**
     * @return array<string, mixed>
     */
    private function getSystemInfo(): array
    {
        $version = new Version();
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        // Languages
        $query = $db->getQuery(true)
            ->select(['lang_code', 'title', 'published'])
            ->from($db->quoteName('#__languages'))
            ->order('ordering');
        $languages = $db->setQuery($query)->loadAssocList();

        // YOOtheme
        $ytVersion = null;
        $ytPath = JPATH_ROOT . '/templates/yootheme/templateDetails.xml';
        if (file_exists($ytPath)) {
            $xml = simplexml_load_file($ytPath);
            $ytVersion = $xml ? (string) $xml->version : null;
        }

        // MirasAI plugins
        $query = $db->getQuery(true)
            ->select(['element', 'folder', 'enabled', 'type'])
            ->from($db->quoteName('#__extensions'))
            ->where('(' . $db->quoteName('element') . ' = ' . $db->quote('mirasai')
                . ' OR ' . $db->quoteName('element') . ' = ' . $db->quote('com_mirasai') . ')')
            ->order('type');
        $extensions = $db->setQuery($query)->loadAssocList();

        return [
            'joomla_version' => $version->getShortVersion(),
            'php_version' => PHP_VERSION,
            'yootheme_version' => $ytVersion,
            'mirasai_version' => '0.1.0',
            'languages' => $languages,
            'extensions' => $extensions,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getTranslationStats(): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $query = $db->getQuery(true)
            ->select([
                'c.language',
                'COUNT(c.id) AS total',
                'SUM(CASE WHEN ' . $db->quoteName('c.fulltext') . ' LIKE ' . $db->quote('%<!-- {%') . ' THEN 1 ELSE 0 END) AS with_yootheme',
            ])
            ->from($db->quoteName('#__content', 'c'))
            ->where('c.state >= 0')
            ->group('c.language')
            ->order('c.language');

        return $db->setQuery($query)->loadAssocList() ?: [];
    }
}
