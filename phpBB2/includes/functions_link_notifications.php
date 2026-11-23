<?php
if(!defined('IN_PHPBB')){die('Hacking attempt');}
require_once dirname(__FILE__).'/functions_link_submission.php';
require_once __DIR__ . '/functions_optional_pm.php';

class PhpbbLinkNoticeMailbox extends PhpbbLinkScope
{
	use PhpbbOptionalPm;
}

function phpbb_link_language($language,$fallback='english')
{
	foreach(array($language,$fallback,'english')as$value){if(is_string($value)&&preg_match('/^[a-z0-9_-]{1,100}$/iD',$value)&&is_file(dirname(__DIR__).'/language/lang_'.$value.'/lang_main_link.php')&&is_file(dirname(__DIR__).'/language/lang_'.$value.'/email/link_add.tpl')){return $value;}}
	phpbb_link_fail();
}

function phpbb_link_notice_context($scope,$outcome,$recipient=null)
{
	$links=$scope->rows('SELECT l.link_id,l.link_url,l.user_id FROM '.LINKS_TABLE.' l JOIN '.USERS_TABLE.' u ON u.user_id=l.user_id AND u.user_active<>0'
		.' WHERE l.link_id='.(int)$outcome['link_id'].' AND l.user_id='.(int)$outcome['user_id'].' AND l.link_active=0 LOCK IN SHARE MODE');
	if(count($links)!==1){return false;}
	$config=phpbb_link_configuration($scope);$settings=array();
	foreach($scope->rows('SELECT config_name,config_value FROM '.CONFIG_TABLE." WHERE config_name IN ('privmsg_disable','max_inbox_privmsgs','default_lang','sitename','board_email') LOCK IN SHARE MODE")as$row){if(isset($settings[$row['config_name']])){phpbb_link_fail();}$settings[$row['config_name']]=$row['config_value'];}
	if($recipient===null){$users=$scope->rows('SELECT user_id FROM '.USERS_TABLE.' WHERE user_level='.(int)ADMIN.' AND user_active<>0 ORDER BY user_id LOCK IN SHARE MODE');}
	else{$users=$scope->rows('SELECT user_id,user_email,user_lang,user_allow_pm FROM '.USERS_TABLE.' WHERE user_id='.(int)$recipient.' AND user_level='.(int)ADMIN.' AND user_active<>0 LOCK IN SHARE MODE');if(count($users)!==1){return false;}}
	$url=phpbb_normalize_external_url($links[0]['link_url']);if($url===false){phpbb_link_fail();}
	return array('source'=>$links[0],'url'=>$url,'config'=>$config,'settings'=>$settings,'users'=>$users);
}

function phpbb_link_notification_tables($pm=false)
{
	$tables=array(LINKS_TABLE,LINK_CONFIG_TABLE,USERS_TABLE,CONFIG_TABLE);
	if($pm){$tables[]=PRIVMSGS_TABLE;$tables[]=PRIVMSGS_TEXT_TABLE;}
	return $tables;
}

// Optional notices own a separate transaction after the link has committed.
// Never evict an existing inbox item, publish an orphan body or lose counters.
function phpbb_link_notice_pm($database,$outcome,$recipient)
{
	$scope=new PhpbbLinkNoticeMailbox($database,phpbb_link_notification_tables(true));
	try{
		$ready=phpbb_link_notice_context($scope,$outcome,$recipient);
		if($ready===false||!$ready['config']['pm_notify']||!empty($ready['settings']['privmsg_disable'])||empty($ready['users'][0]['user_allow_pm'])){return;}
		$user = $ready['users'][0];
		$maximum = isset($ready['settings']['max_inbox_privmsgs']) ? $ready['settings']['max_inbox_privmsgs'] : '50';
		$language=phpbb_link_language($user['user_lang'],isset($ready['settings']['default_lang'])?$ready['settings']['default_lang']:'english');
		$lang=array();require dirname(__DIR__).'/language/lang_'.$language.'/lang_main_link.php';
		$text=htmlspecialchars(sprintf($lang['Link_pm_notify_message'],$ready['url']),ENT_QUOTES,'UTF-8');
		global $user_ip;
		$ip = is_string($user_ip) && preg_match('/^[a-f0-9]{8}$/iD',$user_ip) ? $user_ip : '00000000';
		if ($scope->insert_optional_pm((int)$outcome['user_id'], (int)$user['user_id'], $maximum, $lang['Link_pm_notify_subject'], $text, $ip)) {
			$scope->commit();
		}
	} catch (PhpbbOptionalPmFailure $error) { phpbb_link_fail(); }
	finally { $scope->release(); }
}

// Recheck current recipients/settings outside the author's completed save. No
// SQL owner or advisory lock is held while the optional mail transport runs.
function phpbb_link_notify($database,$outcome)
{
	global$board_config,$phpbb_root_path,$phpEx;
	if(!empty($outcome['active'])||empty($outcome['email_notify'])&&empty($outcome['pm_notify'])){return true;}
	$success=true;$scope=null;
	try{$scope=new PhpbbLinkScope($database,phpbb_link_notification_tables());$initial=phpbb_link_notice_context($scope,$outcome);}
	catch(Exception$error){return false;}catch(Error$error){return false;}finally{if($scope!==null){$scope->release();}}
	if($initial===false){return true;}
	foreach($initial['users']as$recipient){
		$id=(int)$recipient['user_id'];
		if(!empty($outcome['pm_notify'])){try{phpbb_link_notice_pm($database,$outcome,$id);}catch(Exception$error){$success=false;}catch(Error$error){$success=false;}}
		if(empty($outcome['email_notify'])){continue;}
		$scope=null;$ready=false;
		try{$scope=new PhpbbLinkScope($database,phpbb_link_notification_tables());$ready=phpbb_link_notice_context($scope,$outcome,$id);}
		catch(Exception$error){$success=false;}catch(Error$error){$success=false;}finally{if($scope!==null){$scope->release();}}
		if($ready===false||!$ready['config']['email_notify']){continue;}
		try{
			$user=$ready['users'][0];if(!filter_var($user['user_email'],FILTER_VALIDATE_EMAIL)){$success=false;continue;}
			$language=phpbb_link_language($user['user_lang'],isset($ready['settings']['default_lang'])?$ready['settings']['default_lang']:'english');
			require_once $phpbb_root_path.'includes/emailer.'.$phpEx;
			$mailer=new emailer($board_config['smtp_delivery'],true);
			$from=isset($ready['settings']['board_email'])?$ready['settings']['board_email']:$board_config['board_email'];$mailer->from($from);$mailer->replyto($from);
			$mailer->use_template('link_add',$language);$mailer->email_address($user['user_email']);
			$mailer->assign_vars(array('LINK_URL'=>$ready['url'],'SITENAME'=>isset($ready['settings']['sitename'])?html_entity_decode($ready['settings']['sitename'],ENT_QUOTES,'UTF-8'):html_entity_decode($board_config['sitename'],ENT_QUOTES,'UTF-8')));
			if($mailer->send()===false){$success=false;}
		}catch(Exception$error){$success=false;}catch(Error$error){$success=false;}
	}
	return $success;
}
