<?php
namespace phpbb2plus\community\service;

class age_pruning
{
	private const BATCH = 50;
	private $db;
	private $config;
	private $auth;
	private $user;
	private $deletions;
	private $queue;

	public function __construct($db, $config, $auth, $user, $deletions, $prefix)
	{
		$this->db = $db;
		$this->config = $config;
		$this->auth = $auth;
		$this->user = $user;
		$this->deletions = $deletions;
		$this->queue = $prefix . 'plus_prune_queue';
	}

	public function policy()
	{
		return (string) ($this->config['plus_post_delete_policy'] ?? '-1');
	}

	public function filter($mode, $cutoff, $flags)
	{
		$topics = TOPICS_TABLE;
		$cutoff = (int) $cutoff;
		$flags = (int) $flags;
		$time = $mode === 'posted' ? 'topic_last_post_time' : 'topic_last_view_time';
		$where = "topic_moved_id=0 AND topic_status IN (" . ITEM_UNLOCKED . ',' . ITEM_LOCKED . ')
			AND plus_legacy_removed=0 AND topic_visibility=' . ITEM_APPROVED . " AND $time<$cutoff";
		if (!($flags & FORUM_FLAG_PRUNE_ANNOUNCE)) { $where .= ' AND topic_type NOT IN (' . POST_ANNOUNCE . ',' . POST_GLOBAL . ')'; }
		if (!($flags & FORUM_FLAG_PRUNE_STICKY)) { $where .= ' AND topic_type<>' . POST_STICKY; }
		$poll = 'poll_start=0 AND NOT EXISTS (SELECT 1 FROM ' . POLL_OPTIONS_TABLE . " o WHERE o.topic_id=$topics.topic_id)
			AND NOT EXISTS (SELECT 1 FROM " . POLL_VOTES_TABLE . " v WHERE v.topic_id=$topics.topic_id)";
		if ($flags & FORUM_FLAG_PRUNE_POLL) { $poll = "($poll) OR (poll_start>0 AND poll_last_vote<$cutoff)"; }
		// Check the actual posts. A stale topic timestamp, a cross-forum row or a
		// pending moderation case must not authorize the removal of an active topic.
		$relation = "p.topic_id=$topics.topic_id";
		$where .= " AND ($poll) AND EXISTS (SELECT 1 FROM " . POSTS_TABLE . " p WHERE $relation AND p.post_visibility=" . ITEM_APPROVED . ')
			AND NOT EXISTS (SELECT 1 FROM ' . POSTS_TABLE . " p WHERE $relation AND (p.forum_id<>$topics.forum_id OR p.post_time>=$cutoff
				OR (p.post_visibility<>" . ITEM_APPROVED . ' AND p.plus_legacy_erased=0)))';
		return $where;
	}

	private function rows($sql, $limit = 0)
	{
		$r = $limit ? $this->db->sql_query_limit($sql, $limit) : $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($r);
		$this->db->sql_freeresult($r);
		return $rows;
	}

	public function forum($id, $locked = false)
	{
		$rows = $this->rows('SELECT forum_id,forum_name,forum_type,enable_prune,prune_next,prune_days,prune_viewed,prune_freq,forum_flags
			FROM ' . FORUMS_TABLE . ' WHERE forum_id=' . (int) $id . ($locked ? ' LOCK IN SHARE MODE' : ''));
		return $rows ? $rows[0] : null;
	}

	public function schedule($forum, $mode, $cutoff, $flags)
	{
		if (!$forum || (int) $forum['forum_type'] !== FORUM_POST || !(int) $forum['enable_prune'] || !(int) $forum['prune_freq'] ||
			(int) $forum['forum_flags'] !== (int) $flags) { return null; }
		$days = (int) $forum[$mode === 'posted' ? 'prune_days' : 'prune_viewed'];
		if (!$days || (int) $cutoff > time() - $days * 86400) { return null; }
		return array('forum_id' => (int) $forum['forum_id'], 'prune_mode' => $mode, 'cutoff' => (int) $cutoff,
			'flags' => (int) $flags, 'days' => $days, 'frequency' => (int) $forum['prune_freq']);
	}

	public function manual($forum, $locked = false)
	{
		if (!defined('IN_ADMIN') || !IN_ADMIN || empty($this->user->data['session_admin']) || !empty($this->user->data['user_perm_from'])) { return false; }
		if ($locked)
		{
			$id = (int) $this->user->data['user_id'];
			$sid = $this->db->sql_escape($this->user->session_id);
			$rows = $this->rows('SELECT u.* FROM ' . USERS_TABLE . ' u JOIN ' . SESSIONS_TABLE . " s ON s.session_user_id=u.user_id
				WHERE u.user_id=$id AND s.session_id='$sid' AND s.session_admin=1 AND u.user_perm_from=0 AND u.user_type IN (" .
				USER_NORMAL . ',' . USER_FOUNDER . ') LOCK IN SHARE MODE');
			if (!$rows) { return false; }
			$rows[0]['is_registered'] = true;
			$this->auth->acl($rows[0]);
		}
		return $this->auth->acl_get('a_fprune') && $this->auth->acl_get('f_list', $forum);
	}

	public function apply($ids, $forum, $mode, $cutoff, $flags, $schedule = null)
	{
		$counts = array('topics' => 0, 'posts' => 0);
		$filter = $this->filter($mode, $cutoff, $flags);
		$actor = $schedule ? 0 : (int) $this->user->data['user_id'];
		foreach ($ids as $topic)
		{
			$changed = $this->deletions->prune_topic($topic, $forum, $actor, time(), function ($locked) use ($forum, $mode, $cutoff, $flags, $schedule, $filter) {
				if ($schedule)
				{
					if ($this->schedule($this->forum($forum, true), $mode, $cutoff, $flags) !== $schedule) { return false; }
				}
				elseif (!$this->manual($forum, true)) { return false; }
				return (bool) $this->rows('SELECT topic_id FROM ' . TOPICS_TABLE . ' WHERE topic_id=' . (int) $locked['topic_id'] .
					' AND forum_id=' . (int) $forum . " AND $filter FOR UPDATE");
			});
			if ($changed) { $counts['topics'] += $changed['topics']; $counts['posts'] += $changed['posts']; }
		}
		return $counts;
	}

	public function queue($schedule)
	{
		// The native scheduler advances to its next interval after the hard-delete
		// list is emptied. Persist a full batch's continuation before returning.
		$this->db->sql_query("INSERT INTO {$this->queue} " . $this->db->sql_build_array('INSERT', $schedule + array('cursor_id' => 0)) .
			' ON DUPLICATE KEY UPDATE forum_id=VALUES(forum_id)');
	}

	public function available()
	{
		return (bool) $this->rows("SELECT forum_id FROM {$this->queue}", 1);
	}

	public function resume()
	{
		$rows = $this->rows("SELECT * FROM {$this->queue} ORDER BY cutoff,forum_id,prune_mode", 1);
		if (!$rows) { return; }
		$row = $rows[0];
		$forum = (int) $row['forum_id'];
		$mode = $row['prune_mode'];
		$cutoff = (int) $row['cutoff'];
		$flags = (int) $row['flags'];
		$cursor = (int) $row['cursor_id'];
		$where = "forum_id=$forum AND prune_mode='" . $this->db->sql_escape($mode) . "' AND cutoff=$cutoff AND cursor_id=$cursor
			AND flags=$flags AND days=" . (int) $row['days'] . ' AND frequency=' . (int) $row['frequency'];
		$ids = array();
		if (in_array($this->policy(), array('0', '1'), true) && in_array($mode, array('posted', 'viewed'), true))
		{
			$schedule = $this->schedule($this->forum($forum), $mode, $cutoff, $flags);
			if ($schedule && $schedule['days'] === (int) $row['days'] && $schedule['frequency'] === (int) $row['frequency'])
			{
				$filter = $this->filter($mode, $cutoff, $flags);
				$ids = array_column($this->rows('SELECT topic_id FROM ' . TOPICS_TABLE .
					" WHERE forum_id=$forum AND topic_id>$cursor AND $filter ORDER BY topic_id", self::BATCH), 'topic_id');
				$this->apply($ids, $forum, $mode, $cutoff, $flags, $schedule);
			}
		}
		if (count($ids) < self::BATCH) { $this->db->sql_query("DELETE FROM {$this->queue} WHERE $where"); }
		else { $this->db->sql_query("UPDATE {$this->queue} SET cursor_id=" . (int) end($ids) . " WHERE $where"); }
	}
}
