<?php
// Execute the real read worker, owning connection and SQL, with isolated data.
$fixture = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/check-pm-mailbox-storage.php'));
$end = strpos($fixture, "\nset_error_handler(");
if ($end === false) { throw new RuntimeException('Mailbox fixture boundary missing'); }
eval(substr($fixture, 5, $end - 5));
require_once $root . 'includes/functions_pm_read.php';

function pm_read_fixture($engine)
{
	mailbox_storage_fixture($engine);
	$pdo = $GLOBALS['pm_repair_server']->pdo;
	if ($GLOBALS['native']) { $pdo->exec('ALTER TABLE fixture_pm MODIFY privmsgs_id INTEGER NOT NULL AUTO_INCREMENT'); }
	foreach (array("privmsgs_subject VARCHAR(255) NOT NULL DEFAULT ''", "privmsgs_ip CHAR(8) NOT NULL DEFAULT ''",
		'privmsgs_enable_html INTEGER NOT NULL DEFAULT 0', 'privmsgs_enable_bbcode INTEGER NOT NULL DEFAULT 1',
		'privmsgs_enable_smilies INTEGER NOT NULL DEFAULT 1', 'privmsgs_attach_sig INTEGER NOT NULL DEFAULT 0') as $definition)
	{
		$pdo->exec('ALTER TABLE fixture_pm ADD COLUMN ' . $definition);
	}
	$pdo->exec('CREATE UNIQUE INDEX privmsgs_copy_token ON fixture_pm (privmsgs_copy_token)');
	$pdo->exec("ALTER TABLE fixture_text ADD COLUMN privmsgs_bbcode_uid CHAR(10) NOT NULL DEFAULT ''");
	$pdo->exec('ALTER TABLE fixture_links ADD COLUMN user_id_1 INTEGER NOT NULL DEFAULT 0');
	$pdo->exec('ALTER TABLE fixture_links ADD COLUMN user_id_2 INTEGER NOT NULL DEFAULT 0');
	$pdo->exec("UPDATE fixture_pm SET privmsgs_subject='Grüße & Grüße',privmsgs_date=700,privmsgs_attachment=1 WHERE privmsgs_id=12");
	$pdo->exec("UPDATE fixture_text SET privmsgs_text='Überraschung: Grüße & <b>Test</b>',privmsgs_bbcode_uid='abc1234567' WHERE privmsgs_text_id=12");
	$_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = array();
}

function pm_read_assert_complete()
{
	pm_repair_check(pm_repair_value('SELECT privmsgs_type FROM fixture_pm WHERE privmsgs_id=12') === 0, 'Source is read');
	pm_repair_check(pm_repair_value('SELECT user_new_privmsg FROM fixture_users WHERE user_id=8') === 3, 'Current new counter, no repeat decrement');
	pm_repair_check(pm_repair_value('SELECT user_unread_privmsg FROM fixture_users WHERE user_id=8') === 0, 'Current unread counter');
	pm_repair_check(pm_repair_value("SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_id=12 AND privmsgs_read_token='' AND privmsgs_read_copy_id=0") === 1, 'Intent retired');
	pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_copy_token IS NOT NULL') === 1, 'Exactly one copy');
	pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_type=6') === 0, 'No incomplete copy');
	pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm p,fixture_text t,fixture_text original WHERE p.privmsgs_copy_token IS NOT NULL'
		. ' AND p.privmsgs_type=2 AND p.privmsgs_attachment=1 AND t.privmsgs_text_id=p.privmsgs_id AND original.privmsgs_text_id=12'
		. ' AND HEX(t.privmsgs_text)=HEX(original.privmsgs_text) AND HEX(t.privmsgs_bbcode_uid)=HEX(original.privmsgs_bbcode_uid)') === 1, 'Published text preserved byte-for-byte');
	pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_links a,fixture_pm p WHERE p.privmsgs_copy_token IS NOT NULL AND a.privmsgs_id=p.privmsgs_id AND a.attach_id=1 AND a.user_id_1=1 AND a.user_id_2=8 AND a.post_id=0') === 1, 'One valid shared attachment reference');
	pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_links WHERE privmsgs_id=18') === 1, 'Unrelated shared registration retained');
	pm_repair_check($GLOBALS['pm_repair_server']->owner === null, 'Dedicated lock released');
}

