<?php
define('IN_PHPBB',true);
$phpbb_root_path=dirname(dirname(__DIR__)).'/phpBB2/';
require $phpbb_root_path.'includes/php_compat.php';
require $phpbb_root_path.'includes/functions.php';
require $phpbb_root_path.'includes/bbcode.php';
require $phpbb_root_path.'includes/functions_post.php';
require $phpbb_root_path.'includes/functions_signature.php';
$lang=array();require $phpbb_root_path.'language/lang_english/lang_main.php';
class SignatureRenderTemplate {
 function make_filename($file){return $GLOBALS['phpbb_root_path'].'templates/fisubsilversh/'.$file;}
 function assign_block_vars($name,$vars){}
}
$template=new SignatureRenderTemplate();
function signature_render_check($ok,$description){if(!$ok){throw new RuntimeException($description);}}
$source=file_get_contents($phpbb_root_path.'includes/usercp_signature.php');
$a=strpos($source,'// catch the submitted message');$b=strpos($source,'// template',$a);
signature_render_check($a!==false&&$b>$a,'Actual preview/current controller');
$body='if(false){} '.substr($source,$a,$b-$a);
// Evaluate the actual template's textarea bindings, not a copied escaping rule.
preg_match("/'PREVIEW' => ([^\r\n]+),/",$source,$preview_binding);
preg_match("/'SIGNATURE' => ([^\r\n]+),/",$source,$current_binding);
signature_render_check(isset($preview_binding[1],$current_binding[1]),'Actual textarea bindings');
$board_config=array('max_sig_chars'=>10000,'allow_html_tags'=>'b,i','allow_bbcode'=>1);
$smilies_on=0;$mode='signature';$cases=0;
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try {
 foreach(array(0,1) as $html_on){foreach(array(0,1) as $bbcode_on){
  $board_config['allow_bbcode']=$bbcode_on;
  foreach(array('',"Grüße 😀 & \"quote\" 'apostrophe'",'C:\\new\\test \\\\ server',"first\nsecond",'&lt;b&gt;literal&lt;/b&gt;','<b>bold</b>','[b]bold[/b] [i]italic[/i]','[quote="Name"]quoted[/quote]','[list][*]one[*]two[/list]','https://example.invalid/path','</textarea><script>alert("test")</script>') as $raw){
   $uid=$bbcode_on?'0123456789':'';
   $stored=phpbb_signature_prepare($raw,$html_on,$bbcode_on,0,$uid);
   $userdata=array('user_sig'=>$stored,'user_sig_bbcode_uid'=>$uid);
   $preview=false;eval($body);
   $field=eval('return '.$current_binding[1].';');
   $reopened=html_entity_decode($field,ENT_QUOTES,'UTF-8');
   signature_render_check(strpos($field,'</textarea>')===false,'Reopen cannot escape textarea');
   // BBCode list normalization may add whitespace. Re-saving must still be
   // byte-stable for the same UID and must never accumulate entities/slashes.
   signature_render_check(phpbb_signature_prepare($reopened,$html_on,$bbcode_on,0,$uid)===$stored,'Stable reopen/save round trip case '.$cases);
   if(strpos($raw,'[list]')!==0){signature_render_check($reopened===$raw,'Exact source on reopen case '.$cases);}
   $current_render=$user_sig;
   $preview=true;$signature_text=$raw;eval($body);
   $field=eval('return '.$preview_binding[1].';');
   signature_render_check(html_entity_decode($field,ENT_QUOTES,'UTF-8')===$raw&&strpos($field,'</textarea>')===false,'Preview retains safe exact textarea source case '.$cases);
   signature_render_check($preview_sig===$current_render,'Fresh preview equals stored rendering case '.$cases);
   signature_render_check(strpos($preview_sig,'<script>')===false,'Preview rejects raw scripts');
   if($raw==='https://example.invalid/path'){signature_render_check(strpos($preview_sig,'<a href=')!==false,'Links independent of BBCode setting');}
   if($raw==='[b]bold[/b] [i]italic[/i]'&&$bbcode_on){signature_render_check(strpos($preview_sig,'font-weight:bold')!==false&&strpos($preview_sig,':0123456789')===false,'Rendered BBCode, no internal tokens');}
   $cases++;
  }
 }}
 $uid='0123456789';$stored=phpbb_signature_prepare('<b>HTML</b> [b]BBCode[/b] &',1,1,0,$uid);
 $render=phpbb_signature_render($stored,$uid,false,false,false);
 signature_render_check(strpos($render,'&lt;b&gt;HTML&lt;/b&gt; [b]BBCode[/b] &amp;')!==false&&strpos($render,':'.$uid)===false,'Current disabled HTML/BBCode policy applied without double escaping');$cases++;
 $preview=true;$signature_text='too long';$board_config['max_sig_chars']=2;eval($body);
 signature_render_check($preview_sig===$lang['Signature_too_long']&&$signature_text==='too long','Length failure retains edit text');$cases++;
 signature_render_check(phpbb_signature_edit_text(null,null)===''&&phpbb_signature_render(null,'',false,false,false)===$lang['sig_none'],'Empty legacy nullable signature');$cases++;
 echo 'Signature rendering: '.$cases." actual-controller round-trip, preview, BBCode and output-safety cases passed.\n";
} finally {restore_error_handler();}
