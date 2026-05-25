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
$pluginsUrl = Route::_('index.php?option=com_plugins&view=plugins&filter[search]=mirasai', false);
$usersUrl = Route::_('index.php?option=com_users&view=users', false);
$riskBadgeClasses = [
    'read' => 'bg-light text-dark border',
    'safe_write' => 'bg-info text-dark',
    'guarded_write' => 'bg-warning text-dark',
    'dangerous_exec' => 'bg-danger',
];
$riskLabels = [
    'read' => 'COM_MIRASAI_RISK_READ',
    'safe_write' => 'COM_MIRASAI_RISK_SAFE_WRITE',
    'guarded_write' => 'COM_MIRASAI_RISK_GUARDED_WRITE',
    'dangerous_exec' => 'COM_MIRASAI_RISK_DANGEROUS_EXEC',
];
$smokeText = [
    'pending' => Text::_('COM_MIRASAI_SMOKE_STATE_PENDING'),
    'running' => Text::_('COM_MIRASAI_SMOKE_STATE_RUNNING'),
    'passed' => Text::_('COM_MIRASAI_SMOKE_STATE_PASSED'),
    'failed' => Text::_('COM_MIRASAI_SMOKE_STATE_FAILED'),
    'tokenRequired' => Text::_('COM_MIRASAI_SMOKE_TOKEN_REQUIRED'),
    'endpointOk' => Text::_('COM_MIRASAI_SMOKE_ENDPOINT_OK'),
    'authOk' => Text::_('COM_MIRASAI_SMOKE_AUTH_OK'),
    'toolsOk' => Text::_('COM_MIRASAI_SMOKE_TOOLS_OK'),
    'diagnoseOk' => Text::_('COM_MIRASAI_SMOKE_DIAGNOSE_OK'),
    'annotationsOk' => Text::_('COM_MIRASAI_SMOKE_ANNOTATIONS_OK'),
    'structuredOk' => Text::_('COM_MIRASAI_SMOKE_STRUCTURED_OK'),
    'http401' => Text::_('COM_MIRASAI_SMOKE_ERROR_401'),
    'http403' => Text::_('COM_MIRASAI_SMOKE_ERROR_403'),
    'http404' => Text::_('COM_MIRASAI_SMOKE_ERROR_404'),
    'httpOther' => Text::_('COM_MIRASAI_SMOKE_ERROR_HTTP'),
    'jsonRpcError' => Text::_('COM_MIRASAI_SMOKE_ERROR_JSONRPC'),
    'networkError' => Text::_('COM_MIRASAI_SMOKE_ERROR_NETWORK'),
    'complete' => Text::_('COM_MIRASAI_SMOKE_COMPLETE'),
    'rawSummary' => Text::_('COM_MIRASAI_SMOKE_RAW_SUMMARY'),
];
$onboardingText = [
    'pending' => Text::_('COM_MIRASAI_ONBOARDING_STATE_PENDING'),
    'running' => Text::_('COM_MIRASAI_ONBOARDING_STATE_RUNNING'),
    'passed' => Text::_('COM_MIRASAI_ONBOARDING_STATE_PASSED'),
    'failed' => Text::_('COM_MIRASAI_ONBOARDING_STATE_FAILED'),
    'endpointOk' => Text::_('COM_MIRASAI_ONBOARDING_CHECK_ENDPOINT_OK'),
    'tokenOk' => Text::_('COM_MIRASAI_ONBOARDING_CHECK_TOKEN_OK'),
    'toolsOk' => Text::_('COM_MIRASAI_ONBOARDING_CHECK_TOOLS_OK'),
    'diagnoseOk' => Text::_('COM_MIRASAI_ONBOARDING_CHECK_DIAGNOSE_OK'),
    'configOk' => Text::_('COM_MIRASAI_ONBOARDING_CHECK_CONFIG_OK'),
    'endpointWaiting' => Text::_('COM_MIRASAI_ONBOARDING_CHECK_ENDPOINT_WAITING'),
    'tokenWaiting' => Text::_('COM_MIRASAI_ONBOARDING_CHECK_TOKEN_WAITING'),
    'toolsWaiting' => Text::_('COM_MIRASAI_ONBOARDING_CHECK_TOOLS_WAITING'),
    'diagnoseWaiting' => Text::_('COM_MIRASAI_ONBOARDING_CHECK_DIAGNOSE_WAITING'),
    'configWaiting' => Text::_('COM_MIRASAI_ONBOARDING_CHECK_CONFIG_WAITING'),
];

$coreMissingItems = [];
foreach ($coreExts as $ext) {
    if ((int) ($ext['enabled'] ?? 0) === 1) {
        continue;
    }

    $extensionLabel = (string) ($ext['element'] ?? '');
    $extensionMeta = (string) ($ext['type'] ?? '');
    if (!empty($ext['folder'])) {
        $extensionMeta .= '/' . (string) $ext['folder'];
    }

    if ($extensionMeta !== '') {
        $extensionLabel .= ' (' . $extensionMeta . ')';
    }

    $coreMissingItems[] = sprintf(Text::_('COM_MIRASAI_ONBOARDING_CHECK_CORE_MISSING_EXTENSION'), $extensionLabel);
}

if (!$registryReady) {
    $coreMissingItems[] = $registryWarningCount > 0
        ? sprintf(Text::_('COM_MIRASAI_ONBOARDING_CHECK_CORE_REGISTRY_WARNINGS'), $registryWarningCount)
        : Text::_('COM_MIRASAI_ONBOARDING_CHECK_CORE_REGISTRY_FAILED');
}

