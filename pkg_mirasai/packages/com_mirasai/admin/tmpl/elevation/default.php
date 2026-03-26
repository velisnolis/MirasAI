<?php

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

/** @var \Mirasai\Component\Mirasai\Administrator\View\Elevation\HtmlView $this */

$grant = $this->activeGrant;
$activeTab = $this->activeTab;
$csrfToken = Session::getFormToken();
$toolScopes = $this::TOOL_SCOPES;

// Group tools by category
$grouped = [];
foreach ($toolScopes as $toolName => $info) {
    $grouped[$info['group']][$toolName] = $info;
}
?>

<style>
:root {
    --mirasai-elevation-active: #dc3545;
    --mirasai-elevation-warning: #ffc107;
    --mirasai-elevation-muted: #6c757d;
    --mirasai-elevation-expired: #adb5bd;
}
/* Force explicit colors so dark mode doesn't break contrast on light-bg banners */
.mirasai-status-banner { padding: 1rem; border-radius: .375rem; margin-bottom: 1.5rem; color: #212529; }
.mirasai-status-banner .text-muted { color: #6c757d !important; }
.mirasai-status-blocked { background: #f8f9fa; border: 2px solid var(--mirasai-elevation-muted); }
.mirasai-status-active { background: #fff3cd; border: 2px solid var(--mirasai-elevation-warning); }
.mirasai-status-expired { background: #f8f9fa; border: 2px dashed var(--mirasai-elevation-expired); }
.mirasai-countdown {
    font-size: 2rem; font-weight: 700; font-variant-numeric: tabular-nums;
    font-family: SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    color: #856404;
}
.mirasai-audit-new { animation: fadeHighlight 2s ease-out; }
@keyframes fadeHighlight { from { background: #fff3cd; } to { background: transparent; } }
.scope-group legend { font-size: .875rem; font-weight: 600; color: #495057; margin-bottom: .5rem; }
.scope-item { display: flex; align-items: baseline; gap: .75rem; padding: .25rem 0; }
.scope-item code { font-size: .8rem; color: #6c757d; min-width: 140px; }
.scope-item .text-muted { font-size: .8rem; }
</style>

<!-- Tabs — uses Joomla's HTMLHelper uitab which loads web component assets -->
<?php
$elevationLabel = Text::_('COM_MIRASAI_ELEVATION_TAB') . ($grant ? ' ●' : '');
$historyLabel = Text::_('COM_MIRASAI_ELEVATION_HISTORY_TAB') . ($this->historyTotal > 0 ? ' (' . $this->historyTotal . ')' : '');
$defaultTab = $activeTab === 'history' ? 'tab-history' : 'tab-elevation';
echo HTMLHelper::_('uitab.startTabSet', 'elevationTabs', ['active' => $defaultTab, 'recall' => true, 'breakpoint' => 768]);
echo HTMLHelper::_('uitab.addTab', 'elevationTabs', 'tab-elevation', $elevationLabel);
?>

<?php if ($grant): ?>
    <!-- ==================== ACTIVE STATE ==================== -->
    <div class="mirasai-status-banner mirasai-status-active" role="status" aria-live="polite">
        <div class="row align-items-center">
            <div class="col-lg-6">
                <span class="mirasai-countdown" role="timer" aria-live="polite"
                      aria-label="<?php echo Text::_('COM_MIRASAI_ELEVATION_TIME_REMAINING'); ?>"
                      id="mirasai-countdown"
                      data-remaining-seconds="<?php echo $grant->getRemainingSeconds(); ?>">
                    <?php
                    $secs = $grant->getRemainingSeconds();
                    echo sprintf('%d:%02d', intdiv($secs, 60), $secs % 60);
                    ?>
                </span>
                <span class="text-muted ms-2"><?php echo Text::_('COM_MIRASAI_ELEVATION_REMAINING'); ?></span>

                <div class="mt-2">
                    <?php foreach ($grant->scopes as $scope): ?>
                        <span class="badge bg-warning text-dark me-1"><?php echo htmlspecialchars($scope); ?></span>
                    <?php endforeach; ?>
                </div>
                <div class="text-muted small mt-1">
                    <?php echo htmlspecialchars($grant->reason); ?>
                </div>
            </div>
            <div class="col-lg-6 text-lg-end mt-3 mt-lg-0">
                <form method="post" action="<?php echo Route::_('index.php?option=com_mirasai&task=elevation.revoke'); ?>"
                      onsubmit="return confirm('<?php echo Text::_('COM_MIRASAI_ELEVATION_CONFIRM_REVOKE'); ?>');">
                    <input type="hidden" name="grant_id" value="<?php echo $grant->id; ?>">
                    <?php echo HTMLHelper::_('form.token'); ?>
                    <button type="submit" class="btn btn-danger btn-lg"
                            aria-label="<?php echo Text::_('COM_MIRASAI_ELEVATION_REVOKE_ARIA'); ?>">
                        <?php echo Text::_('COM_MIRASAI_ELEVATION_REVOKE_NOW'); ?>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Copyable snippet -->
    <div class="card mb-4">
        <div class="card-body">
            <label class="form-label fw-bold small"><?php echo Text::_('COM_MIRASAI_ELEVATION_SNIPPET_LABEL'); ?></label>
            <div class="input-group">
                <input type="text" class="form-control form-control-sm" readonly id="elevation-snippet"
                       value="<?php echo htmlspecialchars(Text::sprintf('COM_MIRASAI_ELEVATION_SNIPPET_VALUE', implode(', ', $grant->scopes), (int) ceil($grant->getRemainingSeconds() / 60))); ?>">
                <button class="btn btn-outline-secondary btn-sm" type="button"
                        onclick="navigator.clipboard.writeText(document.getElementById('elevation-snippet').value)">
                    <span class="icon-copy"></span> <?php echo Text::_('COM_MIRASAI_COPY'); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Live Audit Log -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title mb-0"><?php echo Text::_('COM_MIRASAI_ELEVATION_AUDIT_TITLE'); ?></h3>
            <span class="badge bg-secondary" id="mirasai-use-count"><?php echo Text::sprintf('COM_MIRASAI_ELEVATION_CALLS_COUNT', $grant->useCount); ?></span>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-sm" id="mirasai-audit-table">
                    <thead>
                        <tr>
                            <th scope="col" style="width:15%"><?php echo Text::_('COM_MIRASAI_ELEVATION_COL_TIME'); ?></th>
                            <th scope="col" style="width:15%"><?php echo Text::_('COM_MIRASAI_ELEVATION_COL_TOOL'); ?></th>
                            <th scope="col" style="width:55%"><?php echo Text::_('COM_MIRASAI_ELEVATION_COL_SUMMARY'); ?></th>
                            <th scope="col" style="width:15%"><?php echo Text::_('COM_MIRASAI_ELEVATION_COL_RESULT'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="mirasai-audit-body">
                        <?php if (empty($this->auditLog)): ?>
                            <tr id="mirasai-audit-empty">
                                <td colspan="4" class="text-center text-muted py-4">
                                    <?php echo Text::_('COM_MIRASAI_ELEVATION_AUDIT_EMPTY'); ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($this->auditLog as $entry):
                                $created = new \DateTimeImmutable($entry['created_at'], new \DateTimeZone('UTC'));
                                $ago = (int) ((new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->getTimestamp() - $created->getTimestamp());
                                $agoText = $ago < 60 ? $ago . 's ago' : intdiv($ago, 60) . ' min ago';

                                $summary = $entry['arguments_summary'];
                                $decoded = json_decode($summary, true);
                                if ($decoded) {
                                    $summary = $decoded['path'] ?? $decoded['first_line'] ?? $decoded['sql'] ?? $decoded['table'] ?? $summary;
                                }

                                $resultClass = match ($entry['result_summary']) {
                                    'success' => 'bg-success',
                                    'error' => 'bg-danger',
                                    default => 'bg-secondary',
                                };
                            ?>
                                <tr data-audit-id="<?php echo (int) $entry['id']; ?>">
                                    <td title="<?php echo htmlspecialchars($entry['created_at']); ?> UTC"><?php echo $agoText; ?></td>
                                    <td><span class="badge bg-dark"><?php echo htmlspecialchars($entry['tool_name']); ?></span></td>
                                    <td class="small"><?php echo htmlspecialchars($summary); ?></td>
                                    <td>
                                        <span class="badge audit-result <?php echo $resultClass; ?>"
                                              data-result="<?php echo htmlspecialchars($entry['result_summary']); ?>">
                                            <?php echo htmlspecialchars($entry['result_summary']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Countdown + Poll JS -->
    <script>
    (function() {
        const countdownEl = document.getElementById('mirasai-countdown');
        let remaining = parseInt(countdownEl.dataset.remainingSeconds, 10);
        let pollFailures = 0;
        let lastAnnounced = Math.floor(remaining / 60);

        // Countdown timer
        const timer = setInterval(function() {
            remaining--;
            if (remaining <= 0) {
                clearInterval(timer);
                countdownEl.textContent = '<?php echo Text::_('COM_MIRASAI_ELEVATION_EXPIRED'); ?>';
                countdownEl.closest('.mirasai-status-banner').className = 'mirasai-status-banner mirasai-status-expired';
                const revokeForm = document.querySelector('form[action*="elevation.revoke"]');
                if (revokeForm) revokeForm.style.display = 'none';

                // Show expiry message
                const msg = document.createElement('div');
                msg.className = 'alert alert-secondary mt-3';
                msg.textContent = '<?php echo Text::_('COM_MIRASAI_ELEVATION_EXPIRED_MSG'); ?>';
                countdownEl.closest('.mirasai-status-banner').after(msg);

                // Auto-redirect after 5s
                setTimeout(function() {
                    window.location.href = '<?php echo Route::_("index.php?option=com_mirasai&view=elevation", false); ?>';
                }, 5000);
                return;
            }

            const mins = Math.floor(remaining / 60);
            const secs = remaining % 60;
            countdownEl.textContent = mins + ':' + String(secs).padStart(2, '0');

            // Announce to screen readers every minute
            if (mins !== lastAnnounced) {
                lastAnnounced = mins;
                countdownEl.setAttribute('aria-label', mins + ' minutes remaining');
            }
        }, 1000);

        // Audit feed poll (every 30s)
        setInterval(function() {
            const url = '<?php echo Route::_("index.php?option=com_mirasai&task=elevation.auditfeed&format=json&grant_id=" . $grant->id, false); ?>';
            const formData = new FormData();
            formData.append('<?php echo $csrfToken; ?>', '1');

            fetch(url, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    pollFailures = 0;

                    // Resync countdown from server
                    if (typeof data.remaining_seconds === 'number') {
                        remaining = data.remaining_seconds;
                    }

                    // Update use count
                    if (typeof data.use_count === 'number') {
                        document.getElementById('mirasai-use-count').textContent = data.use_count + ' <?php echo Text::_('COM_MIRASAI_ELEVATION_CALLS_SUFFIX'); ?>';
                    }

                    // Check if expired server-side
                    if (data.is_active === false) {
                        window.location.href = '<?php echo Route::_("index.php?option=com_mirasai&view=elevation", false); ?>';
                        return;
                    }

                    // Update audit table
                    if (Array.isArray(data.audit)) {
                        renderAuditTable(data.audit);
                    }
                })
                .catch(function() {
                    pollFailures++;
                    if (pollFailures >= 3) {
                        document.getElementById('mirasai-use-count').innerHTML =
                            '<span class="text-warning"><?php echo Text::_('COM_MIRASAI_ELEVATION_CONNECTION_LOST'); ?></span>';
                    }
                });
        }, 30000);

        function renderAuditTable(entries) {
            const tbody = document.getElementById('mirasai-audit-body');
            const emptyRow = document.getElementById('mirasai-audit-empty');
            if (emptyRow) emptyRow.remove();

            const existingIds = new Set();
            tbody.querySelectorAll('tr[data-audit-id]').forEach(function(tr) {
                existingIds.add(tr.dataset.auditId);
            });

            entries.forEach(function(entry) {
                const id = String(entry.id);
                const existing = tbody.querySelector('tr[data-audit-id="' + id + '"]');

                if (existing) {
                    // Update result badge if changed
                    const badge = existing.querySelector('.audit-result');
                    if (badge && badge.dataset.result !== entry.result_summary) {
                        badge.className = 'badge audit-result ' + resultClass(entry.result_summary);
                        badge.textContent = entry.result_summary;
                        badge.dataset.result = entry.result_summary;
                    }
                } else {
                    // New row — prepend with highlight
                    const tr = document.createElement('tr');
                    tr.dataset.auditId = id;
                    tr.className = 'mirasai-audit-new';

                    const created = new Date(entry.created_at + 'Z');
                    const ago = timeAgo(created);

                    let summary = entry.arguments_summary;
                    try { summary = formatSummary(JSON.parse(summary)); } catch(e) {}

                    tr.innerHTML =
                        '<td title="' + created.toISOString() + '">' + ago + '</td>' +
                        '<td><span class="badge bg-dark">' + escHtml(entry.tool_name) + '</span></td>' +
                        '<td class="small">' + escHtml(summary) + '</td>' +
                        '<td><span class="badge audit-result ' + resultClass(entry.result_summary) + '" data-result="' + entry.result_summary + '">' + entry.result_summary + '</span></td>';

                    tbody.prepend(tr);
                }
            });
        }

        function resultClass(r) {
            if (r === 'success') return 'bg-success';
            if (r === 'error') return 'bg-danger';
            return 'bg-secondary';
        }

        function timeAgo(date) {
            const secs = Math.floor((Date.now() - date.getTime()) / 1000);
            if (secs < 60) return secs + 's ago';
            const mins = Math.floor(secs / 60);
            return mins + ' min ago';
        }

        function formatSummary(obj) {
            if (obj.path) return obj.path;
            if (obj.first_line) return obj.first_line + ' (' + obj.lines + ' lines)';
            if (obj.sql) return obj.sql;
            if (obj.table) return obj.table;
            return JSON.stringify(obj);
        }

        function escHtml(s) {
            const d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }
    })();
    </script>

<?php else: ?>
    <!-- ==================== INACTIVE STATE ==================== -->
    <div class="mirasai-status-banner mirasai-status-blocked" role="status" aria-live="polite">
        <strong><?php echo Text::_('COM_MIRASAI_ELEVATION_BLOCKED_TITLE'); ?></strong>
        <div class="text-muted small mt-1">
            <?php echo Text::_('COM_MIRASAI_ELEVATION_BLOCKED_DESC'); ?>
        </div>
    </div>

    <form method="post" action="<?php echo Route::_('index.php?option=com_mirasai&task=elevation.confirm'); ?>">
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title mb-0"><?php echo Text::_('COM_MIRASAI_ELEVATION_STEP1_TITLE'); ?></h3>
            </div>
            <div class="card-body">
                <!-- Tool scopes grouped -->
                <div class="mb-4">
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="select-all-scopes"
                               onchange="document.querySelectorAll('.scope-check').forEach(c => c.checked = this.checked)">
                        <label class="form-check-label fw-bold" for="select-all-scopes"><?php echo Text::_('COM_MIRASAI_ELEVATION_SELECT_ALL'); ?></label>
                    </div>

                    <?php foreach ($grouped as $groupName => $tools): ?>
                        <fieldset class="scope-group mb-3 ms-3">
                            <legend><?php echo htmlspecialchars(Text::_($groupName)); ?></legend>
                            <?php foreach ($tools as $toolName => $info): ?>
                                <div class="scope-item">
                                    <div class="form-check">
                                        <input class="form-check-input scope-check" type="checkbox"
                                               name="scopes[]" value="<?php echo htmlspecialchars($toolName); ?>"
                                               id="scope-<?php echo md5($toolName); ?>">
                                        <label class="form-check-label" for="scope-<?php echo md5($toolName); ?>">
                                            <?php echo htmlspecialchars(Text::_($info['label'])); ?>
                                        </label>
                                    </div>
                                    <code><?php echo htmlspecialchars($toolName); ?></code>
                                    <span class="text-muted"><?php echo htmlspecialchars(Text::_($info['description'])); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </fieldset>
                    <?php endforeach; ?>
                </div>

                <!-- Duration -->
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label for="elevation-duration" class="form-label fw-bold"><?php echo Text::_('COM_MIRASAI_ELEVATION_DURATION'); ?></label>
                        <select class="form-select" name="duration" id="elevation-duration">
                            <option value="15"><?php echo Text::_('COM_MIRASAI_ELEVATION_DURATION_15'); ?></option>
                            <option value="30"><?php echo Text::_('COM_MIRASAI_ELEVATION_DURATION_30'); ?></option>
                            <option value="60" selected><?php echo Text::_('COM_MIRASAI_ELEVATION_DURATION_60'); ?></option>
                            <option value="120"><?php echo Text::_('COM_MIRASAI_ELEVATION_DURATION_120'); ?></option>
                        </select>
                    </div>
                </div>

                <!-- Reason -->
                <div class="mb-3">
                    <label for="elevation-reason" class="form-label fw-bold">
                        <?php echo Text::_('COM_MIRASAI_ELEVATION_REASON'); ?> <span class="text-muted fw-normal"><?php echo Text::_('COM_MIRASAI_ELEVATION_REASON_HINT'); ?></span>
                    </label>
                    <textarea class="form-control" name="reason" id="elevation-reason"
                              rows="2" minlength="10" required
                              placeholder="<?php echo Text::_('COM_MIRASAI_ELEVATION_REASON_PLACEHOLDER'); ?>"
                    ></textarea>
                    <div class="form-text text-end">
                        <span id="reason-count">0</span> / 500
                    </div>
                </div>
            </div>
            <div class="card-footer">
                <?php echo HTMLHelper::_('form.token'); ?>
                <button type="submit" class="btn btn-outline-warning">
                    <?php echo Text::_('COM_MIRASAI_ELEVATION_CONTINUE'); ?>
                </button>
            </div>
        </div>
    </form>

    <script>
    (function() {
        const textarea = document.getElementById('elevation-reason');
        const counter = document.getElementById('reason-count');
        textarea.addEventListener('input', function() {
            counter.textContent = this.value.length;
        });
    })();
    </script>

<?php endif; ?>

<?php echo HTMLHelper::_('uitab.endTab'); ?>

<!-- ============================================================ -->
<!-- TAB: HISTORY                                                  -->
<!-- ============================================================ -->
<?php echo HTMLHelper::_('uitab.addTab', 'elevationTabs', 'tab-history', $historyLabel); ?>

    <?php if (empty($this->history)): ?>
        <div class="text-center text-muted py-5">
            <p class="fs-5"><?php echo Text::_('COM_MIRASAI_ELEVATION_HISTORY_EMPTY_TITLE'); ?></p>
            <p><?php echo Text::_('COM_MIRASAI_ELEVATION_HISTORY_EMPTY_DESC'); ?></p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-striped table-sm">
                <thead>
                    <tr>
                        <th scope="col"><?php echo Text::_('COM_MIRASAI_ELEVATION_HISTORY_DATE'); ?></th>
                        <th scope="col" class="d-none d-md-table-cell"><?php echo Text::_('COM_MIRASAI_ELEVATION_HISTORY_DURATION'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_MIRASAI_ELEVATION_HISTORY_SCOPES'); ?></th>
                        <th scope="col" class="d-none d-md-table-cell"><?php echo Text::_('COM_MIRASAI_ELEVATION_HISTORY_REASON'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_MIRASAI_ELEVATION_HISTORY_CALLS'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_MIRASAI_ELEVATION_HISTORY_OUTCOME'); ?></th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($this->history as $row): ?>
                        <?php
                        $scopes = json_decode($row['scopes_json'] ?? '[]', true) ?: [];
                        $isRevoked = !empty($row['revoked_at']);
                        $hasErrors = ($row['error_count'] ?? 0) > 0;

                        if ($hasErrors) {
                            $outcomeClass = 'bg-danger';
                            $outcomeText = Text::_('COM_MIRASAI_ELEVATION_OUTCOME_ERRORS');
                        } elseif ($isRevoked) {
                            $outcomeClass = 'bg-warning text-dark';
                            $outcomeText = Text::_('COM_MIRASAI_ELEVATION_OUTCOME_REVOKED');
                        } else {
                            $outcomeClass = 'bg-success';
                            $outcomeText = Text::_('COM_MIRASAI_ELEVATION_OUTCOME_EXPIRED');
                        }

                        $issued = new \DateTimeImmutable($row['issued_at'], new \DateTimeZone('UTC'));
                        $expires = new \DateTimeImmutable($row['expires_at'], new \DateTimeZone('UTC'));
                        $durationMins = (int) (($expires->getTimestamp() - $issued->getTimestamp()) / 60);
                        ?>
                        <tr data-grant-id="<?php echo (int) $row['id']; ?>" class="history-row" style="cursor:pointer">
                            <td title="<?php echo htmlspecialchars($row['issued_at']); ?> UTC">
                                <?php echo htmlspecialchars($issued->format('M j, H:i')); ?>
                            </td>
                            <td class="d-none d-md-table-cell"><?php echo $durationMins; ?>m</td>
                            <td>
                                <?php foreach ($scopes as $s): ?>
                                    <span class="badge bg-secondary me-1" style="font-size:.7rem"><?php echo htmlspecialchars($s); ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td class="d-none d-md-table-cell">
                                <?php echo htmlspecialchars(mb_substr($row['reason'] ?? '', 0, 80)); ?>
                                <?php if (mb_strlen($row['reason'] ?? '') > 80): ?>…<?php endif; ?>
                            </td>
                            <td><?php echo (int) $row['use_count']; ?></td>
                            <td><span class="badge <?php echo $outcomeClass; ?>"><?php echo $outcomeText; ?></span></td>
                            <td><span class="icon-chevron-down small text-muted history-toggle"></span></td>
                        </tr>
                        <tr class="history-detail" style="display:none" data-detail-for="<?php echo (int) $row['id']; ?>">
                            <td colspan="7" class="p-3" style="background: color-mix(in srgb, currentColor 8%, transparent);">
                                <strong><?php echo Text::_('COM_MIRASAI_ELEVATION_AUDIT_TITLE'); ?></strong> — <?php echo Text::sprintf('COM_MIRASAI_ELEVATION_CALLS_COUNT', (int) $row['total_calls']); ?>
                                <div class="mt-2" id="history-audit-<?php echo (int) $row['id']; ?>">
                                    <em class="text-muted"><?php echo Text::_('COM_MIRASAI_ELEVATION_CLICK_TO_LOAD'); ?></em>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($this->historyTotal > 20): ?>
            <nav>
                <?php
                $currentPage = (int) floor(Factory::getApplication()->getInput()->getInt('limitstart', 0) / 20) + 1;
                $totalPages = (int) ceil($this->historyTotal / 20);
                ?>
                <ul class="pagination pagination-sm justify-content-center">
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <li class="page-item <?php echo $p === $currentPage ? 'active' : ''; ?>">
                            <a class="page-link"
                               href="<?php echo Route::_('index.php?option=com_mirasai&view=elevation&tab=history&limitstart=' . (($p - 1) * 20)); ?>">
                                <?php echo $p; ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>

        <script>
        (function() {
            document.querySelectorAll('.history-row').forEach(function(row) {
                row.addEventListener('click', function() {
                    const grantId = this.dataset.grantId;
                    const detailRow = document.querySelector('tr[data-detail-for="' + grantId + '"]');
                    const container = document.getElementById('history-audit-' + grantId);
                    const toggle = this.querySelector('.history-toggle');

                    if (detailRow.style.display === 'none') {
                        detailRow.style.display = '';
                        toggle.className = 'icon-chevron-up small text-muted history-toggle';

                        // Load audit if not already loaded
                        if (container.querySelector('em')) {
                            const url = '<?php echo Route::_("index.php?option=com_mirasai&task=elevation.auditfeed&format=json&grant_id=", false); ?>' + grantId;
                            const formData = new FormData();
                            formData.append('<?php echo $csrfToken; ?>', '1');

                            fetch(url, { method: 'POST', body: formData })
                                .then(r => r.json())
                                .then(data => {
                                    if (!data.audit || data.audit.length === 0) {
                                        container.innerHTML = '<span class="text-muted">No tool calls during this session.</span>';
                                        return;
                                    }
                                    let html = '<table class="table table-sm table-borderless mb-0"><thead><tr><th>Time</th><th>Tool</th><th>Summary</th><th>Result</th></tr></thead><tbody>';
                                    data.audit.forEach(function(e) {
                                        let summary = e.arguments_summary;
                                        try { const obj = JSON.parse(summary); summary = obj.path || obj.first_line || obj.sql || obj.table || summary; } catch(ex) {}
                                        const rClass = e.result_summary === 'success' ? 'bg-success' : e.result_summary === 'error' ? 'bg-danger' : 'bg-secondary';
                                        html += '<tr><td class="small">' + escHtml(e.created_at) + '</td><td><span class="badge bg-dark">' + escHtml(e.tool_name) + '</span></td><td class="small">' + escHtml(summary) + '</td><td><span class="badge ' + rClass + '">' + e.result_summary + '</span></td></tr>';
                                    });
                                    html += '</tbody></table>';
                                    container.innerHTML = html;
                                })
                                .catch(function() {
                                    container.innerHTML = '<span class="text-danger">Failed to load audit log.</span>';
                                });
                        }
                    } else {
                        detailRow.style.display = 'none';
                        toggle.className = 'icon-chevron-down small text-muted history-toggle';
                    }
                });
            });

            function escHtml(s) {
                const d = document.createElement('div');
                d.textContent = s;
                return d.innerHTML;
            }
        })();
        </script>
    <?php endif; ?>

<?php echo HTMLHelper::_('uitab.endTab'); ?>
<?php echo HTMLHelper::_('uitab.endTabSet'); ?>
