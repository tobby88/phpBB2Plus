<?php
namespace phpbb2plus\downloads\service;

class file_list
{
	const PAGE_SIZE = 25;
	const FILTERS = array('all','pending','approved','broken');
	private $db;
	private $prefix;

	public function __construct($db, $prefix)
	{
		$this->db = $db;
		$this->prefix = $prefix;
	}

	public function page($query, $start, $filter = 'all', $category = 0, $count_mirrors = false)
	{
		$where = array();
		if (!in_array($filter,self::FILTERS,true)) { throw new \DomainException('FORM_INVALID'); }
		if ($filter === 'broken') { $where[] = 'f.broken=1'; }
		if ($filter === 'approved' || $filter === 'pending') { $where[] = 'f.approved=' . (int) ($filter === 'approved'); }
		if ($category > 0) { $where[] = 'f.cat_id=' . (int) $category; }
		if ($query !== '')
		{
			$matches = array();
			$wild = $this->db->get_any_char();
			foreach (array_unique(array($query,utf8_htmlspecialchars($query))) as $text)
			{ $matches[] = 'f.title ' . $this->db->sql_like_expression($wild . utf8_encode_ucr($text) . $wild); }
			if (ctype_digit($query) && $query<=4294967295) { $matches[] = 'f.file_id=' . (int) $query; }
			$where[] = '(' . implode(' OR ',$matches) . ')';
		}
		$copies = $count_mirrors ? ',(SELECT COUNT(*) FROM ' . $this->prefix . 'mirrors m WHERE m.file_id=f.file_id) AS copies' : '';
		// File management starts with recent uploads; the mirror lookup is alphabetical.
		$order = $count_mirrors ? 'f.title,f.file_id' : 'f.added_at DESC,f.file_id DESC';
		$result = $this->db->sql_query_limit('SELECT f.file_id,f.title,f.approved,f.broken,f.cat_id,c.title AS category' . $copies .
			' FROM ' . $this->prefix . 'files f LEFT JOIN ' . $this->prefix . 'categories c ON c.cat_id=f.cat_id' .
			($where ? ' WHERE ' . implode(' AND ',$where) : '') . ' ORDER BY ' . $order,self::PAGE_SIZE+1,max(0,$start));
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}
}
