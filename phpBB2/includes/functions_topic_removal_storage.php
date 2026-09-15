<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_post_delete_storage.php';

// One owner for bulk moderation, age pruning, and ACP forum removal. These
// policies share atomic storage, not authorization. File cleanup follows COMMIT.
class PhpbbTopicRemovalDatabase extends PhpbbPostSubmitDatabase
{
	var $policy;
	var $forum_removed = false;
	var $target = null;
	var $policy_guard = '';
	var $post_deleted = false;
	var $attachments = array();
	function __construct($connection, $forum_id, $policy, $target = null)
	{
		if (!in_array($policy, array('moderator','prune_auto','prune_admin','forum_admin'), true)) { throw new PhpbbPostSubmitException('Not_Authorised'); }
		$this->policy = $policy;
		parent::__construct($connection, 'newtopic', $forum_id, 0, array());
		if ($target !== null) { $this->target = phpbb_posting_scope_id($target); }
	}
	function fail($key)
	{
		if ($key === 'Posting_submit_unconfirmed') { $key = $this->policy === 'moderator' ? 'Moderation_delete_unconfirmed' : 'Prune_atomic_unconfirmed'; }
		if ($key === 'Posting_submit_upgrade') { $key = 'Moderation_storage_upgrade'; }
		throw new PhpbbPostSubmitException($key);
	}
	function tables()
	{
		$tables = array_merge(parent::tables(), array(LOGS_TABLE, VOTE_USERS_TABLE, ATTACHMENTS_TABLE, ATTACHMENTS_DESC_TABLE, TOPICS_WATCH_TABLE, BOOKMARK_TABLE, TOPIC_VIEW_TABLE, PRIVMSGS_TABLE));
		if ($this->policy !== 'moderator') { $tables[] = PRUNE_TABLE; }
		if ($this->policy === 'prune_admin' || $this->policy === 'forum_admin') { $tables[] = JR_ADMIN_TABLE; }
		if ($this->policy === 'forum_admin') { $tables[] = CATEGORIES_TABLE; }
		return $tables;
	}
	function actor()
	{
		global $userdata;
		$user = parent::actor();
		if ((int)$user['user_id'] === ANONYMOUS) { $this->fail('Session_invalid'); }
		if ($this->policy === 'prune_admin' || $this->policy === 'forum_admin')
		{
			$sid = $this->sql_escape($userdata['session_id']);
			if (!$this->rows('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id = '$sid' AND HEX(session_id) = HEX('$sid') AND session_user_id = " . (int)$user['user_id'] . ' AND session_admin = 1')) { $this->fail('Session_invalid'); }
		}
		return $user;
	}
	function authorize()
	{
		$user = $this->actor();
		$forums = $this->rows('SELECT forum_id FROM ' . FORUMS_TABLE . ' WHERE forum_id = ' . $this->forum_id);
		if ($this->forum_removed ? (bool)$forums : count($forums) !== 1) { $this->fail('Prune_selection_changed'); }
		if ($this->policy === 'moderator' || $this->policy === 'prune_auto')
		{
			$auth = auth(AUTH_ALL, $this->forum_id, $user, '', $this);
			if (empty($auth['auth_view']) || empty($auth['auth_read']) || empty($auth['auth_mod']) || ($this->policy === 'moderator' && empty($auth['auth_delete']))) { $this->fail($this->policy === 'moderator' ? 'Not_Moderator' : 'Not_Authorised'); }
		}
		else
		{
			$module = $this->policy === 'forum_admin' ? 'admin_forums' : 'admin_forum_prune';
			if (!phpbb_prune_admin_allowed($this, $user, $module)) { $this->fail('Not_Authorised'); }
		}
		if ($this->policy_guard !== '' && !$this->rows('SELECT 1 AS allowed WHERE 1 = 1' . $this->policy_guard)) { $this->fail('Prune_selection_changed'); }
		if ($this->target !== null && !$this->rows('SELECT forum_id FROM ' . FORUMS_TABLE . ' WHERE forum_id = ' . $this->target . " AND COALESCE(forum_link, '') = ''")) { $this->fail('Prune_selection_changed'); }
		return true;
	}
	function begin()
	{
		parent::begin();
		if ($this->target !== null) { $this->rows('SELECT forum_id FROM ' . FORUMS_TABLE . ' WHERE forum_id = ' . $this->target . ' FOR UPDATE'); $this->authorize(); }
	}
	function commit()
	{
		$user = $this->actor();
		if ($this->policy === 'prune_admin' || $this->policy === 'forum_admin')
		{
			$this->rows('SELECT user_id FROM ' . JR_ADMIN_TABLE . ' WHERE user_id = ' . (int)$user['user_id'] . ' LOCK IN SHARE MODE');
		}
		if ($this->policy === 'prune_auto')
		{
			$this->rows('SELECT config_value FROM ' . CONFIG_TABLE . " WHERE config_name = 'prune_enable' LOCK IN SHARE MODE");
			$this->rows('SELECT prune_id FROM ' . PRUNE_TABLE . ' WHERE forum_id = ' . $this->forum_id . ' ORDER BY prune_id LOCK IN SHARE MODE');
		}
		parent::commit();
	}
	function detach_post_files($post_id)
	{
		$rows = $this->rows('SELECT d.* FROM ' . ATTACHMENTS_DESC_TABLE . ' d WHERE EXISTS (SELECT 1 FROM ' . ATTACHMENTS_TABLE . ' a WHERE a.attach_id = d.attach_id AND a.post_id = ' . (int)$post_id . ' AND a.privmsgs_id = 0) ORDER BY d.attach_id FOR UPDATE');
		foreach ($rows as $row) { $this->attachments[(int)$row['attach_id']] = $row; }
		$this->sql_query('DELETE FROM ' . ATTACHMENTS_TABLE . ' WHERE post_id = ' . (int)$post_id . ' AND privmsgs_id = 0');
		$this->post_deleted = true;
	}
	function audit($topic_ids)
	{
		if (!$topic_ids) { return; }
		require_once dirname(__FILE__) . '/functions_log.php';
		$user = $this->actor(); $names = $this->rows('SELECT username FROM ' . USERS_TABLE . ' WHERE user_id = ' . (int)$user['user_id']);
		if (!log_action('delete', $topic_ids, (int)$user['user_id'], $names[0]['username'], $this)) { $this->fail('Posting_submit_unconfirmed'); }
	}
	function cleanup_query($sql)
	{
		if (!$this->confirmed || $this->transactional || !$this->post_deleted) { $this->fail('Posting_submit_unconfirmed'); }
		$this->authorize(); return $this->control($sql);
	}
	function cleanup()
	{
		if (!$this->confirmed) { $this->fail('Posting_submit_unconfirmed'); }
		try { if ($this->attachments) { phpbb_post_delete_cleanup($this, $this->attachments); } }
		catch (Exception $e) { return false; }
		catch (Error $e) { return false; }
		return true;
	}
}
