<?php
if (!defined('IN_PHPBB')) { exit; }

/** Effective Plus setting for the native guest posting challenge. */
function plus_guest_post_confirmation($tracker_installed, $tracker_setting, $plus_setting)
{
	// An installed CrackerTracker defaulted missing or malformed values to on.
	if ($tracker_installed && (string) $tracker_setting !== '0') { return 1; }
	if ($plus_setting !== false) { return (int) (bool) $plus_setting; }
	return $tracker_installed ? 0 : null;
}

function plus_import_guest_confirmation()
{
	global $config, $src_db, $convert, $db;
	if (!empty($config['plus_guest_confirm_imported'])) { return; }
	$tools = (new \phpbb\db\tools\factory())->get($src_db);
	$base = $convert->src_table_prefix;
	$has_tracker = $tools->sql_table_exists($base . 'ctracker_config');
	$tracker = $plus = false;
	if ($has_tracker)
	{
		$result = $src_db->sql_query("SELECT ct_config_value FROM {$base}ctracker_config WHERE ct_config_name='vconfirm_guest'");
		$tracker = $src_db->sql_fetchfield('ct_config_value');
		$src_db->sql_freeresult($result);
	}
	if ($tools->sql_table_exists($base . 'plus'))
	{
		$result = $src_db->sql_query("SELECT config_value FROM {$base}plus WHERE config_name='enable_confirm_post'");
		$plus = $src_db->sql_fetchfield('config_value');
		$src_db->sql_freeresult($result);
	}
	$enabled = plus_guest_post_confirmation($has_tracker, $tracker, $plus);
	if ($enabled === null) { return; }
	$db->sql_transaction('begin');
	try
	{
		$config->set('enable_post_confirm', $enabled);
		$config->set('plus_guest_confirm_imported', 1);
		$db->sql_transaction('commit');
	}
	catch (\Throwable $error) { $db->sql_transaction('rollback'); throw $error; }
}

function plus_contact_recipient($current, $sender, $founder, $plus_address, $board_address)
{
	$valid = static function ($address) {
		// Some installer examples pass FILTER_VALIDATE_EMAIL despite naming no actual board mailbox.
		$examples = array('youraddress@yourdomain.com', 'contact@yourdomain.tld', 'address@yourdomain.tld');
		return filter_var($address, FILTER_VALIDATE_EMAIL) !== false && !in_array(strtolower($address), $examples, true);
	};
	$current = trim((string) $current);
	$sender = trim((string) $sender);
	// The installer puts the same mailbox in both settings and on its founder account.
	if ($valid($current) && ($current !== $sender || $current !== trim((string) $founder))) { return $current; }
	foreach (array($plus_address, $board_address, $sender) as $value)
	{
		$address = trim((string) $value);
		if ($valid($address)) { return $address; }
	}
	return '';
}

function plus_import_admin_contact()
{
	global $db, $src_db, $convert, $config;
	// Read the destination's original founder before the wizard replaces its user table.
	$result = $db->sql_query('SELECT user_email FROM ' . USERS_TABLE . ' WHERE user_id=2 AND user_type=' . USER_FOUNDER);
	$founder = $db->sql_fetchfield('user_email');
	$db->sql_freeresult($result);
	$result = $src_db->sql_query("SELECT config_value FROM {$convert->src_table_prefix}plus WHERE config_name='contact_email'");
	$plus_address = $src_db->sql_fetchfield('config_value');
	$src_db->sql_freeresult($result);
	$current = $config['board_contact'];
	$recipient = plus_contact_recipient($current, $config['board_email'], $founder, $plus_address, get_config_value('board_email'));
	// Native forms use board_contact for the recipient; other mail also uses it as the default From/Reply-To.
	// board_email independently controls the SMTP envelope and Return-Path.
	if ($recipient !== '' && $recipient !== $current) { $config->set_atomic('board_contact', $current, $recipient); }
}

/** Apply Plus semantics to the core PM and subscription tables. */
function plus_published_pm_condition($src_db, $prefix)
{
	// Type 6 is the Plus read checkpoint. An unfamiliar custom type can
	// retain its original body without receiving any native mailbox entry.
	$condition = 'privmsgs.privmsgs_type <> 6';
	$tools = (new \phpbb\db\tools\factory())->get($src_db);
	if ($tools->sql_column_exists($prefix . 'privmsgs', 'privmsgs_write_payload'))
	{
		$condition .= ' AND privmsgs.privmsgs_write_payload IS NULL';
	}
	return $condition;
}

