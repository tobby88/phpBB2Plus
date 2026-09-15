<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_posting_storage.php';
require_once dirname(__FILE__) . '/functions_post_submit_storage.php';

// SQL deletion commits before any file is unlinked. The existing description
// reserves detached files until cleanup succeeds (ACP: Shadow attachments).
class PhpbbPostDeleteDatabase extends PhpbbPostSubmitDatabase
{
	var $post_id;
	var $poster_id = null;
	var $was_last = false;
	var $was_first = false;
	var $post_deleted = false;
	var $topic_deleted = false;
	var $topic_status = 0;
	var $poll_ids = array();
	var $poll_voted = false;
	function fail($key)
	{
		if ($key === 'Posting_submit_unconfirmed') { $key = 'Posting_delete_unconfirmed'; }
		parent::fail($key);
	}
	function __construct($connection, $mode, $forum_id, $topic_id, $post_id)
	{
		if (!in_array($mode, array('delete', 'poll_delete'), true)) { $this->fail('No_valid_mode'); }
		parent::__construct($connection, $mode, $forum_id, $topic_id, array());
		$this->post_id = phpbb_posting_scope_id($post_id);
	}
	function tables()
	{
		return array_merge(parent::tables(), array(LOGS_TABLE, VOTE_USERS_TABLE, ATTACHMENTS_TABLE, ATTACHMENTS_DESC_TABLE, TOPICS_WATCH_TABLE, BOOKMARK_TABLE, TOPIC_VIEW_TABLE, PRIVMSGS_TABLE));
	}
	function authorize()
	{
		$user = $this->actor();
		if ((int)$user['user_id'] === ANONYMOUS) { $this->fail('Delete_own_posts'); }
		$forums = $this->rows('SELECT forum_status FROM ' . FORUMS_TABLE . ' WHERE forum_id = ' . $this->forum_id);
		$topics = $this->rows('SELECT topic_status, forum_id, topic_moved_id FROM ' . TOPICS_TABLE . ' WHERE topic_id = ' . $this->topic_id);
		$posts = $this->rows('SELECT poster_id, topic_id, forum_id FROM ' . POSTS_TABLE . ' WHERE post_id = ' . $this->post_id);
		if (count($forums) !== 1) { $this->fail('Posting_target_changed'); }
		if ($this->topic_deleted)
		{
			if ($topics || $this->rows('SELECT post_id FROM ' . POSTS_TABLE . ' WHERE topic_id = ' . $this->topic_id . ' LIMIT 1')) { $this->fail('Posting_target_changed'); }
		}
		else if (count($topics) !== 1 || (int)$topics[0]['forum_id'] !== $this->forum_id || !empty($topics[0]['topic_moved_id'])) { $this->fail('Posting_target_changed'); }
		if ($this->post_deleted)
		{
			if ($posts || $this->poster_id === null) { $this->fail('Posting_target_changed'); }
			$owner = $this->poster_id; $last = $this->was_last;
		}
		else
		{
			if (count($posts) !== 1 || (int)$posts[0]['topic_id'] !== $this->topic_id || (int)$posts[0]['forum_id'] !== $this->forum_id || ($this->poster_id !== null && $this->poster_id !== (int)$posts[0]['poster_id'])) { $this->fail('Posting_target_changed'); }
			$owner = (int)$posts[0]['poster_id'];
			$bounds = $this->rows('SELECT MIN(post_id) AS first_post, MAX(post_id) AS last_post FROM ' . POSTS_TABLE . ' WHERE topic_id = ' . $this->topic_id);
			$last = (int)$bounds[0]['last_post'] === $this->post_id;
			if ($this->mode === 'poll_delete' && (int)$bounds[0]['first_post'] !== $this->post_id) { $this->fail('Cannot_delete_poll'); }
		}
		$auth = auth(AUTH_ALL, $this->forum_id, $user, '', $this);
		if (empty($auth['auth_view']) || empty($auth['auth_read'])) { $this->fail('Posting_submit_denied'); }
		if (empty($auth['auth_mod']))
		{
			if ($owner !== (int)$user['user_id'] || empty($auth['auth_delete'])) { $this->fail('Delete_own_posts'); }
			if ($this->mode === 'delete' && !$last) { $this->fail('Cannot_delete_replied'); }
			if ((int)$forums[0]['forum_status'] === FORUM_LOCKED) { $this->fail('Forum_locked'); }
			$status = $this->topic_deleted ? $this->topic_status : (int)$topics[0]['topic_status'];
			if ($status === TOPIC_LOCKED) { $this->fail('Topic_locked'); }
			if ($this->mode === 'poll_delete' || ($this->was_first && $this->was_last))
			{
				if ($this->poll_voted) { $this->fail('Cannot_delete_poll'); }
				foreach ($this->poll_ids as $id)
				{
					$votes = $this->rows('SELECT SUM(vote_result) AS votes FROM ' . VOTE_RESULTS_TABLE . ' WHERE vote_id = ' . $id);
					if (!empty($votes[0]['votes']) || $this->rows('SELECT vote_id FROM ' . VOTE_USERS_TABLE . ' WHERE vote_id = ' . $id . ' LIMIT 1')) { $this->fail('Cannot_delete_poll'); }
				}
			}
		}
		return $auth;
	}
	function remove_post()
	{
		$this->sql_query('DELETE FROM ' . POSTS_TABLE . ' WHERE post_id = ' . $this->post_id . ' AND topic_id = ' . $this->topic_id . ' AND forum_id = ' . $this->forum_id . ' AND poster_id = ' . $this->poster_id);
		if ((int)$this->sql_affectedrows() !== 1) { $this->fail('Posting_target_changed'); }
		$this->post_deleted = true;
	}
	function remove_topic()
	{
		$this->sql_query('DELETE FROM ' . TOPICS_TABLE . ' WHERE topic_id = ' . $this->topic_id . ' AND forum_id = ' . $this->forum_id
			. ' AND NOT EXISTS (SELECT 1 FROM ' . POSTS_TABLE . ' WHERE topic_id = ' . $this->topic_id . ')');
		if ((int)$this->sql_affectedrows() !== 1) { $this->fail('Posting_target_changed'); }
		$this->topic_deleted = true;
	}
	function cleanup_query($sql)
	{
		if (!$this->confirmed || $this->transactional || !$this->post_deleted) { $this->fail('Posting_submit_unconfirmed'); }
		$this->authorize();
		return $this->control($sql);
	}
}

