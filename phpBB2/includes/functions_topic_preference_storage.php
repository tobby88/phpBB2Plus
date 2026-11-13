<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_owned_storage.php';

class PhpbbTopicPreferenceStore extends PhpbbOwnedStore
{
	var $commit_attempted = false;
	private $topic_id;
	private $kind;
	private $state;
	private $table;

	function __construct($database, $actor, $topic_id, $kind, $state)
	{
		if (!is_array($actor) || !isset($actor['session_logged_in']) || !in_array($actor['session_logged_in'], array(true, 1, '1'), true) || !isset($actor['user_id'], $actor['session_id'])
			|| !phpbb_topic_preference_id($actor['user_id']) || (float)$actor['user_id'] > 8388607
			|| !is_string($actor['session_id']) || $actor['session_id'] === '' || strlen($actor['session_id']) > 32
			|| strpos($actor['session_id'], "\0") !== false) { $this->storage_fail('Session_invalid'); }
		$this->topic_id = $topic_id; $this->kind = $kind; $this->state = $state;
		$this->table = $kind === 'watch' ? TOPICS_WATCH_TABLE : BOOKMARK_TABLE;
		parent::__construct($database, array('id'=>(int)$actor['user_id'], 'sid'=>$actor['session_id']));
	}
	protected function storage_tables()
	{
		$tables = array(CONFIG_TABLE, SESSIONS_TABLE, USERS_TABLE, BANLIST_TABLE, $this->table);
		return $this->state === false ? $tables : array_merge($tables, array(TOPICS_TABLE, FORUMS_TABLE, USER_GROUP_TABLE, AUTH_ACCESS_TABLE));
	}
	protected function storage_fail($key = 'Topic_preference_failed')
	{
		if ($key === 'Owned_storage_failed') { $key = 'Topic_preference_failed'; }
		if ($key === 'Owned_storage_upgrade') { $key = 'Topic_preference_storage_upgrade'; }
		phpbb_topic_preference_error($key);
	}
	// auth() may only read through the already owned, pinned transaction.
	function sql_query($sql)
	{
		if (!is_string($sql) || !preg_match('/^\s*SELECT\b/i', $sql)) { $this->storage_fail(); }
		return $this->query($sql);
	}
	function sql_fetchrow($result) { return $this->connection->sql_fetchrow($result); }
	function sql_fetchrowset($result) { return $this->connection->sql_fetchrowset($result); }
	function sql_freeresult($result) { return $this->connection->sql_freeresult($result); }
	private function current_actor()
	{
		$settings = $this->rows("SELECT config_value FROM " . CONFIG_TABLE . " WHERE config_name='session_length' AND HEX(config_name)=HEX('session_length') LOCK IN SHARE MODE");
		if (count($settings) !== 1 || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$settings[0]['config_value'])
			|| (float)$settings[0]['config_value'] > 2147483647) { $this->storage_fail(); }
		$id = $this->actor['id']; $sid = $this->escape($this->actor['sid']);
		$sessions = $this->rows('SELECT session_time FROM ' . SESSIONS_TABLE . " WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid') AND session_user_id=$id AND session_logged_in=1 FOR UPDATE");
		if (count($sessions) !== 1 || !preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', (string)$sessions[0]['session_time'])
			|| (float)$sessions[0]['session_time'] > 2147483647
			|| (float)$sessions[0]['session_time'] < time() - (int)$settings[0]['config_value']) { $this->storage_fail('Session_invalid'); }
		$users = $this->rows('SELECT user_id,user_level,user_active,user_blocktime FROM ' . USERS_TABLE . ' WHERE user_id=' . $id . ' FOR UPDATE');
		if (count($users) !== 1 || (string)$users[0]['user_active'] !== '1'
			|| !preg_match('/^-?[0-9]+$/D', (string)$users[0]['user_blocktime'])
			|| (float)$users[0]['user_blocktime'] >= time()
			|| $this->rows('SELECT ban_id FROM ' . BANLIST_TABLE . ' WHERE ban_userid=' . $id . ' LOCK IN SHARE MODE')) { $this->storage_fail('Not_Authorised'); }
		return $users[0];
	}
	private function current_topic($user)
	{
		$topics = $this->rows('SELECT forum_id,topic_status,topic_moved_id FROM ' . TOPICS_TABLE . ' WHERE topic_id=' . $this->topic_id . ' FOR UPDATE');
		if (count($topics) !== 1 || (int)$topics[0]['topic_moved_id'] !== 0
			|| !in_array((int)$topics[0]['topic_status'], array(TOPIC_UNLOCKED, TOPIC_LOCKED), true)) { $this->storage_fail('Topic_post_not_exist'); }
		$forum_id = (int)$topics[0]['forum_id'];
		$forums = $this->rows('SELECT forum_id FROM ' . FORUMS_TABLE . ' WHERE forum_id=' . $forum_id . ' FOR UPDATE');
		// Pin all rows used by core auth(), including pending memberships and
		// every grant for this forum. Rechecks use fresh READ COMMITTED state.
		$this->rows('SELECT group_id FROM ' . USER_GROUP_TABLE . ' WHERE user_id=' . $this->actor['id'] . ' ORDER BY group_id LOCK IN SHARE MODE');
		$this->rows('SELECT group_id FROM ' . AUTH_ACCESS_TABLE . ' WHERE forum_id=' . $forum_id . ' ORDER BY group_id LOCK IN SHARE MODE');
		if (count($forums) !== 1 || !phpbb_topic_preference_can_read($this, $forum_id, $user)) { $this->storage_fail('Topic_post_not_exist'); }
	}
	function apply_preference()
	{
		$user = $this->current_actor();
		if ($this->state !== false) { $this->current_topic($user); }
		$where = 'topic_id=' . $this->topic_id . ' AND user_id=' . $this->actor['id'];
		$before = $this->rows('SELECT * FROM ' . $this->table . ' WHERE ' . $where . ' FOR UPDATE');
		if ($this->state === false)
		{
			// The owner is current; removing an orphan/private preference does
			// not inspect the topic or require access to its former forum.
			$this->query('DELETE FROM ' . $this->table . ' WHERE ' . $where);
		}
		elseif ($this->state === true && !$before)
		{
			$this->query('INSERT INTO ' . $this->table . ' (topic_id,user_id) VALUES (' . $this->topic_id . ',' . $this->actor['id'] . ')');
		}
		if ($this->state !== false && $this->kind === 'watch')
		{
			$this->query('UPDATE ' . $this->table . " SET notify_status=0,notify_claim='',notify_claimed_at=0 WHERE " . $where);
		}
		$after = $this->rows('SELECT * FROM ' . $this->table . ' WHERE ' . $where);
		$expected = $this->state === false ? 0 : ($this->state === true && !$before ? 1 : count($before));
		if (count($after) !== $expected) { $this->storage_fail(); }
		if ($this->kind === 'watch' && $this->state !== false)
		{
			foreach ($after as $row) { if ((string)$row['notify_status'] !== '0' || $row['notify_claim'] !== '' || (string)$row['notify_claimed_at'] !== '0') { $this->storage_fail(); } }
		}
		$user = $this->current_actor();
		if ($this->state !== false) { $this->current_topic($user); }
		$this->commit_attempted = true; $this->commit();
		return (bool)$after;
	}
	function remove_bookmarks($topic_ids)
	{
		$topic_ids = phpbb_bookmark_removal_ids($topic_ids);
		if ($this->kind !== 'bookmark' || $this->state !== false || !$topic_ids) { $this->storage_fail(); }
		$this->current_actor();
		$where = 'user_id=' . $this->actor['id'] . ' AND topic_id IN (' . implode(',', $topic_ids) . ')';
		$this->rows('SELECT topic_id FROM ' . BOOKMARK_TABLE . ' WHERE ' . $where . ' ORDER BY topic_id FOR UPDATE');
		// One transaction for the whole selection, including duplicate/orphan
		// bookmarks. A failure must never leave a partially removed selection.
		$this->query('DELETE FROM ' . BOOKMARK_TABLE . ' WHERE ' . $where);
		if ($this->rows('SELECT topic_id FROM ' . BOOKMARK_TABLE . ' WHERE ' . $where)) { $this->storage_fail(); }
		$this->current_actor();
		$this->commit_attempted = true; $this->commit();
		return true;
	}
}
