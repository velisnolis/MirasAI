<?php

/**
 * MirasAI — Regenerate introtext for translated YOOtheme articles.
 *
 * Usage: php regenerate-introtext.php <article_id> [<article_id2> ...]
 *
 * This script bootstraps Joomla + YOOtheme, reads the fulltext layout JSON,
 * renders it via YOOtheme Builder, and writes the rendered HTML back to introtext.
 *
 * Must be run from the Joomla root directory.
 */

declare(strict_types=1);

define('_JEXEC', 1);
define('JPATH_BASE', __DIR__);

require __DIR__ . '/includes/defines.php';
require __DIR__ . '/includes/framework.php';

// Boot Joomla
$container = \Joomla\CMS\Factory::getContainer();
$container->alias(\Joomla\Session\SessionInterface::class, 'session.web.site');
$app = $container->get(\Joomla\CMS\Application\SiteApplication::class);
\Joomla\CMS\Factory::$application = $app;

// Bootstrap YOOtheme (same as the system plugin does)
$bootstrapPattern = JPATH_ROOT . '/templates/*/template_bootstrap.php';
$bootstrapFiles = glob($bootstrapPattern) ?: [];

foreach ($bootstrapFiles as $file) {
    require $file;
}

// Check if YOOtheme Builder is available
if (!class_exists('YOOtheme\Builder') || !function_exists('YOOtheme\app')) {
    output(['error' => 'YOOtheme Builder not available. Is YOOtheme installed?']);
}

$builder = \YOOtheme\app(\YOOtheme\Builder::class);
$db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);

// Get article IDs from arguments or stdin
$articleIds = [];

if ($argc > 1) {
    $articleIds = array_map('intval', array_slice($argv, 1));
} else {
    // Read from stdin (JSON array)
    $input = file_get_contents('php://input') ?: stream_get_contents(STDIN);
    $data = json_decode($input ?: '', true);

    if (isset($data['article_ids'])) {
        $articleIds = array_map('intval', $data['article_ids']);
    }
}

if (empty($articleIds)) {
    output(['error' => 'No article IDs provided. Usage: php regenerate-introtext.php <id> [<id2> ...]']);
}

$results = [];

foreach ($articleIds as $articleId) {
    $results[] = regenerateIntrotext($db, $builder, $articleId);
}

output(['results' => $results]);

function regenerateIntrotext(
    \Joomla\Database\DatabaseInterface $db,
    \YOOtheme\Builder $builder,
    int $articleId,
): array {
    // Load article
    $query = $db->getQuery(true)
        ->select([$db->quoteName('fulltext', 'fulltext_raw'), 'title'])
        ->from($db->quoteName('#__content'))
        ->where('id = :id')
        ->bind(':id', $articleId, \Joomla\Database\ParameterType::INTEGER);

    $row = $db->setQuery($query)->loadAssoc();

    if (!$row) {
        return ['article_id' => $articleId, 'status' => 'error', 'message' => 'Article not found'];
    }

    $fulltext = trim($row['fulltext_raw'] ?? '');

    if (!str_starts_with($fulltext, '<!-- {')) {
        return ['article_id' => $articleId, 'status' => 'skipped', 'message' => 'No YOOtheme layout in fulltext'];
    }

    // Extract JSON from comment
    $end = strrpos($fulltext, ' -->');

    if ($end === false) {
        return ['article_id' => $articleId, 'status' => 'error', 'message' => 'Malformed YOOtheme comment'];
    }

    $layoutJson = substr($fulltext, 5, $end - 5);

    // Render introtext using YOOtheme Builder (same as PageController::savePage)
    try {
        $introtext = $builder
            ->withParams(['context' => 'content'])
            ->render($layoutJson, ['prefix' => 'page']);

        if ($introtext === null) {
            $introtext = '';
        }

        // Also re-encode the fulltext through Builder save context for consistency
        $savedLayout = $builder->withParams(['context' => 'save'])->load($layoutJson);
        $newFulltext = '<!-- ' . json_encode($savedLayout, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ' -->';

        // Update article
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__content'))
            ->set($db->quoteName('introtext') . ' = :introtext')
            ->set($db->quoteName('fulltext') . ' = :fulltext')
            ->set($db->quoteName('modified') . ' = :modified')
            ->where('id = :id')
            ->bind(':introtext', $introtext)
            ->bind(':fulltext', $newFulltext)
            ->bind(':modified', date('Y-m-d H:i:s'))
            ->bind(':id', $articleId, \Joomla\Database\ParameterType::INTEGER);

        $db->setQuery($query)->execute();

        return [
            'article_id' => $articleId,
            'title' => $row['title'],
            'status' => 'success',
            'introtext_length' => strlen($introtext),
        ];
    } catch (\Throwable $e) {
        return [
            'article_id' => $articleId,
            'status' => 'error',
            'message' => $e->getMessage(),
        ];
    }
}

function output(array $data): never
{
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
