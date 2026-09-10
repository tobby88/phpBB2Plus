<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_pm_read.php';
require_once dirname(__FILE__) . '/functions_pm_write_attachments.php';

class PhpbbPmWriteDatabase extends PhpbbPmReadDatabase
{
	function authority()
	{
		$guard = parent::authority() . ' AND EXISTS (SELECT 1 FROM (SELECT DISTINCT user_id,user_allow_pm FROM ' . USERS_TABLE
			. ' WHERE user_id = ' . $this->actor . ') pm_writer WHERE user_allow_pm <> 0)';
		$check = new PhpbbAclDatabase($this->connection, 'PM_cleanup_failed');
		if (!phpbb_acl_rows($check, 'SELECT 1 AS allowed WHERE ' . $guard)) { phpbb_acl_error('Not_Authorised'); }
		return $guard;
	}
}

function phpbb_pm_write_nonce($nonce)
{
	if (!is_string($nonce) || !preg_match('/^[a-f0-9]{32}$/D', $nonce)) { phpbb_acl_error('PM_journal_changed'); }
	return $nonce;
}

// Input is prepared forum content, not raw POST or pre-escaped SQL. Keep a
// deterministic request fingerprint independent of server time and quotas.
function phpbb_pm_write_content($data)
{
	if (!is_array($data)) { phpbb_acl_error('PM_journal_changed'); }
	$result = array();
	foreach (array('subject','text','bbcode_uid','ip') as $key)
	{
		if (!isset($data[$key]) || !is_string($data[$key])) { phpbb_acl_error('PM_journal_changed'); }
		$result[$key] = $data[$key];
	}
	if ($result['subject'] === '' || strlen($result['subject']) > 1020 || strlen($result['text']) > 65535
		|| !preg_match('//u', $result['text']) || preg_match_all('/./us', $result['subject'], $unused) > 255
		|| !preg_match('//u', $result['subject']) || !preg_match('/^[a-zA-Z0-9]{0,10}$/D', $result['bbcode_uid'])
		|| !preg_match('/^[a-fA-F0-9]{8}$/D', $result['ip'])) { phpbb_acl_error('PM_journal_changed'); }
	$ids = isset($data['recipient']) ? attach_delete_id_array(array($data['recipient'])) : false;
	if (!$ids) { phpbb_acl_error('PM_journal_changed'); }
	$result['recipient'] = $ids[0];
	foreach (array('html','bbcode','smilies','sig') as $key)
	{
		if (!isset($data[$key]) || !in_array($data[$key], array(0,1,'0','1'), true)) { phpbb_acl_error('PM_journal_changed'); }
		$result[$key] = (int)$data[$key];
	}
	if (isset($data['attachments']) && $data['attachments'] !== array())
	{
		$result['attachments'] = phpbb_pm_write_attachment_plan($data['attachments']);
	}
	return $result;
}

function phpbb_pm_write_fingerprint($content, $message_id, $revision)
{
	$encoded = json_encode(array('message_id'=>(int)$message_id, 'revision'=>$revision, 'content'=>$content));
	if (!is_string($encoded)) { phpbb_acl_error('PM_journal_changed'); }
	return hash('sha256', $encoded);
}

function phpbb_pm_write_row($db, $nonce, $creating)
{
	$column = 'privmsgs_write_token';
	$rows = phpbb_acl_rows($db, 'SELECT * FROM ' . PRIVMSGS_TABLE . ' WHERE ' . $column . " = '" . $nonce . "' AND HEX("
		. $column . ") = HEX('" . $nonce . "') AND privmsgs_from_userid = " . $db->actor);
	return $rows ? $rows[0] : false;
}

