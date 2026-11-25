<?php
namespace phpbb2plus\gallery\service;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Shared structural operations. The calling public or personal service authorizes each write under the gallery lock. */
class hierarchy
{
	private $db;
	private $pictures;
	private $prefix;

	public function __construct($db, $pictures, $prefix)
	{
		$this->db = $db;
		$this->pictures = $pictures;
		$this->prefix = $prefix;
	}

	public function tree($owner)
	{
		$scope = 'owner_id=' . (int) $owner;
		if (!$owner) { $scope .= ' AND source_owner=0 AND legacy_guest_root=0'; }
		$result = $this->db->sql_query('SELECT * FROM ' . $this->prefix . 'cats WHERE ' . $scope . ' ORDER BY sort_order,cat_id');
		$rows = $children = $tree = array();
		while ($row = $this->db->sql_fetchrow($result))
		{
			$id = (int) $row['cat_id'];
			$rows[$id] = access::category_labels($row);
			$children[(int) $row['parent_id']][] = $id;
		}
		$this->db->sql_freeresult($result);
		$stack = array_reverse($children[0] ?? array());
		while ($stack)
		{
			$id = array_pop($stack);
			$cat = $rows[$id];
			$parent = (int) $cat['parent_id'];
			if (!$id || isset($tree[$id]) || ($parent && (int) $tree[$parent]['source_owner'] !== (int) $cat['source_owner']))
			{ throw new \DomainException('PLUS_ALBUM_TREE_INVALID'); }
			$cat['depth'] = $parent ? 1 + $tree[$parent]['depth'] : 0;
			$cat['children'] = count($children[$id] ?? array());
			$tree[$id] = $cat;
			foreach (array_reverse($children[$id] ?? array()) as $child) { $stack[] = $child; }
		}
		if (count($tree) !== count($rows)) { throw new \DomainException('PLUS_ALBUM_TREE_INVALID'); }
		return $tree;
	}

	public function review($tree, $id, $mode)
	{
		// A removal confirmation also covers records that would be moved or deleted.
		// Reading photos can increase their view counts without invalidating the form.
		$hash = hash_init('sha256');
		foreach ($tree as $cat) { hash_update($hash, serialize($cat)); }
		$count = 0;
		if ($mode === 'delete')
		{
			$id = (int) $id;
			$pics = $this->prefix . 'pics';
			$result = $this->db->sql_query("SELECT pic_id,storage_name,title,description,approved,locked FROM $pics WHERE cat_id=$id ORDER BY pic_id");
			while ($row = $this->db->sql_fetchrow($result)) { ++$count; hash_update($hash, serialize($row)); }
			$this->db->sql_freeresult($result);
			$queries = array('SELECT capability,subject_type,subject_id FROM ' . $this->prefix . "grants WHERE cat_id=$id ORDER BY capability,subject_type,subject_id",
				'SELECT c.comment_id,c.edited_at,c.edit_count FROM ' . $this->prefix . "comments c JOIN $pics p ON p.pic_id=c.pic_id WHERE p.cat_id=$id ORDER BY c.comment_id",
				'SELECT r.rating_id,r.points,r.honour_points FROM ' . $this->prefix . "ratings r JOIN $pics p ON p.pic_id=r.pic_id WHERE p.cat_id=$id ORDER BY r.rating_id");
			foreach ($queries as $query)
			{
				$result = $this->db->sql_query($query);
				while ($row = $this->db->sql_fetchrow($result)) { hash_update($hash, serialize($row)); }
				$this->db->sql_freeresult($result);
			}
		}
		return array('state' => hash_final($hash), 'pictures' => $count);
	}

	public function destinations($tree, $id)
	{
		$choices = $excluded = array();
		foreach ($tree as $other => $cat)
		{
			if ($other === $id || isset($excluded[(int) $cat['parent_id']])) { $excluded[$other] = true; continue; }
			if ((int) $cat['source_owner'] === (int) $tree[$id]['source_owner']) { $choices[$other] = $cat; }
		}
		return $choices;
	}

	public function next_order($tree, $parent)
	{
		$last = 0;
		foreach ($tree as $cat)
		{
			if ($parent === null || $parent === (int) $cat['parent_id']) { $last = max($last, (int) $cat['sort_order']); }
		}
		if ($last > 2147483637) { throw new \DomainException('PLUS_ALBUM_TREE_INVALID'); }
		return $last + 10;
	}

	public function reorder($tree, $id, $mode, $permitted = null)
	{
		$table = $this->prefix . 'cats';
		$parent = (int) $tree[$id]['parent_id'];
		$siblings = array();
		foreach ($tree as $other => $sibling) { if ((int) $sibling['parent_id'] === $parent) { $siblings[] = $other; } }
		$pos = array_search($id, $siblings, true);
		$next = $pos + ($mode === 'up' ? -1 : 1);
		if (isset($siblings[$next]))
		{
			if ($permitted && !$permitted($tree[$siblings[$next]])) { throw new NotFoundHttpException(); }
			$siblings[$pos] = $siblings[$next]; $siblings[$next] = $id;
			foreach ($siblings as $index => $other)
			{
				$order = ($index + 1) * 10;
				if ((int) $tree[$other]['sort_order'] !== $order) { $this->db->sql_query("UPDATE $table SET sort_order=$order WHERE cat_id=$other"); }
			}
		}
	}

	public function remove($cat, $dest)
	{
		$id = (int) $cat['cat_id'];
		$owner = (int) $cat['owner_id'];
		$source = (int) $cat['source_owner'];
		$parent = (int) $cat['parent_id'];
		$table = $this->prefix . 'cats';
		$result = $this->db->sql_query_limit("SELECT cat_id FROM $table WHERE parent_id=$id AND (owner_id<>$owner OR source_owner<>$source" .
			($owner ? ')' : ' OR legacy_guest_root<>0)'), 1);
		$foreign = $this->db->sql_fetchfield('cat_id') !== false;
		$this->db->sql_freeresult($result);
		if ($foreign) { throw new \DomainException('PLUS_ALBUM_TREE_INVALID'); }
		$photos = $this->prefix . 'pics';
		if ($dest === 'remove')
		{
			do
			{
				$result = $this->db->sql_query_limit("SELECT pic_id,storage_name FROM $photos WHERE cat_id=$id ORDER BY pic_id", 50);
				$rows = $this->db->sql_fetchrowset($result);
				$this->db->sql_freeresult($result);
				foreach ($rows as $pic) { $this->pictures->discard($pic); }
			} while (count($rows) === 50);
		}
		else
		{
			$dest = (int) $dest;
			$this->db->sql_query('UPDATE ' . $this->prefix . "comments SET cat_id=$dest WHERE pic_id IN (SELECT pic_id FROM $photos WHERE cat_id=$id)");
			$this->db->sql_query("UPDATE $photos SET cat_id=$dest,legacy_uncategorized=0 WHERE cat_id=$id");
		}
		$this->db->sql_query("UPDATE $table SET parent_id=$parent WHERE owner_id=$owner AND parent_id=$id");
		$this->db->sql_query('DELETE FROM ' . $this->prefix . "grants WHERE cat_id=$id");
		$this->db->sql_query("DELETE FROM $table WHERE cat_id=$id");
	}
}
