<?php
namespace phpbb2plus\gallery\service;

class navigation
{
	private $db;
	private $access;
	private $byline;
	private $prefix;

	public function __construct($db, $access, $byline, $prefix)
	{
		$this->db = $db;
		$this->access = $access;
		$this->byline = $byline;
		$this->prefix = $prefix;
	}

	public function methods($category)
	{
		$methods = array('pic_time' => 'PLUS_ALBUM_DATE', 'pic_title' => 'PLUS_ALBUM_SORT_TITLE');
		if ($category === null || !$this->access->personal_category($category)) { $methods['username'] = 'AUTHOR'; }
		$methods['pic_view_count'] = 'VIEWS';
		if ((int) $this->access->setting('rate', 1) === 1) { $methods['rating'] = 'PLUS_ALBUM_PORTAL_RATING'; }
		if ((int) $this->access->setting('comment', 1) === 1)
		{
			$methods['comments'] = 'PLUS_ALBUM_SORT_COMMENTS';
			$methods['new_comment'] = 'PLUS_ALBUM_SORT_LAST_COMMENT';
		}
		if ($this->access->setting('sort_method', 'pic_time') === 'pic_id') { $methods['pic_id'] = 'PLUS_ALBUM_SORT_ID'; }
		return $methods;
	}

	public function sort($category, $method, $direction)
	{
		$methods = $this->methods($category);
		foreach (array($method, $this->access->setting('sort_method', 'pic_time'), 'pic_time') as $candidate)
		{
			$method = settings::control_value('sort_method', $candidate);
			if (isset($methods[$method])) { break; }
		}
		if (!in_array($direction, array('ASC', 'DESC'), true)) { $direction = $this->access->setting('sort_order', 'DESC'); }
		return array($method, strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC');
	}

	public function category_filter($category, $published_only = false)
	{
		$all = $published_only ? !(int) $category['approval'] : $this->access->pending_visible($category);
		return 'p.cat_id=' . (int) $category['cat_id'] . ($all ? '' : ' AND p.approved=1');
	}

	private function column($method)
	{
		switch ($method)
		{
			case 'pic_title': return 'p.title';
			case 'pic_view_count': return 'p.views';
			case 'pic_id': return 'p.pic_id';
			case 'username': return $this->byline->sort_expression($this->db);
			case 'rating': return '(SELECT COALESCE(AVG(r.points),0) FROM ' . $this->prefix . 'ratings r WHERE r.pic_id=p.pic_id AND r.points>0)';
			case 'comments': return '(SELECT COUNT(*) FROM ' . $this->prefix . 'comments c WHERE c.pic_id=p.pic_id)';
			case 'new_comment': return '(SELECT COALESCE(MAX(c.comment_id),0) FROM ' . $this->prefix . 'comments c WHERE c.pic_id=p.pic_id)';
			default: return 'p.created_at';
		}
	}

	public function order_by($sort)
	{
		$field = $sort[0] === 'rating' ? 'rating' : $this->column($sort[0]);
		return $field . ' ' . $sort[1] . ($sort[0] === 'pic_id' ? '' : ',p.pic_id ' . $sort[1]);
	}

	private function first($from, $where, $order)
	{
		$result = $this->db->sql_query_limit('SELECT p.pic_id FROM ' . $from . ' WHERE ' . $where . ' ORDER BY ' . $order, 1);
		$id = (int) $this->db->sql_fetchfield('pic_id');
		$this->db->sql_freeresult($result);
		return $id;
	}

	public function neighbours($picture, $category, $sort)
	{
		$id = (int) $picture['pic_id'];
		$field = $this->column($sort[0]);
		$from = $this->prefix . 'pics p' . ($sort[0] === 'username' ? ' LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=p.author_id' : '');
		$where = $this->category_filter($category, true) . ' AND p.pic_id<>' . $id;
		if (substr($field, 0, 2) === 'p.') { $value = $picture[substr($field, 2)]; }
		else
		{
			$result = $this->db->sql_query('SELECT ' . $field . ' AS sort_value FROM ' . $from . ' WHERE p.pic_id=' . $id);
			$value = $this->db->sql_fetchfield('sort_value');
			$this->db->sql_freeresult($result);
		}
		$value = in_array($sort[0], array('pic_title', 'username'), true) ? "'" . $this->db->sql_escape((string) $value) . "'" :
			(is_numeric($value) ? (string) $value : '0');

		$neighbours = $orders = array();
		foreach (array('previous', 'next') as $step)
		{
			$ascending = ($step === 'next') === ($sort[1] === 'ASC');
			$operator = $ascending ? '>' : '<';
			$direction = $ascending ? 'ASC' : 'DESC';
			$orders[$step] = $field . ' ' . $direction . ',p.pic_id ' . $direction;
			$after = '(' . $field . $operator . $value . ' OR (' . $field . '=' . $value . ' AND p.pic_id' . $operator . $id . '))';
			$neighbours[$step] = $this->first($from, $where . ' AND ' . $after, $orders[$step]);
		}
		if ($neighbours['previous'] || $neighbours['next'])
		{
			foreach ($neighbours as $step => $other)
			{
				if (!$other) { $neighbours[$step] = $this->first($from, $where, $orders[$step]); }
			}
		}
		return $neighbours;
	}
}
