<?php
namespace phpbb2plus\gallery\service;

/** Build each visible branch once. Counts always use the picture's current category and approval policy. */
class category_index
{
	private $db;
	private $access;
	private $prefix;

	public function __construct($db, $access, $prefix)
	{
		$this->db = $db;
		$this->access = $access;
		$this->prefix = $prefix;
	}

	public function options()
	{
		$on = array();
		foreach (settings::DIRECTORY_SWITCHES as $name => $default)
		{ $on[$name] = (int) $this->access->setting($name, $default) === 1; }
		if (!(int) $this->access->setting('comment', 1))
		{ $on['show_index_comments'] = $on['show_index_total_comments'] = $on['show_index_last_comment'] = false; }
		return $on;
	}

	private function cutoff()
	{
		$value = strtoupper(trim((string) $this->access->setting('new_pic_check_interval', '1M')));
		if (!preg_match('/^([0-9]+)([HMWD]*)$/D', $value, $match) || (int) $match[1] < 1) { return null; }
		// The old setting gives D priority over W, W over M, and M over H. Bare numbers are days.
		$unit = 86400;
		if ($match[2] !== '' && strpos($match[2], 'D') === false)
		{
			$unit = strpos($match[2], 'W') !== false ? 604800 : (strpos($match[2], 'M') !== false ? 2592000 : 3600);
		}
		$now = time();
		return (int) $match[1] > intdiv($now, $unit) ? 0 : $now - (int) $match[1] * $unit;
	}

	private function rows($sql)
	{
		$result = $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}

	private function tree($parent)
	{
		$base = $parent ? (int) $parent['cat_id'] : 0;
		$scope = '';
		if ($parent)
		{
			// Leaf albums are the common case. Avoid loading their owner's entire tree.
			$result = $this->db->sql_query_limit('SELECT cat_id FROM ' . $this->prefix . 'cats WHERE parent_id=' . $base, 1);
			$has_children = $this->db->sql_fetchfield('cat_id') !== false;
			$this->db->sql_freeresult($result);
			if (!$has_children) { return array(array(), array()); }
			$scope = ' WHERE c.owner_id=' . (int) $parent['owner_id'] . ' AND c.source_owner=' . (int) $parent['source_owner'];
		}
		$method = $this->access->setting('album_category_sorting', 'cat_order');
		$descending = strtoupper($this->access->setting('album_category_sorting_direction', 'ASC')) === 'DESC';
		$order = $method === 'cat_id' ? 'c.cat_id ' . ($descending ? 'DESC' : 'ASC') : 'c.sort_order ASC,c.cat_id ASC';
		$rows = $this->rows('SELECT c.*,u.username AS owner_name FROM ' . $this->prefix . 'cats c LEFT JOIN ' . USERS_TABLE .
			' u ON u.user_id=c.owner_id AND ' . $this->db->sql_in_set('u.user_type', array(USER_NORMAL, USER_FOUNDER)) . $scope . ' ORDER BY ' . $order);
		$children = $visible = $links = array();
		foreach ($rows as $cat) { $children[(int) $cat['parent_id']][] = $cat; }
		$stack = array($base);
		while ($stack)
		{
			$id = array_pop($stack);
			$ancestor = $id === $base ? $parent : $visible[$id];
			foreach ($children[$id] ?? array() as $cat)
			{
				$child = (int) $cat['cat_id'];
				if ($child === $base || isset($visible[$child]) || !$this->access->allows($cat, 'view')) { continue; }
				if ($ancestor && ((int) $cat['owner_id'] !== (int) $ancestor['owner_id'] ||
					(int) $cat['source_owner'] !== (int) $ancestor['source_owner'])) { continue; }
				$visible[$child] = $this->access->remember_category($cat);
				$links[$id][] = $child;
				$stack[] = $child;
			}
		}
		if ($method === 'cat_title')
		{
			// phpBB2 sorted sibling titles in PHP, including numeric titles; old and new labels may have different storage encodings.
			foreach ($links as &$siblings)
			{
				usort($siblings, function ($a, $b) use ($visible, $descending) {
					$cmp = $visible[$a]['title'] <=> $visible[$b]['title'];
					return $cmp ? ($descending ? -$cmp : $cmp) : $a <=> $b;
				});
			}
			unset($siblings);
		}
		return array($visible, $links);
	}

