<?php
namespace phpbb2plus\arcade\service;

/** The original Arcade has one level of subcategories. Links are always top level. */
class category_tree
{
	public $rows;
	public $roots = array();
	public $children = array();
	public $repair = false;

	public function __construct(array $rows)
	{
		$this->rows = $rows;
		if (!isset($rows[-1]) || $rows[-1]['cat_type'] !== 'p') { throw new \DomainException('PLUS_ARC_CAT_GLOBAL'); }
		uasort($this->rows, static function ($a, $b) {
			return ((int) $a['cat_order'] <=> (int) $b['cat_order']) ?: ((int) $a['cat_id'] <=> (int) $b['cat_id']);
		});
		foreach ($this->rows as $id => $row)
		{
			if ($id === -1) { continue; }
			if ($id <= 0) { throw new \DomainException('PLUS_ARC_CAT_GLOBAL'); }
			$type = $row['cat_type'];
			$parent = (int) $row['cat_parent'];
			if ($type === 's' && $parent > 0 && $parent !== $id && ($rows[$parent]['cat_type'] ?? '') === 'p')
			{
				$this->children[$parent][] = $id;
				continue;
			}
			if (!in_array($type, array('p', 'l'), true) || $parent !== 0)
			{
				$this->rows[$id]['cat_type'] = 'p';
				$this->rows[$id]['cat_parent'] = 0;
				$this->repair = true;
			}
			$this->roots[] = $id;
		}
	}

	public function order()
	{
		$order = array(-1);
		foreach ($this->roots as $id)
		{
			$order[] = $id;
			foreach ($this->children[$id] ?? array() as $child) { $order[] = $child; }
		}
		return $order;
	}

	public function siblings($id)
	{
		return $this->rows[$id]['cat_type'] === 's' ? $this->children[(int) $this->rows[$id]['cat_parent']] : $this->roots;
	}

	public function move($id, $delta)
	{
		$siblings = $this->siblings($id);
		$at = array_search($id, $siblings, true);
		if ($at === false || !isset($siblings[$at + $delta])) { return; }
		$siblings[$at] = $siblings[$at + $delta];
		$siblings[$at + $delta] = $id;
		if ($this->rows[$id]['cat_type'] === 's') { $this->children[(int) $this->rows[$id]['cat_parent']] = $siblings; }
		else { $this->roots = $siblings; }
	}

	public static function shape($rows)
	{
		ksort($rows, SORT_NUMERIC);
		$shape = array();
		foreach ($rows as $key => $row) { $shape[] = array($key, $row['cat_type'], (int) $row['cat_parent'], (int) $row['cat_order']); }
		return hash('sha256', serialize($shape));
	}

	public static function revision($shape, $row)
	{
		$fields = array('cat_name','cat_desc','cat_icon','special_play','group_required','native_group_id','mod_id','moderator_id');
		$edited = $row ? array_intersect_key($row, array_flip($fields)) + array('cat_id'=>$row['cat_id']) : array();
		return hash('sha256', serialize(array($shape, $edited)));
	}
}
