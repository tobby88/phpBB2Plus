<?php
define('IN_PHPBB',true);$table_prefix='fixture_';$phpEx='php';$phpbb_root_path=dirname(dirname(__DIR__)).'/phpBB2/';
require$phpbb_root_path.'includes/php_compat.php';require$phpbb_root_path.'includes/constants.php';require$phpbb_root_path.'includes/functions_post.php';require$phpbb_root_path.'includes/bbcode.php';require$phpbb_root_path.'includes/functions_kb_discussion.php';
class KbDiscussionFormatTemplate{function make_filename($file){if($file!=='bbcode.tpl'){throw new RuntimeException('Only the real standard BBCode template');}return$GLOBALS['phpbb_root_path'].'templates/fisubsilversh/bbcode.tpl';}}
$template=new KbDiscussionFormatTemplate();$lang=array('Quote'=>'Quote','wrote'=>'wrote','Code'=>'Code','PHPCode'=>'PHP','Select'=>'Select','Expand'=>'Expand','Contract'=>'Contract');$board_config=array('allow_html_tags'=>'b,i,u');
function km_format_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
$uid='1234567890';$cases=0;
foreach(array(
 array("Größe 😀 O'Reilly \\ exact",true,"Größe 😀 O'Reilly \\ exact"),
 array(chr(34).'Double quotes'.chr(34),false,chr(34).'Double quotes'.chr(34)),
 array('<b>literal</b> [b]literal[/b] [img]https://metadata.example.invalid/image.png[/img]',true,'<b>literal</b> [b]literal[/b] [img]https://metadata.example.invalid/image.png[/img]'),
 array("A &amp; B &lt;b&gt; &#39;",true,"A & B <b> '"),
 array('Literal &amp; &#91; &#38;',false,'Literal &amp; &#91; &#38;'),
 array('www.metadata.example.invalid user@example.invalid https://metadata.example.invalid/path :)',false,'www.metadata.example.invalid user@example.invalid https://metadata.example.invalid/path :)'),
 array('0',false,'0')
)as$case){
 $literal=kb_discussion_literal($case[0],$case[1]);km_format_check(strpbrk($literal,"[]<>\"'")===false,'No raw metadata markup delimiters');
 $source='[b]Label[/b] '.$literal.' [url=https://forum.example.invalid/kb.php?mode=article&k=1]Trusted[/url]';
 $stored=stripslashes(prepare_message(addslashes($source),0,true,false,$uid));$rendered=make_clickable(bbencode_second_pass($stored,$uid));
 km_format_check(strpos($rendered,'[b:')===false&&strpos($rendered,'[url=')===false,'Real renderer consumes generated trusted markup');
 km_format_check(substr_count($rendered,'<a ')===1&&strpos($rendered,'<img')===false,'Only generated board link is active, no metadata links/images');
 km_format_check(strpos($rendered,'href="https://forum.example.invalid/kb.php?mode=article&amp;k=1"')!==false,'Generated board link keeps correct single-encoded URL');
 km_format_check(html_entity_decode(strip_tags($rendered),ENT_QUOTES,'UTF-8')==='Label '.$case[2].' Trusted','Actual standard template/display roundtrip preserves literal Unicode/entities/punctuation exactly');$cases++;
}
restore_error_handler();echo 'KB discussion formatting: '.$cases." actual first/second-pass/standard-template/auto-link/display vectors passed; no network.\n";
