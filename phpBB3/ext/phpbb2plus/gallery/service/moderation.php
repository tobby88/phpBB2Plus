<?php
namespace phpbb2plus\gallery\service;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class moderation
{
	// Highest topics_per_page accepted by phpBB's board settings.
	const MAX_ROWS = 9999;
	private $db;
	private $access;
	private $pictures;
	private $navigation;
	private $user;
	private $prefix;

	public function __construct($db, $access, $pictures, $navigation, $user, $prefix)
	{
		$this->db = $db;
		$this->access = $access;
		$this->pictures = $pictures;
		$this->navigation = $navigation;
		$this->user = $user;
		$this->prefix = $prefix;
	}

	public function category($id)
	{
		$cat = $this->access->category($id);
		if (empty($this->user->data['is_registered']) || !empty($this->user->data['user_perm_from']) || !$this->access->moderates($cat))
		{ throw new NotFoundHttpException(); }
		return $cat;
	}

	public function actions($cat)
	{
		$actions = array('move', 'lock', 'unlock');
		// In albums without review these flags are retained for a later change of policy.
		if ($this->access->administrator() || in_array((int) $cat['approval'], array(0, 2), true))
		{ array_push($actions, 'approve', 'unapprove'); }
		if ($this->access->allows($cat, 'delete')) { $actions[] = 'delete'; }
		return $actions;
	}

	public function methods($cat)
	{
		$methods = $this->navigation->methods($cat);
		unset($methods['pic_id']);
		if (isset($methods['username']))
		{
			unset($methods['username']);
			$methods['pic_user_id'] = 'AUTHOR';
		}
		return $methods;
	}

	public static function page_size($topics_per_page)
	{
		// Leave room for the operation, destination and hidden fields when every box is checked without JavaScript.
		$input_limit = (int) ini_get('max_input_vars');
		return max(1, min((int) $topics_per_page, self::MAX_ROWS, $input_limit > 0 ? $input_limit - 8 : self::MAX_ROWS));
	}

	public function count($cat)
	{
		$result = $this->db->sql_query('SELECT COUNT(*) AS total FROM ' . $this->prefix . 'pics p WHERE ' . $this->navigation->category_filter($cat));
		$count = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);
		return $count;
	}

	public function page($cat, $sort, $limit, $start)
	{
		$order = $sort[0] === 'pic_user_id' ? 'p.author_id ' . $sort[1] . ',p.pic_id ' . $sort[1] : $this->navigation->order_by($sort);
		$result = $this->db->sql_query_limit('SELECT p.pic_id,p.title,p.author_id,p.author_name,p.author_removed,p.created_at,p.locked,p.approved,
			u.username,u.user_colour,(SELECT AVG(NULLIF(r.points,0)) FROM ' . $this->prefix . 'ratings r WHERE r.pic_id=p.pic_id) AS rating,
			(SELECT COUNT(*) FROM ' . $this->prefix . 'comments c WHERE c.pic_id=p.pic_id) AS comments
			FROM ' . $this->prefix . 'pics p LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=p.author_id WHERE ' .
			$this->navigation->category_filter($cat) . ' ORDER BY ' . $order, $limit, $start);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		$ids = array_map('intval', array_column($rows, 'pic_id'));
		return array('rows' => $rows, 'ids' => $ids, 'key' => $this->signature(array('page', (int) $cat['cat_id'], $sort, $start, $ids)));
	}

	private function signature($values)
	{
		return hash_hmac('sha256', serialize(array($this->user->session_id, $values)), $this->user->data['user_form_salt']);
	}

	public function review($category, $ids, $action, $destination)
	{
		$cat = $this->category($category);
		if (!in_array($action, $this->actions($cat), true)) { throw new NotFoundHttpException(); }
		if (!$ids || count($ids) > self::MAX_ROWS) { throw new \DomainException('PLUS_ALBUM_MOD_SELECT'); }
		$to = $action === 'move' ? $this->pictures->destination($cat, $destination) : null;
		$counts = $action === 'delete' ? ',(SELECT COUNT(*) FROM ' . $this->prefix . 'comments c WHERE c.pic_id=p.pic_id) AS comments,
			(SELECT COUNT(*) FROM ' . $this->prefix . 'ratings r WHERE r.pic_id=p.pic_id) AS ratings' : '';
		$result = $this->db->sql_query('SELECT p.pic_id,p.title,p.author_id,p.storage_name,p.locked,p.approved' . $counts .
			' FROM ' . $this->prefix . 'pics p WHERE ' . $this->navigation->category_filter($cat) . ' AND ' .
			$this->db->sql_in_set('p.pic_id', $ids) . ' ORDER BY p.pic_id');
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		if (count($rows) !== count($ids)) { throw new \DomainException('PLUS_ALBUM_MOD_CHANGED'); }
		foreach ($rows as $pic)
		{
			if ($action === 'delete' && !$this->pictures->allowed($pic, $cat, 'delete')) { throw new NotFoundHttpException(); }
		}
		$key = $this->signature(array('review', (int) $cat['cat_id'], $cat['title'], (int) $cat['approval'], $action,
			$to ? array((int) $to['cat_id'], $to['title'], (int) $to['approval']) : null, $rows));
		return array('rows' => $rows, 'destination' => $to, 'key' => $key);
	}

	public function confirm($category, $ids, $action, $destination, $key)
	{
		$this->access->write(function () use ($category, $ids, $action, $destination, $key)
		{
			$this->access->check_moderator_session();
			$review = $this->review($category, $ids, $action, $destination);
			if (!hash_equals($review['key'], $key)) { throw new \DomainException('PLUS_ALBUM_MOD_CHANGED'); }
			$this->pictures->apply_moderation($review['rows'], $action, $review['destination']);
		});
	}
}
