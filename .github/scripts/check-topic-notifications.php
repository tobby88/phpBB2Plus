<?php
require __DIR__ . '/check-ajax-edit-storage.php';
define('AUTH_ATTACH',11); define('AUTH_DOWNLOAD',20);
foreach (array('AUTH_VIEW'=>1,'AUTH_READ'=>2,'BANLIST_TABLE'=>'fixture_bans','TOPIC_WATCH_UN_NOTIFIED'=>0,'TOPIC_WATCH_NOTIFIED'=>1) as $key=>$value) { if(!defined($key)) { define($key,$value); } }
require $forum_root . 'includes/functions_topic_notifications.php';
require $forum_root . 'includes/functions_bookmark.php';
foreach (array('Topic_preference_failed','Session_invalid','Topic_unwatch_confirm') as $key) { $lang[$key]=$key; }
function phpbb_board_url($path) { return 'https://fixture.invalid/'.$path; }
class NotificationFixtureRedirect extends RuntimeException {}
function redirect($url) { throw new NotificationFixtureRedirect($url); }
function notification_fixture()
{
	global $mutation_server,$userdata,$board_config,$notification_deliveries,$notification_delivery_hook,$notification_delivery_result;
	ajax_storage_fixture(); $p=$mutation_server->pdo;
	foreach (array('user_level INTEGER DEFAULT 0','user_active INTEGER DEFAULT 1','user_email VARCHAR(255)','user_lang VARCHAR(32)','user_blocktime INTEGER DEFAULT 0') as $column) { $p->exec('ALTER TABLE fixture_users ADD '.$column); }
	$p->exec("UPDATE fixture_users SET user_email='recipient@example.invalid',user_lang='english'");
	$p->exec("UPDATE fixture_topics SET topic_title='Current Grüße 😀 title'");
	$p->exec('CREATE TABLE fixture_bans (ban_userid INTEGER)');
	$p->exec('ALTER TABLE fixture_watches ADD notify_status INTEGER NOT NULL DEFAULT 0');
	$p->exec("ALTER TABLE fixture_watches ADD notify_claim CHAR(32) NOT NULL DEFAULT ''");
	$p->exec('ALTER TABLE fixture_watches ADD notify_claimed_at INTEGER NOT NULL DEFAULT 0');
	$p->exec('INSERT INTO fixture_watches (topic_id,user_id) VALUES (100,9)');
	$userdata['session_id']=str_repeat('a',32);
	$board_config+=array('smtp_delivery'=>0,'board_email'=>'forum@example.invalid','board_email_sig'=>'','sitename'=>'Fixture');
	$GLOBALS['lang']['Topic_reply_notification']='Reply';
	$notification_deliveries=array(); $notification_delivery_hook=null; $notification_delivery_result=true;
}
function notification_failure($callback,$expected)
{
	$caught=false;
	try { call_user_func($callback); } catch(PhpbbTopicPreferenceException $error) { $caught=$error->getMessage()===$expected; }
	mutation_check($caught,'Expected preference failure: '.$expected);
	mutation_check($GLOBALS['mutation_server']->owner===null,'Preference failure releases owner');
}
function notification_send()
{
	global $db,$phpbb_root_path;
	$original_root=$phpbb_root_path;
	$phpbb_root_path=$GLOBALS['forum_root'].'../.github/fixtures/topic-notifications/';
	try { phpbb_send_topic_notifications($db,10); }
	finally { $phpbb_root_path=$original_root; }
}
set_error_handler(function($severity,$message) { if(error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
	notification_fixture();
	foreach(array('watch','bookmark') as $kind)
	{
		mutation_check(phpbb_topic_preference($db,100,$kind,false)===false,'Remove existing own preference');
		mutation_check(phpbb_topic_preference($db,100,$kind,false)===false,'Repeated removal is idempotent');
		mutation_check(phpbb_topic_preference($db,100,$kind,true)===true && phpbb_topic_preference($db,100,$kind,true)===true,'Repeated add succeeds');
		$table=$kind==='watch'?'fixture_watches':'fixture_bookmarks';
		mutation_check((int)posting_value('SELECT COUNT(*) FROM '.$table.' WHERE user_id=8 AND topic_id=100')===1,'Repeated add creates one preference');
	}
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_watches WHERE user_id=9')===1,'Other account untouched');
	foreach(array(0,-1,'1x',array(100),'16777216') as $id) { notification_failure(function() use($db,$id) { phpbb_topic_preference($db,$id,'watch',true); },'Session_invalid'); }
	$userdata['session_logged_in']=false;
	notification_failure(function() use($db) { phpbb_topic_preference($db,100,'watch',false); },'Session_invalid');
	$userdata['session_logged_in']=true;
	$mutation_server->pdo->exec('UPDATE fixture_forums SET auth_read=5 WHERE forum_id=3');
	notification_failure(function() use($db) { phpbb_topic_preference($db,100,'watch',true); },'Topic_post_not_exist');
	mutation_check(phpbb_topic_preference($db,100,'watch',false)===false,'May unsubscribe despite lost private forum access');
	$mutation_server->pdo->exec('DELETE FROM fixture_topics WHERE topic_id=100');
	mutation_check(phpbb_topic_preference($db,100,'bookmark',false)===false,'May remove own orphan bookmark');
	notification_failure(function() use($db) { phpbb_topic_preference($db,100,'watch',true); },'Topic_post_not_exist');

	notification_fixture(); $held=new attach_mutation_lock($db); $busy=false;
	try { phpbb_topic_preference($db,100,'watch',false); } catch(PhpbbTopicPreferenceException $error) { $busy=$error->getMessage()==='busy'; }
	mutation_check($busy && $mutation_server->owner===$held->connection,'Competing session cannot mutate or release another writer lock'); $held->release();
	$mutation_server->failure='DELETE FROM fixture_watches';
	notification_failure(function() use($db) { phpbb_topic_preference($db,100,'watch',false); },'Topic_preference_failed');
	$mutation_server->failure='';

	// Real auth(), no cached tree shortcut; inactive/banned/blocked/invalid mail
	// identities must not become delivery tasks, even with an old watch row.
	foreach(array('UPDATE fixture_forums SET auth_view=5','UPDATE fixture_forums SET auth_read=5','UPDATE fixture_users SET user_active=0 WHERE user_id=9',"UPDATE fixture_users SET user_email='invalid' WHERE user_id=9",'UPDATE fixture_users SET user_blocktime=2147483647 WHERE user_id=9','INSERT INTO fixture_bans VALUES (9)') as $change)
	{
		notification_fixture(); $mutation_server->pdo->exec($change);
		mutation_check(phpbb_topic_notifications_prepare($db,10)===array(),'Reject currently unauthorized recipient: '.$change);
	}
	notification_fixture();
	$mutation_server->pdo->exec('INSERT INTO fixture_watches (topic_id,user_id) VALUES (100,9)');
	$tasks=phpbb_topic_notifications_prepare($db,10);
	mutation_check(count($tasks)===1 && $tasks[0]['user_id']===9,'Duplicate legacy watches result in one claim, author excluded');
	mutation_check(phpbb_topic_notifications_prepare($db,10)===array(),'Parallel reply cannot claim already reserved recipients');
	$ready=phpbb_topic_notification_ready($db,$tasks[0]);
	mutation_check($ready['topic']['topic_title']==='Current Grüße 😀 title','Notification uses current stored Unicode title');
	$mutation_server->pdo->exec('UPDATE fixture_forums SET auth_read=5 WHERE forum_id=3');
	mutation_check(phpbb_topic_notification_ready($db,$tasks[0])===false,'Revoke ACL between preparation and delivery');
	mutation_check((int)posting_value('SELECT SUM(notify_status) FROM fixture_watches')===0,'Suppressed claim becomes retryable, not a permanent notification');

	foreach(array('UPDATE fixture_topics SET forum_id=4','DELETE FROM fixture_posts WHERE post_id=10','DELETE FROM fixture_watches WHERE user_id=9','UPDATE fixture_users SET user_active=0 WHERE user_id=9') as $change)
	{
		notification_fixture(); $tasks=phpbb_topic_notifications_prepare($db,10); $mutation_server->pdo->exec($change);
		mutation_check(phpbb_topic_notification_ready($db,$tasks[0])===false,'Recheck changed target/account/watch before mail: '.$change);
	}
	notification_fixture(); $tasks=phpbb_topic_notifications_prepare($db,10);
	$mutation_server->pdo->exec("UPDATE fixture_users SET user_email='new@example.invalid' WHERE user_id=9");
	$ready=phpbb_topic_notification_ready($db,$tasks[0]); mutation_check($ready['user']['user_email']==='new@example.invalid','Delivery uses current account address');
	$userdata['user_id']=9; phpbb_topic_preference($db,100,'watch',null);
	phpbb_topic_notification_finish($db,$tasks[0],true);
	mutation_check((int)posting_value('SELECT notify_status FROM fixture_watches WHERE user_id=9')===0,'Late mail completion cannot undo concurrent read acknowledgement');
	$second=phpbb_topic_notifications_prepare($db,10);
	phpbb_topic_notification_finish($db,$tasks[0],false);
	mutation_check(posting_value('SELECT notify_claim FROM fixture_watches WHERE user_id=9')===$second[0]['claim'],'Old completion cannot release a newer claim');
	$mutation_server->pdo->exec('UPDATE fixture_watches SET notify_claimed_at=1 WHERE user_id=9');
	$retry=phpbb_topic_notifications_prepare($db,10);
	mutation_check(count($retry)===1 && $retry[0]['claim']!==$second[0]['claim'],'Interrupted expired claim can be retried by later reply');
	phpbb_topic_notification_finish($db,$retry[0],false);
	mutation_check((int)posting_value('SELECT notify_status FROM fixture_watches WHERE user_id=9')===0,'Reported transport rejection releases only its own claim');

	notification_fixture(); notification_send();
	mutation_check(count($notification_deliveries)===1,'Actual dispatcher delivers one mail to test sink');
	mutation_check($notification_deliveries[0]['vars']['U_STOP_WATCHING_TOPIC']==='https://fixture.invalid/topic_watch.php?t=100','Email unsubscribe URL is a session-independent confirmation page');
	mutation_check($notification_deliveries[0]['vars']['TOPIC_TITLE']==='Current Grüße 😀 title','Actual mail preserves current Unicode title');
	notification_send(); mutation_check(count($notification_deliveries)===1,'Notified recipient does not get duplicate mail');
	notification_fixture(); $notification_delivery_result=false; notification_send();
	mutation_check((int)posting_value('SELECT notify_status FROM fixture_watches WHERE user_id=9')===0,'Dispatcher restores eligibility after explicit transport failure');
	notification_fixture();
	$mutation_server->pdo->exec("INSERT INTO fixture_users (user_id,user_active,user_level,user_email,user_lang,user_blocktime) VALUES (10,1,0,'second@example.invalid','english',0)");
	$mutation_server->pdo->exec('INSERT INTO fixture_watches (topic_id,user_id) VALUES (100,10)');
	$notification_delivery_hook=function() { throw new PhpbbMailException('FIXTURE_SECRET_REMOTE_REPLY'); };
	$log=tmpfile(); $log_meta=stream_get_meta_data($log); $previous_log=ini_get('error_log'); ini_set('error_log',$log_meta['uri']);
	try
	{
		notification_send();
		$logged=file_get_contents($log_meta['uri']);
		mutation_check(strpos($logged,'optional topic notification delivery failed')!==false && strpos($logged,'FIXTURE_SECRET_REMOTE_REPLY')===false,'Optional error logs are actionable but contain no remote detail');
	}
	finally { ini_set('error_log',$previous_log); fclose($log); }
	mutation_check(count($notification_deliveries)===1 && (int)posting_value('SELECT SUM(notify_status) FROM fixture_watches')===0,'Optional failure returns normally, stops repeated network attempts and releases remaining claims');
	notification_fixture(); $notification_delivery_hook=function() use(&$userdata,$db) { $userdata['user_id']=9; phpbb_topic_preference($db,100,'watch',null); }; notification_send();
	mutation_check((int)posting_value('SELECT notify_status FROM fixture_watches WHERE user_id=9')===0,'Actual delivery permits concurrent reader without stale overwrite');

	$ajax=file_get_contents($forum_root.'ajax.php'); $view=file_get_contents($forum_root.'viewtopic.php'); $post=file_get_contents($forum_root.'includes/functions_post.php');
	mutation_check(strpos($ajax,"phpbb_topic_preference(\$db, \$topic_id, 'watch'")!==false && strpos($view,"phpbb_topic_preference(\$db, \$topic_id, 'watch'")!==false && strpos($post,"phpbb_topic_preference(\$db, \$topic_id, 'watch'")!==false,'All interactive watch writers use shared worker');
	// Execute the actual confirmation controller without bootstrap or rendering.
	$endpoint=file_get_contents($forum_root.'topic_watch.php'); $start=strpos($endpoint,'$topic_id ='); $end=strpos($endpoint,'$page_title =',$start);
	mutation_check($start!==false && $end>$start,'Locate complete confirmation decision path'); $controller=substr($endpoint,$start,$end-$start);
	notification_fixture(); $_GET=array('t'=>'100','confirm'=>'1'); $_POST=array(); $_SERVER['REQUEST_METHOD']='GET';
	eval($controller);
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_watches WHERE user_id=8')===1,'GET including confirm cannot unsubscribe');
	$_SERVER['REQUEST_METHOD']='POST'; $_POST=array('t'=>'100','confirm'=>'1');
	mutation_expect_failure(function() use($controller) { global $db,$userdata,$lang,$phpEx; eval($controller); },'Session_invalid');
	$_POST['sid']=$userdata['session_id']; $_POST['action_token']=phpbb_session_action_token('topic-preference','unwatch',999,$userdata['session_id']);
	mutation_expect_failure(function() use($controller) { global $db,$userdata,$lang,$phpEx; eval($controller); },'Session_invalid');
	$_POST['action_token']=phpbb_session_action_token('topic-preference','unwatch',100,$userdata['session_id']); $lang['No_longer_watching']='Unsubscribed';
	mutation_expect_failure(function() use($controller) { global $db,$userdata,$lang,$phpEx; eval($controller); },'Unsubscribed');
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_watches WHERE user_id=8')===0 && (int)posting_value('SELECT COUNT(*) FROM fixture_watches WHERE user_id=9')===1,'Valid confirmation removes only current user');
	mutation_expect_failure(function() use($controller) { global $db,$userdata,$lang,$phpEx; eval($controller); },'Unsubscribed');
	$_POST=array(); $_SERVER['REQUEST_METHOD']='GET'; $userdata['session_logged_in']=false; $redirected='';
	try { eval($controller); } catch(NotificationFixtureRedirect $redirect) { $redirected=$redirect->getMessage(); }
	mutation_check($redirected==='login.php?redirect=topic_watch.php&t=100','Guest login return preserves confirmation route and target');
	define('AJAX_WATCH_TOPIC',6);
	$start=strpos($ajax,"else if (\$mode == 'watch_topic')"); $end=strpos($ajax,"else if (\$mode == 'lock_topic')",$start);
	mutation_check($start!==false && $end>$start,'Locate actual AJAX watch response branch'); $branch=substr($ajax,$start+5,$end-$start-5);
	notification_fixture(); $mode='watch_topic';
	$images=array('topic_un_watch'=>'stop.png','Topic_watch'=>'start.png'); $lang['Start_watching_topic']='Watch'; $lang['Stop_watching_topic']='Unwatch';
	$HTTP_POST_VARS=array('t'=>'100','watch_status'=>'1'); $HTTP_GET_VARS=array();
	foreach(array('1','1','0','0') as $desired)
	{
		$HTTP_POST_VARS['watch_status']=$desired; $response=null;
		try { eval($branch); } catch(AjaxFixtureResponse $reply) { $response=$reply->value; }
		mutation_check($response['result']===AJAX_WATCH_TOPIC && $response['watching']===(int)$desired,'AJAX repeated desired state returns success');
		parse_str(parse_url($response['linkurl'],PHP_URL_QUERY),$fallback);
		$action=$desired==='1'?'unwatch':'watch';
		mutation_check($fallback['action_token']===phpbb_session_action_token('topic-preference',$action,100,$userdata['session_id']),'AJAX fallback remains action-bound');
	}
	$HTTP_POST_VARS['watch_status']='invalid'; $response=null;
	try { eval($branch); } catch(AjaxFixtureResponse $reply) { $response=$reply->value; }
	mutation_check($response['result']===AJAX_ERROR && $response['error_msg']==='Session_invalid','Invalid state never silently unsubscribes');
	echo "Topic preference, current notification ACL, mail claims and delivery lifecycle checks passed.\n";
}
finally { if(isset($mutation_server) && $mutation_server->owner!==null) { $mutation_server->owner->sql_close(); } restore_error_handler(); }