function phpbb_post_delete_attachment_guard($db, $row)
{
	$terms = array('attach_id = ' . (int)$row['attach_id']);
	foreach (array('physical_filename','real_filename','comment','extension','mimetype','filesize','filetime','thumbnail','download_count','pm_write_token','pm_write_slot') as $field)
	{
		$terms[] = 'HEX(COALESCE(' . $field . ",'')) = HEX('" . $db->sql_escape((string)$row[$field]) . "')";
	}
	$terms[] = 'NOT EXISTS (SELECT 1 FROM ' . ATTACHMENTS_TABLE . ' WHERE attach_id = ' . (int)$row['attach_id'] . ')';
	return implode(' AND ', $terms);
}

function phpbb_post_delete_cleanup($db, $rows)
{
	if (!$db->confirmed || $db->transactional || !$db->post_deleted) { $db->fail('Posting_submit_unconfirmed'); }
	require_once dirname(__FILE__) . '/../attach_mod/includes/functions_pm_staging.php';
	foreach ($rows as $old)
	{
		if (attach_pm_stage_is_claimed($db, $old['physical_filename']) || $db->rows('SELECT privmsgs_id FROM ' . PRIVMSGS_TABLE . " WHERE privmsgs_write_payload IS NOT NULL AND HEX(privmsgs_write_token) = HEX('" . $db->sql_escape($old['pm_write_token']) . "') LIMIT 1")) { $db->fail('Attachment_delete_incomplete'); }
		$guard = phpbb_post_delete_attachment_guard($db, $old);
		if (!$db->rows('SELECT attach_id FROM ' . ATTACHMENTS_DESC_TABLE . ' WHERE ' . $guard)) { continue; }
		$shared = $db->rows('SELECT attach_id FROM ' . ATTACHMENTS_DESC_TABLE . " WHERE physical_filename = '" . $db->sql_escape($old['physical_filename']) . "' AND attach_id <> " . (int)$old['attach_id'] . ' LIMIT 1');
		if (!$shared)
		{
			if ((int)$old['thumbnail'] === 1)
			{
				$db->authorize();
				if (!$db->rows('SELECT attach_id FROM ' . ATTACHMENTS_DESC_TABLE . ' WHERE ' . $guard)) { $db->fail('Posting_target_changed'); }
				if (!attach_delete_file($old['physical_filename'], MODE_THUMBNAIL)) { $db->fail('Attachment_delete_incomplete'); }
				$db->cleanup_query('UPDATE ' . ATTACHMENTS_DESC_TABLE . ' SET thumbnail = 0 WHERE ' . $guard);
				if ((int)$db->sql_affectedrows() !== 1) { $db->fail('Posting_target_changed'); }
				$old['thumbnail'] = 0; $guard = phpbb_post_delete_attachment_guard($db, $old);
			}
			$db->authorize();
			if (!$db->rows('SELECT attach_id FROM ' . ATTACHMENTS_DESC_TABLE . ' WHERE ' . $guard)) { $db->fail('Posting_target_changed'); }
			if (!attach_delete_file($old['physical_filename'])) { $db->fail('Attachment_delete_incomplete'); }
		}
		$db->cleanup_query('DELETE FROM ' . ATTACHMENTS_DESC_TABLE . ' WHERE ' . $guard);
		if ((int)$db->sql_affectedrows() !== 1) { $db->fail('Posting_target_changed'); }
	}
}

