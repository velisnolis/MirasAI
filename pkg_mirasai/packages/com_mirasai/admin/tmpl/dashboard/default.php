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
$tools      = $this->toolSummary;
$toolGroups = $this->toolGroups;
$coreExts   = $this->coreExtensions;
$elevated   = $this->elevationActive;
$registryReady = $this->registryReady;
$registryWarningCount = $this->registryWarningCount;
$dashboardStatus = $this->dashboardStatus;

$toolCount = count($tools);
$langCount = $this->configuredLanguageCount;
$bannerClass = match ($dashboardStatus) {
    'active' => 'mirasai-banner-active',
    'degraded' => 'mirasai-banner-warning',
    default => 'mirasai-banner-inactive',
};
$statusBadgeClass = match ($dashboardStatus) {
    'active' => 'success',
    'degraded' => 'warning text-dark',
    default => 'danger',
};
$statusLabel = match ($dashboardStatus) {
    'active' => 'COM_MIRASAI_STATUS_ACTIVE',
    'degraded' => 'COM_MIRASAI_STATUS_DEGRADED',
    default => 'COM_MIRASAI_STATUS_INACTIVE',
};
$serverHost = (string) parse_url($endpoint, PHP_URL_HOST);
$serverName = preg_replace('/[^a-z0-9]+/i', '-', $serverHost ?: 'mirasai') ?: 'mirasai';
$pluginsUrl = Route::_('index.php?option=com_plugins&view=plugins&filter[search]=mirasai');
$usersUrl = Route::_('index.php?option=com_users&view=users');
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
.mirasai-banner-warning {
    background: #fff3cd;
    border: 2px solid var(--mirasai-warning);
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
.mirasai-provider-accordion .accordion-button {
    gap: .75rem;
}
.mirasai-provider-accordion .accordion-button:not(.collapsed) {
    background: #f8f9fa;
    color: inherit;
}
.mirasai-provider-meta {
    font-size: .8rem;
    color: var(--mirasai-secondary);
}
.mirasai-provider-count {
    min-width: 2.5rem;
    text-align: center;
}
.mirasai-onboarding {
    background: #f0f6ff;
    border: 1px solid #b6d4fe;
    border-radius: .375rem;
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
}
.mirasai-onboarding ol { margin-bottom: .5rem; padding-left: 1.25rem; }
.mirasai-onboarding li { margin-bottom: .35rem; font-size: .875rem; }
.mirasai-client-config {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: .375rem;
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
}
.mirasai-client-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: .5rem;
    margin: .75rem 0 1rem;
}
.mirasai-client-tabs .btn.active {
    background: #212529;
    border-color: #212529;
    color: #fff;
}
.mirasai-config-panel {
    display: none;
}
.mirasai-config-panel.active {
    display: block;
}
.mirasai-config-panel pre {
    margin-bottom: 0;
    white-space: pre-wrap;
    word-break: break-word;
}
.mirasai-config-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: .75rem;
    margin-bottom: .5rem;
}
.mirasai-config-toggle summary {
    cursor: pointer;
    list-style: none;
}
.mirasai-config-toggle summary::-webkit-details-marker {
    display: none;
}
.mirasai-config-toggle summary::before {
    content: "▸";
    display: inline-block;
    margin-right: .5rem;
    transition: transform .15s ease;
}
.mirasai-config-toggle[open] summary::before {
    transform: rotate(90deg);
}
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
        <li>
            <?php echo Text::_('COM_MIRASAI_ONBOARDING_STEP1_PREFIX'); ?>
            <a href="<?php echo $pluginsUrl; ?>"><?php echo Text::_('COM_MIRASAI_ONBOARDING_STEP1_LINK'); ?></a>.
        </li>
        <li><?php echo Text::_('COM_MIRASAI_ONBOARDING_STEP2'); ?></li>
        <li>
            <?php echo Text::_('COM_MIRASAI_ONBOARDING_STEP3_PREFIX'); ?>
            <a href="<?php echo $usersUrl; ?>"><?php echo Text::_('COM_MIRASAI_ONBOARDING_STEP3_LINK'); ?></a>
            <?php echo Text::_('COM_MIRASAI_ONBOARDING_STEP3_SUFFIX'); ?>
        </li>
    </ol>
</div>

