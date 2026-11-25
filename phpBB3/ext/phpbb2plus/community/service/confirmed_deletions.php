<?php
namespace phpbb2plus\community\service;

class confirmed_deletions
{
	private $db;
	private $config;
	private $auth;
	private $user;
	private $language;
	private $prefix;
	private $files;
	private $container;
	private $origin = '';
	private $selected = array();
	private $source_topic = 0;
	private $source_forum = 0;
	private $prune_rule;
	private $pruned_posts = 0;
	private $active;

	public function __construct($db, $config, $auth, $user, $language, $prefix, $files, $container)
	{
		$this->db = $db;
		$this->config = $config;
		$this->auth = $auth;
		$this->user = $user;
		$this->language = $language;
		$this->prefix = $prefix;
		$this->files = $files;
		$this->container = $container;
	}
	private function rows($sql)
	{
		$result = $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}
	public function from_author($post, $topic, $forum)
	{
		$this->origin = 'author';
		$this->source_topic = $topic;
		$this->source_forum = $forum;
		$this->selected = array($post);
	}
	public function from_mcp($topic, $ids)
	{
		$this->origin = $topic ? 'topics' : 'posts';
		$this->selected = array_values(array_unique(array_filter(array_map('intval', $ids))));
	}
	public function matches($whole, $posts, $topic, $forum, $actor, $visibility)
	{
		if (!$this->origin || $this->active || (int) $visibility !== ITEM_DELETED) { return false; }
		if ($this->origin === 'prune') { return $whole && (int) $topic === $this->source_topic && (int) $forum === $this->source_forum; }
		if (!$this->user->data['is_registered'] || (int) $actor !== (int) $this->user->data['user_id']) { return false; }
		if ($this->origin === 'author' && ((int) $topic !== $this->source_topic || (int) $forum !== $this->source_forum)) { return false; }
		if ($whole) { return $this->origin === 'author' || ($this->origin === 'topics' && in_array((int) $topic, $this->selected, true)); }
		return $posts && $this->origin !== 'topics' && !array_diff(array_map('intval', (array) $posts), $this->selected);
	}
	public function topic_children($topic, $forum, $actor, $visibility)
	{
		$a = $this->active;
		return $a && $a['whole'] && $a['topic'] === (int) $topic && $a['forum'] === (int) $forum &&
			$a['actor'] === (int) $actor && (int) $visibility === ITEM_DELETED ? $a['ids'] : array();
	}

	/** A purged post has nothing to restore. Keep phpBB's own publication/time limits for all other rows. */
	public function restore($whole, $posts, $topic_id, $force, $limit_visibility, $limit_time, callable $native)
	{
		$db = $this->db;
		$db->sql_transaction('begin');
		try
		{
			$topic_id = (int) $topic_id;
			$scope = "topic_id=$topic_id";
			$topics = $this->rows('SELECT plus_legacy_removed,topic_status,topic_visibility,topic_delete_time FROM ' . TOPICS_TABLE . " WHERE $scope FOR UPDATE");
			if ($topics && removed_topics::marker($topics[0])) { $db->sql_transaction('commit'); return array(); }
			if ($whole && $topics && !$force && (int) $topics[0]['topic_visibility'] === ITEM_DELETED && $topics[0]['topic_delete_time'])
			{
				$limit_visibility = ITEM_DELETED;
				$limit_time = (int) $topics[0]['topic_delete_time'];
			}
			if ($posts) { $scope .= ' AND ' . $db->sql_in_set('post_id', array_map('intval', (array) $posts)); }
			else
			{
				if ($limit_visibility !== false) { $scope .= ' AND post_visibility=' . (int) $limit_visibility; }
				if ($limit_time !== false) { $scope .= ' AND post_delete_time=' . (int) $limit_time; }
			}
			$erased = $this->rows('SELECT post_id FROM ' . POSTS_TABLE . " WHERE $scope AND plus_legacy_erased=1 LIMIT 1");
			if ($erased)
			{
				$allowed = $this->rows('SELECT post_id FROM ' . POSTS_TABLE . " WHERE $scope AND plus_legacy_erased=0 ORDER BY post_id FOR UPDATE");
				if (!$allowed) { $db->sql_transaction('commit'); return array(); }
				$posts = array_column($allowed, 'post_id');
			}
			$result = $native($posts);
			$db->sql_transaction('commit');
			return $result;
		}
		catch (\Throwable $error) { $db->sql_transaction('rollback'); throw $error; }
	}