	private function summary($scope, $comments, $latest, $cutoff)
	{
		$filter = $this->access->picture_filter($scope);
		$from = $this->prefix . 'pics p';
		$entry = 'p';
		$id = 'p.pic_id';
		if ($comments)
		{
			$from .= ' JOIN ' . $this->prefix . 'comments c ON c.pic_id=p.pic_id';
			$entry = 'c';
			$id = 'c.comment_id';
		}
		$recent = !$comments && $cutoff !== null ? ',SUM(CASE WHEN p.created_at>=' . (int) $cutoff . ' THEN 1 ELSE 0 END) AS recent' : '';
		$group = 'SELECT p.cat_id,COUNT(*) AS amount' . $recent . ($latest ? ',MAX(' . $entry . '.created_at) AS last_time' : '') .
			' FROM ' . $from . ' WHERE ' . $filter . ' GROUP BY p.cat_id';
		if (!$latest) { return $this->rows($group); }
		// Pick the highest ID only among visible records with that category's most recent timestamp.
		$fields = 's.cat_id,s.amount,s.last_time' . ($recent ? ',s.recent' : '');
		return $this->rows('SELECT ' . $fields . ',MAX(' . $id . ') AS last_id FROM ' . $from . ' JOIN (' . $group . ') s ON
			s.cat_id=p.cat_id AND s.last_time=' . $entry . '.created_at WHERE ' . $filter . ' GROUP BY ' . $fields);
	}

	private function details($winners, $comments)
	{
		$rows = array();
		foreach (array_chunk($winners, 500, true) as $chunk)
		{
			$filter = $this->access->picture_filter(array_values(array_unique($chunk)));
			$entry = $comments ? 'c' : 'p';
			$key = $comments ? 'c.comment_id' : 'p.pic_id';
			$from = $this->prefix . 'pics p' . ($comments ? ' JOIN ' . $this->prefix . 'comments c ON c.pic_id=p.pic_id' : '');
			$sql = 'SELECT p.cat_id,p.pic_id,p.title,p.storage_name,' . $key . ' AS event_id,' . $entry . '.created_at,' . $entry . '.author_id,' .
				$entry . '.author_name,' . $entry . '.author_removed,u.username,u.user_colour FROM ' . $from . ' LEFT JOIN ' . USERS_TABLE .
				' u ON u.user_id=' . $entry . '.author_id WHERE ' . $filter . ' AND ' . $this->db->sql_in_set($key, array_keys($chunk));
			foreach ($this->rows($sql) as $row)
			{
				$id = (int) $row['event_id'];
				if ((int) $row['cat_id'] === $chunk[$id]) { $rows[$id] = $row; }
			}
		}
		return $rows;
	}

	private function moderator_groups($categories)
	{
		$groups = array();
		foreach (array_chunk($categories, 500) as $chunk)
		{
			$sql = 'SELECT a.cat_id,g.group_id,g.group_name FROM ' . $this->prefix . 'grants a JOIN ' . GROUPS_TABLE . " g
				ON g.group_id=a.subject_id WHERE a.capability='moderator' AND a.subject_type='g' AND " .
				$this->db->sql_in_set('a.cat_id', $chunk) . ' AND ' . $this->db->sql_in_set('g.group_type', array(GROUP_HIDDEN, GROUP_SPECIAL), true) .
				' ORDER BY a.cat_id,g.group_name,g.group_id';
			foreach ($this->rows($sql) as $row) { $groups[(int) $row['cat_id']][] = $row; }
		}
		return $groups;
	}

	public function listing($parent, $options)
	{
		list($categories, $links) = $this->tree($parent);
		if (!$categories) { return array(); }
		$direct = $links[$parent ? $parent['cat_id'] : 0];
		$cutoff = $this->cutoff();
		$want_pic = $options['show_index_last_pic'] || $options['show_index_thumb'];
		$want_comment = $options['show_index_last_comment'];
		$children = array();
		foreach ($direct as $id)
		{
			$show = $this->access->personal_category($categories[$id]) ? $options['personal_show_subcats_in_index'] : $options['show_index_subcats'];
			if ($show && isset($links[$id])) { $children[$id] = $links[$id]; }
		}
		$picture_counts = $want_pic || $options['show_index_pics'] || $options['show_index_total_pics'] || $children || $cutoff !== null;
		$comment_counts = $want_comment || $options['show_index_comments'] || $options['show_index_total_comments'];
		$local = array_fill_keys(array_keys($categories), array('pictures' => 0, 'comments' => 0, 'new' => 0, 'photo' => array(), 'comment' => array()));
		foreach (array_chunk(array_keys($categories), 500) as $chunk)
		{
			if ($picture_counts)
			{
				foreach ($this->summary($chunk, false, $want_pic, $cutoff) as $row)
				{
					$id = (int) $row['cat_id'];
					$local[$id]['pictures'] = (int) $row['amount'];
					$local[$id]['new'] = (int) ($row['recent'] ?? 0);
					if ($want_pic) { $local[$id]['photo'] = array((int) $row['last_time'], (int) $row['last_id'], $id); }
				}
			}
			if ($comment_counts)
			{
				foreach ($this->summary($chunk, true, $want_comment, null) as $row)
				{
					$id = (int) $row['cat_id'];
					$local[$id]['comments'] = (int) $row['amount'];
					if ($want_comment) { $local[$id]['comment'] = array((int) $row['last_time'], (int) $row['last_id'], $id); }
				}
			}
		}
		$total = $local;
		foreach (array_reverse(array_keys($categories)) as $id)
		{
			$up = (int) $categories[$id]['parent_id'];
			if (!isset($total[$up])) { continue; }
			foreach (array('pictures', 'comments', 'new') as $field) { $total[$up][$field] += $total[$id][$field]; }
			foreach (array('photo', 'comment') as $field)
			{
				if ($total[$id][$field] > $total[$up][$field]) { $total[$up][$field] = $total[$id][$field]; }
			}
		}
		$winners = array('photo' => array(), 'comment' => array());
		foreach ($direct as $id)
		{
			foreach (array('photo', 'comment') as $field)
			{
				$event = $total[$id][$field];
				if ($event) { $winners[$field][$event[1]] = $event[2]; }
			}
		}
		$photos = $this->details($winners['photo'], false);
		$comments = $this->details($winners['comment'], true);
		$moderators = $this->moderator_groups(array_keys($children));
		$list = array();
		foreach ($direct as $id)
		{
			$cat = $categories[$id];
			$subcategories = array();
			foreach ($children[$id] ?? array() as $child)
			{
				$subcategories[] = array('category' => $categories[$child], 'pictures' => $total[$child]['pictures'], 'new' => $total[$child]['new']);
			}
			$list[] = array('category' => $cat, 'children' => $subcategories, 'local' => $local[$id], 'total' => $total[$id],
				'moderators' => $moderators[$id] ?? array(),
				'photo' => $photos[$total[$id]['photo'][1] ?? 0] ?? null, 'comment' => $comments[$total[$id]['comment'][1] ?? 0] ?? null);
		}
		return $list;
	}
}
