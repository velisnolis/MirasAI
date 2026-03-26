<?php

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \Mirasai\Component\Mirasai\Administrator\View\Elevation\HtmlView $this */

$pending = $this->pendingElevation;

// If no pending data, redirect back
if (empty($pending) || empty($pending['scopes'])) {
    Factory::getApplication()->enqueueMessage(Text::_('COM_MIRASAI_ELEVATION_MSG_NO_PENDING'), 'error');
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
    60 => Text::_('COM_MIRASAI_ELEVATION_DURATION_60'),
    120 => Text::_('COM_MIRASAI_ELEVATION_DURATION_120'),
    default => Text::sprintf('COM_MIRASAI_ELEVATION_DURATION_FALLBACK', $duration),
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
    <?php echo Text::_('COM_MIRASAI_ELEVATION_BACK'); ?>
</a>

<div class="confirm-summary">
    <strong><?php echo Text::_('COM_MIRASAI_ELEVATION_CONFIRM_SUMMARY'); ?></strong>
    <div class="mt-2 mb-2">
        <?php foreach ($scopes as $s): ?>
            <span class="badge bg-warning text-dark me-1 fs-6"><?php echo htmlspecialchars($s); ?></span>
        <?php endforeach; ?>
    </div>
    <div>
        <?php echo Text::sprintf('COM_MIRASAI_ELEVATION_CONFIRM_DURATION', $durationText); ?> <strong class="text-danger"><?php echo Text::_('COM_MIRASAI_ELEVATION_CONFIRM_PRODUCTION'); ?></strong>
    </div>
    <div class="text-muted small mt-1">
        <?php echo Text::_('COM_MIRASAI_ELEVATION_REASON'); ?>: <?php echo htmlspecialchars($reason); ?>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">
        <h3 class="card-title mb-0"><?php echo Text::_('COM_MIRASAI_ELEVATION_STEP2_TITLE'); ?></h3>
    </div>
    <div class="card-body">
        <ul class="risk-bullets">
            <?php if ($hasFileTools): ?>
                <li>
                    <strong><?php echo Text::_('COM_MIRASAI_ELEVATION_RISK_FILES_TITLE'); ?></strong> — <?php echo Text::_('COM_MIRASAI_ELEVATION_RISK_FILES_DESC'); ?>
                </li>
            <?php endif; ?>
            <?php if ($hasExecTool): ?>
                <li>
                    <strong><?php echo Text::_('COM_MIRASAI_ELEVATION_RISK_EXEC_TITLE'); ?></strong> — <?php echo Text::_('COM_MIRASAI_ELEVATION_RISK_EXEC_DESC'); ?>
                </li>
            <?php endif; ?>
            <li>
                <strong><?php echo Text::_('COM_MIRASAI_ELEVATION_RISK_AUDIT_TITLE'); ?></strong> — <?php echo Text::_('COM_MIRASAI_ELEVATION_RISK_AUDIT_DESC'); ?>
            </li>
            <li>
                <strong><?php echo Text::_('COM_MIRASAI_ELEVATION_RISK_TIME_TITLE'); ?></strong> — <?php echo Text::sprintf('COM_MIRASAI_ELEVATION_RISK_TIME_DESC', $durationText); ?>
            </li>
        </ul>

        <div class="alert alert-light border mt-3">
            <strong><?php echo Text::_('COM_MIRASAI_ELEVATION_RECOMMENDED'); ?></strong> <?php echo Text::_('COM_MIRASAI_ELEVATION_RECOMMENDED_DESC'); ?>
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
                    <?php echo Text::_('COM_MIRASAI_ELEVATION_ACK_LABEL'); ?>
                </label>
            </div>

            <input type="hidden" name="acknowledged" value="1">
            <?php echo HTMLHelper::_('form.token'); ?>

            <button type="submit" class="btn btn-warning btn-lg" id="btn-activate"
                    disabled aria-disabled="true"
                    title="<?php echo Text::_('COM_MIRASAI_ELEVATION_ACK_TITLE'); ?>">
                <?php echo Text::_('COM_MIRASAI_ELEVATION_ACTIVATE'); ?>
            </button>
        </form>
    </div>
</div>
