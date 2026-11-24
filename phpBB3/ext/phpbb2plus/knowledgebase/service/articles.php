<?php
namespace phpbb2plus\knowledgebase\service;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class articles
{
	private $db;
	private $auth;
	private $user;
	private $visibility;
	private $prefix;
	private $settings;
	private $categories;

	public function __construct($db, $auth, $user, $visibility, $prefix)
	{
		$this->db = $db;
		$this->auth = $auth;
		$this->user = $user;
		$this->visibility = $visibility;
		$this->prefix = $prefix;
	}

	public function setting($name, $default)
	{
		if ($this->settings === null)
		{
			$this->settings = array();
			$result = $this->db->sql_query('SELECT setting_name,setting_value FROM ' . $this->prefix . 'settings');
			while ($row = $this->db->sql_fetchrow($result)) { $this->settings[$row['setting_name']] = $row['setting_value']; }
			$this->db->sql_freeresult($result);
		}
		return $this->settings[$name] ?? $default;
	}

	public function moderator()
	{
		return !empty($this->user->data['is_registered']) && ($this->auth->acl_get('m_plus_kb') || $this->auth->acl_get('a_plus_kb'));
	}

	public function may_rate()
	{
		return empty($this->user->data['is_bot']) && (int) $this->setting('allow_rating', 0) &&
			(!empty($this->user->data['is_registered']) || (int) $this->setting('allow_anonymos_rating', 0));
	}

	public function visible_sql()
	{
		if ($this->moderator()) { return '1=1'; }
		if (empty($this->user->data['is_registered'])) { return 'a.approval=1'; }
		return '(a.approval=1 OR a.author_id=' . (int) $this->user->data['user_id'] . ')';
	}

	public function category_list()
	{
		if ($this->categories === null)
		{
			$this->categories = array();
			$result = $this->db->sql_query('SELECT * FROM ' . $this->prefix . 'cats ORDER BY sort_order,cat_id');
			while ($row = $this->db->sql_fetchrow($result)) { $this->categories[(int) $row['cat_id']] = $row; }
			$this->db->sql_freeresult($result);
		}
		return $this->categories;
	}

	public function trail($id)
	{
		$categories = $this->category_list();
		$trail = array();
		while ($id)
		{
			if (!isset($categories[$id]) || isset($trail[$id])) { throw new NotFoundHttpException(); }
			$trail[$id] = $categories[$id];
			$id = (int) $categories[$id]['parent_id'];
		}
		return array_reverse($trail);
	}

	public function find($id)
	{
		$result = $this->db->sql_query('SELECT a.*,u.username,u.user_colour,t.title AS type_name FROM ' . $this->prefix . 'articles a
			LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=a.author_id LEFT JOIN ' . $this->prefix . 'types t ON t.type_id=a.type_id
			WHERE a.article_id=' . (int) $id . ' AND ' . $this->visible_sql());
		$article = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$article) { throw new NotFoundHttpException(); }
		return $article;
	}

