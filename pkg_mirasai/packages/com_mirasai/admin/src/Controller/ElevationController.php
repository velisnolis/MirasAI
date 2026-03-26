<?php

declare(strict_types=1);

namespace Mirasai\Component\Mirasai\Administrator\Controller;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Mirasai\Library\Sandbox\ElevationService;

/**
 * Controller for the Elevation admin view.
 *
 * Handles: activate (2-step POST), revoke (POST), auditfeed (JSON poll).
 */
class ElevationController extends BaseController
{
    /**
     * Step 1 → Step 2: show confirmation screen with selected scopes/duration.
     */
    public function confirm(): void
    {
        $this->assertAdminAccess();
        Session::checkToken() or jexit('Invalid Token');

        $input = $this->input;
        $scopes = $input->get('scopes', [], 'array');
        $duration = $input->getInt('duration', 60);
        $reason = $input->getString('reason', '');

        // Validate
        $allowedDurations = [15, 30, 60, 120];
        if (!\in_array($duration, $allowedDurations, true)) {
            $duration = 60;
        }

        $validScopes = ['file/write', 'file/edit', 'file/delete', 'sandbox/execute-php'];
        $scopes = array_values(array_intersect($scopes, $validScopes));

        if (empty($scopes)) {
            $this->setMessage(Text::_('COM_MIRASAI_ELEVATION_MSG_SCOPE_REQUIRED'), 'error');
            $this->setRedirect(Route::_('index.php?option=com_mirasai&view=elevation', false));
            return;
        }

        if (\strlen(trim($reason)) < 10) {
            $this->setMessage(Text::_('COM_MIRASAI_ELEVATION_MSG_REASON_SHORT'), 'error');
            $this->setRedirect(Route::_('index.php?option=com_mirasai&view=elevation', false));
            return;
        }

        // Check no active grant
        $elevation = new ElevationService();
        $existing = $elevation->getActiveGrant();
        if ($existing !== null) {
            $remaining = (int) ceil($existing->getRemainingSeconds() / 60);
            $this->setMessage(Text::sprintf('COM_MIRASAI_ELEVATION_MSG_ALREADY_ACTIVE', $remaining), 'warning');
            $this->setRedirect(Route::_('index.php?option=com_mirasai&view=elevation', false));
            return;
        }

        // Store in session for Step 2 confirmation rendering
        $session = Factory::getApplication()->getSession();
        $session->set('mirasai.elevation.pending', [
            'scopes' => $scopes,
            'duration' => $duration,
            'reason' => $reason,
        ]);

        $this->setRedirect(Route::_('index.php?option=com_mirasai&view=elevation&layout=confirm', false));
    }

    /**
     * Step 2: actually activate the elevation grant.
     */
    public function activate(): void
    {
        $this->assertAdminAccess();
        Session::checkToken() or jexit('Invalid Token');

        $input = $this->input;
        $acknowledged = $input->getBool('acknowledged', false);

        if (!$acknowledged) {
            $this->setMessage(Text::_('COM_MIRASAI_ELEVATION_MSG_ACK_REQUIRED'), 'error');
            $this->setRedirect(Route::_('index.php?option=com_mirasai&view=elevation', false));
            return;
        }

        // Retrieve pending data from session
        $session = Factory::getApplication()->getSession();
        $pending = $session->get('mirasai.elevation.pending');

        if (empty($pending) || empty($pending['scopes'])) {
            $this->setMessage(Text::_('COM_MIRASAI_ELEVATION_MSG_NO_PENDING'), 'error');
            $this->setRedirect(Route::_('index.php?option=com_mirasai&view=elevation', false));
            return;
        }

        // Clear session data
        $session->clear('mirasai.elevation.pending');

        try {
            $userId = Factory::getApplication()->getIdentity()->id;
            $elevation = new ElevationService();
            $grant = $elevation->activate(
                (int) $userId,
                $pending['scopes'],
                (int) $pending['duration'],
                (string) $pending['reason'],
            );

            $remaining = (int) ceil($grant->getRemainingSeconds() / 60);
            $this->setMessage(Text::sprintf('COM_MIRASAI_ELEVATION_MSG_ACTIVATED', $remaining), 'success');
        } catch (\RuntimeException $e) {
            $this->setMessage($e->getMessage(), 'error');
        }

        $this->setRedirect(Route::_('index.php?option=com_mirasai&view=elevation', false));
    }

    /**
     * Revoke the active elevation grant.
     */
    public function revoke(): void
    {
        $this->assertAdminAccess();
        Session::checkToken() or jexit('Invalid Token');

        $grantId = $this->input->getInt('grant_id', 0);

        if ($grantId <= 0) {
            $this->setMessage(Text::_('COM_MIRASAI_ELEVATION_MSG_INVALID_GRANT'), 'error');
            $this->setRedirect(Route::_('index.php?option=com_mirasai&view=elevation', false));
            return;
        }

        try {
            $elevation = new ElevationService();
            $elevation->revoke($grantId);
            $this->setMessage(Text::_('COM_MIRASAI_ELEVATION_MSG_REVOKED'), 'warning');
        } catch (\Throwable $e) {
            $this->setMessage(Text::sprintf('COM_MIRASAI_ELEVATION_MSG_REVOKE_FAILED', $e->getMessage()), 'error');
        }

        $this->setRedirect(Route::_('index.php?option=com_mirasai&view=elevation', false));
    }

    /**
     * JSON endpoint for audit feed polling (30s interval from JS).
     * Also serves as session keep-alive.
     */
    public function auditfeed(): void
    {
        $this->assertAdminAccess();
        Session::checkToken() or jexit('Invalid Token');

        $grantId = $this->input->getInt('grant_id', 0);

        header('Content-Type: application/json; charset=utf-8');

        if ($grantId <= 0) {
            echo json_encode(['error' => 'Invalid grant ID']);
            Factory::getApplication()->close();
            return;
        }

        try {
            $elevation = new ElevationService();
            $grant = $elevation->getActiveGrant();

            $remainingSeconds = 0;
            $isActive = false;

            if ($grant !== null && $grant->id === $grantId) {
                $remainingSeconds = $grant->getRemainingSeconds();
                $isActive = $grant->isActive();
            }

            $audit = $elevation->getAuditLog($grantId);

            echo json_encode([
                'remaining_seconds' => $remainingSeconds,
                'is_active' => $isActive,
                'use_count' => $grant?->useCount ?? 0,
                'audit' => $audit,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }

        Factory::getApplication()->close();
    }

    private function assertAdminAccess(): void
    {
        $user = Factory::getApplication()->getIdentity();

        if (!$user || $user->guest || !$user->authorise('core.admin')) {
            http_response_code(403);
            jexit('Forbidden');
        }
    }
}
