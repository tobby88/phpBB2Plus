<?php
$read_fixture = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/check-pm-read-recovery.php'));
$read_end = strpos($read_fixture, "\nset_error_handler(");
if ($read_end === false) { throw new RuntimeException('Read fixture boundary missing'); }
eval(substr($read_fixture, 5, $read_end - 5));
require_once $root . 'includes/functions_pm_write.php';
define('PM_WRITE_RECEIPTS_TABLE', 'fixture_write_receipts');

function pm_write_fixture($engine)
{
	pm_read_fixture($engine); $pdo = $GLOBALS['pm_repair_server']->pdo;
	foreach (array('privmsgs_write_token CHAR(32) DEFAULT NULL',"privmsgs_write_hash CHAR(64) NOT NULL DEFAULT ''",'user_last_privmsg INTEGER NOT NULL DEFAULT 0') as $column)
	{
		$pdo->exec('ALTER TABLE ' . (strpos($column, 'user_') === 0 ? 'fixture_users' : 'fixture_pm') . ' ADD COLUMN ' . $column);
	}
	$pdo->exec('CREATE UNIQUE INDEX privmsgs_write_token ON fixture_pm (privmsgs_write_token)');
	$pdo->exec('DROP TABLE IF EXISTS fixture_write_receipts');
	$pdo->exec('CREATE TABLE fixture_write_receipts (request_token CHAR(32) PRIMARY KEY,request_hash CHAR(64),user_id INTEGER,message_id INTEGER,created_at INTEGER,notify_state INTEGER NOT NULL DEFAULT 0)'.($GLOBALS['native']?' ENGINE='.$engine:''));
	$GLOBALS['userdata']['user_id'] = 1; $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = array('sid'=>'fixture-sid');
}
function pm_write_data($subject = 'Neuer Betreff')
{
	return array('subject'=>$subject,'text'=>'Überarbeitet: Grüße & <b>Test</b>','bbcode_uid'=>'abc1234567',
		'ip'=>'7f000001','recipient'=>8,'html'=>0,'bbcode'=>1,'smilies'=>1,'sig'=>0);
}
function pm_write_receipt_races($engine)
{
	foreach(array('removed','owner','hash','message','header') as $case)
	{
		pm_write_fixture($engine);$nonce=str_repeat('a',32);
		$GLOBALS['pm_repair_server']->hook=function($sql) use($case)
		{
			if(strpos($sql,'UPDATE fixture_pm SET privmsgs_write_payload = NULL')!==0){return;}
			$s=$GLOBALS['pm_repair_server'];$s->hook=null;
			if($case==='removed'){$s->pdo->exec('DELETE FROM fixture_write_receipts');}
			elseif($case==='owner'){$s->pdo->exec('UPDATE fixture_write_receipts SET user_id=8');}
			elseif($case==='hash'){$s->pdo->exec("UPDATE fixture_write_receipts SET request_hash='changed'");}
			elseif($case==='message'){$s->pdo->exec('UPDATE fixture_write_receipts SET message_id=99');}
			else{$s->pdo->exec("UPDATE fixture_pm SET privmsgs_subject='Changed header' WHERE privmsgs_id=12");}
		};
		mailbox_storage_fail(function() use($nonce){phpbb_pm_write_message($nonce,12,'',pm_write_data(),0);},$GLOBALS['lang']['PM_journal_changed']);
		pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_id=12 AND privmsgs_write_payload IS NOT NULL')===1,'Publication SQL preserves pending checkpoint after changed receipt/header: '.$case);
	}
}
function pm_write_assert($id, $nonce, $creating)
{
	$rows = $GLOBALS['pm_repair_server']->pdo->query('SELECT p.*,t.privmsgs_text FROM fixture_pm p,fixture_text t WHERE p.privmsgs_id=' . $id . ' AND t.privmsgs_text_id=p.privmsgs_id')->fetchAll(PDO::FETCH_ASSOC);
	pm_repair_check(count($rows) === 1 && $rows[0]['privmsgs_write_payload'] === null && $rows[0]['privmsgs_write_token'] === $nonce, 'Published checkpoint retired exactly once');
	$data = pm_write_data();
	pm_repair_check($rows[0]['privmsgs_subject'] === $data['subject'] && $rows[0]['privmsgs_text'] === $data['text'], 'Matching header and text bytes');
	pm_repair_check(pm_repair_value('SELECT user_new_privmsg FROM fixture_users WHERE user_id=8') === ($creating ? 5 : 4), 'Recipient counter reflects completed operation');
	pm_repair_check($GLOBALS['pm_repair_server']->owner === null, 'Publication owner released');
}

