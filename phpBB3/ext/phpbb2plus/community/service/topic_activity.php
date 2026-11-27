<?php
namespace phpbb2plus\community\service;

class topic_activity
{
	private $db;
	private $auth;
	private $visibility;
	private $user;
	private $dispatcher;
	private $config;

	public function __construct($db, $auth, $visibility, $user, $dispatcher, $config)
	{
		$this->db = $db;
		$this->auth = $auth;
		$this->visibility = $visibility;
		$this->user = $user;
		$this->dispatcher = $dispatcher;
		$this->config = $config;
	}

	public function listed_forums()
	{
		return array_diff(array_keys($this->auth->acl_getf('f_list', true)), $this->user->get_passworded_forums());
	}

	public function forums($listed = null)
	{
		return array_intersect($listed ?? $this->listed_forums(), array_keys($this->auth->acl_getf('f_read', true)));
	}

	public static function period($mode, $days, $now, \DateTimeZone $zone)
	{
		$end = $now + 1;
		if ($mode === 'today' || $mode === 'yesterday')
		{
			$midnight = (new \DateTimeImmutable('@' . $now))->setTimezone($zone)->setTime(0, 0);
			if ($mode === 'yesterday') { return array($midnight->modify('-1 day')->getTimestamp(), $midnight->getTimestamp()); }
			return array($midnight->getTimestamp(), $end);
		}
		$days = $mode === 'lastXdays' ? $days : ($mode === 'lastweek' ? 7 : 1);
		return array($now - $days * 86400, $end);
	}

	public function page($forums, $limit, $period = null, $start = 0)
	{
		$page = array('total' => 0, 'start' => 0, 'rows' => array());
		if (!$forums || !$limit) { return $page; }
		$from = ' FROM ' . TOPICS_TABLE . ' t JOIN ' . POSTS_TABLE . ' p
			ON p.post_id=t.topic_last_post_id AND p.topic_id=t.topic_id AND p.forum_id=t.forum_id
			JOIN ' . FORUMS_TABLE . ' f ON f.forum_id=t.forum_id';
		$where = ' WHERE ' . $this->db->sql_in_set('t.forum_id', $forums) . ' AND t.topic_moved_id=0 AND t.topic_status<>' . ITEM_MOVED .
			' AND t.topic_visibility=' . ITEM_APPROVED . ' AND p.post_visibility=' . ITEM_APPROVED .
			' AND ' . $this->visibility->get_forums_visibility_sql('topic', $forums, 't.') .
			' AND ' . $this->visibility->get_forums_visibility_sql('post', $forums, 'p.');
		$columns = 't.topic_id,t.forum_id,t.topic_title,f.forum_name,p.post_id,p.post_time,p.post_username,p.poster_id AS user_id,u.username,u.user_colour';
		$joins = ' LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=p.poster_id';
		$order = 'p.post_id DESC';
		if ($period !== null)
		{
			$where .= ' AND p.post_time>=' . (int) $period[0] . ' AND p.post_time<' . (int) $period[1];
			$count = $this->db->sql_query('SELECT COUNT(*) AS total' . $from . $where);
			$page['total'] = (int) $this->db->sql_fetchfield('total');
			$this->db->sql_freeresult($count);
			if (!$page['total']) { return $page; }
			$page['start'] = intdiv(min(max(0, $start), $page['total'] - 1), $limit) * $limit;
			$order = 'p.post_time DESC,p.post_id DESC,t.topic_id DESC';
			$columns .= ',t.topic_views,t.topic_posts_approved,t.topic_type,t.topic_status,t.poll_start,
				b.post_id AS first_id,b.post_time AS first_time,b.poster_id AS first_user,b.post_username AS first_guest,
				bu.username AS first_name,bu.user_colour AS first_colour';
			$joins .= ' LEFT JOIN ' . POSTS_TABLE . ' b ON b.post_id=t.topic_first_post_id AND b.topic_id=t.topic_id
				AND b.forum_id=t.forum_id AND b.post_visibility=' . ITEM_APPROVED .
				' AND (' . $this->visibility->get_forums_visibility_sql('post', $forums, 'b.') . ')
				LEFT JOIN ' . USERS_TABLE . ' bu ON bu.user_id=b.poster_id';
			if ($this->user->data['is_registered'] && $this->config['load_db_lastread'])
			{
				$actor = (int) $this->user->data['user_id'];
				$columns .= ',tt.mark_time,ft.mark_time AS forum_mark_time';
				$joins .= ' LEFT JOIN ' . TOPICS_TRACK_TABLE . " tt ON tt.topic_id=t.topic_id AND tt.user_id=$actor
					LEFT JOIN " . FORUMS_TRACK_TABLE . " ft ON ft.forum_id=t.forum_id AND ft.user_id=$actor";
			}
		}
		$result = $this->db->sql_query_limit('SELECT ' . $columns . $from . $joins . $where . ' ORDER BY ' . $order, $limit, $page['start']);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		$page['rows'] = $this->with_authors($rows);
		return $page;
	}

	// Resolve both bylines in one pass, including the visitor's former-user visibility.
	public function with_authors(array $rows)
	{
		if (!$rows) { return array(); }
		$posts = array_column($rows, null, 'post_id');
		foreach ($rows as $r)
		{
			if (!empty($r['first_id']))
			{
				$posts[$r['first_id']] = array('post_id'=>$r['first_id'], 'user_id'=>$r['first_user'], 'post_username'=>$r['first_guest'],
					'username'=>$r['first_name'], 'user_colour'=>$r['first_colour']);
			}
		}
		$authors = $this->dispatcher->trigger_event('phpbb2plus.community.preview_bylines',
			array('rowset'=>$posts, 'post_list'=>array_keys($posts)))['rowset'];
		foreach ($rows as $index => $r)
		{
			$rows[$index]['last_author'] = $this->author($authors[$r['post_id']]);
			if (!empty($r['first_id'])) { $rows[$index]['first_author'] = $this->author($authors[$r['first_id']]); }
		}
		return $rows;
	}

	public function tracking(array $rows, $include_guests = false)
	{
		$member = !empty($this->user->data['is_registered']);
		if (!$member && (!$include_guests || !$this->config['load_anon_lastread'])) { return array(); }
		$by_forum = array();
		foreach ($rows as $r) { $by_forum[$r['forum_id']][$r['topic_id']] = $r; }
		$tracked = array();
		foreach ($by_forum as $forum=>$topics)
		{
			$ids = array_keys($topics);
			if ($member && $this->config['load_db_lastread'])
			{
				$first = reset($topics);
				$tracked += get_topic_tracking((int) $forum, $ids, $topics, array($forum=>$first['forum_mark_time']));
			}
			else { $tracked += get_complete_topic_tracking((int) $forum, $ids); }
		}
		return $tracked;
	}

	private function author($post)
	{
		if ($post['username'] === null && (int) $post['user_id'] > ANONYMOUS) { return $this->user->lang('PLUS_FORMER_MEMBER'); }
		return get_username_string('full', (int) $post['user_id'], (string) $post['username'], (string) $post['user_colour'], (string) $post['post_username']);
	}
}
