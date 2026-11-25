<?php
namespace phpbb2plus\downloads\service;

class catalogue
{
	private $db;
	private $access;
	private $submissions;
	private $prefix;

	public function __construct($db, $access, $submissions, $prefix)
	{
		$this->db = $db;
		$this->access = $access;
		$this->submissions = $submissions;
		$this->prefix = $prefix;
	}

	// Keep siblings in their imported position/ID order. Unreachable entries remain
	// visible to administrators so that a damaged historical parent can be corrected.
	public function tree()
	{
		$rows = $this->access->categories();
		$children = array();
		foreach ($rows as $id => $row) { $children[(int) $row['parent_id']][] = $id; }
		$list = array();
		$stack = array();
		foreach (array_reverse($children[0] ?? array()) as $id) { $stack[] = array($id,0); }
		while ($stack)
		{
			list($id,$depth) = array_pop($stack);
			if (isset($list[$id])) { continue; }
			$list[$id] = $depth;
			foreach (array_reverse($children[$id] ?? array()) as $child) { $stack[] = array($child,$depth + 1); }
		}
		foreach (array_diff_key($rows,$list) as $id=>$row) { $list[$id] = -1; }
		return array('rows'=>$rows,'children'=>$children,'list'=>$list);
	}

	public function descendants($tree, $id, $repair = false)
	{
		$found = array();
		$stack = array($id);
		while ($stack)
		{
			$current = array_pop($stack);
			if (isset($found[$current]))
			{
				if ($repair) { continue; }
				throw new \DomainException('ACP_PLUS_DL_TREE_INVALID');
			}
			$found[$current] = $current;
			foreach ($tree['children'][$current] ?? array() as $child) { $stack[] = $child; }
		}
		return $found;
	}

	public function counts()
	{
		$result = $this->db->sql_query('SELECT cat_id,COUNT(*) AS total FROM ' . $this->prefix . 'files GROUP BY cat_id');
		$counts = array();
		while ($r = $this->db->sql_fetchrow($result)) { $counts[(int) $r['cat_id']] = (int) $r['total']; }
		$this->db->sql_freeresult($result);
		return $counts;
	}

	public function review($tree, $action, $id, $lock = false)
	{
		$rows = $tree['rows'];
		if ($action !== 'create' && !isset($rows[$id])) { throw new \DomainException('ACP_PLUS_DL_CATALOGUE_CHANGED'); }
		if ($action === 'delete' && $tree['list'][$id] < 0) { throw new \DomainException('ACP_PLUS_DL_TREE_INVALID'); }
		$current = $lock && in_array($this->db->get_sql_layer(),array('mysql','mysqli'),true) ? ' FOR UPDATE' : '';
		$hash = hash_init('sha256');
		foreach ($rows as $row) { hash_update($hash, serialize($row)); }
		$files = array();
		$affected = array();
		$direct = $grants = $below = 0;
		$scope = array();
		if ($action === 'delete' || $action === 'edit')
		{
			$scope = $this->descendants($tree,$id,$action === 'edit');
			foreach (array_chunk(array_values($scope), 200) as $batch)
			{
				$where = ' WHERE ' . $this->db->sql_in_set('cat_id',$batch);
				$table = $this->prefix . 'files';
				// Acquire the file locks before inspecting the confirmed set. File reviews
				// and owner deletion serialize on the same rows; downloads do not change revisions.
				if ($lock) { $this->db->sql_query("UPDATE $table SET file_id=file_id" . $where); }
				$result = $this->db->sql_query('SELECT file_id,cat_id,owner_id,approved,review_revision FROM ' . $table . $where . ' ORDER BY file_id' . $current);
				while ($file = $this->db->sql_fetchrow($result))
				{
					$cat = (int) $file['cat_id'];
					$files[$cat][] = (int) $file['file_id'];
					if ($action === 'delete') { $affected[] = (int) $file['file_id']; }
					if ($cat === $id) { ++$direct; } else { ++$below; }
					hash_update($hash, serialize($file));
				}
				$this->db->sql_freeresult($result);
			}
			if ($action === 'delete')
			{
				// A newly contributed comment, rating or mirror also merits a fresh removal confirmation.
				foreach (array_chunk($affected,200) as $batch)
				{
					$where = ' WHERE ' . $this->db->sql_in_set('file_id',$batch);
					foreach (array('comments'=>array('comment_id','file_id,owner_id,posted_at'), 'votes'=>array('row_id','file_id,points'),
						'mirrors'=>array('mirror_id','file_id,location,storage_name,asset_status,source_json')) as $name=>$details)
					{
						list($key,$columns) = $details;
						$result = $this->db->sql_query("SELECT $key,$columns FROM " . $this->prefix . $name . $where . " ORDER BY $key" . $current);
						while ($item = $this->db->sql_fetchrow($result)) { hash_update($hash,serialize($item)); }
						$this->db->sql_freeresult($result);
					}
				}
			}
			// The administrator can choose any surviving destination on this form.
			$result = $this->db->sql_query('SELECT row_id,cat_id,subject_id,subject_type,allowed FROM ' . $this->prefix .
				'grants WHERE cat_id>0 ORDER BY row_id' . $current);
			while ($grant = $this->db->sql_fetchrow($result))
			{
				hash_update($hash, serialize($grant));
				if (isset($scope[(int) $grant['cat_id']])) { ++$grants; }
			}
			$this->db->sql_freeresult($result);
		}
		return array('state'=>hash_final($hash),'scope'=>$scope,'files'=>$files,'direct'=>$direct,'below'=>$below,'grants'=>$grants);
	}