set_error_handler(function($severity,$message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
	foreach ($native ? array('MyISAM','InnoDB') : array('SQLite') as $engine)
	{
		foreach (array('english','german') as $locale)
		{
			include $root . 'language/lang_' . $locale . '/lang_main.php';
			pm_write_receipt_races($engine);
			// The final recount can fail after the message became visible. Its
			// receipt must retry the counter without creating another message.
			pm_write_fixture($engine); $counter_nonce = str_repeat('9',32);
			$pm_repair_server->hook = function($sql)
			{
				if (strpos($sql, 'UPDATE fixture_users SET') !== 0) { return; }
				$s = $GLOBALS['pm_repair_server'];
				if (!(int)$s->pdo->query("SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_write_token='" . str_repeat('9',32) . "' AND privmsgs_write_payload IS NULL")->fetchColumn()) { return; }
				$s->hook = null; $s->failure = 'UPDATE fixture_users SET';
			};
			mailbox_storage_fail(function() use($counter_nonce) { phpbb_pm_write_message($counter_nonce,0,'',pm_write_data(),0); }, $lang['PM_cleanup_failed']);
			pm_repair_check(pm_repair_value('SELECT user_new_privmsg FROM fixture_users WHERE user_id=8') === 4, 'Unpublished body was not prematurely counted');
			$pm_repair_server->failure = ''; $pm_repair_server->hook = null;
			$counter_id = phpbb_pm_retry_write($counter_nonce,0);
			pm_write_assert($counter_id,$counter_nonce,true);
			foreach (array(false,true) as $creating)
			{
				$nonce = str_repeat($creating ? 'c' : 'e', 32); $target = $creating ? 0 : 12;
				pm_write_fixture($engine);
				$id = phpbb_pm_write_message($nonce, $target, '', pm_write_data(), 0);
				pm_repair_check($id === phpbb_pm_write_message($nonce, $target, '', pm_write_data(), 0), 'Repeated submit returns same message');
				pm_write_assert($id, $nonce, $creating);
				foreach (array($creating ? 'INSERT INTO fixture_pm ' : 'UPDATE fixture_pm SET privmsgs_write_token',
					'INSERT INTO fixture_text ','UPDATE fixture_text SET','UPDATE fixture_pm SET privmsgs_to_userid',
					'UPDATE fixture_users SET','INSERT INTO fixture_write_receipts ','UPDATE fixture_pm SET privmsgs_write_payload = NULL') as $boundary)
				{
					foreach (array(false,true) as $lost_ack)
					{
						pm_write_fixture($engine);
						if ($lost_ack)
						{
							$pm_repair_server->hook = function($sql) use ($boundary)
							{
								if (strpos($sql, $boundary) !== 0) { return; }
								$s=$GLOBALS['pm_repair_server'];$s->hook=null;$s->pdo->exec($sql);$s->failure=$boundary;
							};
						}
						else { $pm_repair_server->failure=$boundary; }
						mailbox_storage_fail(function() use($nonce,$target) { phpbb_pm_write_message($nonce,$target,'',pm_write_data(),0); }, $lang['PM_cleanup_failed']);
						$pm_repair_server->failure='';$pm_repair_server->hook=null;
						$id=phpbb_pm_write_message($nonce,$target,'',pm_write_data(),0); pm_write_assert($id,$nonce,$creating);
						pm_repair_check(phpbb_pm_retry_write($nonce,0)===$id, 'Explicit retry after completion is idempotent');
					}
				}
			}
			// A reader between body and metadata writes must see no half edit,
			// even after the failed writer released its dedicated connection.
			pm_write_fixture($engine);$nonce=str_repeat('a',32);$pm_repair_server->failure='UPDATE fixture_pm SET privmsgs_to_userid';
			mailbox_storage_fail(function() use($nonce) { phpbb_pm_write_message($nonce,12,'',pm_write_data(),0); },$lang['PM_cleanup_failed']);
			$pm_repair_server->failure='';$userdata['user_id']=8;
			mailbox_storage_fail(function(){phpbb_pm_read_message(12,'inbox',0);},$lang['PM_write_pending']);
			mailbox_storage_fail(function(){phpbb_pm_save_messages(array(12),8,'inbox',0);},$lang['PM_write_pending']);
			mailbox_storage_fail(function(){phpbb_pm_delete_messages(array(12),8,'inbox');},$lang['PM_write_pending']);
			pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_copy_token IS NOT NULL')===0, 'Reader cannot copy partially edited body/header');
			$userdata['user_id']=1;phpbb_pm_retry_write($nonce,0);pm_write_assert(12,$nonce,false);
			$userdata['user_id']=8;phpbb_pm_read_message(12,'inbox',0);
			pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm p,fixture_text t,fixture_text original WHERE p.privmsgs_copy_token IS NOT NULL AND t.privmsgs_text_id=p.privmsgs_id AND original.privmsgs_text_id=12 AND HEX(t.privmsgs_text)=HEX(original.privmsgs_text)')===1, 'Resumed edit produces one consistent sent copy');
			pm_write_fixture($engine);$create=str_repeat('b',32);$edit=str_repeat('d',32);
			$id=phpbb_pm_write_message($create,0,'',pm_write_data(),0);
			phpbb_pm_write_message($edit,$id,$create,pm_write_data('Zweiter Betreff'),0);
			pm_repair_check(phpbb_pm_write_message($create,0,'',pm_write_data(),0)===$id,'Creation retry after editing never creates duplicate');
			mailbox_storage_fail(function() use($id,$create) { phpbb_pm_write_message(str_repeat('f',32),$id,$create,pm_write_data(),0); },$lang['PM_journal_changed']);
			pm_repair_check(pm_repair_value("SELECT COUNT(*) FROM fixture_write_receipts WHERE request_token='".$create."'")===1,'One creation receipt after stale retries');
			$pm_repair_server->pdo->exec('DELETE FROM fixture_pm WHERE privmsgs_id='.$id);
			mailbox_storage_fail(function() use($create){phpbb_pm_write_message($create,0,'',pm_write_data(),0);},$lang['No_such_post']);
			pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_id='.$id)===0,'Deleted published message is not resurrected by old form');
			foreach(array('foreign','get','wrong-sid','disabled','nonce-reuse','invalid-upload-plan') as $case)
			{
				pm_write_fixture($engine);$nonce=str_repeat('e',32);$data=pm_write_data();$expected=$lang['Not_Authorised'];
				if($case==='foreign'){$userdata['user_id']=8;$expected=$lang['PM_journal_changed'];}
				elseif($case==='get'){$_SERVER['REQUEST_METHOD']='GET';$expected=$lang['Session_invalid'];}
				elseif($case==='wrong-sid'){$_POST['sid']='wrong';$expected=$lang['Session_invalid'];}
				elseif($case==='disabled'){$pm_repair_server->pdo->exec('UPDATE fixture_users SET user_allow_pm=0 WHERE user_id=1');}
				elseif($case==='invalid-upload-plan'){$data['attachments']=array('cannot silently discard');$expected=$lang['PM_journal_changed'];}
				else{phpbb_pm_write_message($nonce,12,'',$data,0);$data['text']='Changed reuse';$expected=$lang['PM_journal_changed'];}
				mailbox_storage_fail(function() use($nonce,$data){phpbb_pm_write_message($nonce,12,'',$data,0);},$expected);
			}
			pm_write_fixture($engine);
			$pm_repair_server->pdo->exec("UPDATE fixture_pm SET privmsgs_write_payload='{}' WHERE privmsgs_id=10");
			phpbb_pm_write_message(str_repeat('f',32),0,'',pm_write_data(),4);
			pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_id=10')===1,'Quota of a new delivery preserves an older pending write');
			foreach(array('UPDATE fixture_pm SET privmsgs_write_token','INSERT INTO fixture_text ','UPDATE fixture_text SET',
				'UPDATE fixture_pm SET privmsgs_to_userid','INSERT INTO fixture_write_receipts ','UPDATE fixture_pm SET privmsgs_write_payload = NULL') as $boundary)
			{
				pm_write_fixture($engine);$nonce=str_repeat('a',32);
				$pm_repair_server->hook=function($sql) use($boundary){if(strpos($sql,$boundary)!==0){return;}$s=$GLOBALS['pm_repair_server'];$s->hook=null;$s->pdo->exec('UPDATE fixture_users SET user_allow_pm=0 WHERE user_id=1');};
				mailbox_storage_fail(function() use($nonce){phpbb_pm_write_message($nonce,12,'',pm_write_data(),0);},$lang['Not_Authorised']);
				$pm_repair_server->pdo->exec('UPDATE fixture_users SET user_allow_pm=1 WHERE user_id=1');
				phpbb_pm_write_message($nonce,12,'',pm_write_data(),0);pm_write_assert(12,$nonce,false);
			}
			pm_write_fixture($engine);$pm_repair_server->failure='INSERT INTO fixture_text ';$nonce=str_repeat('d',32);
			mailbox_storage_fail(function() use($nonce){phpbb_pm_write_message($nonce,0,'',pm_write_data(),0);},$lang['PM_cleanup_failed']);
			$pm_repair_server->failure='';$userdata['user_level']=ADMIN;$userdata['session_admin']=true;
			$pending=pm_repair_value("SELECT privmsgs_id FROM fixture_pm WHERE privmsgs_write_token='".$nonce."'");
			pm_repair_check(phpbb_pm_repair_messages(array($pending),'missing_text',time()+3600)===0,'Maintenance preserves recoverable pending text publication');
			echo $engine.' '.$locale." durable PM text publication and edit recovery passed.\n";
		}
	}
}
finally { if(isset($pm_repair_server)&&$pm_repair_server->owner!==null){$pm_repair_server->owner->sql_close();}restore_error_handler(); }