<details class="mb-4">
    <summary class="fw-bold small text-muted" style="cursor: pointer;">
        <?php echo Text::_('COM_MIRASAI_CURL_SHOW'); ?>
    </summary>
    <div class="mirasai-config-toolbar mt-2">
        <span class="text-muted small"><?php echo Text::_('COM_MIRASAI_CURL_NOTE'); ?></span>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-mirasai-copy-curl>
            <?php echo Text::_('COM_MIRASAI_COPY'); ?>
        </button>
    </div>
    <pre class="bg-light p-3 rounded"><code id="mirasai-curl-example">curl -X POST <?php echo htmlspecialchars($endpoint); ?> \
  -H "Content-Type: application/json" \
  -H "X-Joomla-Token: YOUR_TOKEN" \
  -d '{"jsonrpc":"2.0","method":"tools/list","params":{},"id":1}'</code></pre>
</details>

<?php
$clientConfigs = [
    'claude-code' => [
        'label' => Text::_('COM_MIRASAI_CLIENT_CLAUDE_CODE'),
        'helper' => Text::_('COM_MIRASAI_CLIENT_HELPER_CLAUDE_CODE'),
        'code' => "claude mcp add --transport http " . $serverName . " " . $endpoint . " \\\n  --header \"X-Joomla-Token: YOUR_TOKEN\"",
    ],
    'claude-desktop' => [
        'label' => Text::_('COM_MIRASAI_CLIENT_CLAUDE_DESKTOP'),
        'helper' => Text::_('COM_MIRASAI_CLIENT_HELPER_CLAUDE_DESKTOP'),
        'code' => "{\n  \"mcpServers\": {\n    \"" . addslashes($serverName) . "\": {\n      \"type\": \"http\",\n      \"url\": \"" . addslashes($endpoint) . "\",\n      \"headers\": {\n        \"X-Joomla-Token\": \"YOUR_TOKEN\"\n      }\n    }\n  }\n}",
    ],
    'cursor' => [
        'label' => Text::_('COM_MIRASAI_CLIENT_CURSOR'),
        'helper' => Text::_('COM_MIRASAI_CLIENT_HELPER_CURSOR'),
        'code' => "{\n  \"mcpServers\": {\n    \"" . addslashes($serverName) . "\": {\n      \"type\": \"http\",\n      \"url\": \"" . addslashes($endpoint) . "\",\n      \"headers\": {\n        \"X-Joomla-Token\": \"YOUR_TOKEN\"\n      }\n    }\n  }\n}",
    ],
    'vscode' => [
        'label' => Text::_('COM_MIRASAI_CLIENT_VSCODE'),
        'helper' => Text::_('COM_MIRASAI_CLIENT_HELPER_VSCODE'),
        'code' => "{\n  \"servers\": {\n    \"" . addslashes($serverName) . "\": {\n      \"type\": \"http\",\n      \"url\": \"" . addslashes($endpoint) . "\",\n      \"headers\": {\n        \"X-Joomla-Token\": \"YOUR_TOKEN\"\n      }\n    }\n  }\n}",
    ],
    'windsurf' => [
        'label' => Text::_('COM_MIRASAI_CLIENT_WINDSURF'),
        'helper' => Text::_('COM_MIRASAI_CLIENT_HELPER_WINDSURF'),
        'code' => "{\n  \"mcpServers\": {\n    \"" . addslashes($serverName) . "\": {\n      \"type\": \"http\",\n      \"url\": \"" . addslashes($endpoint) . "\",\n      \"headers\": {\n        \"X-Joomla-Token\": \"YOUR_TOKEN\"\n      }\n    }\n  }\n}",
    ],
    'zed' => [
        'label' => Text::_('COM_MIRASAI_CLIENT_ZED'),
        'helper' => Text::_('COM_MIRASAI_CLIENT_HELPER_ZED'),
        'code' => "{\n  \"context_servers\": {\n    \"" . addslashes($serverName) . "\": {\n      \"source\": \"remote\",\n      \"url\": \"" . addslashes($endpoint) . "\",\n      \"headers\": {\n        \"X-Joomla-Token\": \"YOUR_TOKEN\"\n      }\n    }\n  }\n}",
    ],
    'opencode' => [
        'label' => Text::_('COM_MIRASAI_CLIENT_OPENCODE'),
        'helper' => Text::_('COM_MIRASAI_CLIENT_HELPER_OPENCODE'),
        'code' => "{\n  \"mcpServers\": {\n    \"" . addslashes($serverName) . "\": {\n      \"transport\": \"http\",\n      \"url\": \"" . addslashes($endpoint) . "\",\n      \"headers\": {\n        \"X-Joomla-Token\": \"YOUR_TOKEN\"\n      }\n    }\n  }\n}",
    ],
];
?>

