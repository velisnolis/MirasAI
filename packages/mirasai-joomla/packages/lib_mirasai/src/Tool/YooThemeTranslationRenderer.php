<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

class YooThemeTranslationRenderer
{
    /** Render without shelling out or opening a second database connection. */
    public static function render(string $fulltext, ?string $language = null): array
    {
        $originalApplication = null;
        try {
            if (str_contains($fulltext, '"source"')) {
                $application = \Joomla\CMS\Factory::getApplication();
                if ($language === null || !$application->isClient('site') || $application->getLanguage()->getTag() !== $language) {
                    return ['result' => ['status' => 'skipped', 'message' => 'Dynamic fallback needs a target-language site runtime with its content context.']];
                }
            }
            if (defined('JPATH_ROOT')) {
                $bootstrap = JPATH_ROOT . '/templates/yootheme/template_bootstrap.php';
                if (self::builderAvailable() || is_file($bootstrap)) {
                    // The API dispatches MirasAI before YOOtheme boots its site Builder.
                    // Use Joomla's site service only for this render; keep API identity and
                    // restore the original application even if bootstrap/render fails.
                    $originalApplication = \Joomla\CMS\Factory::getApplication();
                    if (!$originalApplication->isClient('site')) {
                        $site = \Joomla\CMS\Factory::getContainer()->get(\Joomla\CMS\Application\SiteApplication::class);
                        $site->loadIdentity($originalApplication->getIdentity());
                        \Joomla\CMS\Factory::$application = $site;
                    }
                    if (!self::builderAvailable()) {
                        require $bootstrap;
                    }
                }
            }
            if (!self::builderAvailable()) {
                return ['result' => ['status' => 'skipped', 'message' => 'YOOtheme Builder is unavailable in this runtime.']];
            }
            $builder = \YOOtheme\app(\YOOtheme\Builder::class);
            $json = (new YooThemeLayoutProcessor())->extractJson($fulltext);
            $layout = $builder->withParams(['context' => 'save'])->load($json);
            $normalized = json_encode($layout, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $html = $builder->withParams(['context' => 'content'])->render($normalized, ['prefix' => 'page']);
            if (!is_string($html) || trim($html) === '') { throw new \RuntimeException('Builder returned empty fallback HTML.'); }
            return ['fulltext' => '<!-- ' . $normalized . ' -->', 'introtext' => $html,
                'result' => ['status' => 'success', 'introtext_length' => strlen($html)]];
        } catch (\Throwable $e) {
            return ['result' => ['status' => 'error', 'message' => $e->getMessage()]];
        } finally {
            if ($originalApplication !== null) {
                \Joomla\CMS\Factory::$application = $originalApplication;
            }
        }
    }

    private static function builderAvailable(): bool
    {
        return class_exists('YOOtheme\\Builder') && function_exists('YOOtheme\\app');
    }
}
