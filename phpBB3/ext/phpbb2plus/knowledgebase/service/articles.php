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
			$result = $this->db->sql_query('SELECT setting_name,setting_value,value_encoded FROM ' . $this->prefix . 'settings');
			while ($row = $this->db->sql_fetchrow($result)) { $this->settings[$row['setting_name']] = self::label($row['setting_value'], $row['value_encoded']); }
			$this->db->sql_freeresult($result);
		}
		return $this->settings[$name] ?? $default;
	}

	public function refresh() { $this->settings = $this->categories = null; }

	public static function encode_label($value)
	{
		return utf8_encode_ucr(utf8_htmlspecialchars($value));
	}

	public static function label($value, $encoded)
	{
		return $encoded ? html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8') : (string) $value;
	}

	public function types()
	{
		$result = $this->db->sql_query('SELECT type_id,title,label_encoded FROM ' . $this->prefix . 'types ORDER BY title,type_id');
		$types = array();
		while ($row = $this->db->sql_fetchrow($result))
		{
			$row['title'] = self::label($row['title'], $row['label_encoded']);
			$types[(int) $row['type_id']] = $row;
		}
		$this->db->sql_freeresult($result);
		return $types;
	}

	public function moderator()
	{
		return !empty($this->user->data['is_registered']) && $this->auth->acl_gets('m_plus_kb', 'a_plus_kb');
	}

	public function reviewer()
	{
		return !empty($this->user->data['is_registered']) && $this->auth->acl_gets('m_plus_kb', 'a_plus_kb', 'a_plus_kb_articles');
	}

	public function may_rate()
	{
		return empty($this->user->data['is_bot']) && (int) $this->setting('allow_rating', 0) &&
			(!empty($this->user->data['is_registered']) || (int) $this->setting('allow_anonymos_rating', 0));
	}

	public function visible_sql()
	{
		if ($this->reviewer()) { return '1=1'; }
		if (empty($this->user->data['is_registered'])) { return 'a.approval=1'; }
		return '(a.approval=1 OR a.author_id=' . (int) $this->user->data['user_id'] . ')';
	}

	public function category_list()
	{
		if ($this->categories === null)
		{
			$this->categories = array();
			$result = $this->db->sql_query('SELECT * FROM ' . $this->prefix . 'cats ORDER BY sort_order,cat_id');
			while ($row = $this->db->sql_fetchrow($result))
			{
				$row['title'] = self::label($row['title'], $row['label_encoded']);
				$row['description'] = self::label($row['description'], $row['label_encoded']);
				$this->categories[(int) $row['cat_id']] = $row;
			}
			$this->db->sql_freeresult($result);
		}
		return $this->categories;
	}

	/** Published articles in each immediately visible folder, including its descendants. */
	public function published_in_children($parent)
	{
		$children = array();
		foreach ($this->category_list() as $id => $row)
		{
			if ($id > 0) { $children[(int) $row['parent_id']][] = $id; }
		}
		$counts = array_fill_keys($children[$parent] ?? array(), 0);
		if (!$counts) { return $counts; }
		$branches = array();
		$pending = array();
		foreach (array_keys($counts) as $child) { $pending[] = array($child, $child); }
		while ($pending)
		{
			list($id, $branch) = array_pop($pending);
			if ($id === $parent || isset($branches[$id])) { continue; }
			$branches[$id] = $branch;
			foreach ($children[$id] ?? array() as $child) { $pending[] = array($child, $branch); }
		}
		if (!$branches) { return $counts; }
		$result = $this->db->sql_query('SELECT cat_id, COUNT(*) AS total FROM ' . $this->prefix . 'articles WHERE approval=1 AND ' .
			$this->db->sql_in_set('cat_id', array_keys($branches)) . ' GROUP BY cat_id');
		while ($row = $this->db->sql_fetchrow($result)) { $counts[$branches[(int) $row['cat_id']]] += (int) $row['total']; }
		$this->db->sql_freeresult($result);
		return $counts;
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
		$result = $this->db->sql_query('SELECT a.*,u.username,u.user_colour,t.title AS type_name,t.label_encoded FROM ' . $this->prefix . 'articles a
			LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=a.author_id LEFT JOIN ' . $this->prefix . 'types t ON t.type_id=a.type_id
			WHERE a.article_id=' . (int) $id . ' AND ' . $this->visible_sql());
		$article = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$article) { throw new NotFoundHttpException(); }
		$article['type_name'] = self::label($article['type_name'], $article['label_encoded']);
		return $article;
	}

	public function search_sql($query, $match)
	{
		$terms = preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY);
		if (!$terms || count($terms) > 12 || utf8_strlen($query) > 240) { return '1=0'; }
		$operators = array('OR' => ' OR ', 'AND' => ' AND ', 'NOT' => ' AND NOT ');
		$operator = $match === 'any' ? ' OR ' : ' AND ';
		$wildcard = $this->db->get_any_char();
		$expression = '';
		foreach ($terms as $term)
		{
			$keyword = strtoupper($term);
			if ($match !== '' && isset($operators[$keyword]))
			{
				if ($match === 'any') { $operator = $operators[$keyword]; }
				continue;
			}
			if ($match !== '') { $term = str_replace('*', $wildcard, $term); }
			$like = $this->db->sql_like_expression($wildcard . $term . $wildcard);
			$condition = "(a.title $like OR a.description $like OR a.search_text $like)";
			// The original any-word search applies operators in input order.
			$expression = $expression === '' ? $condition : '(' . $expression . $operator . $condition . ')';
		}
		return $expression === '' ? '1=0' : $expression;
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
		$result = $this->db->sql_query('SELECT t.topic_id,t.topic_title,t.forum_id,t.topic_moved_id,t.topic_status,f.forum_password FROM ' . TOPICS_TABLE . ' t
			JOIN ' . FORUMS_TABLE . ' f ON f.forum_id=t.forum_id WHERE t.topic_id=' . (int) $id);
		$topic = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$topic || $topic['topic_moved_id'] || (int) $topic['topic_status'] === ITEM_MOVED || !$this->auth->acl_get('f_list', $topic['forum_id']) || !$this->auth->acl_get('f_read', $topic['forum_id'])) { return false; }
		if (!$this->forum_open($topic['forum_id'], $topic['forum_password'])) { return false; }
		$result = $this->db->sql_query_limit('SELECT topic_id FROM ' . TOPICS_TABLE . ' t WHERE t.topic_id=' . (int) $id .
			' AND ' . $this->visibility->get_visibility_sql('topic', $topic['forum_id'], 't.'), 1);
		$visible = $this->db->sql_fetchfield('topic_id') !== false;
		$this->db->sql_freeresult($result);
		return $visible ? $topic : false;
	}

	public function discussion_sort_sql()
	{
		$forums = array_diff(array_intersect(array_keys($this->auth->acl_getf('f_list', true)), array_keys($this->auth->acl_getf('f_read', true))),
			$this->user->get_passworded_forums());
		if (!$forums || !(int) $this->setting('comments', 1)) { return 'a.article_id'; }
		// A topic's cached last post can be hidden from this reader. The indexed
		// lookup considers only the posts of the article's own linked topic.
		return 'COALESCE((SELECT MAX(p.post_id) FROM ' . TOPICS_TABLE . ' t JOIN ' . POSTS_TABLE . ' p ON p.topic_id=t.topic_id AND p.forum_id=t.forum_id
			WHERE t.topic_id=a.topic_id AND t.topic_moved_id=0 AND t.topic_status<>' . ITEM_MOVED . ' AND ' . $this->db->sql_in_set('t.forum_id', array_map('intval', $forums)) . ' AND '
			. $this->visibility->get_forums_visibility_sql('topic', $forums, 't.') . ' AND ' . $this->visibility->get_forums_visibility_sql('post', $forums, 'p.') . '),0)';
	}

	public function forum_open($forum, $password)
	{
		if ($password === '') { return true; }
		$result = $this->db->sql_query_limit('SELECT forum_id FROM ' . FORUMS_ACCESS_TABLE . ' WHERE forum_id=' . (int) $forum .
			' AND user_id=' . (int) $this->user->data['user_id'] . " AND session_id='" . $this->db->sql_escape($this->user->session_id) . "'", 1);
		$open = $this->db->sql_fetchfield('forum_id') !== false;
		$this->db->sql_freeresult($result);
		return $open;
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
