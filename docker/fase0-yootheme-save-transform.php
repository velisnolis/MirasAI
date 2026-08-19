<?php
/**
 * Lab helper: run YOOtheme Builder::load(context:save) on a layout JSON.
 *
 * This is the same PHP path Customizer uses in TemplateController::saveTemplate
 * (and PageController::savePage for articles). It does not write to the CMS.
 *
 * Must run inside the Joomla container:
 *   php fase0-yootheme-save-transform.php < layout.json > out.json
 */

declare(strict_types=1);

if (!isset($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
}

const _JEXEC = 1;
define('JPATH_BASE', '/var/www/html');
require JPATH_BASE . '/includes/defines.php';
require JPATH_BASE . '/includes/framework.php';

$container = Joomla\CMS\Factory::getContainer();
$container->alias('session.web', 'session.web.site')
    ->alias('session', 'session.web.site')
    ->alias('JSession', 'session.web.site')
    ->alias(Joomla\CMS\Session\Session::class, 'session.web.site')
    ->alias(Joomla\Session\Session::class, 'session.web.site')
    ->alias(Joomla\Session\SessionInterface::class, 'session.web.site');

$app = $container->get(Joomla\CMS\Application\SiteApplication::class);
Joomla\CMS\Factory::$application = $app;

require '/var/www/html/templates/yootheme/template_bootstrap.php';

$raw = stream_get_contents(STDIN);
if ($raw === false || trim($raw) === '') {
    fwrite(STDERR, "Expected layout JSON on stdin.\n");
    exit(1);
}

$layout = json_decode($raw, true);
if (!is_array($layout)) {
    fwrite(STDERR, "stdin is not valid JSON object.\n");
    exit(1);
}

$builder = YOOtheme\Application::getInstance()->get(YOOtheme\Builder::class);
$out = $builder->withParams(['context' => 'save'])->load(json_encode($layout));

if ($out === null) {
    fwrite(STDERR, "Builder::load(context:save) returned null.\n");
    exit(2);
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