	/** Trusted maintenance entry. Recheck the caller's forum/age policy while holding this topic's write lock. */
	public function prune_topic($topic_id, $forum_id, $actor_id, $time, callable $still_eligible)
	{
		if ($this->origin || $this->active || (int) $topic_id < 1 || (int) $forum_id < 1 || (int) $actor_id < 0 || (int) $time < 1) { return false; }
		$this->origin = 'prune';
		$this->source_topic = (int) $topic_id;
		$this->source_forum = (int) $forum_id;
		$this->prune_rule = $still_eligible;
		$this->pruned_posts = 0;
		try
		{
			$result = $this->container->get('content.visibility')->set_topic_visibility(ITEM_DELETED, $topic_id, $forum_id, $actor_id, $time, '');
			return $result && $this->pruned_posts ? array('topics' => 1, 'posts' => $this->pruned_posts) : false;
		}
		finally { $this->origin = ''; $this->prune_rule = null; }
	}

	private function allowed($topic, $rows, $whole, $actor)
	{
		if ($this->origin === 'prune') { return true; }
		$account = $this->rows('SELECT * FROM ' . USERS_TABLE . ' WHERE user_id=' . $actor . ' LOCK IN SHARE MODE');
		if (!$account || !in_array((int) $account[0]['user_type'], array(USER_NORMAL, USER_FOUNDER), true)) { return false; }
		$account[0]['is_registered'] = true;
		$this->auth->acl($account[0]);
		$forum = (int) $topic['forum_id'];
		if ($this->origin === 'author' && $whole && array_keys($rows) !== $this->selected) { return false; }
		if ($this->auth->acl_get('m_softdelete', $forum)) { return true; }
		if ($this->origin !== 'author' || count($rows) !== 1 || !$this->auth->acl_get('f_softdelete', $forum)) { return false; }
		$post = reset($rows);
		return (int) $post['poster_id'] === $actor && (int) $post['post_id'] === (int) $topic['topic_last_post_id'] &&
			!$post['post_edit_locked'] && (!$this->config['delete_time'] || (int) $post['post_time'] > time() - 60 * (int) $this->config['delete_time']);
	}

	public function change($whole, $posts, $topic_id, $forum_id, $actor, $time, callable $native)
	{
		$db = $this->db;
		$topic_id = (int) $topic_id;
		$forum_id = (int) $forum_id;
		$actor = (int) $actor;
		$db->sql_transaction('begin');
		try
		{
			$policy = $this->rows('SELECT config_value FROM ' . CONFIG_TABLE . " WHERE config_name='plus_post_delete_policy' LOCK IN SHARE MODE");
			$topics = $this->rows('SELECT * FROM ' . TOPICS_TABLE . " WHERE topic_id=$topic_id AND forum_id=$forum_id AND topic_moved_id=0 FOR UPDATE");
			if (!$policy || !in_array((string) $policy[0]['config_value'], array('0','1'), true) || !$topics ||
				(int) $topics[0]['topic_visibility'] !== ITEM_APPROVED) { $db->sql_transaction('commit'); return array(); }
			$topic = $topics[0];
			if ($this->origin === 'prune' && ($this->prune_rule)($topic) !== true) { $db->sql_transaction('commit'); return array(); }
			$scope = "topic_id=$topic_id AND forum_id=$forum_id AND post_visibility=" . ITEM_APPROVED;
			if (!$whole) { $scope .= ' AND ' . $db->sql_in_set('post_id', array_map('intval', (array) $posts)); }
			$rows = array_column($this->rows('SELECT post_id,topic_id,forum_id,poster_id,post_time,post_edit_locked FROM ' . POSTS_TABLE .
				" WHERE $scope ORDER BY post_id FOR UPDATE"), null, 'post_id');
			if (!$rows || !$this->allowed($topic, $rows, $whole, $actor)) { $db->sql_transaction('commit'); return array(); }
			$ids = array_map('intval', array_keys($rows));
			$counters = array_column($this->rows('SELECT config_name,config_value FROM ' . CONFIG_TABLE .
				" WHERE config_name IN ('num_posts','num_topics') ORDER BY config_name FOR UPDATE"), 'config_value', 'config_name');
			$this->active = array('whole' => $whole, 'topic' => $topic_id, 'forum' => $forum_id, 'actor' => $actor, 'ids' => $ids);
			$result = $native($ids, $topic);
			if ($result)
			{
				$changed = $this->rows('SELECT post_id FROM ' . POSTS_TABLE . " WHERE topic_id=$topic_id AND forum_id=$forum_id AND post_visibility=" .
					ITEM_DELETED . " AND post_delete_user=$actor AND post_delete_time=" . (int) $time . ' AND ' . $db->sql_in_set('post_id', $ids));
				$rows = array_intersect_key($rows, array_flip(array_column($changed, 'post_id')));
				if ($rows)
				{
					$this->finish($topic, $rows, $counters);
					if ($this->origin === 'prune') { $this->pruned_posts = count($rows); }
				}
			}
			$db->sql_transaction('commit');
		}
		catch (\Throwable $error) { $db->sql_transaction('rollback'); throw $error; }
		finally { $this->active = null; }
		$this->files->after_request();
		return $result;
	}