function phpbb_pm_write_receipt($db, $nonce, $hash = null)
{
	$rows = phpbb_acl_rows($db, 'SELECT * FROM ' . PM_WRITE_RECEIPTS_TABLE . " WHERE request_token = '" . $nonce . "'");
	if (!$rows) { return false; }
	$r = $rows[0];
	if ($r['request_token'] !== $nonce || (int)$r['user_id'] !== $db->actor || ($hash !== null && !hash_equals((string)$r['request_hash'], $hash))) { phpbb_acl_error('PM_journal_changed'); }
	$messages = phpbb_acl_rows($db, 'SELECT * FROM ' . PRIVMSGS_TABLE . ' WHERE privmsgs_id = ' . (int)$r['message_id'] . ' AND privmsgs_from_userid = ' . $db->actor);
	// A receipt outlives deletion of its published message. A repeated form
	// must not resurrect that message or allocate another one with the token.
	if (!$messages) { phpbb_acl_error('No_such_post'); }
	return $messages[0];
}

function phpbb_pm_write_guard($db, $row)
{
	$nonce = phpbb_pm_write_nonce($row['privmsgs_write_token']);
	if (!is_string($row['privmsgs_write_payload']) || !is_string($row['privmsgs_write_hash'])
		|| !preg_match('/^[a-f0-9]{64}$/D', $row['privmsgs_write_hash'])) { phpbb_acl_error('PM_journal_changed'); }
	return 'privmsgs_id = ' . (int)$row['privmsgs_id'] . ' AND privmsgs_from_userid = ' . $db->actor
		. " AND HEX(privmsgs_write_token) = HEX('" . $nonce . "') AND HEX(privmsgs_write_hash) = HEX('" . $row['privmsgs_write_hash'] . "')"
		. " AND HEX(privmsgs_write_payload) = HEX('" . $db->sql_escape($row['privmsgs_write_payload']) . "')"
		. ' AND privmsgs_type IN (' . PRIVMSGS_NEW_MAIL . ',' . PRIVMSGS_UNREAD_MAIL . ')';
}

