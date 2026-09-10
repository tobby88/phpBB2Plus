<?php
$fixture = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/check-private-message-cleanup.php'));
$end = strpos($fixture, "\nset_error_handler(");
if ($end === false) { throw new RuntimeException('Attachment action fixture boundary'); }
eval(substr($fixture,5,$end-5));
define('POST_POST_URL','p');
class PmAttachmentRedirect extends RuntimeException {}
function append_sid($url,$unused=false){return $url;}
function redirect($url){throw new PmAttachmentRedirect($url);}
$source = str_replace("\r\n", "\n", file_get_contents($forum_root.'privmsg.php'));
$a = strpos($source,'function privmsg_post_session_is_valid(');$b = strpos($source,"\n//",$a);
mutation_check($a!==false&&$b>$a,'Actual shared session check');eval(substr($source,$a,$b-$a));
$a = strpos($source,'// Attachment processing can move/delete files.');$b = strpos($source,'// Durable write form identity',$a);
mutation_check($a!==false&&$b>$a,'Actual early request gate');$request_gate=substr($source,$a,$b-$a);
function pm_attachment_gate()
{
	global $userdata,$lang,$phpEx,$folder,$sid,$mode,$privmsg_id,$request_gate;
	eval($request_gate);
}
function pm_attachment_failure($call,$expected)
{
	$caught='';try{call_user_func($call);}catch(RuntimeException $e){$caught=$e->getMessage();}
	mutation_check($caught===$expected,'Expected attachment action denial: '.$caught.' / '.$expected);
	mutation_check($GLOBALS['mutation_server']->owner===null,'Owning connection released');
}
class PmAttachmentRouteProbe extends attach_pm
{
	var $processed=0;
	var $sources=array();
	function display_attach_box_limits(){}
	function display_attachment_bodies(){}
	function handle_attachments($mode){$this->processed++;$this->sources[]=array($mode,$GLOBALS['privmsg_id'],$GLOBALS['post_id']);return true;}
}
class PmAttachmentReadForum extends MutationForum
{
	function sql_query($sql)
	{
		mutation_check(preg_match('/^SELECT (user_id FROM fixture_users|privmsgs_write_payload FROM fixture_messages) WHERE /D',$sql)===1,'Only composer preflight SELECTs use forum connection');
		return $GLOBALS['mutation_server']->pdo->query($sql);
	}
	function sql_fetchrowset($result){return $result->fetchAll(PDO::FETCH_ASSOC);}
	function sql_freeresult($result){$result->closeCursor();}
}
$db=new PmAttachmentReadForum();
mutation_check(mkdir($upload_dir,0700),'Owned action fixture directory');
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try
{
	foreach(array('english','german') as $locale)
	{
		include $forum_root.'language/lang_'.$locale.'/lang_main.php';
		foreach(array('inbox','outbox','sentbox','savebox') as $selected_folder)
		{
			pm_cleanup_fixture();$folder=$selected_folder;$id=$folder==='sentbox'?21:20;
			$userdata['user_id']=in_array($folder,array('outbox','sentbox'),true)?8:7;
			if($folder==='savebox'){$mutation_server->pdo->exec('UPDATE fixture_messages SET privmsgs_type=3 WHERE privmsgs_id=20');}
			$before_text=$mutation_server->pdo->query('SELECT * FROM fixture_message_text ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
			phpbb_pm_delete_attachments(array($id),$userdata['user_id'],$folder);
			phpbb_pm_delete_attachments(array($id),$userdata['user_id'],$folder);
			mutation_check(pm_scalar('SELECT COUNT(*) FROM fixture_messages')===2,'Attachment-only action keeps messages');
			mutation_check($before_text===$mutation_server->pdo->query('SELECT * FROM fixture_message_text ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC),'Attachment-only action keeps bodies');
			mutation_check(pm_scalar('SELECT COUNT(*) FROM fixture_links WHERE privmsgs_id='.$id)===0 && $mutation_server->count_rows(ATTACHMENTS_TABLE)===1 && is_file($upload_dir.'/fixture.txt'),'Only selected reference removed; other copy and shared bytes retained');
		}
		foreach(array('get','sid','foreign','mixed','pending','invalid','inactive') as $case)
		{
			pm_cleanup_fixture();$userdata['user_id']=7;$ids=array(20);$expected=$lang['Not_Authorised'];
			if($case==='get'){$_SERVER['REQUEST_METHOD']='GET';$expected=$lang['Session_invalid'];}
			elseif($case==='sid'){$_POST['sid']='bad';$expected=$lang['Session_invalid'];}
			elseif($case==='foreign'){$userdata['user_id']=99;}
			elseif($case==='mixed'){$ids=array(20,21);}
			elseif($case==='pending'){$mutation_server->pdo->exec("UPDATE fixture_messages SET privmsgs_write_payload='pending' WHERE privmsgs_id=20");$expected=$lang['PM_write_pending'];}
			elseif($case==='inactive'){$mutation_server->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=7');}
			else{$ids=array(20,null);$expected=$lang['PM_journal_changed'];}
			pm_attachment_failure(function() use($ids){phpbb_pm_delete_attachments($ids,$GLOBALS['userdata']['user_id'],'inbox');},$expected);
			mutation_check($mutation_server->count_rows(ATTACHMENTS_TABLE)===2 && is_file($upload_dir.'/fixture.txt'),'Denied action changes no attachment');
		}
		foreach(array('moved','pending','revoked') as $case)
		{
			pm_cleanup_fixture();$userdata['user_id']=7;
			$mutation_server->hook=function($sql) use($case)
			{
				if(strpos($sql,'DELETE FROM fixture_links')!==0){return;}$s=$GLOBALS['mutation_server'];$s->hook=null;
				if($case==='moved'){$s->pdo->exec('UPDATE fixture_messages SET privmsgs_to_userid=99 WHERE privmsgs_id=20');}
				elseif($case==='pending'){$s->pdo->exec("UPDATE fixture_messages SET privmsgs_write_payload='pending' WHERE privmsgs_id=20");}
				else{$s->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=7');}
			};
			pm_attachment_failure(function(){phpbb_pm_delete_attachments(array(20),7,'inbox');},$lang['Not_Authorised']);
			mutation_check($mutation_server->count_rows(ATTACHMENTS_TABLE)===2 && is_file($upload_dir.'/fixture.txt'),'Current parent/actor checked inside link deletion');
		}
		foreach(array('guest','bad-sid','foreign','own') as $case)
		{
			pm_cleanup_fixture();$userdata['user_id']=$case==='foreign'?99:7;$folder='inbox';$mode='';$privmsg_id=20;$sid='fixture-sid';$_POST['pm_delete_attach']='1';$_POST['mark']=array(20);
			if($case==='guest'){$userdata['session_logged_in']=false;}
			if($case==='bad-sid'){$sid='bad';}
			$caught='';$redirected=false;try{pm_attachment_gate();}catch(PmAttachmentRedirect $e){$redirected=true;}catch(RuntimeException $e){$caught=$e->getMessage();}
			mutation_check($redirected===in_array($case,array('guest','own'),true),'Controller routes login/success before parser');
			if($case==='bad-sid'||$case==='foreign'){mutation_check($caught===$lang[$case==='bad-sid'?'Session_invalid':'Not_Authorised'],'Controller denies invalid early mutation');}
			mutation_check($mutation_server->count_rows(ATTACHMENTS_TABLE)===($case==='own'?1:2),'Actual controller only deletes owned links');
		}
		pm_cleanup_fixture();$attach_config['allow_pm_attach']=1;$folder='inbox';$submit=false;$refresh=false;
		$probe=(new ReflectionClass('PmAttachmentRouteProbe'))->newInstanceWithoutConstructor();
		foreach(array('','newpm','read','unknown') as $route){$probe->privmsgs_attachment_mod($route);}
		mutation_check($probe->processed===0,'Non-compose routes never enter upload/edit handling or duplicate normal deletion');
		foreach(array('guest','recipient','read','pending','inactive','disabled','bad-sid','get','post') as $case)
		{
			pm_cleanup_fixture();$privmsg_id=20;$post_id=321;$folder='outbox';$expected=$lang['Not_Authorised'];$refresh=false;
			$probe=(new ReflectionClass('PmAttachmentRouteProbe'))->newInstanceWithoutConstructor();
			if($case==='guest'){$userdata['session_logged_in']=false;}
			elseif($case==='recipient'){$userdata['user_id']=7;}
			elseif($case==='read'){$mutation_server->pdo->exec('UPDATE fixture_messages SET privmsgs_type=0 WHERE privmsgs_id=20');}
			elseif($case==='pending'){$mutation_server->pdo->exec("UPDATE fixture_messages SET privmsgs_write_payload='pending' WHERE privmsgs_id=20");$expected=$lang['PM_write_pending'];}
			elseif($case==='inactive'){$mutation_server->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=8');}
			elseif($case==='disabled'){$mutation_server->pdo->exec('UPDATE fixture_users SET user_allow_pm=0 WHERE user_id=8');}
			elseif($case==='bad-sid'){$_POST['sid']='bad';$expected=$lang['Session_invalid'];}
			elseif($case==='get'){$_SERVER['REQUEST_METHOD']='GET';$_POST=array();}
			if(in_array($case,array('get','post'),true))
			{
				$probe->privmsgs_attachment_mod('edit');
				mutation_check($probe->sources===array(array('edit',20,20)),'Own undelivered source reaches compose parser');
			}
			else
			{
				pm_attachment_failure(function() use($probe){$probe->privmsgs_attachment_mod('edit');},$expected);
				mutation_check($probe->processed===0,'Denied source never reaches parser: '.$case);
			}
			mutation_check($privmsg_id===20&&$post_id===321,'Controller source globals retained');
			mutation_check($mutation_server->count_rows(ATTACHMENTS_TABLE)===2,'Compose preflight does not mutate attachment links');
		}
		foreach(array('post','reply','quote') as $route)
		{
			pm_cleanup_fixture();$privmsg_id=20;$post_id=321;$refresh=false;
			$probe=(new ReflectionClass('PmAttachmentRouteProbe'))->newInstanceWithoutConstructor();$probe->privmsgs_attachment_mod($route);
			mutation_check($probe->sources===array(array($route==='quote'?'reply':$route,0,0)),'New/reply/quote never inherit source attachment permissions');
			mutation_check($privmsg_id===20&&$post_id===321,'Original reply/quote source retained for message controller');
		}
		echo $locale." PN attachment-only owner/session/controller checks passed.\n";
	}
}
finally
{
	if($mutation_server->owner!==null){$mutation_server->owner->sql_close();}
	if(is_file($upload_dir.'/fixture.txt')){unlink($upload_dir.'/fixture.txt');}rmdir($upload_dir);restore_error_handler();
}