<details class="mirasai-client-config mirasai-config-toggle mb-4" open>
    <summary class="fw-bold"><?php echo Text::_('COM_MIRASAI_CLIENT_CONNECT_TITLE'); ?></summary>
    <p class="text-muted small mb-2"><?php echo Text::_('COM_MIRASAI_CLIENT_CONNECT_DESC'); ?></p>
    <div class="mirasai-client-tabs" role="tablist" aria-label="<?php echo Text::_('COM_MIRASAI_CLIENT_CONNECT_TITLE'); ?>">
        <?php $clientIndex = 0; ?>
        <?php foreach ($clientConfigs as $clientId => $clientConfig): ?>
            <button
                type="button"
                class="btn btn-sm btn-outline-dark <?php echo $clientIndex === 0 ? 'active' : ''; ?>"
                data-mirasai-client-tab="<?php echo htmlspecialchars($clientId); ?>"
            >
                <?php echo htmlspecialchars($clientConfig['label']); ?>
            </button>
            <?php $clientIndex++; ?>
        <?php endforeach; ?>
    </div>
    <?php $clientIndex = 0; ?>
    <?php foreach ($clientConfigs as $clientId => $clientConfig): ?>
        <div class="mirasai-config-panel <?php echo $clientIndex === 0 ? 'active' : ''; ?>" data-mirasai-client-panel="<?php echo htmlspecialchars($clientId); ?>">
            <div class="mirasai-config-toolbar">
                <span class="text-muted small"><?php echo htmlspecialchars($clientConfig['helper']); ?></span>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-mirasai-copy-config="<?php echo htmlspecialchars($clientId); ?>">
                    <?php echo Text::_('COM_MIRASAI_COPY'); ?>
                </button>
            </div>
            <pre class="bg-dark text-light p-3 rounded"><code><?php echo htmlspecialchars($clientConfig['code']); ?></code></pre>
        </div>
        <?php $clientIndex++; ?>
    <?php endforeach; ?>
    <p class="text-muted small mt-2 mb-0"><?php echo Text::_('COM_MIRASAI_CLIENT_NOTE'); ?></p>
</details>

<?php // ── Status banner (full-width) ── ?>
<div class="mirasai-banner <?php echo $bannerClass; ?>" role="banner">
    <div class="d-flex flex-wrap align-items-center gap-3 mb-2">
        <span class="fw-bold fs-5">MirasAI v<?php echo htmlspecialchars($version); ?></span>
        <span class="badge bg-<?php echo $statusBadgeClass; ?> fs-6">
            <?php echo Text::_($statusLabel); ?>
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
        &middot;
        <?php echo Text::_($registryReady ? 'COM_MIRASAI_SUMMARY_REGISTRY_OK' : 'COM_MIRASAI_SUMMARY_REGISTRY_FAILED'); ?>
        <?php if ($registryWarningCount > 0): ?>
            &middot;
            <?php echo sprintf(Text::_('COM_MIRASAI_SUMMARY_REGISTRY_WARNINGS'), $registryWarningCount); ?>
        <?php endif; ?>
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
                                    <div class="text-muted small mt-1"><?php echo Text::_('COM_MIRASAI_SYSTEM_CORE_ONLY_HINT'); ?></div>
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
                                    <?php echo Text::_((int) $ext['enabled'] ? 'COM_MIRASAI_ON' : 'COM_MIRASAI_OFF'); ?>
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
                                    <?php if (!empty($stat['title'])): ?>
                                        <span class="text-muted small ms-2"><?php echo htmlspecialchars($stat['title']); ?></span>
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