function phpbb_submit_post_delete($database, $mode, $forum_id, $topic_id, $post_id, $poll_id)
{
	$lock = attach_require_mutation_lock($database); $db = null;
	try
	{
		$db = new PhpbbPostDeleteDatabase($lock->connection, $mode, $forum_id, $topic_id, $post_id);
		$db->begin();
		$posts = $db->rows('SELECT post_id, poster_id FROM ' . POSTS_TABLE . ' WHERE topic_id = ' . $db->topic_id . ' ORDER BY post_id FOR UPDATE');
		foreach ($posts as $post) { if ((int)$post['post_id'] === $db->post_id) { $db->poster_id = (int)$post['poster_id']; } }
		$db->was_last = (int)$posts[count($posts)-1]['post_id'] === $db->post_id;
		$first = (int)$posts[0]['post_id'] === $db->post_id;
		$db->was_first = $first;
		$topics = $db->rows('SELECT topic_status FROM ' . TOPICS_TABLE . ' WHERE topic_id = ' . $db->topic_id);
		$db->topic_status = (int)$topics[0]['topic_status'];
		$polls = $db->rows('SELECT vote_id FROM ' . VOTE_DESC_TABLE . ' WHERE topic_id = ' . $db->topic_id . ' ORDER BY vote_id FOR UPDATE');
		foreach ($polls as $poll)
		{
			$id = phpbb_posting_scope_id($poll['vote_id']); $db->poll_ids[] = $id;
			$options = $db->rows('SELECT vote_result FROM ' . VOTE_RESULTS_TABLE . ' WHERE vote_id = ' . $id . ' FOR UPDATE');
			$voters = $db->rows('SELECT vote_id FROM ' . VOTE_USERS_TABLE . ' WHERE vote_id = ' . $id . ' FOR UPDATE');
			foreach ($options as $option) { if ((int)$option['vote_result'] !== 0) { $db->poll_voted = true; } }
			if ($voters) { $db->poll_voted = true; }
		}
		if ($mode === 'poll_delete' && (count($polls) !== 1 || phpbb_posting_scope_id($poll_id) !== $db->poll_ids[0])) { $db->fail('Posting_target_changed'); }
		$db->authorize(); $attachments = array();
		$data = array('first_post'=>$first, 'last_post'=>$db->was_last, 'last_topic'=>false, 'poster_id'=>$db->poster_id, 'poster_post'=>$db->poster_id === (int)$GLOBALS['userdata']['user_id'], 'has_poll'=>(bool)$polls);
		if ($mode === 'delete')
		{
			$attachments = $db->rows('SELECT d.* FROM ' . ATTACHMENTS_DESC_TABLE . ' d WHERE EXISTS (SELECT 1 FROM ' . ATTACHMENTS_TABLE . ' a WHERE a.attach_id = d.attach_id AND a.post_id = ' . $db->post_id . ' AND a.privmsgs_id = 0) ORDER BY d.attach_id FOR UPDATE');
			$db->remove_post();
			$db->sql_query('DELETE FROM ' . POSTS_TEXT_TABLE . ' WHERE post_id = ' . $db->post_id);
			// A malformed mixed PM/post link is not authority to delete PM data.
			$db->sql_query('DELETE FROM ' . ATTACHMENTS_TABLE . ' WHERE post_id = ' . $db->post_id . ' AND privmsgs_id = 0');
			if ($first && $db->was_last)
			{
				$db->remove_topic();
				phpbb_cleanup_removed_topic_preferences($db, $db->topic_id);
				phpbb_posting_cleanup_empty_redirects($db, $db->topic_id);
			}
			else { attachment_sync_topic($db->topic_id, $db); }
			require_once dirname(__FILE__) . '/functions_search.php';
			remove_search_post($db->post_id, true, true, $db);
		}
		if ($mode === 'poll_delete' || $db->topic_deleted)
		{
			foreach ($db->poll_ids as $id)
			{
				$db->sql_query('DELETE FROM ' . VOTE_RESULTS_TABLE . ' WHERE vote_id = ' . $id);
				$db->sql_query('DELETE FROM ' . VOTE_USERS_TABLE . ' WHERE vote_id = ' . $id);
				$db->sql_query('DELETE FROM ' . VOTE_DESC_TABLE . ' WHERE vote_id = ' . $id . ' AND topic_id = ' . $db->topic_id);
			}
		}
		$user_id = $db->poster_id;
		update_post_stats($mode, $data, $db->forum_id, $db->topic_id, $db->post_id, $user_id, $db, false);
		if ($mode === 'delete') { phpbb_posting_sync_forum($db, $db->forum_id); }
		$auth = $db->authorize();
		if (!empty($auth['auth_mod']))
		{
			require_once dirname(__FILE__) . '/functions_log.php';
			$user = $db->actor(); $names = $db->rows('SELECT username FROM ' . USERS_TABLE . ' WHERE user_id = ' . (int)$user['user_id']);
			// Removing only a poll is a topic edit, not deletion of its posts.
			if (!log_action($mode === 'delete' ? 'delete' : 'edit', $db->topic_id, (int)$user['user_id'], $names[0]['username'], $db)) { $db->fail('Posting_submit_unconfirmed'); }
		}
		$db->commit(); $data['_delete_audit_completed'] = true; $data['_delete_cleanup_pending'] = false;
		try { if ($attachments) { phpbb_post_delete_cleanup($db, $attachments); } }
		catch (Exception $e) { $data['_delete_cleanup_pending'] = true; }
		catch (Error $e) { $data['_delete_cleanup_pending'] = true; }
		return array('post_data'=>$data, 'poll_id'=>$db->poll_ids ? $db->poll_ids[0] : 0);
	}
	catch (PhpbbPostSubmitException $e) { throw $e; }
	catch (Exception $e) { throw new PhpbbPostSubmitException('Posting_delete_unconfirmed'); }
	catch (Error $e) { throw new PhpbbPostSubmitException('Posting_delete_unconfirmed'); }
	finally { if ($db !== null) { $db->rollback(); } $lock->release(); }
}
