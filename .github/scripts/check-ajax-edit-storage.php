<?php
require __DIR__ . '/check-posting-lifecycle.php';
foreach (array('AUTH_ALL'=>0,'AUTH_LIST_ALL'=>0,'AUTH_REG'=>1,'AUTH_ACL'=>2,'AUTH_MOD'=>3,'AUTH_ADMIN'=>5,'ADMIN'=>1,'AUTH_ACCESS_TABLE'=>'fixture_auth','USER_GROUP_TABLE'=>'fixture_groups') as $key=>$value) { if(!defined($key)) { define($key,$value); } }
require $forum_root . 'includes/php_compat.php';
require_once $forum_root . 'includes/auth.php';
require_once $forum_root . 'attach_mod/includes/functions_includes.php';
require $forum_root . 'includes/bbcode.php';
require $forum_root . 'includes/functions_ajax_storage.php';
foreach (array('Ajax_edit_invalid_text','Ajax_edit_too_large','Empty_subject','Empty_message','Auth_Anonymous_Users','Auth_Registered_Users','Auth_Users_granted_access','Auth_Moderators','Auth_Administrators') as $key) { $lang[$key]=$key; }
function ajax_storage_fixture()
{
	global $mutation_server,$userdata,$board_config,$tree;
	posting_fixture(); $p=$mutation_server->pdo;
	$userdata['session_logged_in']=true; $userdata['user_allowhtml']=false;
	$board_config['allow_html']=false; $board_config['allow_bbcode']=true; $board_config['allow_smilies']=false;
	$p->exec('ALTER TABLE fixture_users ADD username VARCHAR(255)');
	$p->exec("UPDATE fixture_users SET username='Fixture user'");
	$fields=array('auth_view','auth_read','auth_news','auth_post','auth_reply','auth_edit','auth_delete','auth_cal','auth_sticky','auth_announce','auth_global_announce','auth_vote','auth_pollcreate','auth_ban','auth_greencard','auth_bluecard','auth_attachments','auth_download');
	$columns=array('forum_id INTEGER','group_id INTEGER','auth_mod INTEGER DEFAULT 0');
	foreach ($fields as $field) { $p->exec('ALTER TABLE fixture_forums ADD '.$field.' INTEGER DEFAULT 1'); $columns[]=$field.' INTEGER DEFAULT 0'; }
	$p->exec('CREATE TABLE fixture_auth ('.implode(',',$columns).')');
	$p->exec('CREATE TABLE fixture_groups (user_id INTEGER,group_id INTEGER,user_pending INTEGER)');
	$tree=array('data'=>array(array('forum_id'=>3,'auth_edit'=>0,'auth_view'=>0,'auth_read'=>0)),'keys'=>array('f3'=>0),'type'=>array('f'));
}
function ajax_storage_failure($callback,$expected)
{
	$caught=false;
	try { call_user_func($callback); } catch (PhpbbAjaxStorageException $error) { $caught=$error->getMessage()===$expected; }
	mutation_check($caught,'Expected AJAX error: '.$expected);
	mutation_check($GLOBALS['mutation_server']->owner===null,'Failed edit releases owning connection');
}
// These isolate only the transport and the optional censor lookup.
class AjaxFixtureResponse extends RuntimeException
{
	var $value;
	function __construct($value) { $this->value=$value; parent::__construct('AJAX fixture response'); }
}
function AJAX_message_die($value) { throw new AjaxFixtureResponse($value); }
function obtain_word_list(&$words,&$replacements) { $words=array(); $replacements=array(); }
define('AJAX_ERROR',-1); define('AJAX_POST_SUBJECT_EDITED',2); define('AJAX_POST_TEXT_EDITED',3);
set_error_handler(function($severity,$message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
	foreach (array('subject','text') as $field)
	{
		ajax_storage_fixture();
		$value="Grüße author's C:\\draft %20 + 😀";
		$result=phpbb_ajax_edit_post($db,10,$field,$value);
		mutation_check($result['value']===$value,'Plain UTF-8/quotes/backslashes/percent signs round-trip without slash accumulation');
		mutation_check(posting_value('SELECT post_'.$field.' FROM fixture_post_text WHERE post_id=10')===$value,'Stored content matches the response representation');
		mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_matches WHERE post_id=10 AND title_match='.($field==='text'?1:0))===1,'Partial edit preserves other field index');
		mutation_check((int)posting_value('SELECT user_posts FROM fixture_users WHERE user_id=8')===1 && !$mutation_server->owner,'AJAX edits leave counts untouched and release owner');
		phpbb_ajax_edit_post($db,10,$field,$value);
		mutation_check(posting_value('SELECT post_'.$field.' FROM fixture_post_text WHERE post_id=10')===$value,'Repeated unchanged edit remains valid');
	}
	ajax_storage_fixture();
	$result=phpbb_ajax_edit_post($db,10,'subject',str_repeat('ä',59).'😀extra');
	mutation_check($result['value']===str_repeat('ä',59).'😀' && preg_match('//u',$result['value'])===1,'Title limit preserves whole Unicode characters');
	$result=phpbb_ajax_edit_post($db,10,'subject',str_repeat('a',59).'äextra');
	mutation_check($result['value']===str_repeat('a',59).'ä','A character crossing the old 60-byte boundary remains intact');
	$result=phpbb_ajax_edit_post($db,10,'subject',str_repeat('&',20));
	mutation_check($result['value']===str_repeat('&amp;',12),'Title limit keeps complete HTML entities within storage capacity');
	phpbb_ajax_edit_post($db,10,'subject','0'); phpbb_ajax_edit_post($db,10,'text','0');
	mutation_check(posting_value('SELECT post_text FROM fixture_post_text WHERE post_id=10')==='0','Zero is valid nonempty content');
	ajax_storage_failure(function() use($db) { phpbb_ajax_edit_post($db,10,'subject',''); },'Empty_subject');
	ajax_storage_failure(function() use($db) { phpbb_ajax_edit_post($db,10,'text'," \n "); },'Empty_message');
	ajax_storage_failure(function() use($db) { phpbb_ajax_edit_post($db,10,'text',"bad\xC3"); },'Ajax_edit_invalid_text');
	ajax_storage_failure(function() use($db) { phpbb_ajax_edit_post($db,10,'text',str_repeat('a',1048577)); },'Ajax_edit_too_large');
	foreach(array(0,-1,'1,2',array(10),true,'99999999999999999999999999') as $id)
	{
		ajax_storage_failure(function() use($db,$id) { phpbb_ajax_edit_post($db,$id,'text','new text'); },'Topic_post_not_exist');
	}

	foreach(array('UPDATE fixture_forums SET forum_status=1'=>'Forum_locked','UPDATE fixture_topics SET topic_status=1'=>'Topic_locked','UPDATE fixture_posts SET poster_id=9'=>'Edit_own_posts','UPDATE fixture_forums SET auth_edit=5'=>'Edit_own_posts','UPDATE fixture_forums SET auth_read=5'=>'Edit_own_posts','UPDATE fixture_topics SET forum_id=4'=>'Topic_post_not_exist','UPDATE fixture_topics SET topic_moved_id=99'=>'Topic_post_not_exist','DELETE FROM fixture_post_text'=>'Topic_post_not_exist','DELETE FROM fixture_topics'=>'Topic_post_not_exist') as $change=>$expected)
	{
		ajax_storage_fixture(); $mutation_server->pdo->exec($change);
		ajax_storage_failure(function() use($db) { phpbb_ajax_edit_post($db,10,'text','unauthorized replacement'); },$expected);
		mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_matches')===2,'Denied/stale edit leaves original index alone despite permissive cached tree');
	}
	ajax_storage_fixture(); $userdata['session_logged_in']=false;
	ajax_storage_failure(function() use($db) { phpbb_ajax_edit_post($db,10,'text','guest attempt'); },'Edit_own_posts');
	ajax_storage_fixture();
	$mutation_server->pdo->exec('INSERT INTO fixture_groups VALUES(8,7,0)');
	$mutation_server->pdo->exec('INSERT INTO fixture_auth (forum_id,group_id,auth_mod) VALUES(3,7,1)');
	$mutation_server->pdo->exec('UPDATE fixture_posts SET poster_id=9');
	$mutation_server->pdo->exec('UPDATE fixture_forums SET forum_status=1');
	phpbb_ajax_edit_post($db,10,'subject','Moderator title');
	$mutation_server->pdo->exec('UPDATE fixture_auth SET auth_mod=0');
	ajax_storage_failure(function() use($db) { phpbb_ajax_edit_post($db,10,'text','revoked moderator'); },'Edit_own_posts');

	ajax_storage_fixture(); $newreply=posting_submit('reply');
	$mutation_server->pdo->exec('UPDATE fixture_topics SET topic_first_post_id='.$newreply[0].',topic_last_post_id=10');
	$mutation_server->pdo->exec('UPDATE fixture_posts SET post_edit_count=NULL WHERE post_id=10');
	$result=phpbb_ajax_edit_post($db,10,'subject','Actual first post');
	mutation_check(posting_value('SELECT topic_title FROM fixture_topics WHERE topic_id=100')==='Actual first post','Title update uses actual first post, not cached ID');
	mutation_check((int)$result['post']['post_edit_count']===1 && (int)posting_value('SELECT post_edit_count FROM fixture_posts WHERE post_id=10')===1,'Actual last-post bounds and NULL count yield truthful edit metadata');
	phpbb_ajax_edit_post($db,$newreply[0],'subject','');
	mutation_check(posting_value('SELECT topic_title FROM fixture_topics WHERE topic_id=100')==='Actual first post','Empty reply subject does not erase topic title');

	ajax_storage_fixture(); $interleaved=false;
	$mutation_server->hook=function($sql) use(&$interleaved)
	{
		if($interleaved || strpos($sql,'UPDATE fixture_post_text')!==0) { return; }
		$interleaved=true;
		mutation_expect_failure(function() { posting_delete(); },'busy');
		$caught=false;
		try { phpbb_ajax_edit_post($GLOBALS['db'],10,'subject','competing title'); } catch(PhpbbAjaxStorageException $error) { $caught=$error->getMessage()==='busy'; }
		mutation_check($caught,'Concurrent AJAX writer cannot interleave');
	};
	phpbb_ajax_edit_post($db,10,'text','serialized text'); $mutation_server->hook=null;
	mutation_check($interleaved && !$mutation_server->owner,'Normal and AJAX storage share the same owner boundary');
	posting_delete();
	ajax_storage_failure(function() use($db) { phpbb_ajax_edit_post($db,10,'text','late edit'); },'Topic_post_not_exist');

	foreach(array('SELECT p.post_id','SELECT a.forum_id','UPDATE fixture_post_text','DELETE FROM fixture_matches','INSERT INTO fixture_matches') as $failure)
	{
		ajax_storage_fixture(); $mutation_server->failure=$failure;
		ajax_storage_failure(function() use($db) { phpbb_ajax_edit_post($db,10,'text','quasarword replacement'); },'Posting_storage_failed');
		if($failure==='UPDATE fixture_post_text') { mutation_check(posting_value('SELECT post_text FROM fixture_post_text WHERE post_id=10')==='originalword' && (int)posting_value('SELECT COUNT(*) FROM fixture_matches')===2,'Failed text SQL retains old body and index'); }
	}
	foreach(array('UPDATE fixture_posts SET poster_id=9','DELETE FROM fixture_post_text') as $change)
	{
		ajax_storage_fixture();
		$mutation_server->hook=function($sql) use($change) { if(strpos($sql,'UPDATE fixture_post_text')===0) { $GLOBALS['mutation_server']->pdo->exec($change); } };
		ajax_storage_failure(function() use($db) { phpbb_ajax_edit_post($db,10,'text','stale replacement'); },'Posting_target_changed');
		mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_matches')===2,'Lost parent/text is not reported as an unchanged successful edit');
	}
	// Execute both actual controller branches. Censor lookup is deliberately
	// empty; request decoding, storage and the response fields are production code.
	$ajax_source=file_get_contents($forum_root.'ajax.php');
	$helper_start=strpos($ajax_source,'function ajax_scalar_value(');
	$helper_end=strpos($ajax_source,'// Get SID and check it',$helper_start);
	mutation_check($helper_start!==false && $helper_end>$helper_start,'Locate actual AJAX request helpers');
	eval(substr($ajax_source,$helper_start,$helper_end-$helper_start));
	$functions_source=file_get_contents($forum_root.'includes/functions.php');
	foreach(array('utf8_rawurldecode','unhtmlspecialchars') as $name)
	{
		$start=strpos($functions_source,'function '.$name.'('); $end=strpos($functions_source,"\n}",$start);
		mutation_check($start!==false && $end>$start,'Locate actual response/request text conversion');
		eval(substr($functions_source,$start,$end+2-$start));
	}
	$start=strpos($ajax_source,"if (\$mode == 'edit_post_subject')"); $end=strpos($ajax_source,'// Voting/Viewing of polls',$start);
	mutation_check($start!==false && $end>$start,'Locate both actual edit branches');
	$branches=substr($ajax_source,$start,$end-$start);
	foreach(array('edit_post_subject','edit_post_text') as $mode)
	{
		ajax_storage_fixture(); $mutation_server->pdo->exec('UPDATE fixture_posts SET enable_bbcode=0');
		$expected="Grüße author's C:\\draft %20 + 😀";
		$HTTP_POST_VARS=array('p'=>'10','subject'=>addslashes($expected),'message'=>addslashes($expected)); $HTTP_GET_VARS=array();
		$response=null;
		try { eval($branches); } catch(AjaxFixtureResponse $reply) { $response=$reply->value; }
		mutation_check(is_array($response) && $response['result']!==AJAX_ERROR,'Controller emits a success response');
		$key=$mode==='edit_post_text'?'rawmessage':'rawsubject';
		mutation_check($response[$key]===$expected,'Actual response draft preserves UTF-8, quotes, percent signs and backslashes');
		if($mode==='edit_post_text') { mutation_check(strpos($response['message'],'Grüße')!==false,'Actual rendered response is not blank'); }
		$mutation_server->pdo->exec('UPDATE fixture_forums SET forum_status=1');
		$response=null;
		try { eval($branches); } catch(AjaxFixtureResponse $reply) { $response=$reply->value; }
		mutation_check($response['result']===AJAX_ERROR && $response['error_msg']==='Forum_locked','Controlled failures stay in the AJAX response format');
	}
	echo "AJAX edit storage, current ACLs, Unicode titles and shared writer checks passed.\n";
}

finally
{
	if(isset($mutation_server) && $mutation_server->owner!==null) { $mutation_server->owner->sql_close(); }
	restore_error_handler();
}
