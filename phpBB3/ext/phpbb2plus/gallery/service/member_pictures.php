<?php
namespace phpbb2plus\gallery\service;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class member_pictures
{
	private $db;
	private $access;
	private $personal;
	private $navigation;
	private $prefix;

	public function __construct($db, $access, $personal, $navigation, $prefix)
	{
		$this->db = $db;
		$this->access = $access;
		$this->personal = $personal;
		$this->navigation = $navigation;
		$this->prefix = $prefix;
	}

	private function filter($owner, $type)
	{
		$categories = $this->access->portal_categories(true, array());
		if ($type === 'all')
		{
			if (!$this->personal->available($owner)) { throw new NotFoundHttpException(); }
			$owned = array();
			foreach ($categories as $id)
			{
				$cat = $this->access->category_row($id);
				if ((int) $cat['owner_id'] === $owner) { $owned[] = $id; }
			}
			return $this->access->picture_filter($owned);
		}
		// Former category-zero photos are available within the personal album only.
		$where = $this->access->picture_filter($categories) . ' AND p.legacy_uncategorized=0';
		switch ($type)
		{
			case 'pic': return $where . ' AND p.author_id=' . $owner;
			case 'rating': return $where . ' AND EXISTS (SELECT 1 FROM ' . $this->prefix . 'ratings r WHERE r.pic_id=p.pic_id AND r.user_id=' . $owner . ' AND r.points>0)';
			case 'comment': return $where . ' AND EXISTS (SELECT 1 FROM ' . $this->prefix . 'comments c WHERE c.pic_id=p.pic_id AND c.author_id=' . $owner . ')';
		}
		throw new NotFoundHttpException();
	}

	public function page($owner, $type, $method, $direction, $start)
	{
		$owner = (int) $owner;
		$this->db->sql_transaction('begin');
		try
		{
			$account = $this->personal->owner($owner);
			$where = $this->filter($owner, $type);
			$rate = (int) $this->access->setting('rate', 1) === 1;
			$comments = (int) $this->access->setting('comment', 1) === 1;
			if (($type === 'rating' && !$rate) || ($type === 'comment' && !$comments)) { throw new NotFoundHttpException(); }
			if ($method === '' && ($type === 'rating' || $type === 'comment'))
			{
				$method = $type === 'rating' ? 'rating' : 'comments';
				$direction = 'ASC';
			}
			$sort = $this->navigation->sort(null, $method, $direction);
			$columns = max(1, min(20, (int) $this->access->setting('cols_per_page', 4)));
			$limit = min(100, $columns * max(1, min(50, (int) $this->access->setting('rows_per_page', 3))));
			$pictures = $this->prefix . 'pics p';
			$result = $this->db->sql_query("SELECT COUNT(*) AS total FROM $pictures WHERE $where");
			$total = (int) $this->db->sql_fetchfield('total');
			$this->db->sql_freeresult($result);
			$start = intdiv(max(0, min(1000000, (int) $start, $total - 1)), $limit) * $limit;
			$rows = array();
			if ($total)
			{
				$fields = 'p.pic_id,p.cat_id,p.title,p.created_at,p.views,p.author_id,p.author_name,p.author_removed,p.storage_name,u.username,u.user_colour';
				if ($this->access->may_view_upload_ips()) { $fields .= ',p.author_ip'; }
				$rating_filter = $type === 'rating' ? ' AND r.user_id=' . $owner : '';
				$comment_filter = $type === 'comment' ? ' AND c.author_id=' . $owner : '';
				if ($rate)
				{
					$fields .= ',(SELECT COALESCE(AVG(r.points),0) FROM ' . $this->prefix . "ratings r WHERE r.pic_id=p.pic_id AND r.points>0$rating_filter) AS rating";
				}
				if ($comments)
				{
					$from = ' FROM ' . $this->prefix . "comments c WHERE c.pic_id=p.pic_id$comment_filter";
					$fields .= ",(SELECT COUNT(*)$from) AS comments";
					if ($sort[0] === 'new_comment') { $fields .= ",(SELECT COALESCE(MAX(c.comment_id),0)$from) AS new_comment"; }
				}
				// On a member's rating/comment list, both the displayed value and its sort belong to that member.
				$order = in_array($sort[0], array('comments','new_comment'), true) ? $sort[0] . ' ' . $sort[1] . ',p.pic_id ' . $sort[1] : $this->navigation->order_by($sort);
				$result = $this->db->sql_query_limit("SELECT $fields FROM $pictures LEFT JOIN " . USERS_TABLE . " u ON u.user_id=p.author_id WHERE $where ORDER BY $order", $limit, $start);
				$rows = $this->db->sql_fetchrowset($result);
				$this->db->sql_freeresult($result);
			}
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $error)
		{
			$this->db->sql_transaction('rollback');
			throw $error;
		}
		return array('account' => $account, 'total' => $total, 'start' => $start, 'limit' => $limit, 'columns' => $columns,
			'rate' => $rate, 'comments' => $comments, 'sort' => $sort, 'rows' => $rows);
	}
}
