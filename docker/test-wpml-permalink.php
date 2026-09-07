<?php
/** WPML remaps IDs to the current request language outside wp-admin. */
declare(strict_types=1);
require dirname(__DIR__) . '/packages/mirasai-wp/src/Tool/WordPressTranslationHelper.php';
$lang = 'ca'; $throws = false; $wpml = true;
function has_filter($name) { return $GLOBALS['wpml']; }
function apply_filters($name, $value) { return $name === 'wpml_current_language' ? $GLOBALS['lang'] : $value; }
function do_action($name, $value) { if ($name === 'wpml_switch_language') { $GLOBALS['lang'] = $value; } }
function get_permalink($id) {
    if ($GLOBALS['throws']) { throw new RuntimeException('permalink failed'); }
    return 'https://example.test/?page_id=' . ($GLOBALS['lang'] === 'ca' ? 10 : $id);
}
function check($ok, $label) { if (!$ok) { throw new RuntimeException($label); } }
$helper = new Mirasai\WordPress\Tool\WordPressTranslationHelper();
check($helper->postPermalink(20, 'es') === 'https://example.test/?page_id=20', 'Selected target, not current-language source');
check($lang === 'ca', 'Request language restored after success');
$throws = true;
try { $helper->postPermalink(20, 'es'); } catch (RuntimeException $e) {}
check($lang === 'ca', 'Request language restored after an exception');
$throws = false; $wpml = false;
$helper->postPermalink(20, 'es');
check($lang === 'ca', 'Non-WPML providers do not switch language');
echo "All 4 WPML permalink assertions passed.\n";