	public function change($action, $id, $state, $form)
	{
		if (!$this->access->catalogue_admin()) { throw new \DomainException('NO_AUTH_OPERATION'); }
		$this->db->sql_transaction('begin');
		try
		{
			$this->access->lock_catalogue(true);
			$this->access->refresh();
			$tree = $this->tree();
			$review = $this->review($tree,$action,$id,true);
			if (!hash_equals($review['state'],$state)) { throw new \DomainException('ACP_PLUS_DL_CATALOGUE_CHANGED'); }
			if ($action === 'delete') { $this->remove($tree,$id,$form,$review); }
			elseif ($action === 'up' || $action === 'down')
			{
				$parent = (int) $tree['rows'][$id]['parent_id'];
				$ids = $tree['children'][$parent];
				$from = array_search($id,$ids,true);
				$to = $from + ($action === 'up' ? -1 : 1);
				if (isset($ids[$to])) { $ids[$from] = $ids[$to]; $ids[$to] = $id; $this->positions($ids,$tree['rows']); }
			}
			elseif ($action === 'create' || $action === 'edit') { $this->save($tree,$id,$form,$review['direct']); }
			else { throw new \DomainException('FORM_INVALID'); }
			if (!$this->db->sql_transaction('commit')) { throw new \RuntimeException('The download category transaction did not commit.'); }
			$this->access->refresh();
		}
		catch (\Throwable $e)
		{
			if ($this->db->get_transaction()) { $this->db->sql_transaction('rollback'); }
			$this->access->refresh();
			throw $e;
		}
	}

