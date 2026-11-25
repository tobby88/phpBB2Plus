<?php
namespace phpbb2plus\downloads\service;

class report_data
{
	private $db;
	private $access;
	private $prefix;

	public function __construct($db,$access,$prefix)
	{
		$this->db = $db;
		$this->access = $access;
		$this->prefix = $prefix;
	}

	public function scope()
	{
		$ids = array();
		$categories = 0;
		foreach ($this->access->categories() as $id=>$category)
		{
			if (!$this->access->visible($id) || !$this->access->allows($id,'view_file')) { continue; }
			++$categories;
			if ($category['allow_files']) { $ids[] = $id; }
		}
		return array('categories'=>$categories,'where'=>'f.approved=1 AND ' . ($ids ? $this->db->sql_in_set('f.cat_id',$ids) : '1=0'));
	}

	private function rows($sql,$limit = 0,$start = 0)
	{
		$result = $limit ? $this->db->sql_query_limit($sql,$limit,$start) : $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}

	private function rating()
	{
		return '(SELECT AVG(v.points) FROM ' . $this->prefix . 'votes v WHERE v.file_id=f.file_id)';
	}

	public function statistics($scope)
	{
		$where = $scope['where'];
		$files = $this->prefix . 'files';
		$rating = $this->rating();
		$summary = $this->rows("SELECT COUNT(*) AS files,SUM(f.downloads) AS downloads,AVG(f.downloads) AS mean_downloads,
			AVG($rating) AS mean_rating FROM $files f WHERE $where")[0];
		$records = array();
		foreach (array('newest'=>array('f.added_at','DESC'),'oldest'=>array('f.added_at','ASC'),
			'best'=>array('rating','DESC'),'lowest'=>array('rating','ASC'),
			'most'=>array('f.downloads','DESC'),'least'=>array('f.downloads','ASC')) as $key=>$sort)
		{
			list($field,$order) = $sort;
			$columns = $field === 'rating' ? ",$rating AS rating" : '';
			$rated = $field === 'rating' ? ' AND EXISTS (SELECT 1 FROM ' . $this->prefix . 'votes rv WHERE rv.file_id=f.file_id)' : '';
			$records[$key] = $this->rows("SELECT f.file_id,f.title,f.added_at,f.downloads$columns FROM $files f WHERE $where$rated
				ORDER BY $field $order,f.file_id $order",1)[0] ?? null;
		}
		$clients = array();
		foreach (array('visitors','votes') as $table)
		{
			$clients[$table] = array('os'=>array_fill_keys(array_keys(client_family::OS),0),
				'browser'=>array_fill_keys(array_keys(client_family::BROWSERS),0));
			$counts = $this->rows('SELECT c.client_os,c.client_browser,COUNT(*) AS total FROM ' . $this->prefix . $table .
				" c JOIN $files f ON f.file_id=c.file_id WHERE $where GROUP BY c.client_os,c.client_browser");
			foreach ($counts as $row)
			{
				foreach (array('os','browser') as $key)
				{
					$family = $row['client_' . $key];
					$clients[$table][$key][isset($clients[$table][$key][$family]) ? $family : 'other'] += (int) $row['total'];
				}
			}
		}
		return array($summary,$records,$clients);
	}

	public function recent_totals($where,$now)
	{
		$week = $now - 7*86400;
		$month = $now - 30*86400;
		return $this->rows('SELECT COUNT(*) AS month,SUM(CASE WHEN f.added_at>=' . $week . ' THEN 1 ELSE 0 END) AS week FROM ' .
			$this->prefix . "files f WHERE $where AND f.added_at>=$month")[0];
	}

	public function day_counts($where,\DateTimeImmutable $today,$days)
	{
		$first = $today->modify('-' . ($days-1) . ' days');
		$start = $first->getTimestamp();
		$end = $today->modify('+1 day')->getTimestamp();
		$transitions = $today->getTimezone()->getTransitions($start,$end);
		// Group by the viewer's local midnight, including 23/25-hour days, without a database timezone table.
		$offset = (int) $first->getOffset();
		if ($transitions && count($transitions) > 1)
		{
			$terms = '';
			foreach (array_slice($transitions,1) as $change)
			{
				$terms .= ' WHEN f.added_at<' . (int) $change['ts'] . ' THEN ' . $offset;
				$offset = (int) $change['offset'];
			}
			$offset = '(CASE' . $terms . ' ELSE ' . $offset . ' END)';
		}
		$rows = $this->rows("SELECT FLOOR((f.added_at + $offset)/86400) AS local_day,COUNT(*) AS total FROM " . $this->prefix .
			"files f WHERE $where AND f.added_at>=$start AND f.added_at<$end GROUP BY local_day");
		$counts = array();
		foreach ($rows as $row) { $counts[gmdate('Y-m-d',(int) $row['local_day']*86400)] = (int) $row['total']; }
		return $counts;
	}

	public function rated_filter()
	{
		return ' AND EXISTS (SELECT 1 FROM ' . $this->prefix . 'votes v WHERE v.file_id=f.file_id AND v.points>0)';
	}

	public function count($where)
	{
		return (int) $this->rows('SELECT COUNT(*) AS total FROM ' . $this->prefix . 'files f WHERE ' . $where)[0]['total'];
	}

	public function directory_totals($category_ids)
	{
		if (!$category_ids) { return array(); }
		$table = $this->prefix . 'files';
		// One row per readable category. Break ties on the latest timestamp by file ID.
		return $this->rows("SELECT g.cat_id,g.total,f.file_id,f.title,f.added_at FROM
			(SELECT cat_id,COUNT(*) AS total,MAX(added_at) AS latest FROM $table WHERE approved=1 AND " .
			$this->db->sql_in_set('cat_id',$category_ids) . " GROUP BY cat_id) g
			JOIN $table f ON f.file_id=(SELECT MAX(x.file_id) FROM $table x WHERE x.cat_id=g.cat_id AND x.approved=1 AND x.added_at=g.latest)");
	}

	public function files($where,$mode,$limit,$start,$direction = 'DESC',$listing = '')
	{
		$sort = array('rating'=>'rating','downloads'=>'f.downloads','newest'=>'f.added_at','file_name'=>'f.title','file_time'=>'f.added_at',
			'file_rating'=>'rating','file_dls'=>'f.downloads','file_update_time'=>'f.updated_at')[$mode];
		$direction = $direction === 'ASC' ? 'ASC' : 'DESC';
		$pin_order = $listing === 'quick_search' ? 'f.pinned DESC,' : '';
		$columns = $listing !== '' ? 'f.preview_name,f.source_json' : 'u.user_id,u.username,u.user_colour';
		$owner = $listing !== '' ? '' : ' LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=f.owner_id AND u.user_id>' . ANONYMOUS;
		return $this->rows("SELECT f.file_id,f.cat_id,f.title,f.summary,f.added_at,f.downloads,f.pinned,f.icon_name,$columns," .
			$this->rating() . ' AS rating FROM ' . $this->prefix . "files f$owner WHERE $where ORDER BY $pin_order$sort $direction,f.file_id $direction",$limit,$start);
	}
}
