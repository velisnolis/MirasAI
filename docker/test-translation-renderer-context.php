<?php
/** API bootstrap and application restoration, including failed renders. */
declare(strict_types=1);
namespace Joomla\CMS {
 class Factory { public static $application; public static $container; public static function getApplication(){return self::$application;} public static function getContainer(){return self::$container;} }
}
namespace Mirasai\Library\Tool { class YooThemeLayoutProcessor { public function extractJson($s){return '{}';} } }
namespace {
 require dirname(__DIR__).'/packages/mirasai-joomla/packages/lib_mirasai/src/Tool/YooThemeTranslationRenderer.php';
 class RuntimeApp {
  public function __construct(public string $client,public string $tag='ca-ES'){}
  public function isClient($v){return $v===$this->client;}
  public function getIdentity(){return(object)['id'=>42];}
  public function loadIdentity($v){$GLOBALS['loaded_identity']=$v->id;}
  public function getLanguage(){return new class($this->tag){public function __construct(private string $tag){}public function getTag(){return $this->tag;}};}
 }
 $root=sys_get_temp_dir().'/mirasai-render-context-'.bin2hex(random_bytes(8));mkdir($root.'/templates/yootheme',0700,true);define('JPATH_ROOT',$root);
 $bootstrap=$root.'/templates/yootheme/template_bootstrap.php';
 $code= <<<'BOOT'
<?php
namespace YOOtheme;
if (\Joomla\CMS\Factory::getApplication()->isClient('api')) { throw new \RuntimeException('API context leaked into bootstrap'); }
class Builder {
 public function withParams($a){return $this;}
 public function load($j){return ['type'=>'text','props'=>['content'=>'Hola']];}
 public function render($j,$a){if(!\Joomla\CMS\Factory::getApplication()->isClient('site'))throw new \RuntimeException('Render needs site context');if($GLOBALS['fail_render']??false)throw new \RuntimeException('injected render failure');return '<p>Hola</p>';}
}
function app($class){return new Builder();}
BOOT;
 $checks=0;function ck($ok,$label){global$checks;if(!$ok)throw new \RuntimeException($label);$checks++;}
 try {
  $api=new RuntimeApp('api');\Joomla\CMS\Factory::$application=$api;
  \Joomla\CMS\Factory::$container=new class {public function get($name){return new RuntimeApp('site');}};
  $r=\Mirasai\Library\Tool\YooThemeTranslationRenderer::render('<!-- {} -->','es-ES');ck($r['result']['status']==='skipped','No installed Builder yields skipped');
  file_put_contents($bootstrap,$code);
  $r=\Mirasai\Library\Tool\YooThemeTranslationRenderer::render('<!-- {"source":{}} -->','es-ES');ck($r['result']['status']==='skipped'&&\Joomla\CMS\Factory::$application===$api,'API dynamic layout does not bootstrap a fabricated site context');
  $r=\Mirasai\Library\Tool\YooThemeTranslationRenderer::render('<!-- {} -->','es-ES');ck($r['result']['status']==='success','Static API layout boots the site Builder');ck(\Joomla\CMS\Factory::$application===$api,'API application restored after bootstrap');ck(($GLOBALS['loaded_identity']??0)===42,'Authenticated identity retained during render');
  $r=\Mirasai\Library\Tool\YooThemeTranslationRenderer::render('<!-- {} -->','es-ES');ck($r['result']['status']==='success'&&\Joomla\CMS\Factory::$application===$api,'Warm Builder still renders inside a restored site context');
  $GLOBALS['fail_render']=true;$r=\Mirasai\Library\Tool\YooThemeTranslationRenderer::render('<!-- {} -->','es-ES');ck($r['result']['status']==='error'&&\Joomla\CMS\Factory::$application===$api,'Render failure preserves API application');$GLOBALS['fail_render']=false;
  \Joomla\CMS\Factory::$application=new RuntimeApp('site','ca-ES');$r=\Mirasai\Library\Tool\YooThemeTranslationRenderer::render('<!-- {"source":{}} -->','es-ES');ck($r['result']['status']==='skipped','Wrong site language rejected');
  \Joomla\CMS\Factory::$application=new RuntimeApp('site','es-ES');$r=\Mirasai\Library\Tool\YooThemeTranslationRenderer::render('<!-- {"source":{}} -->',null);ck($r['result']['status']==='skipped','Dynamic layout requires explicit target language');
  $r=\Mirasai\Library\Tool\YooThemeTranslationRenderer::render('<!-- {"source":{}} -->','es-ES');ck($r['result']['status']==='success','Matching site language can render dynamic layout');
 } finally {if(is_file($bootstrap))unlink($bootstrap);rmdir($root.'/templates/yootheme');rmdir($root.'/templates');rmdir($root);}
 echo "All {$checks} renderer context assertions passed.\n";
}
