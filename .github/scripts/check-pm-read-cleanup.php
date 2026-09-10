<?php
$read_fixture = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/check-pm-read-recovery.php'));
$read_end = strpos($read_fixture, "\nset_error_handler(");
if ($read_end === false) { throw new RuntimeException('Read fixture boundary missing'); }
eval(substr($read_fixture, 5, $read_end - 5));
$phpEx = 'php';

function pm_pending_fixture($engine, $has_links = true)
{
	pm_read_fixture($engine);
	$GLOBALS['pm_repair_server']->failure = $has_links ? 'UPDATE fixture_pm SET privmsgs_type = 2' : 'INSERT INTO fixture_text ';
	mailbox_storage_fail(function() { phpbb_pm_read_message(12, 'inbox', 0); }, $GLOBALS['lang']['PM_cleanup_failed']);
	$GLOBALS['pm_repair_server']->failure = '';
	return pm_repair_value('SELECT privmsgs_id FROM fixture_pm WHERE privmsgs_type=6');
}

set_error_handler(function($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
	foreach ($native ? array('MyISAM','InnoDB') : array('SQLite') as $engine)
	{
		foreach (array('english','german') as $locale)
		{
			include $root . 'language/lang_' . $locale . '/lang_main.php';
			include $root . 'language/lang_' . $locale . '/lang_dbmtnc.php';
			foreach (array(false,true) as $links)
			{
				$id = pm_pending_fixture($engine, $links);
				phpbb_pm_resume_reads(0); phpbb_pm_resume_reads(0); pm_read_assert_complete();
				$id = pm_pending_fixture($engine, $links);
				$userdata['user_id'] = 1; $userdata['user_level'] = ADMIN; $userdata['session_admin'] = true;
				pm_repair_check(phpbb_pm_repair_messages(array($id), 'missing_text', time()) === 0, 'Old original date does not turn pending copy into missing-text corruption');
				pm_repair_check(phpbb_pm_repair_messages(array($id), 'abandoned_copy', time()) === 0, 'Matching read intent protects pending copy');
				$pm_repair_server->pdo->exec('DELETE FROM fixture_pm WHERE privmsgs_id=12');
				pm_repair_check(phpbb_pm_repair_messages(array($id), 'abandoned_copy', time()) === 1, 'ACP repairs abandoned copy with or without text');
				pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_text WHERE privmsgs_text_id=' . $id) === 0
					&& pm_repair_value('SELECT COUNT(*) FROM fixture_links WHERE privmsgs_id=' . $id) === 0, 'ACP cleans dependent copy data');
			}
			foreach (array('INSERT INTO fixture_pm_delete_jobs','INSERT INTO fixture_pm_delete_items','UPDATE fixture_pm_delete_jobs SET job_state',
				'DELETE FROM fixture_pm WHERE','DELETE FROM fixture_text','DELETE FROM fixture_links','DELETE FROM fixture_pm_delete_items','DELETE FROM fixture_pm_delete_jobs') as $boundary)
			{
				foreach (array(false,true) as $lost_ack)
				{
					$id = pm_pending_fixture($engine); $userdata['user_id'] = 1;
					$pm_repair_server->pdo->exec('DELETE FROM fixture_pm WHERE privmsgs_id=12');
					if ($lost_ack)
					{
						$pm_repair_server->hook = function($sql) use ($boundary)
						{
							if (strpos($sql, $boundary) !== 0) { return; }
							$s = $GLOBALS['pm_repair_server']; $s->hook = null; $s->pdo->exec($sql); $s->failure = $boundary;
						};
					}
					else { $pm_repair_server->failure = $boundary; }
					mailbox_storage_fail(function() { phpbb_pm_resume_reads(0); }, $lang['PM_cleanup_failed']);
					$pm_repair_server->failure = ''; $pm_repair_server->hook = null;
					phpbb_pm_resume_reads(0); phpbb_pm_resume_reads(0);
					pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_id=' . $id) === 0, 'Abandoned pending parent removed');
					pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_text WHERE privmsgs_text_id=' . $id) === 0
						&& pm_repair_value('SELECT COUNT(*) FROM fixture_links WHERE privmsgs_id=' . $id) === 0, 'Interrupted dependent cleanup resumes');
					pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm_delete_jobs') === 0 && pm_repair_value('SELECT COUNT(*) FROM fixture_pm_delete_items') === 0, 'Cleanup intent retired');
					pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_descriptions') === 1 && pm_repair_value('SELECT COUNT(*) FROM fixture_links WHERE privmsgs_id=18') === 1, 'Unrelated shared upload registration preserved');
				}
			}
			foreach (array('restored-source','published-copy','revoked','foreign-sender') as $case)
			{
				$id = pm_pending_fixture($engine);
				$original = $pm_repair_server->pdo->query('SELECT * FROM fixture_pm WHERE privmsgs_id=12')->fetch(PDO::FETCH_ASSOC);
				$userdata['user_id'] = 1; $pm_repair_server->pdo->exec('DELETE FROM fixture_pm WHERE privmsgs_id=12');
				$pm_repair_server->hook = function($sql) use ($case, $original, $id)
				{
					if (strpos($sql, 'DELETE FROM fixture_pm WHERE') !== 0) { return; }
					$s = $GLOBALS['pm_repair_server']; $s->hook = null;
					if ($case === 'restored-source')
					{
						$q = $s->pdo->prepare('INSERT INTO fixture_pm (' . implode(',', array_keys($original)) . ') VALUES (' . implode(',', array_fill(0, count($original), '?')) . ')');
						$q->execute(array_values($original));
					}
					elseif ($case === 'published-copy') { $s->pdo->exec('UPDATE fixture_pm SET privmsgs_type=2 WHERE privmsgs_id=' . $id); }
					elseif ($case === 'foreign-sender') { $s->pdo->exec('UPDATE fixture_pm SET privmsgs_from_userid=8 WHERE privmsgs_id=' . $id); }
					else { $s->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1'); }
				};
				if ($case === 'revoked') { mailbox_storage_fail(function() { phpbb_pm_resume_reads(0); }, $lang['Not_Authorised']); }
				else { phpbb_pm_resume_reads(0); }
				pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_id=' . $id) === 1, 'Current SQL predicate protects ' . $case);
				pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_text WHERE privmsgs_text_id=' . $id) === 1 && pm_repair_value('SELECT COUNT(*) FROM fixture_links WHERE privmsgs_id=' . $id) === 1, 'No dependent loss when candidate ceases to qualify');
			}
			pm_pending_fixture($engine); $userdata['user_id'] = 20; phpbb_pm_resume_reads(0);
			pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_type=6') === 1, 'Unrelated account cannot publish or clean another user staging');
			echo $engine . ' ' . $locale . " PM read resumption and abandoned-copy cleanup passed.\n";
		}
	}
}
finally
{
	if (isset($pm_repair_server) && $pm_repair_server->owner !== null) { $pm_repair_server->owner->sql_close(); }
	restore_error_handler();
}
