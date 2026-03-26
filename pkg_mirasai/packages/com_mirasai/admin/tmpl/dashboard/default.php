<?php

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \Mirasai\Component\Mirasai\Administrator\View\Dashboard\HtmlView $this */

$info       = $this->systemInfo;
$stats      = $this->translationStats;
$endpoint   = $this->mcpEndpoint;
$version    = $this->mirasaiVersion;
$allEnabled = $this->allCoreEnabled;
$tools      = $this->toolSummary;
$grouped    = $this->toolsByDomain;
$coreExts   = $this->coreExtensions;
$addons     = $this->addonPlugins;
$elevated   = $this->elevationActive;

$toolCount = count($tools);
$langCount = 0;
foreach ($stats as $s) {
    if ($s['language'] !== '*') {
        $langCount++;
    }
}
?>

<style>
:root {
    --mirasai-success: #198754;
    --mirasai-secondary: #6c757d;
    --mirasai-warning: #ffc107;
}
.mirasai-banner {
    padding: 1rem 1.25rem;
    border-radius: .375rem;
    margin-bottom: 1.5rem;
    color: #212529;
}
.mirasai-banner-active {
    background: #d1e7dd;
    border: 2px solid var(--mirasai-success);
}
.mirasai-banner-inactive {
    background: #f8d7da;
    border: 2px solid #dc3545;
}
.mirasai-banner .text-muted { color: var(--mirasai-secondary) !important; }
.mirasai-domain-header {
    font-size: .75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: var(--mirasai-secondary);
    padding: .5rem 0 .25rem;
    border-bottom: 1px solid #dee2e6;
    margin-top: 1rem;
}
.mirasai-domain-header:first-child { margin-top: 0; }
.mirasai-tool-row {
    display: flex;
    align-items: baseline;
    gap: .75rem;
    padding: .35rem 0;
    font-size: .875rem;
}
.mirasai-tool-row code {
    min-width: 180px;
    font-size: .8rem;
    color: #495057;
}
.mirasai-tool-desc {
    flex: 1;
    color: var(--mirasai-secondary);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.mirasai-tool-badges { white-space: nowrap; }
.mirasai-onboarding {
    background: #f0f6ff;
    border: 1px solid #b6d4fe;
    border-radius: .375rem;
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
}
.mirasai-onboarding ol { margin-bottom: .5rem; padding-left: 1.25rem; }
.mirasai-onboarding li { margin-bottom: .35rem; font-size: .875rem; }
</style>

<?php // ── Onboarding block (localStorage-driven) ── ?>
<div id="mirasai-onboarding" class="mirasai-onboarding" style="display:none;">
    <div class="d-flex justify-content-between align-items-start">
        <strong><?php echo Text::_('COM_MIRASAI_ONBOARDING_TITLE'); ?></strong>
        <button type="button" class="btn btn-sm btn-link text-muted p-0" id="mirasai-onboarding-dismiss">
            <?php echo Text::_('COM_MIRASAI_ONBOARDING_DISMISS'); ?>
        </button>
    </div>
    <ol class="mt-2">
        <li><?php echo Text::_('COM_MIRASAI_ONBOARDING_STEP1'); ?></li>
        <li><?php echo Text::_('COM_MIRASAI_ONBOARDING_STEP2'); ?></li>
        <li><?php echo Text::_('COM_MIRASAI_ONBOARDING_STEP3'); ?></li>
    </ol>
</div>

<?php // ── Status banner (full-width) ── ?>
<div class="mirasai-banner <?php echo $allEnabled ? 'mirasai-banner-active' : 'mirasai-banner-inactive'; ?>" role="banner">
    <div class="d-flex flex-wrap align-items-center gap-3 mb-2">
        <span class="fw-bold fs-5">MirasAI v<?php echo htmlspecialchars($version); ?></span>
        <span class="badge bg-<?php echo $allEnabled ? 'success' : 'danger'; ?> fs-6">
            <?php echo Text::_($allEnabled ? 'COM_MIRASAI_STATUS_ACTIVE' : 'COM_MIRASAI_STATUS_INACTIVE'); ?>
        </span>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <label class="fw-bold small mb-0"><?php echo Text::_('COM_MIRASAI_ENDPOINT'); ?></label>
        <div class="input-group input-group-sm" style="max-width: 500px;">
            <input type="text" class="form-control form-control-sm" value="<?php echo htmlspecialchars($endpoint); ?>" readonly id="mcp-endpoint">
            <button class="btn btn-outline-secondary btn-sm" type="button" id="mirasai-copy-btn" aria-label="<?php echo Text::_('COM_MIRASAI_COPY'); ?>">
                <span class="icon-copy" aria-hidden="true"></span>
            </button>
        </div>
    </div>
    <div class="text-muted small">
        <?php echo sprintf(Text::_('COM_MIRASAI_SUMMARY_TOOLS'), $toolCount); ?>
        &middot;
        <?php echo sprintf(Text::_('COM_MIRASAI_SUMMARY_LANGUAGES'), $langCount); ?>
        &middot;
        <?php echo Text::_($elevated ? 'COM_MIRASAI_SUMMARY_ELEVATION_ON' : 'COM_MIRASAI_SUMMARY_ELEVATION_OFF'); ?>
    </div>
</div>

<?php // ── System + Translations (2 columns) ── ?>
<div class="row" role="main">
    <div class="col-lg-4 mb-4">
        <div class="card h-100">
            <div class="card-header">
                <h3 class="card-title mb-0"><?php echo Text::_('COM_MIRASAI_SYSTEM'); ?></h3>
            </div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        <tr>
                            <td class="fw-bold"><?php echo Text::_('COM_MIRASAI_SYSTEM_JOOMLA'); ?></td>
                            <td><?php echo htmlspecialchars($info['joomla_version']); ?></td>
                        </tr>
                        <tr>
                            <td class="fw-bold"><?php echo Text::_('COM_MIRASAI_SYSTEM_PHP'); ?></td>
                            <td><?php echo htmlspecialchars($info['php_version']); ?></td>
                        </tr>
                        <tr>
                            <td class="fw-bold"><?php echo Text::_('COM_MIRASAI_SYSTEM_YOOTHEME'); ?></td>
                            <td>
                                <?php if ($info['yootheme_version']): ?>
                                    <span class="badge bg-info"><?php echo htmlspecialchars($info['yootheme_version']); ?></span>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><?php echo Text::_('COM_MIRASAI_NOT_INSTALLED'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <hr>
                <h4 class="h6"><?php echo Text::_('COM_MIRASAI_CORE_EXTENSIONS'); ?></h4>
                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        <?php foreach ($coreExts as $ext): ?>
                        <tr>
                            <td class="small">
                                <?php echo htmlspecialchars($ext['element']); ?>
                                <span class="text-muted">(<?php echo htmlspecialchars($ext['type']); ?><?php echo $ext['folder'] ? '/' . htmlspecialchars($ext['folder']) : ''; ?>)</span>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo (int) $ext['enabled'] ? 'success' : 'secondary'; ?>">
                                    <?php echo (int) $ext['enabled'] ? 'ON' : 'OFF'; ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-8 mb-4">
        <div class="card h-100">
            <div class="card-header">
                <h3 class="card-title mb-0"><?php echo Text::_('COM_MIRASAI_TRANSLATIONS'); ?></h3>
            </div>
            <div class="card-body">
                <?php if (empty($stats)): ?>
                    <p class="text-muted mb-1"><?php echo Text::_('COM_MIRASAI_TRANSLATIONS_EMPTY'); ?></p>
                    <p class="text-muted small"><?php echo Text::_('COM_MIRASAI_TRANSLATIONS_EMPTY_CTA'); ?></p>
                <?php else: ?>
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th><?php echo Text::_('COM_MIRASAI_TRANSLATIONS_LANGUAGE'); ?></th>
                                <th class="text-center"><?php echo Text::_('COM_MIRASAI_TRANSLATIONS_ARTICLES'); ?></th>
                                <th class="text-center"><?php echo Text::_('COM_MIRASAI_TRANSLATIONS_YOOTHEME'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats as $stat): ?>
                            <tr>
                                <td>
                                    <?php if ($stat['language'] === '*'): ?>
                                        <span class="badge bg-warning text-dark" title="<?php echo htmlspecialchars(Text::_('COM_MIRASAI_TRANSLATIONS_STAR_TOOLTIP')); ?>">
                                            <?php echo htmlspecialchars($stat['language']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-primary"><?php echo htmlspecialchars($stat['language']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?php echo (int) $stat['total']; ?></td>
                                <td class="text-center"><?php echo (int) $stat['with_yootheme']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php // ── Tools grouped by domain ── ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title mb-0"><?php echo Text::_('COM_MIRASAI_TOOLS'); ?> (<?php echo $toolCount; ?>)</h3>
    </div>
    <div class="card-body">
        <?php if (empty($tools)): ?>
            <p class="text-muted"><?php echo Text::_('COM_MIRASAI_TOOLS_EMPTY'); ?></p>
        <?php else: ?>
            <?php foreach ($grouped as $domain => $domainTools): ?>
                <div class="mirasai-domain-header">
                    <?php echo htmlspecialchars(strtoupper($domain)); ?>
                    (<?php echo count($domainTools); ?>)
                    <?php
                    // Show provider label if all tools in this domain share the same non-core provider
                    $providers = array_unique(array_column($domainTools, 'provider'));
                    if (count($providers) === 1 && $providers[0] !== 'core'):
                    ?>
                        <span class="ms-2 badge bg-secondary"><?php echo htmlspecialchars($providers[0]); ?></span>
                    <?php endif; ?>
                </div>
                <?php foreach ($domainTools as $tool): ?>
                    <div class="mirasai-tool-row">
                        <code><?php echo htmlspecialchars($tool['name']); ?></code>
                        <span class="mirasai-tool-desc" title="<?php echo htmlspecialchars($tool['description']); ?>">
                            <?php echo htmlspecialchars(mb_strimwidth($tool['description'], 0, 100, '...')); ?>
                        </span>
                        <span class="mirasai-tool-badges">
                            <?php if ($tool['provider'] === 'core'): ?>
                                <span class="badge bg-success"><?php echo Text::_('COM_MIRASAI_TOOLS_CORE'); ?></span>
                            <?php else: ?>
                                <span class="badge bg-secondary"><?php echo Text::_('COM_MIRASAI_TOOLS_ADDON'); ?></span>
                            <?php endif; ?>
                            <?php if ($tool['destructive']): ?>
                                <span title="<?php echo htmlspecialchars(Text::_('COM_MIRASAI_TOOLS_DESTRUCTIVE_HINT')); ?>">&#x1F534;</span>
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php // ── Addons section (read-only) ── ?>
<div class="card mb-4" role="complementary">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title mb-0"><?php echo Text::_('COM_MIRASAI_ADDONS'); ?></h3>
        <a href="<?php echo Route::_('index.php?option=com_plugins&filter[folder]=mirasai'); ?>" class="btn btn-sm btn-outline-secondary">
            <?php echo Text::_('COM_MIRASAI_ADDONS_MANAGE'); ?> &rarr;
        </a>
    </div>
    <div class="card-body">
        <?php if (empty($addons)): ?>
            <p class="text-muted mb-1"><?php echo Text::_('COM_MIRASAI_ADDONS_EMPTY'); ?></p>
            <p class="text-muted small"><?php echo Text::_('COM_MIRASAI_ADDONS_EMPTY_CTA'); ?></p>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($addons as $addon):
                    // Count tools from this addon
                    $addonId = 'mirasai.' . $addon['element'];
                    $addonToolCount = 0;
                    foreach ($tools as $t) {
                        if ($t['provider'] === $addonId) {
                            $addonToolCount++;
                        }
                    }
                ?>
                    <div class="col-sm-6 col-md-4 col-lg-3">
                        <div class="card h-100 <?php echo (int) $addon['enabled'] ? '' : 'opacity-50'; ?>">
                            <div class="card-body p-3 text-center">
                                <div class="fw-bold mb-1"><?php echo htmlspecialchars($addon['element']); ?></div>
                                <span class="badge bg-<?php echo (int) $addon['enabled'] ? 'success' : 'secondary'; ?> mb-1">
                                    <?php echo Text::_((int) $addon['enabled'] ? 'COM_MIRASAI_ADDONS_ENABLED' : 'COM_MIRASAI_ADDONS_DISABLED'); ?>
                                </span>
                                <div class="text-muted small"><?php echo sprintf(Text::_('COM_MIRASAI_ADDONS_TOOLS_COUNT'), $addonToolCount); ?></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php // ── Curl example (collapsible) ── ?>
<details class="mb-4">
    <summary class="fw-bold small text-muted" style="cursor: pointer;">
        <?php echo Text::_('COM_MIRASAI_CURL_SHOW'); ?>
    </summary>
    <pre class="bg-light p-3 rounded mt-2"><code>curl -X POST <?php echo htmlspecialchars($endpoint); ?> \
  -H "Content-Type: application/json" \
  -H "X-Joomla-Token: YOUR_TOKEN" \
  -d '{"jsonrpc":"2.0","method":"tools/list","params":{},"id":1}'</code></pre>
</details>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ── Copy endpoint button ──
    var copyBtn = document.getElementById('mirasai-copy-btn');
    if (copyBtn) {
        copyBtn.addEventListener('click', function() {
            var input = document.getElementById('mcp-endpoint');
            if (input && navigator.clipboard) {
                navigator.clipboard.writeText(input.value).then(function() {
                    var icon = copyBtn.querySelector('.icon-copy');
                    if (icon) {
                        icon.className = 'icon-check';
                        setTimeout(function() { icon.className = 'icon-copy'; }, 1500);
                    }
                });
            }
        });
    }

    // ── Onboarding (localStorage) ──
    var onboarding = document.getElementById('mirasai-onboarding');
    var dismissBtn = document.getElementById('mirasai-onboarding-dismiss');
    var storageKey = 'mirasai_onboarding_dismissed';

    if (onboarding) {
        try {
            if (!localStorage.getItem(storageKey)) {
                onboarding.style.display = '';
            }
        } catch(e) {
            // localStorage unavailable — show by default
            onboarding.style.display = '';
        }
    }

    if (dismissBtn) {
        dismissBtn.addEventListener('click', function() {
            try { localStorage.setItem(storageKey, '1'); } catch(e) {}
            if (onboarding) { onboarding.style.display = 'none'; }
        });
    }
});
</script>