<?php // ── Tools grouped by provider/addon ── ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title mb-0"><?php echo Text::_('COM_MIRASAI_TOOLS'); ?> (<?php echo $toolCount; ?>)</h3>
    </div>
    <div class="card-body">
        <?php if (empty($tools)): ?>
            <p class="text-muted mb-1"><?php echo Text::_('COM_MIRASAI_TOOLS_EMPTY'); ?></p>
            <?php if (!$registryReady): ?>
                <p class="text-danger small mb-0"><?php echo Text::_('COM_MIRASAI_TOOLS_REGISTRY_FAILED'); ?></p>
            <?php endif; ?>
        <?php else: ?>
            <div class="accordion mirasai-provider-accordion" id="mirasai-tools-accordion">
                <?php foreach ($toolGroups as $index => $group):
                    $collapseId = 'mirasai-tools-group-' . $index;
                    $headingId = $collapseId . '-heading';
                    $isOpen = !empty($group['open']);
                    $stateBadgeClass = match ($group['state']) {
                        'active' => 'success',
                        'unavailable' => 'warning text-dark',
                        default => 'secondary',
                    };
                    $stateLabel = match ($group['state']) {
                        'active' => 'COM_MIRASAI_TOOLS_GROUP_ACTIVE',
                        'unavailable' => 'COM_MIRASAI_TOOLS_GROUP_UNAVAILABLE',
                        default => 'COM_MIRASAI_TOOLS_GROUP_DISABLED',
                    };
                ?>
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="<?php echo $headingId; ?>">
                            <button
                                class="accordion-button <?php echo $isOpen ? '' : 'collapsed'; ?>"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#<?php echo $collapseId; ?>"
                                aria-expanded="<?php echo $isOpen ? 'true' : 'false'; ?>"
                                aria-controls="<?php echo $collapseId; ?>"
                            >
                                <span class="fw-bold"><?php echo htmlspecialchars($group['title']); ?></span>
                                <span class="badge bg-<?php echo $stateBadgeClass; ?>"><?php echo Text::_($stateLabel); ?></span>
                                <span class="badge bg-light text-dark mirasai-provider-count"><?php echo (int) $group['count']; ?></span>
                                <span class="mirasai-provider-meta"><?php echo htmlspecialchars((string) $group['subtitle']); ?></span>
                            </button>
                        </h2>
                        <div
                            id="<?php echo $collapseId; ?>"
                            class="accordion-collapse collapse <?php echo $isOpen ? 'show' : ''; ?>"
                            aria-labelledby="<?php echo $headingId; ?>"
                            data-bs-parent="#mirasai-tools-accordion"
                        >
                            <div class="accordion-body">
                                <?php if (empty($group['tools_by_domain'])): ?>
                                    <p class="text-muted mb-0"><?php echo Text::_('COM_MIRASAI_TOOLS_GROUP_EMPTY'); ?></p>
                                <?php else: ?>
                                    <?php foreach ($group['tools_by_domain'] as $domain => $domainTools): ?>
                                        <div class="mirasai-domain-header">
                                            <?php echo htmlspecialchars(strtoupper($domain)); ?>
                                            (<?php echo count($domainTools); ?>)
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
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

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

    var clientTabs = document.querySelectorAll('[data-mirasai-client-tab]');
    var clientPanels = document.querySelectorAll('[data-mirasai-client-panel]');
    clientTabs.forEach(function(tab) {
        tab.addEventListener('click', function() {
            var target = tab.getAttribute('data-mirasai-client-tab');
            clientTabs.forEach(function(otherTab) {
                otherTab.classList.toggle('active', otherTab === tab);
            });
            clientPanels.forEach(function(panel) {
                panel.classList.toggle('active', panel.getAttribute('data-mirasai-client-panel') === target);
            });
        });
    });

    var configCopyButtons = document.querySelectorAll('[data-mirasai-copy-config]');
    configCopyButtons.forEach(function(button) {
        button.addEventListener('click', function() {
            var target = button.getAttribute('data-mirasai-copy-config');
            var panel = document.querySelector('[data-mirasai-client-panel="' + target + '"] code');
            if (!panel || !navigator.clipboard) {
                return;
            }

            navigator.clipboard.writeText(panel.textContent || '').then(function() {
                button.textContent = '<?php echo addslashes(Text::_('COM_MIRASAI_COPIED')); ?>';
                setTimeout(function() {
                    button.textContent = '<?php echo addslashes(Text::_('COM_MIRASAI_COPY')); ?>';
                }, 1500);
            });
        });
    });

    var curlCopyButton = document.querySelector('[data-mirasai-copy-curl]');
    if (curlCopyButton) {
        curlCopyButton.addEventListener('click', function() {
            var curlCode = document.getElementById('mirasai-curl-example');
            if (!curlCode || !navigator.clipboard) {
                return;
            }

            navigator.clipboard.writeText(curlCode.textContent || '').then(function() {
                curlCopyButton.textContent = '<?php echo addslashes(Text::_('COM_MIRASAI_COPIED')); ?>';
                setTimeout(function() {
                    curlCopyButton.textContent = '<?php echo addslashes(Text::_('COM_MIRASAI_COPY')); ?>';
                }, 1500);
            });
        });
    }
});
</script>
