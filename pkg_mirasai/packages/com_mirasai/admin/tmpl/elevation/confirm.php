<?php

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Router\Route;

/** @var \Mirasai\Component\Mirasai\Administrator\View\Elevation\HtmlView $this */

$pending = $this->pendingElevation;

// If no pending data, redirect back
if (empty($pending) || empty($pending['scopes'])) {
    Factory::getApplication()->enqueueMessage('No pending elevation request. Please start again.', 'error');
    Factory::getApplication()->redirect(Route::_('index.php?option=com_mirasai&view=elevation', false));
    return;
}

$scopes = $pending['scopes'];
$duration = (int) $pending['duration'];
$reason = (string) $pending['reason'];
$toolScopes = $this::TOOL_SCOPES;

// Build human-readable scope list
$scopeLabels = [];
foreach ($scopes as $s) {
    $scopeLabels[] = $toolScopes[$s]['label'] ?? $s;
}

// Duration text
$durationText = match ($duration) {
    15 => '15 minutes',
    30 => '30 minutes',
    60 => '1 hour',
    120 => '2 hours',
    default => $duration . ' minutes',
};

// Determine which risk bullets apply
$hasFileTools = !empty(array_intersect($scopes, ['file/write', 'file/edit', 'file/delete']));
$hasExecTool = in_array('sandbox/execute-php', $scopes, true);
?>

<style>
.confirm-summary {
    background: #fff3cd;
    border: 2px solid #ffc107;
    border-radius: .375rem;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}
.confirm-summary strong { font-size: 1.1rem; }
.risk-bullets { list-style: none; padding: 0; margin: 1rem 0; }
.risk-bullets li { padding: .5rem 0; padding-left: 1.5rem; position: relative; }
.risk-bullets li::before { content: "⚠"; position: absolute; left: 0; }
</style>

<a href="<?php echo Route::_('index.php?option=com_mirasai&view=elevation'); ?>" class="btn btn-outline-secondary btn-sm mb-3">
    ← Back to scope selection
</a>

<div class="confirm-summary">
    <strong>You are granting the AI agent access to:</strong>
    <div class="mt-2 mb-2">
        <?php foreach ($scopes as $s): ?>
            <span class="badge bg-warning text-dark me-1 fs-6"><?php echo htmlspecialchars($s); ?></span>
        <?php endforeach; ?>
    </div>
    <div>
        for <strong><?php echo $durationText; ?></strong> on <strong class="text-danger">PRODUCTION</strong>
    </div>
    <div class="text-muted small mt-1">
        Reason: <?php echo htmlspecialchars($reason); ?>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">
        <h3 class="card-title mb-0">Step 2 — Risk acknowledgment</h3>
    </div>
    <div class="card-body">
        <ul class="risk-bullets">
            <?php if ($hasFileTools): ?>
                <li>
                    <strong>Modifies files</strong> — The agent can create, edit, and delete files in the sandbox directory
                </li>
            <?php endif; ?>
            <?php if ($hasExecTool): ?>
                <li>
                    <strong>Executes PHP</strong> — The agent can run arbitrary PHP code via eval() with DB transaction wrapping
                </li>
            <?php endif; ?>
            <li>
                <strong>All actions logged</strong> — Every destructive call is recorded with tool name and arguments
            </li>
            <li>
                <strong>Time-limited</strong> — Access automatically expires after <?php echo $durationText; ?>
            </li>
        </ul>

        <div class="alert alert-light border mt-3">
            <strong>Recommended:</strong> Use SSH or a staging copy for destructive operations when possible.
            Only use production elevation for time-sensitive fixes you understand the risks of.
        </div>

        <form method="post" id="form-activate"
              action="<?php echo Route::_('index.php?option=com_mirasai&task=elevation.activate'); ?>"
              onsubmit="if(!document.getElementById('acknowledge-risks').checked){return false;}
                        var btn=document.getElementById('btn-activate');
                        btn.textContent='Activating…';
                        setTimeout(function(){btn.disabled=true;},50);
                        return true;">
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="acknowledge-risks"
                       onchange="document.getElementById('btn-activate').disabled = !this.checked;
                                 document.getElementById('btn-activate').setAttribute('aria-disabled', !this.checked ? 'true' : 'false');">
                <label class="form-check-label fw-bold" for="acknowledge-risks">
                    I understand the risks and accept responsibility
                </label>
            </div>

            <input type="hidden" name="acknowledged" value="1">
            <?php echo HTMLHelper::_('form.token'); ?>

            <button type="submit" class="btn btn-warning btn-lg" id="btn-activate"
                    disabled aria-disabled="true"
                    title="Check the risk acknowledgment to continue">
                Activate Smart Sudo
            </button>
        </form>
    </div>
</div>
