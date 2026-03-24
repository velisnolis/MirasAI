<?php

declare(strict_types=1);

namespace Mirasai\Component\Mirasai\Administrator\View\Elevation;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Database\DatabaseInterface;
use Mirasai\Library\Sandbox\ElevationGrant;
use Mirasai\Library\Sandbox\ElevationService;

/**
 * Admin view for Smart Sudo elevation management.
 *
 * Renders one of three states:
 * - Inactive: activation form (Step 1: scope+duration)
 * - Confirm: confirmation screen (Step 2: risk acknowledgment)
 * - Active: countdown + audit feed + revoke
 *
 * History tab is always available alongside the current state.
 */
class HtmlView extends BaseHtmlView
{
    /** @var ElevationGrant|null Active elevation grant, if any */
    public ?ElevationGrant $activeGrant = null;

    /** @var list<array<string, mixed>> Audit log for the active grant */
    public array $auditLog = [];

    /** @var list<array<string, mixed>> History of past grants */
    public array $history = [];

    /** @var int Total history rows for pagination */
    public int $historyTotal = 0;

    /** @var array<string, mixed>|null Pending elevation from session (Step 2) */
    public ?array $pendingElevation = null;

    /** @var string CSRF token for JS polling */
    public string $csrfToken = '';

    /** @var string Current tab: 'elevation' or 'history' */
    public string $activeTab = 'elevation';

    /** Destructive tools available for elevation */
    public const TOOL_SCOPES = [
        'file/write' => [
            'label' => 'Write files',
            'group' => 'FILES',
            'description' => 'Can create/overwrite sandbox files',
        ],
        'file/edit' => [
            'label' => 'Edit files',
            'group' => 'FILES',
            'description' => 'Can modify sandbox files in-place',
        ],
        'file/delete' => [
            'label' => 'Delete files',
            'group' => 'FILES',
            'description' => 'Can remove sandbox files',
        ],
        'sandbox/execute-php' => [
            'label' => 'Execute PHP',
            'group' => 'EXECUTION',
            'description' => 'Runs PHP via eval() with DB transaction',
        ],
    ];

    public function display($tpl = null): void
    {
        $app = Factory::getApplication();
        $this->activeTab = $app->getInput()->getString('tab', 'elevation');

        ToolbarHelper::title('MirasAI — Smart Sudo', 'lock');

        // Load elevation state
        $elevation = new ElevationService();
        $this->activeGrant = $elevation->getActiveGrant();

        if ($this->activeGrant !== null && $this->activeGrant->isActive()) {
            $this->auditLog = $elevation->getAuditLog($this->activeGrant->id);
        } else {
            $this->activeGrant = null; // Expired grant = inactive
        }

        // Load pending confirmation from session (Step 2)
        $session = $app->getSession();
        $this->pendingElevation = $session->get('mirasai.elevation.pending');

        // CSRF token for JS polling
        $this->csrfToken = Session::getFormToken();

        // Load history
        $this->loadHistory();

        parent::display($tpl);
    }

    private function loadHistory(): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $input = Factory::getApplication()->getInput();
        $limitstart = $input->getInt('limitstart', 0);
        $limit = 20;

        // Count total
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__mirasai_elevation_grants'))
            ->where('(' . $db->quoteName('revoked_at') . ' IS NOT NULL OR '
                . $db->quoteName('expires_at') . ' <= NOW())');

        $this->historyTotal = (int) $db->setQuery($query)->loadResult();

        // Load page
        $query = $db->getQuery(true)
            ->select('g.*')
            ->from($db->quoteName('#__mirasai_elevation_grants', 'g'))
            ->where('(' . $db->quoteName('g.revoked_at') . ' IS NOT NULL OR '
                . $db->quoteName('g.expires_at') . ' <= NOW())')
            ->order($db->quoteName('g.issued_at') . ' DESC');

        $db->setQuery($query, $limitstart, $limit);
        $rows = $db->loadAssocList() ?: [];

        // Attach audit counts and error flag
        foreach ($rows as &$row) {
            $q = $db->getQuery(true)
                ->select([
                    'COUNT(*) AS total_calls',
                    "SUM(CASE WHEN result_summary = 'error' THEN 1 ELSE 0 END) AS error_count",
                ])
                ->from($db->quoteName('#__mirasai_elevation_audit'))
                ->where($db->quoteName('grant_id') . ' = ' . (int) $row['id']);

            $stats = $db->setQuery($q)->loadAssoc();
            $row['total_calls'] = (int) ($stats['total_calls'] ?? 0);
            $row['error_count'] = (int) ($stats['error_count'] ?? 0);
        }

        $this->history = $rows;
    }
}