function plus_pm_recipient($source_id)
{
	$id = plus_existing_author($source_id);
	return $id === ANONYMOUS ? '' : 'u_' . $id;
}

function plus_pm_incoming_folder($type)
{
	return (int) $type === 1 ? PRIVMSGS_NO_BOX : PRIVMSGS_INBOX;
}

function plus_pm_unread($type)
{
	return in_array((int) $type, array(1, 5), true) ? 1 : 0;
}

function plus_sync_new_pms()
{
	global $db, $src_db, $convert, $config;
	$prefix = $convert->src_table_prefix;
	$result = $src_db->sql_query('SELECT COUNT(*) AS unfinished FROM ' . $prefix . 'privmsgs privmsgs WHERE NOT (' .
		plus_published_pm_condition($src_db, $prefix) . ')');
	$unfinished = (int) $src_db->sql_fetchfield('unfinished');
	$src_db->sql_freeresult($result);
	$config->set('plus_pm_unfinished', $unfinished);
	$result = $db->sql_query('SELECT user_id, COUNT(*) AS pending FROM ' . PRIVMSGS_TO_TABLE . '
		WHERE pm_new = 1 AND folder_id = ' . PRIVMSGS_NO_BOX . ' GROUP BY user_id');
	$rows = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	foreach ($rows as $row)
	{
		$db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_new_privmsg = ' . (int) $row['pending']
			. ' WHERE user_id = ' . (int) $row['user_id']);
	}
}

function plus_watch_status($topic_id, $user_id)
{
	global $src_db, $convert;
	static $topic = null, $states = array();
	$topic_id = (int) $topic_id;
	$user_id = (int) $user_id;
	if ($topic !== $topic_id || !isset($states[$user_id]))
	{
		$topic = $topic_id;
		$states = array();
		// Old installations permit duplicate watch rows. A notification already
		// delivered for any of them must not be mailed again after cutover.
		$sql = 'SELECT user_id, MAX(notify_status) AS notified FROM ' . $convert->src_table_prefix . "topics_watch
			WHERE topic_id = $topic_id AND user_id >= $user_id GROUP BY user_id ORDER BY user_id";
		$result = $src_db->sql_query_limit($sql, 128);
		while ($row = $src_db->sql_fetchrow($result))
		{
			$states[(int) $row['user_id']] = $row['notified'] ? NOTIFY_NO : NOTIFY_YES;
		}
		$src_db->sql_freeresult($result);
	}
	if (!isset($states[$user_id])) { throw new \RuntimeException('A topic subscription changed during conversion.'); }
	return $states[$user_id];
}

/** Use the wizard's resumable, ordered pass and the exact text stored by phpBB3. */
function plus_index_native_post($post_id)
{
	global $db, $convert;
	static $rows = array(), $last = 0;
	if (!($convert->fulltext_search instanceof \phpbb\search\fulltext_native)) { return; }

	$post_id = (int) $post_id;
	if ($post_id > $last || !$last)
	{
		$sql = 'SELECT p.post_id, p.post_text, p.post_subject, p.poster_id, p.forum_id, f.enable_indexing
			FROM ' . POSTS_TABLE . ' p JOIN ' . FORUMS_TABLE . ' f ON f.forum_id = p.forum_id
			WHERE p.post_id >= ' . $post_id . ' ORDER BY p.post_id';
		$result = $db->sql_query_limit($sql, 32);
		$rows = array();
		$last = $post_id;
		while ($row = $db->sql_fetchrow($result))
		{
			$last = (int) $row['post_id'];
			if ($row['enable_indexing']) { $rows[$last] = $row; }
		}
		$db->sql_freeresult($result);
	}
	if (isset($rows[$post_id]))
	{
		$row = $rows[$post_id];
		// "edit" reconciles existing terms if the same conversion batch is retried.
		$convert->fulltext_search->index('edit', $post_id, $row['post_text'], $row['post_subject'], $row['poster_id'], $row['forum_id']);
		unset($rows[$post_id]);
	}
}