	public function search_sql($query)
	{
		$terms = preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY);
		if (!$terms || count($terms) > 12 || utf8_strlen($query) > 240) { return '1=0'; }
		$conditions = array();
		foreach (array_unique($terms) as $term)
		{
			$like = $this->db->sql_like_expression($this->db->get_any_char() . $term . $this->db->get_any_char());
			$conditions[] = "(a.title $like OR a.description $like OR a.search_text $like)";
		}
		return implode(' AND ', $conditions);
	}

	public function pages($id)
	{
		$result = $this->db->sql_query('SELECT page_number,heading FROM ' . $this->prefix . 'pages WHERE article_id=' . (int) $id . ' ORDER BY page_number');
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}

	public function page($id, $number)
	{
		$result = $this->db->sql_query('SELECT * FROM ' . $this->prefix . 'pages WHERE article_id=' . (int) $id . ' AND page_number=' . (int) $number);
		$page = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$page) { throw new NotFoundHttpException(); }
		return $page;
	}

	public function discussion($id)
	{
		if (!$id || !(int) $this->setting('comments', 1)) { return false; }
		$result = $this->db->sql_query('SELECT t.topic_id,t.topic_title,t.forum_id,t.topic_moved_id,f.forum_password FROM ' . TOPICS_TABLE . ' t
			JOIN ' . FORUMS_TABLE . ' f ON f.forum_id=t.forum_id WHERE t.topic_id=' . (int) $id);
		$topic = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$topic || $topic['topic_moved_id'] || !$this->auth->acl_get('f_list', $topic['forum_id']) || !$this->auth->acl_get('f_read', $topic['forum_id'])) { return false; }
		if ($topic['forum_password'] !== '')
		{
			$result = $this->db->sql_query_limit('SELECT forum_id FROM ' . FORUMS_ACCESS_TABLE . ' WHERE forum_id=' . (int) $topic['forum_id'] .
				' AND user_id=' . (int) $this->user->data['user_id'] . " AND session_id='" . $this->db->sql_escape($this->user->session_id) . "'", 1);
			$open = $this->db->sql_fetchfield('forum_id') !== false;
			$this->db->sql_freeresult($result);
			if (!$open) { return false; }
		}
		$result = $this->db->sql_query_limit('SELECT topic_id FROM ' . TOPICS_TABLE . ' t WHERE t.topic_id=' . (int) $id .
			' AND ' . $this->visibility->get_visibility_sql('topic', $topic['forum_id'], 't.'), 1);
		$visible = $this->db->sql_fetchfield('topic_id') !== false;
		$this->db->sql_freeresult($result);
		return $visible ? $topic : false;
	}

	public function scores($article)
	{
		$result = $this->db->sql_query('SELECT COUNT(*) AS votes,SUM(points) AS total FROM ' . $this->prefix . 'votes WHERE points>0 AND article_id=' . (int) $article['article_id']);
		$current = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		// Historical receipts contain no individual score. Keep both exact source aggregates intact.
		$count = self::add_count($article['historical_votes'], (string) $current['votes']);
		$points = (float) $article['historical_points'] + (float) $current['total'];
		return array('count' => $count, 'average' => $count === '0' ? 0 : $points / (float) $count);
	}

	private static function add_count($base, $value)
	{
		// The source vote count can use the full unsigned BIGINT range on 32- and 64-bit hosts.
		$a = strrev($base); $b = strrev($value); $out = ''; $carry = 0;
		for ($i = 0, $size = max(strlen($a), strlen($b)); $i < $size || $carry; $i++)
		{
			$sum = (int) ($a[$i] ?? 0) + (int) ($b[$i] ?? 0) + $carry;
			$out .= (string) ($sum % 10);
			$carry = intdiv($sum, 10);
		}
		return ltrim(strrev($out), '0') ?: '0';
	}

	public function rate($id, $rating)
	{
		if ($rating < 1 || $rating > 10) { throw new \DomainException('PLUS_KB_BAD_RATING'); }
		$this->db->sql_transaction('begin');
		try
		{
			$this->db->sql_query('UPDATE ' . $this->prefix . 'articles SET article_id=article_id WHERE article_id=' . (int) $id);
			$this->find($id);
			if (!$this->may_rate()) { throw new NotFoundHttpException(); }
			$uid = (int) $this->user->data['user_id'];
			$ip = $this->user->ip;
			$checks = array();
			if ($uid !== ANONYMOUS && (int) $this->setting('votes_check_userid', 1)) { $checks[] = 'user_id=' . $uid; }
			if ((int) $this->setting('votes_check_ip', 1)) { $checks[] = "user_ip='" . $this->db->sql_escape($ip) . "'"; }
			if ($checks)
			{
				$result = $this->db->sql_query_limit('SELECT vote_id FROM ' . $this->prefix . 'votes WHERE article_id=' . (int) $id . ' AND (' . implode(' OR ', $checks) . ')', 1);
				$already = $this->db->sql_fetchfield('vote_id') !== false;
				$this->db->sql_freeresult($result);
				if ($already) { throw new \DomainException('PLUS_KB_ALREADY_RATED'); }
			}
			$this->db->sql_query('INSERT INTO ' . $this->prefix . 'votes ' . $this->db->sql_build_array('INSERT',
				array('article_id' => (int) $id, 'user_id' => $uid, 'user_ip' => $ip, 'points' => $rating)));
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $e) { $this->db->sql_transaction('rollback'); throw $e; }
	}
}
