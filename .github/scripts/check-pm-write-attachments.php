<?php
$fixture = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/check-pm-write-recovery.php'));
$end = strpos($fixture, "\nset_error_handler(");
if ($end === false) { throw new RuntimeException('Write fixture boundary missing'); }
eval(substr($fixture, 5, $end - 5));
define('MODE_THUMBNAIL',1); define('THUMB_DIR','thumbs');
require_once $root . 'attach_mod/includes/functions_attach.php';
require_once $root . 'attach_mod/includes/functions_shadow.php';
require_once $root . 'attach_mod/posting_attachments.php';
require_once $root . 'attach_mod/pm_attachments.php';
require_once $root . 'includes/functions_pm_compose_attachments.php';
$upload_dir = sys_get_temp_dir() . '/phpbb-pm-write-' . uniqid('', true);
$attach_config = array('allow_ftp_upload'=>0,'allow_pm_attach'=>1,'disable_mod'=>0);
pm_repair_check(mkdir($upload_dir,0700), 'Owned attachment fixture directory');
file_put_contents($upload_dir . '/pm_1_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.txt', 'owned upload');
function pm_attachment_fixture($engine)
{
	pm_write_fixture($engine); $pdo = $GLOBALS['pm_repair_server']->pdo;
	if ($GLOBALS['native']) { $pdo->exec('ALTER TABLE fixture_descriptions MODIFY attach_id INTEGER NOT NULL AUTO_INCREMENT'); }
	foreach (array('download_count INTEGER NOT NULL DEFAULT 0','real_filename VARCHAR(255)', 'comment VARCHAR(255)', 'extension VARCHAR(100)', 'mimetype VARCHAR(100)',
		'filesize INTEGER', 'filetime INTEGER', 'pm_write_token CHAR(32) DEFAULT NULL', 'pm_write_slot INTEGER NOT NULL DEFAULT 0') as $column)
	{
		$pdo->exec('ALTER TABLE fixture_descriptions ADD COLUMN ' . $column);
	}
	$pdo->exec('CREATE UNIQUE INDEX pm_write_slot ON fixture_descriptions (pm_write_token,pm_write_slot)');
	$pdo->exec('UPDATE fixture_links SET user_id_1=1,user_id_2=8 WHERE privmsgs_id IN (12,18)');
}
function pm_attachment_data()
{
	$data = pm_write_data();
	$data['attachments'] = array(array('id'=>1,'comment'=>'Bestehender Anhang'), array('id'=>0,'physical_filename'=>'pm_1_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.txt',
		'real_filename'=>'Grüße.txt','comment'=>'Übertragung vollständig','extension'=>'txt','mimetype'=>'text/plain','filesize'=>12,'filetime'=>700,'thumbnail'=>0));
	return $data;
}
function pm_compose_native_guards($engine)
{
	global $db,$pm_repair_server,$lang;
	foreach(array('compose','attachment-only') as $capability)
	{
		foreach(array('allowed','read','pending','owner','inactive') as $case)
		{
			pm_attachment_fixture($engine);$lock=attach_require_mutation_lock($db);$caught='';
			try
			{
				$owned=$capability==='compose'?new PhpbbPmComposeDatabase($lock->connection,'edit',12):new PhpbbPmAttachmentDatabase($lock->connection,1,'outbox',array(12));
				$pm_repair_server->hook=function($sql)use($case)
				{
					if(strpos($sql,'UPDATE fixture_pm SET privmsgs_attachment = 0')!==0){return;}$s=$GLOBALS['pm_repair_server'];$s->hook=null;
					if($case==='read'){$s->pdo->exec('UPDATE fixture_pm SET privmsgs_type=0 WHERE privmsgs_id=12');}
					elseif($case==='pending'){$s->pdo->exec("UPDATE fixture_pm SET privmsgs_write_payload='pending' WHERE privmsgs_id=12");}
					elseif($case==='owner'){$s->pdo->exec('UPDATE fixture_pm SET privmsgs_from_userid=8 WHERE privmsgs_id=12');}
					elseif($case==='inactive'){$s->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1');}
				};
				$owned->sql_query('UPDATE fixture_pm SET privmsgs_attachment = 0 WHERE privmsgs_id = 12');
			}
			catch(PhpbbAclException $error){$caught=$error->getMessage();}
			finally{$lock->release();}
			pm_repair_check($caught===($case==='allowed'?'':$lang['Not_Authorised']),'Current native '.$capability.' source guard: '.$case);
			pm_repair_check(pm_repair_value('SELECT privmsgs_attachment FROM fixture_pm WHERE privmsgs_id=12')===($case==='allowed'?0:1),'Native self-table guard prevents late invalid mutation');
		}
	}
}
function pm_attachment_complete($nonce)
{
	phpbb_pm_retry_write($nonce,0); phpbb_pm_retry_write($nonce,0); pm_write_assert(12,$nonce,false);
	pm_repair_check(pm_repair_value("SELECT COUNT(*) FROM fixture_descriptions WHERE pm_write_token='".$nonce."'")===2,'Exactly one comment clone and one new-upload description');
	pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_links WHERE privmsgs_id=12')===2,'Exactly one existing and one new link');
	pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_links WHERE privmsgs_id=18')===1,'Other message keeps its shared attachment');
	pm_repair_check(pm_repair_value('SELECT privmsgs_attachment FROM fixture_pm WHERE privmsgs_id=12')===1,'Completed message has attachment flag');
	pm_repair_check($GLOBALS['pm_repair_server']->pdo->query("SELECT comment FROM fixture_descriptions WHERE pm_write_token='".$nonce."' AND pm_write_slot=0")->fetchColumn()==='Bestehender Anhang','Own UTF8 comment updated on reserved clone');
	pm_repair_check($GLOBALS['pm_repair_server']->pdo->query('SELECT comment FROM fixture_descriptions WHERE attach_id=1')->fetchColumn()===null,'Other copy retains its original comment');
}
set_error_handler(function($severity,$message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
	foreach ($native ? array('MyISAM','InnoDB') : array('SQLite') as $engine)
	{
		foreach (array('english','german') as $locale)
		{
			include $root . 'language/lang_' . $locale . '/lang_main.php';
			include $root . 'language/lang_' . $locale . '/lang_admin_attach.php';
			include $root . 'language/lang_' . $locale . '/lang_main_attach.php';
			$nonce=str_repeat('a',32);
			pm_attachment_fixture($engine);
			$exporter=(new ReflectionClass('attach_pm'))->newInstanceWithoutConstructor();
			$exporter->attachment_list=array('old.txt');$exporter->attachment_id_list=array(1);$exporter->attachment_comment_list=array('Bestehender Anhang');
			$exporter->post_attach=true;$exporter->attach_filename='pm_1_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.txt';$exporter->filename='Grüße.txt';$exporter->file_comment='Übertragung vollständig';
			$exporter->extension='txt';$exporter->type='text/plain';$exporter->filesize=12;$exporter->filetime=700;$exporter->thumbnail=0;
			$export_data=pm_attachment_data();
			pm_repair_check($exporter->prepared_write_attachments()===phpbb_pm_write_attachment_plan($export_data['attachments']),'Actual Attachment MOD exports existing and directly submitted upload exactly');
			pm_repair_check(!$pm_repair_server->queries,'Attachment export does not open a DB connection or mutate files');
			$exporter->attachment_id_list=array('1');
			pm_repair_check($exporter->prepared_write_attachments()===phpbb_pm_write_attachment_plan($export_data['attachments']),'Database string IDs accepted without losing the existing attachment');
			$exporter->post_attach=false;
			$exporter->attachment_list[]='pm_1_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.txt';$exporter->attachment_id_list[]='0';$exporter->attachment_comment_list[]='Übertragung vollständig';
			$exporter->attachment_filename_list=array('old.txt','Grüße.txt');$exporter->attachment_extension_list=array('txt','txt');
			$exporter->attachment_mimetype_list=array('text/plain','text/plain');$exporter->attachment_filesize_list=array(1,12);
			$exporter->attachment_filetime_list=array(1,700);$exporter->attachment_thumbnail_list=array(0,0);
			pm_repair_check($exporter->prepared_write_attachments()===phpbb_pm_write_attachment_plan($export_data['attachments']),'Parser string zero from submit/preview exports new file exactly once');
			foreach(array(null,true,-1,'1oops',array(1),str_repeat('9',40)) as $invalid)
			{
				$exporter->attachment_id_list[0]=$invalid;
				mailbox_storage_fail(function() use($exporter){$exporter->prepared_write_attachments();},$lang['PM_journal_changed']);
			}
			$exporter->attachment_id_list[0]='1';
			$attach_config['disable_mod']=1;
			mailbox_storage_fail(function() use($exporter){$exporter->prepared_write_attachments();},$lang['Not_Authorised']);
			$attach_config['disable_mod']=0;
			pm_compose_native_guards($engine);
			foreach (array('INSERT INTO fixture_descriptions ', 'INSERT INTO fixture_links ', 'UPDATE fixture_links SET attach_id',
				'DELETE FROM fixture_descriptions','UPDATE fixture_links SET user_id_1', 'UPDATE fixture_pm SET privmsgs_attachment', 'UPDATE fixture_pm SET privmsgs_write_payload = NULL') as $boundary)
			{
				foreach (array(false,true) as $lost_ack)
				{
					pm_attachment_fixture($engine);
					if ($lost_ack)
					{
						$pm_repair_server->hook=function($sql) use($boundary)
						{
							if(strpos($sql,$boundary)!==0){return;} $s=$GLOBALS['pm_repair_server'];$s->hook=null;$s->pdo->exec($sql);$s->failure=$boundary;
						};
					}
					else {$pm_repair_server->failure=$boundary;}
					mailbox_storage_fail(function() use($nonce) {phpbb_pm_write_message($nonce,12,'',pm_attachment_data(),0);},$lang['PM_cleanup_failed']);
					$pm_repair_server->failure='';$pm_repair_server->hook=null;
					pm_attachment_complete($nonce);
				}
			}
			pm_attachment_fixture($engine); $pm_repair_server->failure='INSERT INTO fixture_links ';
			mailbox_storage_fail(function() use($nonce) {phpbb_pm_write_message($nonce,12,'',pm_attachment_data(),0);},$lang['PM_cleanup_failed']);
			$pm_repair_server->failure='';
			$id=pm_repair_value("SELECT attach_id FROM fixture_descriptions WHERE pm_write_token='".$nonce."' AND pm_write_slot=1");
			$lock=attach_require_mutation_lock($db);
			try
			{
				$caught=false;
				try {attach_shadow_require_pm_idle($lock->connection,$id);} catch(RuntimeException $e){$caught=$e->getMessage()===$lang['Attachment_shadow_pending'];}
				pm_repair_check($caught,'Unlinked reservation protected from shadow cleanup by actual helper');
			}
			finally {$lock->release();}
			pm_attachment_complete($nonce);
			// A physical name collision is never mistaken for this job's upload.
			pm_attachment_fixture($engine);
			$pm_repair_server->pdo->exec("INSERT INTO fixture_descriptions (attach_id,physical_filename,thumbnail) VALUES(99,'pm_1_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.txt',0)");
			mailbox_storage_fail(function() use($nonce){phpbb_pm_write_message($nonce,12,'',pm_attachment_data(),0);},$lang['Attachment_publish_unavailable']);
			pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_links WHERE attach_id=99')===0,'Foreign description was not adopted');
			pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_write_payload IS NOT NULL')===0,'Invalid upload rejected before changing message');
			foreach(array('foreign-id','foreign-file','forged-size','forged-extension','missing-schema') as $case)
			{
				pm_attachment_fixture($engine);$data=pm_attachment_data();
				if($case==='foreign-id'){$data['attachments'][0]['id']=99;}
				elseif($case==='foreign-file'){$data['attachments'][1]['physical_filename']='pm_2_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.txt';}
				elseif($case==='forged-size'){$data['attachments'][1]['filesize']=1;}
				elseif($case==='forged-extension'){$data['attachments'][1]['extension']='html';}
				else{pm_write_fixture($engine);}
				$before=pm_repair_snapshot('fixture_pm');
				mailbox_storage_fail(function() use($nonce,$data){phpbb_pm_write_message($nonce,12,'',$data,0);},$lang[$case==='missing-schema'?'PM_cleanup_failed':'PM_journal_changed']);
				pm_repair_check(pm_repair_snapshot('fixture_pm')===$before,'Invalid attachment ownership/schema causes no accepted write');
			}
			foreach(array('nonce','metadata','foreign-link','revoke') as $case)
			{
				pm_attachment_fixture($engine);
				$pm_repair_server->hook=function($sql) use($case)
				{
					if(strpos($sql,'INSERT INTO fixture_links ')!==0){return;}$s=$GLOBALS['pm_repair_server'];$s->hook=null;
					if($case==='nonce'){$s->pdo->exec("UPDATE fixture_descriptions SET pm_write_token='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' WHERE pm_write_token IS NOT NULL");}
					elseif($case==='metadata'){$s->pdo->exec("UPDATE fixture_descriptions SET real_filename='Changed.txt' WHERE pm_write_token IS NOT NULL");}
					elseif($case==='revoke'){$s->pdo->exec('UPDATE fixture_users SET user_allow_pm=0 WHERE user_id=1');}
					else{$s->pdo->exec('INSERT INTO fixture_links (attach_id,post_id,privmsgs_id,user_id_1,user_id_2) SELECT attach_id,0,18,1,8 FROM fixture_descriptions WHERE pm_write_token IS NOT NULL');}
				};
				mailbox_storage_fail(function() use($nonce){phpbb_pm_write_message($nonce,12,'',pm_attachment_data(),0);},$lang[$case==='revoke'?'Not_Authorised':'PM_journal_changed']);
				pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_links WHERE privmsgs_id=12')===1,'Changed reservation cannot be linked: '.$case);
				pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_id=12 AND privmsgs_write_payload IS NOT NULL')===1,'Unfinished write remains hidden: '.$case);
			}
			pm_attachment_fixture($engine);
			$pm_repair_server->hook=function($sql)
			{
				if(strpos($sql,'DELETE FROM fixture_pm WHERE')!==0){return;}$s=$GLOBALS['pm_repair_server'];$s->hook=null;
				$s->pdo->exec('DELETE FROM fixture_links WHERE privmsgs_id=12 AND attach_id IN (SELECT attach_id FROM fixture_descriptions WHERE pm_write_token IS NOT NULL AND pm_write_slot=1)');
			};
			mailbox_storage_fail(function() use($nonce){phpbb_pm_write_message($nonce,12,'',pm_attachment_data(),3);},$lang['PM_journal_changed']);
			pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_id=10')===1,'Lost new attachment during quota prevents deletion of older mailbox entry');
			pm_attachment_complete($nonce);
			pm_attachment_fixture($engine);$data=pm_attachment_data();$data['attachments']=array($data['attachments'][1]);
			$new_id=phpbb_pm_write_message($nonce,0,'',$data,0);
			pm_repair_check($new_id===phpbb_pm_write_message($nonce,0,'',$data,0),'Repeated send with new upload yields same message');
			pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_links WHERE privmsgs_id='.$new_id)===1,'New send creates exactly one new-upload link');
			pm_repair_check(is_file($upload_dir.'/pm_1_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.txt'),'No file removed by publication or refusal');
			echo $engine.' '.$locale." durable PN attachment reservation/retry checks passed.\n";
		}
	}
}
finally
{
	if(isset($pm_repair_server)&&$pm_repair_server->owner!==null){$pm_repair_server->owner->sql_close();}
	unlink($upload_dir.'/pm_1_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.txt');rmdir($upload_dir);restore_error_handler();
}
