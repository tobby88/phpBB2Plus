<?php
$fixture = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/check-private-message-cleanup.php'));
$end = strpos($fixture, "\nset_error_handler(");
if ($end === false) { throw new RuntimeException('Compose fixture boundary'); }
eval(substr($fixture, 5, $end - 5));
define('ADMIN',1);
require_once $forum_root . 'includes/functions_pm_compose_attachments.php';
class PmComposeTemplate
{
	function assign_vars($values) {}
	function assign_block_vars($name, $values) {}
}
function pm_compose_fixture()
{
	pm_cleanup_fixture();
	$GLOBALS['mutation_server']->pdo->exec('ALTER TABLE fixture_descriptions ADD COLUMN download_count INTEGER DEFAULT 0');
	$GLOBALS['mutation_server']->pdo->exec('ALTER TABLE fixture_descriptions ADD COLUMN pm_write_token CHAR(32) DEFAULT NULL');
	$GLOBALS['mutation_server']->pdo->exec('ALTER TABLE fixture_descriptions ADD COLUMN pm_write_slot INTEGER DEFAULT 0');
	$GLOBALS['mutation_server']->pdo->exec('CREATE UNIQUE INDEX pm_write_slot ON fixture_descriptions (pm_write_token,pm_write_slot)');
	$GLOBALS['privmsg_id']=20;$GLOBALS['post_id']=20;$GLOBALS['userdata']['user_level']=0;
	$GLOBALS['submit']=false;$GLOBALS['preview']=false;$GLOBALS['refresh']=false;$GLOBALS['error']=false;$GLOBALS['error_msg']='';
	$GLOBALS['attach_config']=array('allow_pm_attach'=>1,'disable_mod'=>0,'max_attachments_pm'=>5,'display_order'=>0,'allow_ftp_upload'=>0);
	$GLOBALS['template']=new PmComposeTemplate();
	$GLOBALS['HTTP_POST_VARS']=&$_POST;$GLOBALS['HTTP_GET_VARS']=&$_GET;$GLOBALS['HTTP_POST_FILES']=array();$_GET=array();
}
function pm_compose_post()
{
	$_POST+=array('attachment_list'=>array('fixture.txt'),'attach_id_list'=>array('1'),'comment_list'=>array('Grüße'),
		'filename_list'=>array('fixture.txt'),'extension_list'=>array('txt'),'mimetype_list'=>array('text/plain'),
		'filesize_list'=>array('5'),'filetime_list'=>array('123'),'attach_thumbnail_list'=>array('0'));
}
function pm_compose_deny($call,$expected)
{
	$caught='';try{call_user_func($call);}catch(RuntimeException $error){$caught=$error->getMessage();}
	mutation_check($caught===$expected,'Expected compose rejection: '.$caught.' / '.$expected);
	mutation_check($GLOBALS['mutation_server']->owner===null&&$GLOBALS['db'] instanceof MutationForum,'Release and restore after failure');
}
mutation_check(mkdir($upload_dir,0700),'Owned compose directory');
mutation_check(mkdir($upload_dir.'/thumbs',0700),'Owned thumbnail directory');
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try
{
	foreach(array('english','german') as $locale)
	{
		include $forum_root.'language/lang_'.$locale.'/lang_main.php';
		include $forum_root.'language/lang_'.$locale.'/lang_main_attach.php';
		pm_compose_fixture();$_SERVER['REQUEST_METHOD']='GET';$_POST=array();
		$pm=new attach_pm();$pm->handle_attachments('edit');
		mutation_check($pm->attachment_list===array('fixture.txt'),'Actual GET parser loads owned stored attachment');
		mutation_check($mutation_server->owner===null&&$db instanceof MutationForum&&$pm->compose_database===null,'Successful parser restores owning state');
		$lock=attach_require_mutation_lock($db);$caught='';
		try
		{
			$readonly=new PhpbbPmComposeDatabase($lock->connection,'edit',20);
			try{$readonly->insert_select(PRIVMSGS_TABLE,'privmsgs_id','99',USERS_TABLE,'user_id=8');}
			catch(PhpbbAclException $error){$caught=$error->getMessage();}
		}
		finally{$lock->release();}
		mutation_check($caught===$lang['Session_invalid']&&$mutation_server->count_rows(PRIVMSGS_TABLE)===2,'GET capability also rejects inherited INSERT helper');
		pm_compose_fixture();pm_compose_post();$_POST['del_attachment']=array('fixture.txt'=>'1');
		$pm=new attach_pm();$pm->handle_attachments('edit');
		mutation_check($mutation_server->count_rows(ATTACHMENTS_TABLE)===1&&is_file($upload_dir.'/fixture.txt'),'Actual parser deletes only own reference without a nested lock');
		mutation_check($pm->attachment_list===array()&&$mutation_server->owner===null,'Actual parser removes deleted list entry and releases');
		foreach(array('read','pending','revoked','connection') as $case)
		{
			pm_compose_fixture();pm_compose_post();$_POST['del_attachment']=array('fixture.txt'=>'1');$pm=new attach_pm();
			$mutation_server->hook=function($sql,$connection) use($case)
			{
				if(strpos($sql,'DELETE FROM fixture_links')!==0){return;}$s=$GLOBALS['mutation_server'];$s->hook=null;
				if($case==='read'){$s->pdo->exec('UPDATE fixture_messages SET privmsgs_type=0 WHERE privmsgs_id=20');}
				elseif($case==='pending'){$s->pdo->exec("UPDATE fixture_messages SET privmsgs_write_payload='pending' WHERE privmsgs_id=20");}
				elseif($case==='revoked'){$s->pdo->exec('UPDATE fixture_users SET user_allow_pm=0 WHERE user_id=8');}
				else{$connection->db_connect_id=false;}
			};
			pm_compose_deny(function()use($pm){$pm->handle_attachments('edit');},$lang[$case==='connection'?'PM_cleanup_failed':'Not_Authorised']);
			mutation_check($mutation_server->count_rows(ATTACHMENTS_TABLE)===2&&is_file($upload_dir.'/fixture.txt'),'Late state change/lost owner prevents attachment mutation');
		}
		pm_compose_fixture();pm_compose_post();$_POST['del_attachment']=array('fixture.txt'=>'1');$_SERVER['REQUEST_METHOD']='GET';$pm=new attach_pm();
		pm_compose_deny(function()use($pm){$pm->handle_attachments('edit');},$lang['Session_invalid']);
		mutation_check($mutation_server->count_rows(ATTACHMENTS_TABLE)===2,'GET cannot use submitted delete controls');
		pm_compose_fixture();pm_compose_post();$GLOBALS['privmsg_id']=0;$pm=new attach_pm();
		pm_compose_deny(function()use($pm){$pm->handle_attachments('reply');},$lang['Not_Authorised']);
		foreach(array('own','foreign','published','wrong-name','mixed') as $case)
		{
			pm_compose_fixture();pm_compose_post();
			$name='pm_'.($case==='foreign'?7:8).'_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.txt';
			file_put_contents($upload_dir.'/'.$name,'owned fixture');file_put_contents($upload_dir.'/thumbs/t_'.$name,'owned thumbnail');
			try
			{
				$_POST['attachment_list']=array($name);$_POST['attach_id_list']=array('0');$_POST['attach_thumbnail_list']=array('1');$_POST['del_attachment']=array($name=>'1');
				$expected=$lang['PM_journal_changed'];
				if($case==='published'){$mutation_server->pdo->exec("INSERT INTO fixture_descriptions (physical_filename) VALUES('".$name."')");$expected=$lang['Attachment_publish_unavailable'];}
				elseif($case==='wrong-name'){$_POST['attach_id_list']=array('1');$expected=$lang['Not_Authorised'];}
				elseif($case==='mixed'){$_POST['attachment_list'][]='pm_7_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.txt';$_POST['attach_id_list'][]='0';}
				$pm=new attach_pm();
				if($case==='own')
				{
					$pm->handle_attachments('edit');
					mutation_check(!is_file($upload_dir.'/'.$name)&&!is_file($upload_dir.'/thumbs/t_'.$name),'Own temporary original AND thumbnail removed without requiring removed original');
				}
				else
				{
					pm_compose_deny(function()use($pm){$pm->handle_attachments('edit');},$expected);
					mutation_check(is_file($upload_dir.'/'.$name)&&is_file($upload_dir.'/thumbs/t_'.$name),'Foreign/registered/mismatched/mixed request preserves every file');
				}
			}
			finally
			{
				if(is_file($upload_dir.'/'.$name)){unlink($upload_dir.'/'.$name);}
				if(is_file($upload_dir.'/thumbs/t_'.$name)){unlink($upload_dir.'/thumbs/t_'.$name);}
			}
		}
		$private_stage='pm_8_cccccccccccccccccccccccccccccccc.txt';
		file_put_contents($upload_dir.'/'.$private_stage,'private upload');
		try
		{
			foreach(array('namespace','missing-list','sparse','nested') as $case)
			{
				pm_compose_fixture();$post_id=0;$is_auth=array('auth_attachments'=>1,'auth_edit'=>1);$attach_config['max_attachments']=5;
				$_POST['attachment_list']=array($private_stage);$_POST['attach_id_list']=array('0');$expected=$lang['Attachment_private_upload'];
				if($case==='missing-list'){$_POST['attachment_list']=array();$expected='Invalid attachment upload data';}
				elseif($case==='sparse'){$_POST['attachment_list']=array(2=>'fixture.txt');$_POST['attach_id_list']=array(2=>'1');$expected='Invalid attachment upload data';}
				elseif($case==='nested'){$_POST['attachment_list']=array(array('fixture.txt'));$expected='Invalid attachment upload data';}
				$post=new attach_parent();
				pm_compose_deny(function()use($post){$post->handle_attachments('newtopic');},$expected);
				mutation_check(is_file($upload_dir.'/'.$private_stage),'Post parser cannot access PM upload or bypass validation with malformed lists');
			}
			pm_compose_fixture();
			$post=new attach_parent();
			pm_compose_deny(function()use($post,$private_stage){$post->delete_temporary_attachment($private_stage);},$lang['Attachment_private_upload']);
			foreach(array('attach_list','last_attachment') as $publication)
			{
				$publisher=mutation_publisher($private_stage,$publication);
				pm_compose_deny(function()use($publisher,$publication){$publisher->do_insert_attachment($publication,'post',10);},$lang['Attachment_private_upload']);
			}
			mutation_check(is_file($upload_dir.'/'.$private_stage)&&$mutation_server->count_rows(ATTACHMENTS_TABLE)===2,'Both post publication paths preserve private stage');
		}
		finally{if(is_file($upload_dir.'/'.$private_stage)){unlink($upload_dir.'/'.$private_stage);}}
		// Real server filename generation; PHP 5.6's production CSPRNG requires
		// OpenSSL/mcrypt, so the minimal fixture runner may lack a provider.
		if(function_exists('random_bytes')||function_exists('openssl_random_pseudo_bytes')||function_exists('mcrypt_create_iv'))
		{
			pm_compose_fixture();$pm=new attach_pm();$lock=attach_require_mutation_lock($db);
			try
			{
				$pm->compose_database=new PhpbbPmComposeDatabase($lock->connection,'edit',20);$pm->extension='txt';
				$pm->prepare_physical_filename();$first=$pm->attach_filename;$pm->prepare_physical_filename();
				mutation_check($first!==$pm->attach_filename&&phpbb_pm_staged_attachment_name($first,8)===$first,'Server-generated upload names are actor-bound and independently random');
			}
			finally{$pm->compose_database=null;$lock->release();}
		}
		echo $locale." actual PN compose parser lock/current-source checks passed.\n";
	}
}
finally
{
	if($mutation_server->owner!==null){$mutation_server->owner->sql_close();}
	if(is_file($upload_dir.'/fixture.txt')){unlink($upload_dir.'/fixture.txt');}rmdir($upload_dir.'/thumbs');rmdir($upload_dir);restore_error_handler();
}