$coreOnboardingDetail = empty($coreMissingItems)
    ? Text::_('COM_MIRASAI_ONBOARDING_CHECK_CORE_OK')
    : implode(' ', $coreMissingItems);
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
.mirasai-dashboard-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: .5rem;
    margin-bottom: 1rem;
}
.mirasai-dashboard-tabs .btn.active {
    background: #212529;
    border-color: #212529;
    color: #fff;
}
.mirasai-dashboard-panel {
    display: none;
}
.mirasai-dashboard-panel.active {
    display: block;
}
.mirasai-onboarding-toolbar {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: .75rem;
    margin-bottom: 1rem;
}
.mirasai-onboarding-checklist {
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: .375rem;
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
}
.mirasai-onboarding-steps {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: .5rem;
    margin-top: .75rem;
}
.mirasai-onboarding-step {
    border: 1px solid #dee2e6;
    border-radius: .375rem;
    padding: .65rem .75rem;
    min-height: 6.25rem;
}
.mirasai-onboarding-step-title {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: .75rem;
    margin-bottom: .25rem;
    font-weight: 600;
}
.mirasai-onboarding-step-kicker {
    color: var(--mirasai-secondary);
    font-size: .75rem;
    font-weight: 600;
    margin-bottom: .15rem;
    text-transform: uppercase;
}
.mirasai-onboarding-step-detail {
    color: var(--mirasai-secondary);
    font-size: .8rem;
    overflow-wrap: anywhere;
}
.mirasai-onboarding-step-action {
    display: inline-block;
    font-size: .8rem;
    font-weight: 600;
}
.mirasai-onboarding-step-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .75rem;
    margin-top: .45rem;
}
.mirasai-onboarding-step-info {
    font-size: .8rem;
    font-weight: 600;
}
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
.mirasai-smoke {
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: .375rem;
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
}
.mirasai-smoke-form {
    display: grid;
    grid-template-columns: minmax(220px, 1fr) auto auto;
    gap: .5rem;
    align-items: end;
    margin: .75rem 0 1rem;
}
.mirasai-smoke-checks {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: .5rem;
}
.mirasai-smoke-check {
    border: 1px solid #dee2e6;
    border-radius: .375rem;
    padding: .65rem .75rem;
    min-height: 4.5rem;
}
.mirasai-smoke-check-title {
    display: flex;
    justify-content: space-between;
    gap: .75rem;
    align-items: center;
    margin-bottom: .25rem;
    font-weight: 600;
}
.mirasai-smoke-detail {
    color: var(--mirasai-secondary);
    font-size: .8rem;
    overflow-wrap: anywhere;
}
.mirasai-smoke-result {
    display: none;
    margin-top: 1rem;
}
.mirasai-smoke-result.active {
    display: block;
}
.mirasai-smoke-result pre {
    margin: .5rem 0 0;
    white-space: pre-wrap;
    word-break: break-word;
}
@media (max-width: 767.98px) {
    .mirasai-smoke-form {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="mirasai-dashboard" data-mirasai-dashboard>
    <div class="mirasai-dashboard-tabs" role="tablist" aria-label="<?php echo Text::_('COM_MIRASAI_DASHBOARD_TABS_LABEL'); ?>">
        <button
            type="button"
            class="btn btn-sm btn-outline-dark active"
            role="tab"
            data-mirasai-dashboard-tab="onboarding"
            aria-controls="mirasai-panel-onboarding"
            aria-selected="true"
        >
            <?php echo Text::_('COM_MIRASAI_DASHBOARD_TAB_ONBOARDING'); ?>
        </button>
        <button
            type="button"
            class="btn btn-sm btn-outline-dark"
            role="tab"
            data-mirasai-dashboard-tab="status"
            aria-controls="mirasai-panel-status"
            aria-selected="false"
        >
            <?php echo Text::_('COM_MIRASAI_DASHBOARD_TAB_STATUS'); ?>
        </button>
    </div>

<div id="mirasai-panel-onboarding" class="mirasai-dashboard-panel active" data-mirasai-dashboard-panel="onboarding" role="tabpanel">
    <div class="mirasai-onboarding-toolbar">
        <div>
            <h2 class="h4 mb-1"><?php echo Text::_('COM_MIRASAI_ONBOARDING_PAGE_TITLE'); ?></h2>
            <p class="text-muted small mb-0"><?php echo Text::_('COM_MIRASAI_ONBOARDING_PAGE_DESC'); ?></p>
        </div>
        <button type="button" class="btn btn-sm btn-success" data-mirasai-onboarding-complete>
            <?php echo Text::_('COM_MIRASAI_ONBOARDING_COMPLETE'); ?>
        </button>
    </div>

<section class="mirasai-onboarding-checklist" aria-labelledby="mirasai-onboarding-checklist-title">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
            <h3 id="mirasai-onboarding-checklist-title" class="h5 mb-1"><?php echo Text::_('COM_MIRASAI_ONBOARDING_CHECKLIST_TITLE'); ?></h3>
            <p class="text-muted small mb-0"><?php echo Text::_('COM_MIRASAI_ONBOARDING_CHECKLIST_DESC'); ?></p>
        </div>
    </div>
    <div class="mirasai-onboarding-steps" aria-live="polite">
        <?php
        $onboardingSteps = [
            [
                'id' => 'core',
                'label' => 'COM_MIRASAI_ONBOARDING_CHECK_CORE',
                'state' => $this->allCoreEnabled && $registryReady ? 'passed' : 'failed',
                'detail' => $coreOnboardingDetail,
                'action_label' => 'COM_MIRASAI_ONBOARDING_ACTION_CORE',
                'action_href' => $pluginsUrl,
                'info' => Text::_('COM_MIRASAI_ONBOARDING_INFO_CORE'),
            ],
            [
                'id' => 'token',
                'label' => 'COM_MIRASAI_ONBOARDING_CHECK_TOKEN',
                'state' => 'pending',
                'detail' => Text::_('COM_MIRASAI_ONBOARDING_CHECK_TOKEN_WAITING'),
                'action_label' => 'COM_MIRASAI_ONBOARDING_ACTION_TOKEN',
                'action_href' => $usersUrl,
                'info' => Text::_('COM_MIRASAI_ONBOARDING_INFO_TOKEN'),
            ],
            [
                'id' => 'endpoint',
                'label' => 'COM_MIRASAI_ONBOARDING_CHECK_ENDPOINT',
                'state' => 'pending',
                'detail' => Text::_('COM_MIRASAI_ONBOARDING_CHECK_ENDPOINT_WAITING'),
                'action_label' => 'COM_MIRASAI_ONBOARDING_ACTION_VALIDATE',
                'action_href' => '#mirasai-smoke-test',
                'info' => Text::_('COM_MIRASAI_ONBOARDING_INFO_ENDPOINT'),
            ],
            [
                'id' => 'tools',
                'label' => 'COM_MIRASAI_ONBOARDING_CHECK_TOOLS',
                'state' => 'pending',
                'detail' => Text::_('COM_MIRASAI_ONBOARDING_CHECK_TOOLS_WAITING'),
                'action_label' => 'COM_MIRASAI_ONBOARDING_ACTION_VALIDATE',
                'action_href' => '#mirasai-smoke-test',
                'info' => Text::_('COM_MIRASAI_ONBOARDING_INFO_TOOLS'),
            ],
            [
                'id' => 'diagnose',
                'label' => 'COM_MIRASAI_ONBOARDING_CHECK_DIAGNOSE',
                'state' => 'pending',
                'detail' => Text::_('COM_MIRASAI_ONBOARDING_CHECK_DIAGNOSE_WAITING'),
                'action_label' => 'COM_MIRASAI_ONBOARDING_ACTION_VALIDATE',
                'action_href' => '#mirasai-smoke-test',
                'info' => Text::_('COM_MIRASAI_ONBOARDING_INFO_DIAGNOSE'),
            ],
            [
                'id' => 'config',
                'label' => 'COM_MIRASAI_ONBOARDING_CHECK_CONFIG',
                'state' => 'pending',
                'detail' => Text::_('COM_MIRASAI_ONBOARDING_CHECK_CONFIG_WAITING'),
                'action_label' => 'COM_MIRASAI_ONBOARDING_ACTION_CONFIG',
                'action_href' => '#mirasai-client-config',
                'info' => Text::_('COM_MIRASAI_ONBOARDING_INFO_CONFIG'),
            ],
        ];
        $onboardingBadgeClasses = [
            'pending' => 'bg-secondary',
            'running' => 'bg-info text-dark',
            'passed' => 'bg-success',
            'failed' => 'bg-danger',
        ];
        $onboardingStateLabels = [
            'pending' => 'COM_MIRASAI_ONBOARDING_STATE_PENDING',
            'running' => 'COM_MIRASAI_ONBOARDING_STATE_RUNNING',
            'passed' => 'COM_MIRASAI_ONBOARDING_STATE_PASSED',
            'failed' => 'COM_MIRASAI_ONBOARDING_STATE_FAILED',
        ];
        ?>
        <?php foreach ($onboardingSteps as $stepIndex => $step): ?>
            <div class="mirasai-onboarding-step" data-mirasai-onboarding-step="<?php echo htmlspecialchars($step['id']); ?>">
                <div class="mirasai-onboarding-step-kicker">
                    <?php echo sprintf(Text::_('COM_MIRASAI_ONBOARDING_STEP_LABEL'), $stepIndex + 1); ?>
                </div>
                <div class="mirasai-onboarding-step-title">
                    <span><?php echo Text::_($step['label']); ?></span>
                    <span class="badge <?php echo htmlspecialchars($onboardingBadgeClasses[$step['state']]); ?>" data-mirasai-onboarding-state>
                        <?php echo Text::_($onboardingStateLabels[$step['state']]); ?>
                    </span>
                </div>
                <div class="mirasai-onboarding-step-detail" data-mirasai-onboarding-detail>
                    <?php echo htmlspecialchars((string) $step['detail']); ?>
                </div>
                <div class="mirasai-onboarding-step-actions">
                    <?php if (!empty($step['action_label']) && !empty($step['action_href'])): ?>
                        <a class="mirasai-onboarding-step-action" href="<?php echo htmlspecialchars((string) $step['action_href']); ?>">
                            <?php echo Text::_((string) $step['action_label']); ?>
                        </a>
                    <?php endif; ?>
                    <button
                        type="button"
                        class="btn btn-sm btn-link p-0 mirasai-onboarding-step-info"
                        data-mirasai-onboarding-info
                        data-mirasai-onboarding-info-title="<?php echo htmlspecialchars(Text::_($step['label']), ENT_QUOTES); ?>"
                        data-mirasai-onboarding-info-body="<?php echo htmlspecialchars((string) $step['info'], ENT_QUOTES); ?>"
                    >
                        <?php echo Text::_('COM_MIRASAI_ONBOARDING_MORE_INFO'); ?>
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<div class="modal fade" id="mirasai-onboarding-info-modal" tabindex="-1" aria-labelledby="mirasai-onboarding-info-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="mirasai-onboarding-info-title"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" data-mirasai-onboarding-info-close aria-label="<?php echo Text::_('JCLOSE'); ?>"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0" id="mirasai-onboarding-info-body"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal" data-mirasai-onboarding-info-close>
                    <?php echo Text::_('JCLOSE'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<?php
$clientConfigs = [
    'claude-code' => [
        'label' => Text::_('COM_MIRASAI_CLIENT_CLAUDE_CODE'),
        'helper' => Text::_('COM_MIRASAI_CLIENT_HELPER_CLAUDE_CODE'),
        'code' => "claude mcp add --transport http " . $serverName . " " . $endpoint . " \\\n  --header \"X-Joomla-Token: YOUR_TOKEN\"",
    ],
    'codex' => [
        'label' => Text::_('COM_MIRASAI_CLIENT_CODEX'),
        'helper' => Text::_('COM_MIRASAI_CLIENT_HELPER_CODEX'),
        'code' => "[mcp_servers." . addslashes($serverName) . "]\nurl = \"" . addslashes($endpoint) . "\"\n\n[mcp_servers." . addslashes($serverName) . ".http_headers]\nX-Joomla-Token = \"YOUR_TOKEN\"",
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
    'mcp2cli' => [
        'label' => Text::_('COM_MIRASAI_CLIENT_MCP2CLI'),
        'helper' => Text::_('COM_MIRASAI_CLIENT_HELPER_MCP2CLI'),
        'code' => "mcp2cli --mcp " . $endpoint . " \\\n  --transport streamable \\\n  --auth-header \"X-Joomla-Token:YOUR_TOKEN\" \\\n  --list",
    ],
];
?>

<details id="mirasai-smoke-test" class="mirasai-smoke mirasai-config-toggle mb-4" open data-mirasai-smoke-endpoint="<?php echo htmlspecialchars($endpoint); ?>">
    <summary class="fw-bold"><?php echo Text::_('COM_MIRASAI_SMOKE_TITLE'); ?></summary>
    <p class="text-muted small mb-2"><?php echo Text::_('COM_MIRASAI_SMOKE_DESC'); ?></p>
    <div class="mirasai-smoke-form">
        <div>
            <label for="mirasai-smoke-token" class="form-label small fw-bold mb-1"><?php echo Text::_('COM_MIRASAI_SMOKE_TOKEN_LABEL'); ?></label>
            <input
                type="password"
                id="mirasai-smoke-token"
                class="form-control form-control-sm"
                autocomplete="off"
                spellcheck="false"
                placeholder="<?php echo htmlspecialchars(Text::_('COM_MIRASAI_SMOKE_TOKEN_PLACEHOLDER')); ?>"
            >
        </div>
        <button type="button" class="btn btn-sm btn-primary" id="mirasai-smoke-run">
            <?php echo Text::_('COM_MIRASAI_SMOKE_RUN'); ?>
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="mirasai-smoke-clear">
            <?php echo Text::_('COM_MIRASAI_SMOKE_CLEAR'); ?>
        </button>
    </div>
    <p class="text-muted small mb-3"><?php echo Text::_('COM_MIRASAI_SMOKE_PRIVACY'); ?></p>
    <div class="mirasai-smoke-checks" aria-live="polite">
        <?php
        $smokeChecks = [
            'endpoint' => 'COM_MIRASAI_SMOKE_CHECK_ENDPOINT',
            'auth' => 'COM_MIRASAI_SMOKE_CHECK_AUTH',
            'tools' => 'COM_MIRASAI_SMOKE_CHECK_TOOLS',
            'diagnose' => 'COM_MIRASAI_SMOKE_CHECK_DIAGNOSE',
            'annotations' => 'COM_MIRASAI_SMOKE_CHECK_ANNOTATIONS',
            'structured' => 'COM_MIRASAI_SMOKE_CHECK_STRUCTURED',
        ];
        ?>
        <?php foreach ($smokeChecks as $checkId => $label): ?>
            <div class="mirasai-smoke-check" data-mirasai-smoke-check="<?php echo htmlspecialchars($checkId); ?>">
                <div class="mirasai-smoke-check-title">
                    <span><?php echo Text::_($label); ?></span>
                    <span class="badge bg-secondary" data-mirasai-smoke-state><?php echo Text::_('COM_MIRASAI_SMOKE_STATE_PENDING'); ?></span>
                </div>
                <div class="mirasai-smoke-detail" data-mirasai-smoke-detail><?php echo Text::_('COM_MIRASAI_SMOKE_WAITING'); ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <div id="mirasai-smoke-result" class="mirasai-smoke-result">
        <strong class="small"><?php echo Text::_('COM_MIRASAI_SMOKE_RAW_SUMMARY'); ?></strong>
        <pre class="bg-light p-3 rounded"><code></code></pre>
    </div>
</details>

<details id="mirasai-client-config" class="mirasai-client-config mirasai-config-toggle mb-4" open>
    <summary class="fw-bold"><?php echo Text::_('COM_MIRASAI_CLIENT_CONNECT_TITLE'); ?></summary>
    <p class="text-muted small mb-2"><?php echo Text::_('COM_MIRASAI_CLIENT_CONNECT_DESC'); ?></p>
    <div class="form-check form-switch mb-2">
        <input class="form-check-input" type="checkbox" role="switch" id="mirasai-client-use-token">
        <label class="form-check-label small" for="mirasai-client-use-token">
            <?php echo Text::_('COM_MIRASAI_CLIENT_USE_TOKEN'); ?>
        </label>
        <div class="text-muted small"><?php echo Text::_('COM_MIRASAI_CLIENT_USE_TOKEN_HELP'); ?></div>
    </div>
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
            <pre class="bg-dark text-light p-3 rounded"><code data-mirasai-token-template><?php echo htmlspecialchars($clientConfig['code']); ?></code></pre>
        </div>
        <?php $clientIndex++; ?>
    <?php endforeach; ?>
    <p class="text-muted small mt-2 mb-0"><?php echo Text::_('COM_MIRASAI_CLIENT_NOTE'); ?></p>
</details>

<details class="mirasai-config-toggle mb-4">
    <summary class="fw-bold small text-muted">
        <?php echo Text::_('COM_MIRASAI_CURL_SHOW'); ?>
    </summary>
    <div class="mirasai-config-toolbar mt-2">
        <span class="text-muted small"><?php echo Text::_('COM_MIRASAI_CURL_NOTE'); ?></span>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-mirasai-copy-curl>
            <?php echo Text::_('COM_MIRASAI_COPY'); ?>
        </button>
    </div>
    <pre class="bg-light p-3 rounded"><code id="mirasai-curl-example" data-mirasai-token-template>curl -X POST <?php echo htmlspecialchars($endpoint); ?> \
  -H "Content-Type: application/json" \
  -H "X-Joomla-Token: YOUR_TOKEN" \
  -d '{"jsonrpc":"2.0","method":"tools/list","params":{},"id":1}'</code></pre>
</details>
</div>

<div id="mirasai-panel-status" class="mirasai-dashboard-panel" data-mirasai-dashboard-panel="status" role="tabpanel">
    <div class="mirasai-onboarding-toolbar">
        <div>
            <h2 class="h4 mb-1"><?php echo Text::_('COM_MIRASAI_STATUS_PAGE_TITLE'); ?></h2>
            <p class="text-muted small mb-0"><?php echo Text::_('COM_MIRASAI_STATUS_PAGE_DESC'); ?></p>
        </div>
        <button type="button" class="btn btn-sm btn-outline-primary" data-mirasai-onboarding-restart>
            <?php echo Text::_('COM_MIRASAI_ONBOARDING_RESTART'); ?>
        </button>
    </div>

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
                                <?php if (!empty($group['capability_note'])): ?>
                                    <div class="alert alert-info mb-3">
                                        <?php echo htmlspecialchars((string) $group['capability_note']); ?>
                                    </div>
                                <?php endif; ?>
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
                                                    <?php
                                                        $riskLevel = (string) ($tool['risk_level'] ?? 'read');
                                                        $riskClass = $riskBadgeClasses[$riskLevel] ?? 'bg-secondary';
                                                        $riskLabel = $riskLabels[$riskLevel] ?? 'COM_MIRASAI_RISK_UNKNOWN';
                                                    ?>
                                                    <span class="badge <?php echo htmlspecialchars($riskClass); ?>">
                                                        <?php echo Text::_($riskLabel); ?>
                                                    </span>
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
</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var smokeText = <?php echo json_encode($smokeText, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    var onboardingText = <?php echo json_encode($onboardingText, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    var onboardingCompleteKey = 'mirasai_onboarding_complete';
    var dashboardTabs = document.querySelectorAll('[data-mirasai-dashboard-tab]');
    var dashboardPanels = document.querySelectorAll('[data-mirasai-dashboard-panel]');
    var onboardingCompleteButton = document.querySelector('[data-mirasai-onboarding-complete]');
    var onboardingRestartButton = document.querySelector('[data-mirasai-onboarding-restart]');
    var onboardingInfoModal = document.getElementById('mirasai-onboarding-info-modal');
    var onboardingInfoTitle = document.getElementById('mirasai-onboarding-info-title');
    var onboardingInfoBody = document.getElementById('mirasai-onboarding-info-body');

    function setDashboardView(view) {
        dashboardTabs.forEach(function(tab) {
            var active = tab.getAttribute('data-mirasai-dashboard-tab') === view;
            tab.classList.toggle('active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });

        dashboardPanels.forEach(function(panel) {
            panel.classList.toggle('active', panel.getAttribute('data-mirasai-dashboard-panel') === view);
        });
    }

    function hashTargetsOnboarding() {
        if (!window.location.hash) {
            return false;
        }

        try {
            var target = document.querySelector(window.location.hash);
            return !!(target && target.closest('[data-mirasai-dashboard-panel="onboarding"]'));
        } catch (error) {
            return false;
        }
    }

    function isOnboardingComplete() {
        try {
            return localStorage.getItem(onboardingCompleteKey) === '1';
        } catch (error) {
            return false;
        }
    }

    dashboardTabs.forEach(function(tab) {
        tab.addEventListener('click', function() {
            setDashboardView(tab.getAttribute('data-mirasai-dashboard-tab') || 'onboarding');
        });
    });

    if (onboardingCompleteButton) {
        onboardingCompleteButton.addEventListener('click', function() {
            try {
                localStorage.setItem(onboardingCompleteKey, '1');
            } catch (error) {}
            setDashboardView('status');
        });
    }

    if (onboardingRestartButton) {
        onboardingRestartButton.addEventListener('click', function() {
            try {
                localStorage.removeItem(onboardingCompleteKey);
            } catch (error) {}
            setDashboardView('onboarding');
        });
    }

    setDashboardView(isOnboardingComplete() && !hashTargetsOnboarding() ? 'status' : 'onboarding');

    function showOnboardingInfo(title, body) {
        if (!onboardingInfoModal || !onboardingInfoTitle || !onboardingInfoBody) {
            return;
        }

        onboardingInfoTitle.textContent = title || '';
        onboardingInfoBody.textContent = body || '';

        if (window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(onboardingInfoModal).show();
            return;
        }

        onboardingInfoModal.style.display = 'block';
        onboardingInfoModal.classList.add('show');
        onboardingInfoModal.removeAttribute('aria-hidden');
    }

    function hideOnboardingInfo() {
        if (!onboardingInfoModal) {
            return;
        }

        if (window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(onboardingInfoModal).hide();
            return;
        }

        onboardingInfoModal.classList.remove('show');
        onboardingInfoModal.style.display = 'none';
        onboardingInfoModal.setAttribute('aria-hidden', 'true');
    }

    document.querySelectorAll('[data-mirasai-onboarding-info]').forEach(function(button) {
        button.addEventListener('click', function() {
            showOnboardingInfo(
                button.getAttribute('data-mirasai-onboarding-info-title') || '',
                button.getAttribute('data-mirasai-onboarding-info-body') || ''
            );
        });
    });

    document.querySelectorAll('[data-mirasai-onboarding-info-close]').forEach(function(button) {
        button.addEventListener('click', hideOnboardingInfo);
    });

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

    function setOnboardingStep(name, state, detail) {
        var step = document.querySelector('[data-mirasai-onboarding-step="' + name + '"]');
        if (!step) {
            return;
        }

        var badge = step.querySelector('[data-mirasai-onboarding-state]');
        var detailEl = step.querySelector('[data-mirasai-onboarding-detail]');
        var badgeClass = 'bg-secondary';

        if (state === 'running') {
            badgeClass = 'bg-info text-dark';
        } else if (state === 'passed') {
            badgeClass = 'bg-success';
        } else if (state === 'failed') {
            badgeClass = 'bg-danger';
        }

        if (badge) {
            badge.className = 'badge ' + badgeClass;
            badge.textContent = onboardingText[state] || state;
        }

        if (detailEl) {
            detailEl.textContent = detail || '';
        }
    }

    function resetOnboardingSmokeSteps() {
        setOnboardingStep('endpoint', 'pending', onboardingText.endpointWaiting);
        setOnboardingStep('token', 'pending', onboardingText.tokenWaiting);
        setOnboardingStep('tools', 'pending', onboardingText.toolsWaiting);
        setOnboardingStep('diagnose', 'pending', onboardingText.diagnoseWaiting);
    }

    function copyTextToClipboard(text) {
        function fallbackCopy() {
            return new Promise(function(resolve, reject) {
                var textarea = document.createElement('textarea');
                textarea.value = text;
                textarea.setAttribute('readonly', 'readonly');
                textarea.style.position = 'fixed';
                textarea.style.left = '-9999px';
                document.body.appendChild(textarea);
                textarea.select();

                try {
                    if (document.execCommand('copy')) {
                        resolve();
                    } else {
                        reject(new Error('copy command failed'));
                    }
                } catch (error) {
                    reject(error);
                } finally {
                    document.body.removeChild(textarea);
                }
            });
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text).catch(fallbackCopy);
        }

        return fallbackCopy();
    }

    function flashCopyButton(button) {
        button.textContent = '<?php echo addslashes(Text::_('COM_MIRASAI_COPIED')); ?>';
        setTimeout(function() {
            button.textContent = '<?php echo addslashes(Text::_('COM_MIRASAI_COPY')); ?>';
        }, 1500);
    }

    function markClientConfigCopied(target) {
        var activeTab = document.querySelector('[data-mirasai-client-tab="' + target + '"]');
        var label = activeTab ? (activeTab.textContent || target).trim() : target;
        setOnboardingStep('config', 'passed', onboardingText.configOk.replace('%s', label));
    }

    var clientTabs = document.querySelectorAll('[data-mirasai-client-tab]');
    var clientPanels = document.querySelectorAll('[data-mirasai-client-panel]');
    var tokenTemplateNodes = document.querySelectorAll('[data-mirasai-token-template]');
    var useTokenInSnippets = document.getElementById('mirasai-client-use-token');

    tokenTemplateNodes.forEach(function(node) {
        node.setAttribute('data-mirasai-original-template', node.textContent || '');
    });

    function updateTokenizedSnippets() {
        var token = smokeToken ? (smokeToken.value || '').trim() : '';
        var useToken = !!(useTokenInSnippets && useTokenInSnippets.checked && token);

        tokenTemplateNodes.forEach(function(node) {
            var template = node.getAttribute('data-mirasai-original-template') || '';
            node.textContent = useToken ? template.split('YOUR_TOKEN').join(token) : template;
        });
    }

    if (useTokenInSnippets) {
        useTokenInSnippets.addEventListener('change', updateTokenizedSnippets);
    }

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
            if (!panel) {
                return;
            }

            markClientConfigCopied(target);
            copyTextToClipboard(panel.textContent || '').then(function() {
                flashCopyButton(button);
            }).catch(function() {});
        });
    });

    var curlCopyButton = document.querySelector('[data-mirasai-copy-curl]');
    if (curlCopyButton) {
        curlCopyButton.addEventListener('click', function() {
            var curlCode = document.getElementById('mirasai-curl-example');
            if (!curlCode) {
                return;
            }

            copyTextToClipboard(curlCode.textContent || '').then(function() {
                flashCopyButton(curlCopyButton);
            }).catch(function() {});
        });
    }

    var smokePanel = document.getElementById('mirasai-smoke-test');
    var smokeRun = document.getElementById('mirasai-smoke-run');
    var smokeClear = document.getElementById('mirasai-smoke-clear');
    var smokeToken = document.getElementById('mirasai-smoke-token');
    var smokeResult = document.getElementById('mirasai-smoke-result');

    if (smokeToken) {
        smokeToken.addEventListener('input', updateTokenizedSnippets);
    }

    function setSmokeCheck(name, state, detail) {
        var check = document.querySelector('[data-mirasai-smoke-check="' + name + '"]');
        if (!check) {
            return;
        }

        var badge = check.querySelector('[data-mirasai-smoke-state]');
        var detailEl = check.querySelector('[data-mirasai-smoke-detail]');
        var badgeClass = 'bg-secondary';

        if (state === 'running') {
            badgeClass = 'bg-info text-dark';
        } else if (state === 'passed') {
            badgeClass = 'bg-success';
        } else if (state === 'failed') {
            badgeClass = 'bg-danger';
        } else if (state === 'warning') {
            badgeClass = 'bg-warning text-dark';
        }

        if (badge) {
            badge.className = 'badge ' + badgeClass;
            badge.textContent = smokeText[state] || state;
        }

        if (detailEl) {
            detailEl.textContent = detail || '';
        }
    }

    function resetSmokeChecks() {
        ['endpoint', 'auth', 'tools', 'diagnose', 'annotations', 'structured'].forEach(function(name) {
            setSmokeCheck(name, 'pending', '<?php echo addslashes(Text::_('COM_MIRASAI_SMOKE_WAITING')); ?>');
        });
        resetOnboardingSmokeSteps();

        if (smokeResult) {
            smokeResult.classList.remove('active');
            var code = smokeResult.querySelector('code');
            if (code) {
                code.textContent = '';
            }
        }
    }

    function showSmokeSummary(summary) {
        if (!smokeResult) {
            return;
        }

        var code = smokeResult.querySelector('code');
        if (code) {
            code.textContent = JSON.stringify(summary, null, 2);
        }

        smokeResult.classList.add('active');
    }

    function httpErrorMessage(status) {
        if (status === 401) {
            return smokeText.http401;
        }
        if (status === 403) {
            return smokeText.http403;
        }
        if (status === 404) {
            return smokeText.http404;
        }

        return smokeText.httpOther.replace('%s', String(status));
    }

    async function callMcp(endpoint, token, method, params, id) {
        var response = await fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Joomla-Token': token
            },
            body: JSON.stringify({
                jsonrpc: '2.0',
                method: method,
                params: params || {},
                id: id
            })
        });
        var text = await response.text();
        var json = null;

        try {
            json = text ? JSON.parse(text) : null;
        } catch (e) {
            json = null;
        }

        return {
            ok: response.ok,
            status: response.status,
            json: json,
            text: text
        };
    }

    function assertMcpResponse(result) {
        if (!result.ok) {
            throw new Error(httpErrorMessage(result.status));
        }

        if (!result.json || typeof result.json !== 'object') {
            throw new Error('<?php echo addslashes(Text::_('COM_MIRASAI_SMOKE_ERROR_INVALID_JSON')); ?>');
        }

        if (result.json.error) {
            var message = result.json.error.message || JSON.stringify(result.json.error);
            throw new Error(smokeText.jsonRpcError.replace('%s', message));
        }

        return result.json.result || {};
    }

    async function runSmokeTest() {
        if (!smokePanel || !smokeToken || !smokeRun) {
            return;
        }

        var endpoint = smokePanel.getAttribute('data-mirasai-smoke-endpoint') || '';
        var token = (smokeToken.value || '').trim();

        resetSmokeChecks();

        if (!token) {
            setSmokeCheck('auth', 'failed', smokeText.tokenRequired);
            setOnboardingStep('token', 'failed', smokeText.tokenRequired);
            smokeToken.focus();
            return;
        }

        smokeRun.disabled = true;
        smokeRun.textContent = smokeText.running;

        var summary = {
            endpoint: endpoint,
            initialized: false,
            essential_tool_count: 0,
            diagnose_status: null,
            mirasai_version: null,
            annotations: false,
            structuredContent: false
        };

        try {
            setSmokeCheck('endpoint', 'running', smokeText.running);
            setSmokeCheck('auth', 'running', smokeText.running);
            setOnboardingStep('endpoint', 'running', smokeText.running);
            setOnboardingStep('token', 'running', smokeText.running);

            var initialize = assertMcpResponse(await callMcp(endpoint, token, 'initialize', {
                protocolVersion: '2024-11-05',
                capabilities: {},
                clientInfo: {
                    name: 'mirasai-dashboard',
                    version: '<?php echo addslashes($version); ?>'
                }
            }, 1));

            summary.initialized = !!initialize.serverInfo;
            setSmokeCheck('endpoint', 'passed', smokeText.endpointOk);
            setSmokeCheck('auth', 'passed', smokeText.authOk);
            setOnboardingStep('endpoint', 'passed', onboardingText.endpointOk);
            setOnboardingStep('token', 'passed', onboardingText.tokenOk);

            setSmokeCheck('tools', 'running', smokeText.running);
            setSmokeCheck('annotations', 'running', smokeText.running);
            setOnboardingStep('tools', 'running', smokeText.running);
            var toolsList = assertMcpResponse(await callMcp(endpoint, token, 'tools/list', {surface: 'essential'}, 2));
            var tools = Array.isArray(toolsList.tools) ? toolsList.tools : [];
            summary.essential_tool_count = tools.length;

            if (tools.length < 1) {
                throw new Error('<?php echo addslashes(Text::_('COM_MIRASAI_SMOKE_ERROR_NO_TOOLS')); ?>');
            }

            setSmokeCheck('tools', 'passed', smokeText.toolsOk.replace('%d', String(tools.length)));
            setOnboardingStep('tools', 'passed', onboardingText.toolsOk.replace('%d', String(tools.length)));

            var annotatedTool = tools.find(function(tool) {
                return tool && tool.annotations && typeof tool.annotations.readOnlyHint === 'boolean';
            });
            summary.annotations = !!annotatedTool;

            if (!annotatedTool) {
                setSmokeCheck('annotations', 'failed', '<?php echo addslashes(Text::_('COM_MIRASAI_SMOKE_ERROR_NO_ANNOTATIONS')); ?>');
            } else {
                setSmokeCheck('annotations', 'passed', smokeText.annotationsOk);
            }

            setSmokeCheck('diagnose', 'running', smokeText.running);
            setSmokeCheck('structured', 'running', smokeText.running);
            setOnboardingStep('diagnose', 'running', smokeText.running);
            var diagnoseCall = assertMcpResponse(await callMcp(endpoint, token, 'tools/call', {
                name: 'system/diagnose',
                arguments: {}
            }, 3));

            summary.structuredContent = !!diagnoseCall.structuredContent;
            var diagnosePayload = diagnoseCall.structuredContent || null;

            if (!diagnosePayload && diagnoseCall.content && diagnoseCall.content[0] && diagnoseCall.content[0].text) {
                try {
                    diagnosePayload = JSON.parse(diagnoseCall.content[0].text);
                } catch (e) {
                    diagnosePayload = null;
                }
            }

            if (diagnoseCall.isError) {
                throw new Error((diagnosePayload && diagnosePayload.error) || '<?php echo addslashes(Text::_('COM_MIRASAI_SMOKE_ERROR_DIAGNOSE')); ?>');
            }

            summary.diagnose_status = diagnosePayload ? diagnosePayload.status : null;
            summary.mirasai_version = diagnosePayload ? diagnosePayload.mirasai_version : null;

            setSmokeCheck('diagnose', 'passed', smokeText.diagnoseOk.replace('%s', summary.diagnose_status || 'ok'));
            setOnboardingStep('diagnose', 'passed', onboardingText.diagnoseOk.replace('%s', summary.diagnose_status || 'ok'));

            if (summary.structuredContent) {
                setSmokeCheck('structured', 'passed', smokeText.structuredOk);
            } else {
                setSmokeCheck('structured', 'failed', '<?php echo addslashes(Text::_('COM_MIRASAI_SMOKE_ERROR_NO_STRUCTURED')); ?>');
            }

            showSmokeSummary(summary);
        } catch (error) {
            var message = error && error.message ? error.message : smokeText.networkError;

            ['endpoint', 'auth', 'tools', 'diagnose', 'annotations', 'structured'].forEach(function(name) {
                var check = document.querySelector('[data-mirasai-smoke-check="' + name + '"] [data-mirasai-smoke-state]');
                if (check && check.textContent === smokeText.running) {
                    setSmokeCheck(name, 'failed', message);
                }
            });

            ['endpoint', 'token', 'tools', 'diagnose'].forEach(function(name) {
                var step = document.querySelector('[data-mirasai-onboarding-step="' + name + '"] [data-mirasai-onboarding-state]');
                if (step && step.textContent === onboardingText.running) {
                    setOnboardingStep(name, 'failed', message);
                }
            });

            showSmokeSummary(Object.assign(summary, {error: message}));
        } finally {
            smokeRun.disabled = false;
            smokeRun.textContent = '<?php echo addslashes(Text::_('COM_MIRASAI_SMOKE_RUN')); ?>';
        }
    }

    if (smokeRun) {
        smokeRun.addEventListener('click', runSmokeTest);
    }

    if (smokeClear) {
        smokeClear.addEventListener('click', function() {
            if (smokeToken) {
                smokeToken.value = '';
            }
            if (useTokenInSnippets) {
                useTokenInSnippets.checked = false;
            }
            updateTokenizedSnippets();
            resetSmokeChecks();
            setOnboardingStep('config', 'pending', onboardingText.configWaiting);
        });
    }
});
</script>
