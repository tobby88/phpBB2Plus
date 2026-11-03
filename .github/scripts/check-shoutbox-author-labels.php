<?php
// Actual stored-row bindings and author policy; no board, session or real SQL.
if (PHP_SAPI !== 'cli') { exit(2); }
error_reporting(E_ALL);set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
define('IN_PHPBB',true);$table_prefix='fixture_';$phpEx='php';
$sl_app=isset($sl_app)?$sl_app:dirname(dirname(__DIR__)).'/phpBB2/';$phpbb_root_path=$sl_app;
require $sl_app.'includes/php_compat.php';require $sl_app.'includes/constants.php';
$sl_checks=$sl_cases=0;
function sl_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['sl_checks']++;}
function append_sid($value){return$value;}
$helpers=file_get_contents($sl_app.'includes/functions.php');
sl_check(preg_match('/^function phpbb_stored_text\(.*?^\}/ms',$helpers,$part)===1,'Actual stored-text display helper');eval($part[0]);
class ShoutLabelAliasFixture
{
	var $queries=array();
	function sql_escape($value){return str_replace("'","''",$value);}
	function sql_query($sql){if(strpos($sql,'SELECT n.author_user_id FROM fixture_removed_author_names n WHERE ')!==0){throw new RuntimeException('Only synthetic former-author alias reads permitted');}$this->queries[]=$sql;return(object)array('rows'=>strpos($sql,"n.author_name='Legacy former'")!==false?array(array('author_user_id'=>0)):array());}
	function sql_fetchrowset($result){return$result->rows;}function sql_freeresult($result){}
}
$sl_fragments=array();
foreach(array('shoutbox_view.php','shoutbox_max.php')as$route){
	$source=file_get_contents($sl_app.$route);$a=strpos($source,'$display_username =');$end=$route==='shoutbox_view.php'?'$shout =':'$user_profile =';$b=$a===false?false:strpos($source,$end,$a);
	sl_check($a!==false&&$b>$a,'Complete actual author-label and profile-link bindings');$sl_fragments[$route]=substr($source,$a,$b-$a);
}
foreach(array('shoutbox_view_body.tpl'=>'USERNAME','shoutbox_max_body.tpl'=>'SHOUT_USERNAME','shoutbox_max_guest_body.tpl'=>'SHOUT_USERNAME')as$file=>$field){sl_check(strpos(file_get_contents($sl_app.'templates/fisubsilversh/'.$file),'{shoutrow.'.$field.'}')!==false,'Actual compact/member/guest templates consume the escaped author label');}
function sl_render($route,$row)
{
	global $sl_fragments,$phpEx;
	$shout_row=$row;$user_id=$row['shout_user_id'];eval($sl_fragments[$route]);
	return array('label'=>$display_username,'markup'=>$route==='shoutbox_view.php'?$username:$shout_username);
}
$sl_viewers=array(
	array('user'=>array('user_id'=>ANONYMOUS,'session_logged_in'=>false),'config'=>array('hide_removed_author_names'=>0),'mask'=>false),
	array('user'=>array('user_id'=>ANONYMOUS,'session_logged_in'=>false),'config'=>array('hide_removed_author_names'=>1),'mask'=>true),
	array('user'=>array('user_id'=>3,'session_logged_in'=>true),'config'=>array('hide_removed_author_names'=>0),'mask'=>false),
	array('user'=>array('user_id'=>3,'session_logged_in'=>true),'config'=>array('hide_removed_author_names'=>1),'mask'=>false)
);
if(isset($sl_extra_viewers)){$sl_viewers=array_merge($sl_viewers,$sl_extra_viewers);}
$sl_keep=array();
foreach(array('english','german')as$locale){$lang=array();require $sl_app.'language/lang_'.$locale.'/lang_main.php';
	foreach($sl_viewers as$viewer){$userdata=$viewer['user'];$board_config=$viewer['config'];$db=new ShoutLabelAliasFixture();$sl_keep[]=$db;
		foreach(array('shoutbox_view.php','shoutbox_max.php')as$route){
			foreach(array('A&B',"O'Reilly",'"Name"','&#39;','&amp;','<b>ü</b>','Grüße 😀','C:\\notes')as$raw){foreach(array(ENT_QUOTES,ENT_COMPAT)as$flags){
				$key=phpbb_username_key($raw,$flags);sl_check($key!=='','Complete canonical or historical account key');$row=array('shout_user_id'=>7,'username'=>$key,'shout_username'=>'Ignored stale byline','shout_author_removed'=>0);$before=serialize(array($row,$userdata,$board_config));$r=sl_render($route,$row);
				sl_check($r['label']===htmlspecialchars($raw,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),'Registered account label is decoded once and escaped once');
				sl_check(html_entity_decode(strip_tags($r['markup']),ENT_QUOTES,'UTF-8')===$raw,'Actual linked visible name is exact, including literal entity spellings');
				sl_check(strpos($r['markup'],"href='profile.php?mode=viewprofile&amp;u=7'")!==false&&substr_count($r['markup'],'<a ')===1,'Correct numeric profile link, no injected name markup');
				sl_check(serialize(array($row,$userdata,$board_config))===$before,'Account/post/viewer identity remains unchanged');$sl_cases++;
			}}
			foreach(array(
				array(phpbb_post_author_key('Guest & "Name"'),0,'Guest & "Name"'),array('<Guest & Name>',0,'<Guest & Name>'),
				array(phpbb_post_author_key('Former & "Name"'),1,$viewer['mask']?$lang['Former_member']:'Former & "Name"'),
				array('',0,$lang['Guest']),array(phpbb_post_author_key('&#39;'),0,'&#39;'),
				array('Legacy former',null,$viewer['mask']?$lang['Former_member']:'Legacy former'),array('Legacy genuine',null,'Legacy genuine')
			)as$case){$row=array('shout_user_id'=>ANONYMOUS,'username'=>'Anonymous','shout_username'=>$case[0]);if($case[1]!==null){$row['shout_author_removed']=$case[1];}$before=serialize(array($row,$userdata,$board_config));$r=sl_render($route,$row);
				sl_check(html_entity_decode($r['label'],ENT_QUOTES,'UTF-8')===$case[2],'Guest/former-author policy label is shown once without entity damage');
				sl_check($r['markup']===$r['label']&&strpos($r['markup'],'<')===false&&strpos($r['markup'],'>')===false,'Guest labels cannot inject markup or link to an account');
				sl_check(serialize(array($row,$userdata,$board_config))===$before,'Guest history and policy data are not modified');$sl_cases++;
			}
		}
		sl_check(($viewer['mask']?count($db->queries)>0:count($db->queries)===0),'Only an effective masking policy needs synthetic alias lookups');
	}
}
restore_error_handler();echo 'Shoutbox author labels: ',$sl_cases,' cases, ',$sl_checks," assertions PASS; actual compact/full bindings, stored account keys, literal entities, safe links and guest/former-author policy; synthetic alias reads only.\n";