	private function finish($topic, $rows, $counters)
	{
		$db = $this->db;
		$id = (int) $topic['topic_id'];
		$forum = (int) $topic['forum_id'];
		$ids = array_keys($rows);
		if (isset($rows[$topic['topic_first_post_id']]))
		{
			foreach (array(POLL_OPTIONS_TABLE, POLL_VOTES_TABLE) as $table) { $db->sql_query("DELETE FROM $table WHERE topic_id=$id"); }
			$db->sql_query('UPDATE ' . TOPICS_TABLE . " SET poll_title='',poll_start=0,poll_length=0,poll_last_vote=0,poll_max_options=1,poll_vote_change=0 WHERE topic_id=$id");
		}
		foreach (array_chunk($rows, 200, true) as $batch)
		{
			$posts = array_keys($batch);
			$this->files->detach($id, $posts);
			$db->sql_query('UPDATE ' . POSTS_TABLE . ' SET plus_legacy_erased=1,plus_public_post=1 WHERE ' . $db->sql_in_set('post_id', $posts));
			removed_topics::erase_rows($db, $this->prefix, $batch);
		}
		$db->sql_query('DELETE b FROM ' . TOPICS_POSTED_TABLE . ' b WHERE b.topic_id=' . $id . ' AND ' .
			$db->sql_in_set('b.user_id', array_unique(array_column($rows, 'poster_id'))) . ' AND NOT EXISTS
			(SELECT 1 FROM ' . POSTS_TABLE . ' p WHERE p.topic_id=b.topic_id AND p.poster_id=b.user_id)');
		$remaining = $this->rows('SELECT post_id FROM ' . POSTS_TABLE . " WHERE topic_id=$id AND
			(forum_id<>$forum OR post_visibility<>" . ITEM_DELETED . ' OR plus_legacy_erased=0 OR plus_public_post=0) LIMIT 1');
		if (!$remaining)
		{
			$this->language->add_lang('community', 'phpbb2plus/community');
			$db->sql_query('UPDATE ' . TOPICS_TABLE . ' SET ' . $db->sql_build_array('UPDATE', array('plus_legacy_removed' => 1, 'topic_visibility' => ITEM_DELETED,
				'topic_status' => ITEM_LOCKED, 'topic_title' => $this->language->lang('PLUS_REMOVED_TOPIC'), 'plus_description' => '',
				'topic_poster' => ANONYMOUS, 'topic_first_poster_name' => '', 'topic_first_poster_colour' => '', 'topic_last_poster_id' => ANONYMOUS,
				'topic_last_poster_name' => '', 'topic_last_poster_colour' => '', 'topic_last_post_subject' => '', 'topic_attachment' => 0)) . " WHERE topic_id=$id");
		}
		$after = $this->rows('SELECT topic_visibility FROM ' . TOPICS_TABLE . " WHERE topic_id=$id");
		$this->config->set('num_posts', max(0, (int) $counters['num_posts'] - count($ids)), false);
		$this->config->set('num_topics', max(0, (int) $counters['num_topics'] - (int) ((int) $after[0]['topic_visibility'] !== ITEM_APPROVED)), false);
	}
}