	private function text($value)
	{
		if (!is_string($value) || !preg_match('//u',$value) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value))
		{ throw new \DomainException('ACP_PLUS_DL_CATEGORY_INVALID'); }
		$value = utf8_encode_ucr(utf8_htmlspecialchars($value));
		if (strlen($value) > 65535) { throw new \DomainException('ACP_PLUS_DL_CATEGORY_INVALID'); }
		return $value;
	}

	private function positions($ids, $rows)
	{
		foreach (array_values($ids) as $index=>$id)
		{
			$position = ($index + 1) * 10;
			if ((int) $rows[$id]['position'] !== $position)
			{ $this->db->sql_query('UPDATE ' . $this->prefix . "categories SET position=$position WHERE cat_id=" . (int) $id); }
		}
	}

	private function reparented_order($ids, $rows)
	{
		// The legacy ACP merged siblings by their existing position, then category ID.
		$this->positions(array_keys(array_intersect_key($rows,array_fill_keys($ids,true))),$rows);
	}

	private function save($tree, $id, $form, $count)
	{
		$rows = $tree['rows'];
		$parent = $form['parent'];
		if ($parent < 0 || ($parent && (!isset($rows[$parent]) || $tree['list'][$parent] < 0))) { throw new \DomainException('ACP_PLUS_DL_TREE_INVALID'); }
		for ($cursor=$parent; $cursor; $cursor=(int) $rows[$cursor]['parent_id'])
		{ if ($cursor === $id) { throw new \DomainException('ACP_PLUS_DL_TREE_INVALID'); } }
		if (trim($form['title']) === '') { throw new \DomainException('ACP_PLUS_DL_CATEGORY_INVALID'); }
		if (!$form['allow_files'])
		{
			if ($count) { throw new \DomainException('ACP_PLUS_DL_FOLDER_HAS_FILES'); }
			if ($parent && !$rows[$parent]['allow_files']) { throw new \DomainException('ACP_PLUS_DL_FOLDER_CONFLICT'); }
			if ($id)
			{
				foreach ($tree['children'][$id] ?? array() as $child)
				{ if (!$rows[$child]['allow_files']) { throw new \DomainException('ACP_PLUS_DL_FOLDER_CONFLICT'); } }
			}
		}
		$table = $this->prefix . 'categories';
		$data = array('title'=>$this->text(trim($form['title'])),'summary'=>$this->text($form['summary']),'parent_id'=>$parent,
			'allow_files'=>(int) $form['allow_files'],'allow_ratings'=>(int) $form['allow_ratings'],'allow_comments'=>(int) $form['allow_comments']);
		if (!$id)
		{
			if (!in_array($form['initial_access'],array('private','legacy'),true)) { throw new \DomainException('ACP_PLUS_DL_CATEGORY_INVALID'); }
			$levels = array_fill_keys(array('view','read','view_file','edit_file','delete_file','upload','download','rate','email',
				'view_comment','post_comment','edit_comment','delete_comment'),$form['initial_access'] === 'legacy' ? 0 : 5);
			$ids = $tree['children'][$parent] ?? array();
			$last = $ids ? max(0,(int) $rows[end($ids)]['position']) : 0;
			if ($last > 2147483637) { throw new \DomainException('ACP_PLUS_DL_CATEGORY_INVALID'); }
			$data += array('position'=>$last+10,'access_levels'=>json_encode($levels),'source_json'=>'{}');
			$this->db->sql_query("INSERT INTO $table " . $this->db->sql_build_array('INSERT',$data));
			return;
		}
		$old = (int) $rows[$id]['parent_id'];
		if ($old !== $parent)
		{
			$this->positions(array_diff($tree['children'][$old],array($id)),$rows);
			$ids = $tree['children'][$parent] ?? array();
			$ids[] = $id;
			$this->reparented_order($ids,$rows);
		}
		$this->db->sql_query("UPDATE $table SET " . $this->db->sql_build_array('UPDATE',$data) . " WHERE cat_id=$id");
	}

	private function remove($tree, $id, $form, $review)
	{
		$rows = $tree['rows'];
		$children = $tree['children'][$id] ?? array();
		$sub = $form['children_mode'];
		$mode = $form['files_mode'];
		if (!$form['confirm'] || !in_array($sub,array('move','delete'),true) || !in_array($mode,array('move','delete'),true))
		{ throw new \DomainException('ACP_PLUS_DL_REMOVAL_CHOICE'); }
		$scope = $sub === 'move' ? array($id=>$id) : $review['scope'];
		$to = $form['children_to'];
		if ($sub === 'move' && $children)
		{
			if ($to < 0 || isset($review['scope'][$to]) || ($to && (!isset($rows[$to]) || $tree['list'][$to] < 0)))
			{ throw new \DomainException('ACP_PLUS_DL_TREE_INVALID'); }
			foreach ($children as $child)
			{ if ($to && !$rows[$to]['allow_files'] && !$rows[$child]['allow_files']) { throw new \DomainException('ACP_PLUS_DL_FOLDER_CONFLICT'); } }
		}
		$ids = array();
		foreach ($scope as $cat) { foreach ($review['files'][$cat] ?? array() as $file) { $ids[] = $file; } }
		$target = $form['files_to'];
		if ($mode === 'move' && $ids && (isset($scope[$target]) || !isset($rows[$target]) || !$rows[$target]['allow_files'] || $tree['list'][$target] < 0))
		{ throw new \DomainException('ACP_PLUS_DL_FILE_TARGET'); }
		if ($mode === 'delete') { $this->submissions->purge_category_files($ids); }
		else
		{
			foreach (array_chunk($ids,200) as $batch)
			{ $this->db->sql_query('UPDATE ' . $this->prefix . "files SET cat_id=$target,review_revision=review_revision+1 WHERE " . $this->db->sql_in_set('file_id',$batch)); }
		}
		$table = $this->prefix . 'categories';
		$old_parent = (int) $rows[$id]['parent_id'];
		$siblings = array_diff($tree['children'][$old_parent],array($id));
		if ($sub === 'move' && $children)
		{
			$at = array_diff($tree['children'][$to] ?? array(),array($id));
			foreach (array_chunk($children,200) as $batch)
			{ $this->db->sql_query("UPDATE $table SET parent_id=$to WHERE " . $this->db->sql_in_set('cat_id',$batch)); }
			$this->reparented_order(array_merge($at,$children),$rows);
		}
		foreach (array_chunk(array_values($scope),200) as $batch)
		{
			$where = ' WHERE ' . $this->db->sql_in_set('cat_id',$batch);
			$this->db->sql_query('DELETE FROM ' . $this->prefix . 'grants' . $where);
			$this->db->sql_query("DELETE FROM $table" . $where);
		}
		if ($sub !== 'move' || !$children || $to !== $old_parent) { $this->positions($siblings,$rows); }
	}
}