function phpbb_pm_write_complete($db, $row, $limit)
{
	$id = (int)$row['privmsgs_id'];
	if ($row['privmsgs_write_payload'] === null)
	{
		// Publication may have succeeded before the final recount reply was
		// received. Repeating the accepted request also repairs that counter.
		phpbb_pm_recount_recipient($db, (int)$row['privmsgs_to_userid']);
		return $id;
	}
	$intent = json_decode($row['privmsgs_write_payload'], true);
	if (!is_array($intent) || !isset($intent['version'],$intent['content'],$intent['old_recipient'],$intent['date'],$intent['type'],$intent['creating'],$intent['message_id'],$intent['revision'])
		|| $intent['version'] !== 1 || !is_int($intent['date']) || $intent['date'] < 0
		|| !is_int($intent['old_recipient']) || $intent['old_recipient'] <= 0
		|| !in_array($intent['type'], array(PRIVMSGS_NEW_MAIL,PRIVMSGS_UNREAD_MAIL), true)
		|| !is_bool($intent['creating'])) { phpbb_acl_error('PM_journal_changed'); }
	$content = phpbb_pm_write_content($intent['content']);
	if (!is_int($intent['message_id']) || $intent['message_id'] !== ($intent['creating'] ? 0 : $id)
		|| !is_string($intent['revision']) || ($intent['revision'] !== '' && !preg_match('/^[a-f0-9]{32}$/D', $intent['revision']))
		|| !hash_equals((string)$row['privmsgs_write_hash'], phpbb_pm_write_fingerprint($content, $intent['message_id'], $intent['revision']))) { phpbb_acl_error('PM_journal_changed'); }
	$key = phpbb_pm_write_guard($db, $row);
	$recipient = (int)$content['recipient'];
	$target = 'EXISTS (SELECT 1 FROM ' . USERS_TABLE . ' u WHERE u.user_id = ' . $recipient . ')';
	$guard = 'EXISTS (SELECT 1 FROM (SELECT DISTINCT privmsgs_id FROM ' . PRIVMSGS_TABLE . ' WHERE ' . $key . ') write_source) AND ' . $target;
	if (!phpbb_acl_rows($db, 'SELECT 1 AS allowed WHERE ' . $guard)) { phpbb_acl_error('PM_journal_changed'); }
	$body = $db->sql_escape($content['text']); $uid = $db->sql_escape($content['bbcode_uid']);
	// INSERT-if-absent and UPDATE have the same intent/owner guard. A retry
	// after either acknowledgement is lost repairs the very same body row.
	$db->insert_select(PRIVMSGS_TEXT_TABLE, 'privmsgs_text_id,privmsgs_bbcode_uid,privmsgs_text', $id . ",'" . $uid . "','" . $body . "'",
		USERS_TABLE . ' writer', 'writer.user_id = ' . $db->actor . ' AND ' . $guard
		. ' AND NOT EXISTS (SELECT 1 FROM (SELECT DISTINCT privmsgs_text_id FROM ' . PRIVMSGS_TEXT_TABLE . ') write_text WHERE privmsgs_text_id = ' . $id . ')');
	$db->sql_query('UPDATE ' . PRIVMSGS_TEXT_TABLE . " SET privmsgs_bbcode_uid = '" . $uid . "',privmsgs_text = '" . $body
		. "' WHERE privmsgs_text_id = " . $id . ' AND ' . $guard);
	$assignments = array('privmsgs_to_userid = ' . $recipient, 'privmsgs_date = ' . $intent['date'], 'privmsgs_type = ' . $intent['type']);
	foreach (array('subject'=>'privmsgs_subject','ip'=>'privmsgs_ip') as $field=>$column) { $assignments[] = $column . " = '" . $db->sql_escape($content[$field]) . "'"; }
	foreach (array('html'=>'privmsgs_enable_html','bbcode'=>'privmsgs_enable_bbcode','smilies'=>'privmsgs_enable_smilies','sig'=>'privmsgs_attach_sig') as $field=>$column) { $assignments[] = $column . ' = ' . $content[$field]; }
	$db->sql_query('UPDATE ' . PRIVMSGS_TABLE . ' SET ' . implode(',', $assignments) . ' WHERE ' . $key . ' AND ' . $target);
	if (!phpbb_acl_rows($db, 'SELECT 1 AS allowed WHERE ' . $guard)) { phpbb_acl_error('PM_journal_changed'); }
	foreach (array_unique(array($intent['old_recipient'], $recipient)) as $user_id) { phpbb_pm_recount_recipient($db, $user_id); }
	$attachments_ready = phpbb_pm_write_attachments($db, $row, $content, $guard);
	if ($intent['creating'])
	{
		$db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_last_privmsg = CASE WHEN user_last_privmsg IS NULL OR user_last_privmsg < '
			. $intent['date'] . ' THEN ' . $intent['date'] . ' ELSE user_last_privmsg END WHERE user_id = ' . $recipient . ' AND ' . $guard);
	}
	$body_ready = 'EXISTS (SELECT 1 FROM ' . PRIVMSGS_TEXT_TABLE . ' t WHERE t.privmsgs_text_id = ' . $id
		. " AND HEX(t.privmsgs_text) = HEX('" . $body . "') AND HEX(t.privmsgs_bbcode_uid) = HEX('" . $uid . "')) AND " . $attachments_ready;
	if (!phpbb_acl_rows($db, 'SELECT 1 AS allowed WHERE ' . $guard . ' AND ' . $body_ready)) { phpbb_acl_error('PM_journal_changed'); }
	$quota = new PhpbbMailboxDatabase($db->connection, $recipient, 'inbox', 'send');
	phpbb_pm_trim_mailbox_locked($quota, $limit, $id, $guard . ' AND ' . $body_ready);
	$nonce = $row['privmsgs_write_token'];
	$db->insert_select(PM_WRITE_RECEIPTS_TABLE, 'request_token,request_hash,user_id,message_id,created_at,notify_state',
		"'" . $nonce . "','" . $row['privmsgs_write_hash'] . "'," . $db->actor . ',' . $id . ',' . $intent['date'] . ',' . ($intent['creating'] ? 1 : 0), USERS_TABLE . ' writer',
		'writer.user_id = ' . $db->actor . ' AND ' . $guard . ' AND ' . $body_ready . ' AND NOT EXISTS (SELECT 1 FROM '
		. PM_WRITE_RECEIPTS_TABLE . " receipt WHERE receipt.request_token = '" . $nonce . "')");
	if (!phpbb_pm_write_receipt($db, $nonce, $row['privmsgs_write_hash'])) { phpbb_acl_error('PM_journal_changed'); }
	$receipt_guard = 'EXISTS (SELECT 1 FROM ' . PM_WRITE_RECEIPTS_TABLE . " r WHERE HEX(r.request_token) = HEX('" . $nonce
		. "') AND HEX(r.request_hash) = HEX('" . $row['privmsgs_write_hash'] . "') AND r.user_id = " . $db->actor . ' AND r.message_id = ' . $id . ')';
	// Clearing the payload is the publication boundary. Readers must exclude
	// pending payloads, not infer completion from NEW/UNREAD or a present body.
	$db->sql_query('UPDATE ' . PRIVMSGS_TABLE . ' SET privmsgs_write_payload = NULL WHERE ' . $key . ' AND ' . $target . ' AND ' . $body_ready . ' AND ' . implode(' AND ', $assignments) . ' AND ' . $receipt_guard);
	if ($db->sql_affectedrows() !== 1) { phpbb_acl_error('PM_journal_changed'); }
	phpbb_pm_recount_recipient($db, $recipient);
	return $id;
}

