<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_toplist.php';

function pafiledb_listing_rows($database, $sql)
{
	$result = $database->sql_query($sql);
	if (!$result) { throw new RuntimeException('Download list unavailable'); }
	try { $rows = $database->sql_fetchrowset($result); }
	finally { $database->sql_freeresult($result); }
	if (!is_array($rows)) { throw new RuntimeException('Download list unavailable'); }
	return $rows;
}

function pafiledb_listing_options($sort, $direction, $start, $per_page)
{
	$sort = $sort === 'file_rating' ? 'rating' : $sort;
	if (!in_array($sort, array('file_name', 'file_time', 'file_dls', 'rating', 'file_update_time'), true)) { $sort = 'file_time'; }
	$direction = $direction === 'ASC' ? 'ASC' : 'DESC';
	$start = (is_int($start) || is_string($start)) && preg_match('/^[0-9]+$/D', (string)$start) ? (int)min(2147483647, (float)$start) : 0;
	$per_page = (is_int($per_page) || is_string($per_page)) && preg_match('/^[0-9]+$/D', (string)$per_page) ? (int)min(1000000, (float)$per_page) : 1;
	return array('sort'=>$sort, 'direction'=>$direction, 'start'=>$start, 'per_page'=>max(1, $per_page));
}

function pafiledb_listing_scope($categories, $category)
{
	// Validate the whole scope even when a particular category was requested.
	pafiledb_toplist_scope_sql($categories);
	if (in_array($category, array(false, null, 0, '0', ''), true)) { return array($categories, 0); }
	if (!(is_int($category) || is_string($category)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$category) || (float)$category > 2147483647)
	{ throw new InvalidArgumentException('Invalid download category'); }
	$category = (int)$category;
	return array(in_array($category, $categories, true) ? array($category) : array(), $category);
}

function pafiledb_listing_files_sql($categories, $pinned, $options)
{
	if (!is_bool($pinned) || !is_array($options) || !isset($options['sort'], $options['direction'], $options['start'], $options['per_page']) ||
		$options !== pafiledb_listing_options($options['sort'], $options['direction'], $options['start'], $options['per_page']))
	{ throw new InvalidArgumentException('Invalid download list options'); }
	return 'SELECT f.*,COALESCE(f.file_dls,0) AS file_dls,'
		. ' (SELECT AVG(v.rate_point) FROM ' . PA_VOTES_TABLE . ' v WHERE v.votes_file=f.file_id) AS rating,'
		. ' (SELECT COUNT(*) FROM ' . PA_VOTES_TABLE . ' v WHERE v.votes_file=f.file_id) AS total_votes,'
		. ' (SELECT COUNT(*) FROM ' . PA_COMMENTS_TABLE . ' c WHERE c.file_id=f.file_id) AS total_comments'
		. ' FROM ' . PA_FILES_TABLE . ' f WHERE f.file_approved=1 AND ' . pafiledb_toplist_scope_sql($categories)
		. ' AND COALESCE(f.file_pin,0)' . ($pinned ? '=' : '<>') . (int)FILE_PINNED
		. ' ORDER BY ' . $options['sort'] . ' ' . $options['direction'] . ',f.file_id ' . $options['direction']
		. ($pinned ? '' : ' LIMIT ' . $options['start'] . ',' . $options['per_page']);
}

function pafiledb_listing_read($database, $categories, $category, $sort, $direction, $start, $per_page)
{
	list($categories, $category) = pafiledb_listing_scope($categories, $category);
	$options = pafiledb_listing_options($sort, $direction, $start, $per_page);
	$count = pafiledb_listing_rows($database, 'SELECT COUNT(*) AS total_file FROM ' . PA_FILES_TABLE . ' f WHERE f.file_approved=1 AND '
		. pafiledb_toplist_scope_sql($categories) . ' AND COALESCE(f.file_pin,0)<>' . (int)FILE_PINNED);
	if (count($count) !== 1 || !preg_match('/^[0-9]+$/D', (string)$count[0]['total_file']) || (float)$count[0]['total_file'] > 2147483647)
	{ throw new RuntimeException('Download list unavailable'); }
	$total = (int)$count[0]['total_file'];
	if ($options['start'] >= $total) { $options['start'] = $total ? (int)(floor(($total - 1) / $options['per_page']) * $options['per_page']) : 0; }
	return array('options'=>$options, 'category'=>$category, 'total'=>$total,
		'pinned'=>pafiledb_listing_rows($database, pafiledb_listing_files_sql($categories, true, $options)),
		'files'=>pafiledb_listing_rows($database, pafiledb_listing_files_sql($categories, false, $options)));
}
