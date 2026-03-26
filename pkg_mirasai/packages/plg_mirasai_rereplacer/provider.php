<?php
/**
 * Standalone provider bootstrap for plg_mirasai_rereplacer.
 */

declare(strict_types=1);

defined('_JEXEC') or define('_JEXEC', 1);

require_once __DIR__ . '/src/Support/ConditionsService.php';
require_once __DIR__ . '/src/Support/RereplacerService.php';

require_once __DIR__ . '/src/Tool/AbstractRereplacerTool.php';
require_once __DIR__ . '/src/Tool/ConditionsListTool.php';
require_once __DIR__ . '/src/Tool/ConditionsReadTool.php';
require_once __DIR__ . '/src/Tool/RereplacerCapabilitiesTool.php';
require_once __DIR__ . '/src/Tool/RereplacerAttachConditionTool.php';
require_once __DIR__ . '/src/Tool/RereplacerCreateItemSimpleTool.php';
require_once __DIR__ . '/src/Tool/RereplacerListItemsTool.php';
require_once __DIR__ . '/src/Tool/RereplacerPreviewMatchScopeTool.php';
require_once __DIR__ . '/src/Tool/RereplacerPublishItemTool.php';
require_once __DIR__ . '/src/Tool/RereplacerReadItemTool.php';
require_once __DIR__ . '/src/Tool/RereplacerUpdateItemSimpleTool.php';

require_once __DIR__ . '/src/RereplacerToolProvider.php';

return new \Mirasai\Plugin\Mirasai\Rereplacer\RereplacerToolProvider();