function phpbb_pm_write_message($nonce, $message_id, $revision, $data, $limit)
{
	global $db;
	$nonce = phpbb_pm_write_nonce($nonce);
	$ids = $message_id === 0 ? array() : attach_delete_id_array(array($message_id));
	if ($ids === false || !is_string($revision) || ($revision !== '' && !preg_match('/^[a-f0-9]{32}$/D', $revision))) { phpbb_acl_error('PM_journal_changed'); }
	$creating = !$ids; $message_id = $creating ? 0 : $ids[0];
	if ($creating && $revision !== '') { phpbb_acl_error('PM_journal_changed'); }
	$content = phpbb_pm_write_content($data); $fingerprint = phpbb_pm_write_fingerprint($content, $message_id, $revision);
	phpbb_mailbox_post_request();
	$lock = attach_require_mutation_lock($db);
	try
	{
		$writer = new PhpbbPmWriteDatabase($lock->connection);
		$schema = $writer->sql_query('SELECT notify_state FROM ' . PM_WRITE_RECEIPTS_TABLE . ' WHERE 1 = 0');
		$writer->sql_freeresult($schema);
		$schema = $writer->sql_query('SELECT privmsgs_write_token,privmsgs_write_hash,privmsgs_write_payload FROM ' . PRIVMSGS_TABLE . ' WHERE 1 = 0');
		$writer->sql_freeresult($schema);
		if (!empty($content['attachments']))
		{
			$schema = $writer->sql_query('SELECT pm_write_token,pm_write_slot FROM ' . ATTACHMENTS_DESC_TABLE . ' WHERE 1 = 0');
			$writer->sql_freeresult($schema);
		}
		$receipt = phpbb_pm_write_receipt($writer, $nonce, $fingerprint);
		if ($receipt)
		{
			if ($receipt['privmsgs_write_token'] !== $nonce) { return (int)$receipt['privmsgs_id']; }
			return phpbb_pm_write_complete($writer, $receipt, $limit);
		}
		$row = phpbb_pm_write_row($writer, $nonce, $creating);
		if ($row)
		{
			$hash = $row['privmsgs_write_hash'];
			if (!hash_equals((string)$hash, $fingerprint) || (!$creating && (int)$row['privmsgs_id'] !== $message_id)) { phpbb_acl_error('PM_journal_changed'); }
			return phpbb_pm_write_complete($writer, $row, $limit);
		}
		$recipient = (int)$content['recipient'];
		if (!phpbb_acl_rows($writer, 'SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id = ' . $recipient)) { phpbb_acl_error('No_such_user'); }
		$prior = false;
		if (!$creating)
		{
			$rows = phpbb_acl_rows($writer, 'SELECT * FROM ' . PRIVMSGS_TABLE . ' WHERE privmsgs_id = ' . $message_id
				. ' AND privmsgs_from_userid = ' . $writer->actor . ' AND privmsgs_type IN (' . PRIVMSGS_NEW_MAIL . ',' . PRIVMSGS_UNREAD_MAIL . ')');
			$prior = $rows ? $rows[0] : false;
			if (!$prior || $prior['privmsgs_write_payload'] !== null || (string)$prior['privmsgs_write_token'] !== $revision) { phpbb_acl_error('PM_journal_changed'); }
		}
		phpbb_pm_validate_new_attachment_plan($writer, $message_id, $content);
		$intent = array('version'=>1, 'content'=>$content, 'old_recipient'=>$creating ? $recipient : (int)$prior['privmsgs_to_userid'],
			'date'=>time(), 'type'=>$creating ? PRIVMSGS_NEW_MAIL : (int)$prior['privmsgs_type'], 'creating'=>$creating, 'message_id'=>$message_id, 'revision'=>$revision);
		$encoded = json_encode($intent);
		if (!is_string($encoded)) { phpbb_acl_error('PM_journal_changed'); }
		$payload = $writer->sql_escape($encoded);
		if ($creating)
		{
			$writer->insert_select(PRIVMSGS_TABLE,
				'privmsgs_type,privmsgs_from_userid,privmsgs_to_userid,privmsgs_date,privmsgs_subject,privmsgs_ip,privmsgs_write_token,privmsgs_write_hash,privmsgs_write_payload',
				PRIVMSGS_NEW_MAIL . ',' . $writer->actor . ',' . $recipient . ',' . $intent['date'] . ",'" . $writer->sql_escape($content['subject']) . "','" . $writer->sql_escape($content['ip'])
				. "','" . $nonce . "','" . $fingerprint . "','" . $payload . "'", USERS_TABLE . ' writer',
				'writer.user_id = ' . $writer->actor . ' AND EXISTS (SELECT 1 FROM ' . USERS_TABLE . ' recipient WHERE recipient.user_id = ' . $recipient . ')'
				. " AND NOT EXISTS (SELECT 1 FROM (SELECT DISTINCT privmsgs_write_token FROM " . PRIVMSGS_TABLE . ") prior_creation WHERE HEX(privmsgs_write_token) = HEX('" . $nonce . "'))");
		}
		else
		{
			$version = $revision === '' ? 'privmsgs_write_token IS NULL' : "HEX(privmsgs_write_token) = HEX('" . $revision . "')";
			$writer->sql_query('UPDATE ' . PRIVMSGS_TABLE . " SET privmsgs_write_token = '" . $nonce . "',privmsgs_write_hash = '" . $fingerprint . "',privmsgs_write_payload = '" . $payload
				. "' WHERE privmsgs_id = " . $message_id . ' AND privmsgs_from_userid = ' . $writer->actor
				. ' AND privmsgs_to_userid = ' . (int)$prior['privmsgs_to_userid'] . ' AND privmsgs_type = ' . (int)$prior['privmsgs_type']
				. ' AND privmsgs_date = ' . (int)$prior['privmsgs_date'] . ' AND privmsgs_write_payload IS NULL AND ' . $version
				. ' AND EXISTS (SELECT 1 FROM ' . USERS_TABLE . ' recipient WHERE recipient.user_id = ' . $recipient . ')');
		}
		$row = phpbb_pm_write_row($writer, $nonce, $creating);
		if (!$row) { phpbb_acl_error('PM_journal_changed'); }
		return phpbb_pm_write_complete($writer, $row, $limit);
	}
	finally { $lock->release(); }
}

