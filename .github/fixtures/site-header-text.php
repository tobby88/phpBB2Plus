<?php
if(!defined('IN_PHPBB')){define('IN_PHPBB',true);}
$sht_checks=$sht_cases=0;
function sht_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['sht_checks']++;}
$sht_app=dirname(dirname(__DIR__)).'/phpBB2/';
$sht_functions=file_get_contents($sht_app.'includes/functions.php');
foreach(array('phpbb_stored_text','phpbb_text_excerpt')as$sht_function){if(!function_exists($sht_function)){sht_check(preg_match('/^function '.$sht_function.'\(.*?^\}/ms',$sht_functions,$sht_match)===1,'Actual header text helper');eval($sht_match[0]);}}
if(!function_exists('append_sid')){function append_sid($url){return$url;}}
class SiteHeaderTextTemplate{var $vars=array();function assign_vars($values){$this->vars=array_merge($this->vars,$values);}}
function sht_render_header($stored,$name){
    global $sht_app;
    $source=file_get_contents($sht_app.'includes/page_header.php');$a=strpos($source,'$site_description =');$b=strpos($source,'$template->assign_vars(array(',$a);sht_check($a!==false&&$b>$a,'Actual complete header metadata binding');
    $board_config=array('site_desc'=>$stored,'sitename'=>$name);$page_title='Fixture &amp; title';eval(substr($source,$a,$b-$a));
    return array('name'=>$sitename_html,'description'=>$site_description_html,'page'=>$page_title_html);
}
function sht_render_index($name,$locale){
    global $sht_app;
    $lang=array();$lang_extend_admin=false;require$sht_app.'language/lang_'.$locale.'/lang_main.php';$board_config=array('sitename'=>$name);$phpEx='php';$template=new SiteHeaderTextTemplate();require$sht_app.'stat_modules/forum_index/module.php';return array($template->vars,$lang['Forum_Index']);
}
error_reporting(E_ALL);set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
    $cases=array(array('',''),array('0','0'),array(str_repeat('a',75),str_repeat('a',75)),array(str_repeat('a',76),str_repeat('a',75).'...'),
        array(str_repeat('ä',75),str_repeat('ä',75)),array(str_repeat('ä',76),str_repeat('ä',75).'...'),array(str_repeat('a',74).'ä tail',str_repeat('a',74).'ä...'),
        array(str_repeat('a',74).'😀 tail',str_repeat('a',74).'😀...'),array(str_repeat('a',74).'&amp; tail',str_repeat('a',74).'&...'),
        array('A &amp; B','A & B'),array('&amp;amp;','&amp;'),array('&lt;b&gt;Beschreibung &quot;ä&quot;&lt;/b&gt;','<b>Beschreibung "ä"</b>'),array('<b>Beschreibung "ä"</b>','<b>Beschreibung "ä"</b>'),
        array(str_repeat('a',74)."\xC3".'suffix',str_repeat('a',74)."\xEF\xBF\xBD..."));
    foreach($cases as$case){$rendered=sht_render_header($case[0],'Fixture &amp; forum');$text=html_entity_decode($rendered['description'],ENT_QUOTES,'UTF-8');sht_check($text===$case[1],'Actual header description preserves complete visible characters and one stored entity layer');sht_check(preg_match('//u',$rendered['description'])===1&&strpos($rendered['description'],'<')===false,'Description remains valid escaped UTF-8');sht_check($rendered['name']==='Fixture &amp; forum'&&$rendered['page']==='Fixture &amp; title','Neighboring site/page title contracts retained');$sht_cases++;}
    foreach(array('english','german')as$locale){foreach(array('Plain','0','A&amp;B','&amp;amp;','<b>Forum "ä😀"</b>','&lt;b&gt;Forum&lt;/b&gt;')as$name){$header=sht_render_header('Description',$name);list($index,$format)=sht_render_index($name,$locale);sht_check($index['L_INDEX']===sprintf($format,$header['name']),'Actual localized statistics index uses the same site label as the public header');sht_check(strpos($index['L_INDEX'],'<')===false&&$index['U_INDEX']==='index.php','Site name cannot become markup and index destination stays unchanged');$sht_cases++;}}
    echo 'Site header text: ',$sht_cases,' actual-header/module cases, ',$sht_checks," assertions PASS; UTF-8/entity boundaries, 75-character excerpts, malformed legacy bytes, preserved site/page labels and EN/DE index links.\n";
}finally{restore_error_handler();}
