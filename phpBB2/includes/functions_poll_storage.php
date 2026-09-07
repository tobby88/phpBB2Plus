<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

class PhpbbPollStorageException extends RuntimeException {}
function phpbb_poll_error($key)
{
	global $lang;
	throw new PhpbbPollStorageException($lang[$key]);
}

// auth() must use this connection and report failures through the caller's
// normal HTML or AJAX transport, not terminate inside a locked SQL operation.
class PhpbbPollStorageDatabase
{
	var $connection;
	function __construct($connection) { $this->connection = $connection; }
	function __call($method, $args) { return call_user_func_array(array($this->connection, $method), $args); }
	function sql_query($sql, $transaction = false)
	{
		$result = $this->connection->sql_query($sql, $transaction);
		if (!$result) { phpbb_poll_error('Poll_storage_failed'); }
		return $result;
	}
}

function phpbb_poll_id($value)
{
	$ids = (is_int($value) || is_string($value)) && preg_match('/^[0-9]+$/D', (string) $value) ? attach_delete_id_array($value) : false;
	if ($ids === false || count($ids) !== 1) { phpbb_poll_error('No_vote_option'); }
	return $ids[0];
}

// Read-only state for the ballot; writers call this again after taking the lock.
function phpbb_poll_state($database, $topic_id)
{
	global $userdata, $user_ip;
	$topic_id = phpbb_poll_id($topic_id);
	$db = new PhpbbPollStorageDatabase($database);
	$result = $db->sql_query('SELECT vd.*, t.forum_id, t.topic_status, f.forum_status FROM ' . VOTE_DESC_TABLE . ' vd'
		. ' JOIN ' . TOPICS_TABLE . ' t ON t.topic_id = vd.topic_id AND t.topic_moved_id = 0'
		. ' JOIN ' . FORUMS_TABLE . ' f ON f.forum_id = t.forum_id WHERE t.topic_id = ' . $topic_id);
	$row = $db->sql_fetchrow($result); $extra = $db->sql_fetchrow($result); $db->sql_freeresult($result);
	if (!$row || $extra) { phpbb_poll_error('Topic_post_not_exist'); }
	$auth = auth(AUTH_ALL, (int) $row['forum_id'], $userdata, '', $db);
	if (empty($auth['auth_view']) || empty($auth['auth_read'])) { phpbb_poll_error('Topic_post_not_exist'); }
	$reason = '';
	if (empty($auth['auth_vote'])) { $reason = 'Poll_vote_denied'; }
	else if (empty($auth['auth_mod']) && (int) $row['forum_status'] === FORUM_LOCKED) { $reason = 'Forum_locked'; }
	else if (empty($auth['auth_mod']) && (int) $row['topic_status'] === TOPIC_LOCKED) { $reason = 'Topic_locked'; }
	else if ((int) $row['vote_length'] > 0 && (float) $row['vote_start'] + (float) $row['vote_length'] <= time()) { $reason = 'Poll_expired'; }
	$user_id = empty($userdata['session_logged_in']) ? ANONYMOUS : (int) $userdata['user_id'];
	if ($user_id !== ANONYMOUS && $user_id <= 0) { phpbb_poll_error('Poll_vote_denied'); }
	if (!is_string($user_ip) || $user_ip === '' || strlen($user_ip) > 45) { phpbb_poll_error('Poll_vote_denied'); }
	$identity = 'vote_user_id = ' . $user_id;
	if ($user_id === ANONYMOUS) { $identity .= " AND vote_user_ip = '" . $db->sql_escape($user_ip) . "'"; }
	$vote_id = (int) $row['vote_id'];
	$result = $db->sql_query('SELECT vote_id FROM ' . VOTE_USERS_TABLE . " WHERE vote_id = $vote_id AND $identity");
	$voted = (bool) $db->sql_fetchrow($result); $db->sql_freeresult($result);
	return array('poll' => $row, 'reason' => $reason, 'voted' => $voted, 'can_vote' => $reason === '' && !$voted, 'identity' => $identity, 'user_id' => $user_id, 'moderator' => !empty($auth['auth_mod']));
}

// Internal: both endpoints verify POST + session token before calling this.
// The existing shared lock also serializes cooperating post/poll edit/delete
// operations. No schema conversion is needed for legacy MyISAM installations.
function phpbb_cast_poll_vote($database, $topic_id, $option_id)
{
	global $user_ip;
	$topic_id = phpbb_poll_id($topic_id); $option_id = phpbb_poll_id($option_id);
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_poll_error('Attachment_storage_busy'); }
	try
	{
		$db = new PhpbbPollStorageDatabase($lock->connection);
		$state = phpbb_poll_state($lock->connection, $topic_id);
		if ($state['reason'] !== '') { phpbb_poll_error($state['reason']); }
		if ($state['voted']) { return 'Already_voted'; }
		$vote_id = (int) $state['poll']['vote_id']; $user_id = $state['user_id']; $identity = $state['identity'];
		$result = $db->sql_query('SELECT vote_option_id FROM ' . VOTE_RESULTS_TABLE . " WHERE vote_id = $vote_id AND vote_option_id = $option_id");
		$option = $db->sql_fetchrow($result); $db->sql_freeresult($result);
		if (!$option) { phpbb_poll_error('No_vote_option'); }
		$db->sql_query('INSERT INTO ' . VOTE_USERS_TABLE . ' (vote_id, vote_user_id, vote_user_ip)'
			. " SELECT $vote_id, $user_id, '" . $db->sql_escape($user_ip) . "' WHERE NOT EXISTS (SELECT 1 FROM " . VOTE_USERS_TABLE . " WHERE vote_id = $vote_id AND $identity)");
		if ((int) $db->sql_affectedrows() !== 1) { return 'Already_voted'; }
		$forum_id = (int) $state['poll']['forum_id'];
		$parent_scope = $state['moderator'] ? '' : ' AND f.forum_status <> ' . FORUM_LOCKED . ' AND t.topic_status <> ' . TOPIC_LOCKED;
		// Check affected rows as well as SQL success: a removed option must not
		// consume a vote. Compensate only the voter inserted by this operation.
		$updated = $lock->connection->sql_query('UPDATE ' . VOTE_RESULTS_TABLE
			. " SET vote_result = vote_result + 1 WHERE vote_id = $vote_id AND vote_option_id = $option_id AND vote_result >= 0 AND vote_result < 2147483647"
			. ' AND EXISTS (SELECT 1 FROM ' . VOTE_DESC_TABLE . ' vd JOIN ' . TOPICS_TABLE . ' t ON t.topic_id = vd.topic_id'
			. ' JOIN ' . FORUMS_TABLE . " f ON f.forum_id = t.forum_id WHERE vd.vote_id = $vote_id AND t.topic_id = $topic_id AND t.forum_id = $forum_id AND t.topic_moved_id = 0"
			. $parent_scope . ' AND (vd.vote_length = 0 OR vd.vote_start + vd.vote_length > ' . time() . '))');
		if (!$updated || (int) $db->sql_affectedrows() !== 1)
		{
			$db->sql_query('DELETE FROM ' . VOTE_USERS_TABLE . " WHERE vote_id = $vote_id AND $identity");
			phpbb_poll_error('Poll_storage_failed');
		}
		return 'Vote_cast';
	}
	finally { $lock->release(); }
}