function phpbb_pm_retry_write($nonce, $limit, $optional = false)
{
	global $db;
	$nonce = phpbb_pm_write_nonce($nonce); phpbb_mailbox_post_request();
	$lock = attach_require_mutation_lock($db);
	try
	{
		$writer = new PhpbbPmWriteDatabase($lock->connection); $row = phpbb_pm_write_receipt($writer, $nonce);
		if ($row && $row['privmsgs_write_token'] !== $nonce) { return (int)$row['privmsgs_id']; }
		if (!$row) { $row = phpbb_pm_write_row($writer, $nonce, false); }
		if (!$row) { if ($optional === true) { return false; } phpbb_acl_error('PM_journal_changed'); }
		return phpbb_pm_write_complete($writer, $row, $limit);
	}
	finally { $lock->release(); }
}

// 0: edit/historical receipt; 1: new-message notification eligible; 2: claimed.
// Claim before SMTP, outside the lock. Lost send/SQL acknowledgements never
// trigger an automatic second mail; this is at-most-once effort, not a mail queue.
function phpbb_pm_claim_notification($nonce)
{
	global $db;
	$nonce = phpbb_pm_write_nonce($nonce); phpbb_mailbox_post_request();
	$lock = attach_require_mutation_lock($db);
	try
	{
		$writer = new PhpbbPmWriteDatabase($lock->connection);
		$rows = phpbb_acl_rows($writer, 'SELECT p.privmsgs_id,p.privmsgs_to_userid FROM ' . PRIVMSGS_TABLE . ' p,' . PM_WRITE_RECEIPTS_TABLE
			. " r WHERE HEX(r.request_token) = HEX('" . $nonce . "') AND r.user_id = " . $writer->actor . ' AND r.notify_state = 1'
			. ' AND p.privmsgs_id = r.message_id AND p.privmsgs_from_userid = r.user_id AND p.privmsgs_write_payload IS NULL'
			. ' AND HEX(p.privmsgs_write_token) = HEX(r.request_token) AND HEX(p.privmsgs_write_hash) = HEX(r.request_hash)');
		if (!$rows) { return false; }
		$id = (int)$rows[0]['privmsgs_id']; $recipient = (int)$rows[0]['privmsgs_to_userid'];
		$users = phpbb_acl_rows($writer, 'SELECT user_id,username,user_email,user_lang,user_active,user_notify_pm FROM ' . USERS_TABLE . ' WHERE user_id = ' . $recipient);
		if (!$users) { return false; }
		$user = $users[0];
		$guard = 'EXISTS (SELECT 1 FROM ' . PRIVMSGS_TABLE . ' p WHERE p.privmsgs_id = ' . $id . ' AND p.privmsgs_from_userid = ' . $writer->actor
			. ' AND p.privmsgs_to_userid = ' . $recipient . " AND HEX(p.privmsgs_write_token) = HEX('" . $nonce . "') AND p.privmsgs_write_payload IS NULL"
			. ' AND HEX(p.privmsgs_write_hash) = HEX(request_hash))';
		$profile = 'u.user_id = ' . $recipient;
		foreach (array('username','user_email','user_lang','user_active','user_notify_pm') as $field)
		{
			$profile .= ' AND HEX(COALESCE(u.' . $field . ",'')) = HEX('" . $writer->sql_escape((string)$user[$field]) . "')";
		}
		$writer->sql_query('UPDATE ' . PM_WRITE_RECEIPTS_TABLE . " SET notify_state = 2 WHERE HEX(request_token) = HEX('" . $nonce . "')"
			. ' AND user_id = ' . $writer->actor . ' AND message_id = ' . $id . ' AND notify_state = 1 AND ' . $guard
			. ' AND EXISTS (SELECT 1 FROM ' . USERS_TABLE . ' u WHERE ' . $profile . ')');
		if ($writer->sql_affectedrows() !== 1 || !$user['user_active'] || !$user['user_notify_pm'] || $user['user_email'] === '') { return false; }
		return $user;
	}
	finally { $lock->release(); }
}