set_error_handler(function($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
	foreach ($native ? array('MyISAM','InnoDB') : array('SQLite') as $engine)
	{
		foreach (array('english','german') as $locale)
		{
			include $root . 'language/lang_' . $locale . '/lang_main.php';
			pm_read_fixture($engine);
			phpbb_pm_read_message(12, 'inbox', 0); phpbb_pm_read_message(12, 'inbox', 0); pm_read_assert_complete();
			foreach (array('UPDATE fixture_pm SET privmsgs_type = 0', 'UPDATE fixture_users SET',
				'INSERT INTO fixture_pm ', 'UPDATE fixture_pm SET privmsgs_read_copy_id', 'INSERT INTO fixture_text ',
				'INSERT INTO fixture_links ', 'UPDATE fixture_pm SET privmsgs_type = 2', 'UPDATE fixture_pm SET privmsgs_read_token') as $boundary)
			{
				foreach (array(false, true) as $lost_ack)
				{
					pm_read_fixture($engine);
					if ($lost_ack)
					{
						$pm_repair_server->hook = function($sql) use ($boundary)
						{
							if (strpos($sql, $boundary) !== 0) { return; }
							$s = $GLOBALS['pm_repair_server']; $s->hook = null; $s->pdo->exec($sql); $s->failure = $boundary;
						};
					}
					else { $pm_repair_server->failure = $boundary; }
					mailbox_storage_fail(function() { phpbb_pm_read_message(12, 'inbox', 0); }, $lang['PM_cleanup_failed']);
					$pm_repair_server->failure = ''; $pm_repair_server->hook = null;
					phpbb_pm_read_message(12, 'inbox', 0); phpbb_pm_read_message(12, 'inbox', 0); pm_read_assert_complete();
				}
			}
			foreach (array('saved','deleted') as $action)
			{
				pm_read_fixture($engine); $pm_repair_server->failure = 'UPDATE fixture_pm SET privmsgs_read_token';
				mailbox_storage_fail(function() { phpbb_pm_read_message(12, 'inbox', 0); }, $lang['PM_cleanup_failed']);
				$pm_repair_server->failure = '';
				$pm_repair_server->pdo->exec($action === 'saved' ? 'UPDATE fixture_pm SET privmsgs_type=4 WHERE privmsgs_copy_token IS NOT NULL' : 'DELETE FROM fixture_pm WHERE privmsgs_copy_token IS NOT NULL');
				phpbb_pm_read_message(12, 'inbox', 0); phpbb_pm_read_message(12, 'inbox', 0);
				pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_type=2') === 1, 'Original legacy sent copy only');
				pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_copy_token IS NOT NULL') === ($action === 'saved' ? 1 : 0), 'No resurrection of ' . $action . ' copy');
			}
			foreach (array('UPDATE fixture_pm SET privmsgs_type = 0','INSERT INTO fixture_pm ','INSERT INTO fixture_text ',
				'INSERT INTO fixture_links ','UPDATE fixture_pm SET privmsgs_type = 2') as $boundary)
			{
				pm_read_fixture($engine);
				$pm_repair_server->hook = function($sql) use ($boundary)
				{
					if (strpos($sql, $boundary) !== 0) { return; }
					$s = $GLOBALS['pm_repair_server']; $s->hook = null;
					$s->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=8');
				};
				mailbox_storage_fail(function() { phpbb_pm_read_message(12, 'inbox', 0); }, $lang['Not_Authorised']);
				pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_copy_token IS NOT NULL AND privmsgs_type=2') === 0, 'Revocation inside modifying statement prevents publication');
				$pm_repair_server->pdo->exec('UPDATE fixture_users SET user_active=1 WHERE user_id=8');
				phpbb_pm_read_message(12, 'inbox', 0); pm_read_assert_complete();
			}
			foreach (array('recipient','generation','source-removed') as $case)
			{
				pm_read_fixture($engine);
				$pm_repair_server->hook = function($sql) use ($case)
				{
					if (strpos($sql, 'UPDATE fixture_pm SET privmsgs_type = 2') !== 0) { return; }
					$s = $GLOBALS['pm_repair_server']; $s->hook = null;
					if ($case === 'recipient') { $s->pdo->exec('UPDATE fixture_pm SET privmsgs_to_userid=1 WHERE privmsgs_id=12'); }
					elseif ($case === 'generation') { $s->pdo->exec("UPDATE fixture_pm SET privmsgs_read_token='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' WHERE privmsgs_id=12"); }
					else { $s->pdo->exec('DELETE FROM fixture_pm WHERE privmsgs_id=12'); }
				};
				mailbox_storage_fail(function() { phpbb_pm_read_message(12, 'inbox', 0); }, $lang['PM_journal_changed']);
				pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_copy_token IS NOT NULL AND privmsgs_type=2') === 0, 'Changed source cannot authorize publication: ' . $case);
			}
			foreach (array('before-copy','before-text','before-links','quota-failure','success') as $case)
			{
				pm_read_fixture($engine);
				$pm_repair_server->pdo->exec("INSERT INTO fixture_pm (privmsgs_id,privmsgs_type,privmsgs_from_userid,privmsgs_to_userid,privmsgs_date) VALUES (30,2,1,8,900)");
				$pm_repair_server->pdo->exec("INSERT INTO fixture_text (privmsgs_text_id,privmsgs_text) VALUES (30,'Older sent copy')");
				$boundaries = array('before-copy'=>'INSERT INTO fixture_pm ','before-text'=>'INSERT INTO fixture_text ',
					'before-links'=>'INSERT INTO fixture_links ','quota-failure'=>'DELETE FROM fixture_pm WHERE');
				if ($case !== 'success')
				{
					$pm_repair_server->failure = $boundaries[$case];
					mailbox_storage_fail(function() { phpbb_pm_read_message(12, 'inbox', 1); }, $lang['PM_cleanup_failed']);
					pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_id=30') === 1, 'Old sent message retained across incomplete publication/failed eviction');
					$pm_repair_server->failure = '';
				}
				phpbb_pm_read_message(12, 'inbox', 1); phpbb_pm_read_message(12, 'inbox', 1); pm_read_assert_complete();
				pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_id=30') === 0, 'Quota resumes after publication and preserves new copy despite older source date');
			}
			pm_read_fixture($engine); $pm_repair_server->failure = 'INSERT INTO fixture_text ';
			mailbox_storage_fail(function() { phpbb_pm_read_message(12, 'inbox', 0); }, $lang['PM_cleanup_failed']);
			$pm_repair_server->failure = ''; $pm_repair_server->pdo->exec('UPDATE fixture_pm SET privmsgs_type=3 WHERE privmsgs_id=12');
			phpbb_pm_read_message(12, 'savebox', 0);
			pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_copy_token IS NOT NULL AND privmsgs_type=2') === 1, 'Saved source resumes its pending copy');
			foreach (array('inactive','foreign','missing','outbox','already-read') as $case)
			{
				pm_read_fixture($engine); $before = pm_repair_snapshot('fixture_pm');
				if ($case === 'inactive') { $pm_repair_server->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=8'); }
				if ($case === 'foreign') { $userdata['user_id'] = 1; }
				if ($case === 'already-read') { $pm_repair_server->pdo->exec('UPDATE fixture_pm SET privmsgs_type=0 WHERE privmsgs_id=12'); }
				if ($case === 'inactive') { mailbox_storage_fail(function() { phpbb_pm_read_message(12, 'inbox', 0); }, $lang['Not_Authorised']); }
				else { phpbb_pm_read_message($case === 'missing' ? 999 : 12, $case === 'outbox' ? 'outbox' : 'inbox', 0); }
				pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_copy_token IS NOT NULL') === 0, 'No unauthorized or inferred historical copy: ' . $case);
				if ($case !== 'already-read') { pm_repair_check(pm_repair_snapshot('fixture_pm') === $before, 'Parent protected: ' . $case); }
			}
			echo $engine . ' ' . $locale . " durable PM read recovery passed.\n";
		}
	}
}
finally
{
	if (isset($pm_repair_server) && $pm_repair_server->owner !== null) { $pm_repair_server->owner->sql_close(); }
	restore_error_handler();
}
