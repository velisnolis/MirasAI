<?php

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var \Mirasai\Component\Mirasai\Administrator\View\Dashboard\HtmlView $this */

$info = $this->systemInfo;
$stats = $this->translationStats;
$endpoint = $this->mcpEndpoint;

$allEnabled = true;
foreach ($info['extensions'] as $ext) {
    if (!$ext['enabled']) {
        $allEnabled = false;
    }
}
?>

<div class="row">
    <!-- MCP Status Card -->
    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title mb-0">MCP Server</h3>
            </div>
            <div class="card-body">
                <div class="d-flex align-items-center mb-3">
                    <span class="badge bg-<?php echo $allEnabled ? 'success' : 'danger'; ?> fs-6">
                        <?php echo $allEnabled ? 'ACTIU' : 'INACTIU'; ?>
                    </span>
                    <span class="ms-2 text-muted">v<?php echo htmlspecialchars($info['mirasai_version']); ?></span>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Endpoint</label>
                    <div class="input-group">
                        <input type="text" class="form-control form-control-sm" value="<?php echo htmlspecialchars($endpoint); ?>" readonly id="mcp-endpoint">
                        <button class="btn btn-outline-secondary btn-sm" type="button" onclick="navigator.clipboard.writeText(document.getElementById('mcp-endpoint').value)">
                            <span class="icon-copy"></span>
                        </button>
                    </div>
                </div>

                <div class="mb-2">
                    <label class="form-label fw-bold">Auth</label>
                    <div class="text-muted small">X-Joomla-Token (API Token estàndard)</div>
                </div>
            </div>
        </div>
    </div>

    <!-- System Info Card -->
    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title mb-0">Sistema</h3>
            </div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        <tr>
                            <td class="fw-bold">Joomla</td>
                            <td><?php echo htmlspecialchars($info['joomla_version']); ?></td>
                        </tr>
                        <tr>
                            <td class="fw-bold">PHP</td>
                            <td><?php echo htmlspecialchars($info['php_version']); ?></td>
                        </tr>
                        <tr>
                            <td class="fw-bold">YOOtheme</td>
                            <td>
                                <?php if ($info['yootheme_version']): ?>
                                    <span class="badge bg-info"><?php echo htmlspecialchars($info['yootheme_version']); ?></span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">No instal·lat</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <hr>
                <h4 class="h6">Extensions MirasAI</h4>
                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        <?php foreach ($info['extensions'] as $ext): ?>
                        <tr>
                            <td class="small"><?php echo htmlspecialchars($ext['element']); ?> (<?php echo htmlspecialchars($ext['type']); ?><?php echo $ext['folder'] ? '/' . htmlspecialchars($ext['folder']) : ''; ?>)</td>
                            <td>
                                <span class="badge bg-<?php echo $ext['enabled'] ? 'success' : 'secondary'; ?>">
                                    <?php echo $ext['enabled'] ? 'ON' : 'OFF'; ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Translation Stats Card -->
    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title mb-0">Traduccions</h3>
            </div>
            <div class="card-body">
                <?php if (empty($stats)): ?>
                    <p class="text-muted">Sense articles</p>
                <?php else: ?>
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Idioma</th>
                                <th class="text-center">Articles</th>
                                <th class="text-center">YOOtheme</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats as $stat): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-primary"><?php echo htmlspecialchars($stat['language']); ?></span>
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

<!-- Available Tools Card -->
<div class="row">
    <div class="col-12">
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title mb-0">Tools MCP disponibles</h3>
            </div>
            <div class="card-body">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th style="width: 200px">Tool</th>
                            <th>Descripció</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>system/info</code></td>
                            <td>Informació del runtime: versió Joomla, PHP, idiomes, YOOtheme.</td>
                        </tr>
                        <tr>
                            <td><code>content/list</code></td>
                            <td>Llista articles amb idioma, detecció YOOtheme Builder, i associacions de traducció.</td>
                        </tr>
                        <tr>
                            <td><code>content/read</code></td>
                            <td>Llegeix un article amb el layout YOOtheme complet i identifica els nodes de text traduïbles.</td>
                        </tr>
                        <tr>
                            <td><code>content/translate</code></td>
                            <td>Tradueix un article: duplica, patcheja layout YOOtheme, crea associació, menu item, i regenera introtext.</td>
                        </tr>
                    </tbody>
                </table>

                <div class="mt-3">
                    <h4 class="h6">Exemple d'ús (Claude Code / Claude Desktop)</h4>
                    <pre class="bg-light p-3 rounded"><code>curl -X POST <?php echo htmlspecialchars($endpoint); ?> \
  -H "Content-Type: application/json" \
  -H "X-Joomla-Token: YOUR_TOKEN" \
  -d '{"jsonrpc":"2.0","method":"tools/list","params":{},"id":1}'</code></pre>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Languages Card -->
<div class="row">
    <div class="col-12">
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title mb-0">Idiomes configurats</h3>
            </div>
            <div class="card-body">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Codi</th>
                            <th>Nom</th>
                            <th>Estat</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($info['languages'] as $lang): ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars($lang['lang_code']); ?></code></td>
                            <td><?php echo htmlspecialchars($lang['title']); ?></td>
                            <td>
                                <span class="badge bg-<?php echo $lang['published'] ? 'success' : 'secondary'; ?>">
                                    <?php echo $lang['published'] ? 'Publicat' : 'No publicat'; ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
