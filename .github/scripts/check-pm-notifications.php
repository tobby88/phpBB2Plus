<?php
// Real publication/claim SQL; no SMTP or forum configuration is loaded.
$fixture = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/check-pm-write-recovery.php'));
$end = strpos($fixture, "\nset_error_handler(");
if ($end === false) { throw new RuntimeException('Writer fixture boundary missing'); }
eval(substr($fixture, 5, $end - 5));

function pm_notification_fixture($engine)
{
	pm_write_fixture($engine);
	$pdo = $GLOBALS['pm_repair_server']->pdo;
	foreach (array("user_email VARCHAR(255) NOT NULL DEFAULT ''", "user_lang VARCHAR(255) NOT NULL DEFAULT 'english'", 'user_notify_pm INTEGER NOT NULL DEFAULT 1') as $definition)
	{
		$pdo->exec('ALTER TABLE fixture_users ADD COLUMN ' . $definition);
	}
	$pdo->exec("UPDATE fixture_users SET username='Empfänger',user_email='recipient@example.invalid' WHERE user_id=8");
}

set_error_handler(function($severity,$message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
	foreach ($native ? array('MyISAM','InnoDB') : array('SQLite') as $engine)
	{
		foreach (array('english','german') as $locale)
		{
			include $root . 'language/lang_' . $locale . '/lang_main.php';
			$nonce = str_repeat('a',32);
			pm_notification_fixture($engine);
			$id = phpbb_pm_write_message($nonce,0,'',pm_write_data(),0);
			pm_repair_check(pm_repair_value('SELECT notify_state FROM fixture_write_receipts') === 1, 'Creation eligible');
			$user = phpbb_pm_claim_notification($nonce);
			pm_repair_check(is_array($user) && $user['username'] === 'Empfänger' && $user['user_email'] === 'recipient@example.invalid', 'Current recipient notification returned');
			pm_repair_check($pm_repair_server->owner === null, 'SMTP caller receives profile after lock release');
			pm_repair_check(pm_repair_value('SELECT notify_state FROM fixture_write_receipts') === 2, 'Claim is persisted before caller can send');
			phpbb_pm_retry_write($nonce,0);
			pm_repair_check(phpbb_pm_claim_notification($nonce) === false, 'Repeated accepted request never claims a second mail');
			foreach (array('edit','historical','disabled','inactive','no-email','deleted','later-edit','foreign','get','bad-sid') as $case)
			{
				pm_notification_fixture($engine);
				$id = phpbb_pm_write_message($nonce,$case === 'edit' ? 12 : 0,'',pm_write_data(),0);
				$pdo = $pm_repair_server->pdo;
				if ($case === 'historical') { $pdo->exec('UPDATE fixture_write_receipts SET notify_state=0'); }
				elseif ($case === 'disabled') { $pdo->exec('UPDATE fixture_users SET user_notify_pm=0 WHERE user_id=8'); }
				elseif ($case === 'inactive') { $pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=8'); }
				elseif ($case === 'no-email') { $pdo->exec("UPDATE fixture_users SET user_email='' WHERE user_id=8"); }
				elseif ($case === 'deleted') { $pdo->exec('DELETE FROM fixture_pm WHERE privmsgs_id='.$id); }
				elseif ($case === 'later-edit') { phpbb_pm_write_message(str_repeat('b',32),$id,$nonce,pm_write_data('New subject'),0); }
				elseif ($case === 'foreign') { $userdata['user_id']=8; }
				elseif ($case === 'get') { $_SERVER['REQUEST_METHOD']='GET'; }
				elseif ($case === 'bad-sid') { $_POST['sid']='wrong'; }
				if ($case === 'get' || $case === 'bad-sid')
				{
					mailbox_storage_fail(function() use($nonce) { phpbb_pm_claim_notification($nonce); },$lang['Session_invalid']);
				}
				else { pm_repair_check(phpbb_pm_claim_notification($nonce) === false, 'No email for '.$case); }
			}
			// Changes between SELECT and UPDATE cannot authorize a stale profile,
			// another message, or a mismatched request receipt.
			foreach (array('email','preference','recipient','hash','owner','deleted') as $case)
			{
				pm_notification_fixture($engine);
				$id = phpbb_pm_write_message($nonce,0,'',pm_write_data(),0);
				$pm_repair_server->hook = function($sql) use($case,$id)
				{
					if (strpos($sql,'UPDATE fixture_write_receipts SET notify_state') !== 0) { return; }
					$s=$GLOBALS['pm_repair_server']; $s->hook=null;
					if ($case === 'email') { $s->pdo->exec("UPDATE fixture_users SET user_email='other@example.invalid' WHERE user_id=8"); }
					elseif ($case === 'preference') { $s->pdo->exec('UPDATE fixture_users SET user_notify_pm=0 WHERE user_id=8'); }
					elseif ($case === 'recipient') { $s->pdo->exec('UPDATE fixture_pm SET privmsgs_to_userid=20 WHERE privmsgs_id='.$id); }
					elseif ($case === 'hash') { $s->pdo->exec("UPDATE fixture_write_receipts SET request_hash='changed'"); }
					elseif ($case === 'owner') { $s->pdo->exec('UPDATE fixture_write_receipts SET user_id=8'); }
					else { $s->pdo->exec('DELETE FROM fixture_pm WHERE privmsgs_id='.$id); }
				};
				pm_repair_check(phpbb_pm_claim_notification($nonce) === false, 'Atomic claim refuses changed '.$case);
				pm_repair_check(pm_repair_value('SELECT notify_state FROM fixture_write_receipts') === 1, 'Changed snapshot not claimed');
			}
			foreach (array(false,true) as $lost_ack)
			{
				pm_notification_fixture($engine);
				phpbb_pm_write_message($nonce,0,'',pm_write_data(),0);
				$boundary='UPDATE fixture_write_receipts SET notify_state';
				if ($lost_ack)
				{
					$pm_repair_server->hook=function($sql) use($boundary)
					{
						if (strpos($sql,$boundary)!==0) { return; }
						$s=$GLOBALS['pm_repair_server']; $s->hook=null; $s->pdo->exec($sql); $s->failure=$boundary;
					};
				}
				else { $pm_repair_server->failure=$boundary; }
				mailbox_storage_fail(function() use($nonce) { phpbb_pm_claim_notification($nonce); },$lang['PM_cleanup_failed']);
				$pm_repair_server->failure=''; $pm_repair_server->hook=null;
				pm_repair_check((phpbb_pm_claim_notification($nonce) === false) === $lost_ack, 'Uncertain acknowledged claim is not resent; failed unclaimed SQL can retry');
			}
			echo $engine.' '.$locale." PM notification claims, preferences and lost acknowledgements passed.\n";
		}
	}
}
finally { if (isset($pm_repair_server) && $pm_repair_server->owner !== null) { $pm_repair_server->owner->sql_close(); } restore_error_handler(); }
