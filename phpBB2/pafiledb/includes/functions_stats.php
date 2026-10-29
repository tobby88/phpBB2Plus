<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_toplist.php';

function pafiledb_stats_rows($database, $sql)
{
	$result = $database->sql_query($sql);
	if (!$result) { throw new RuntimeException('Download statistics unavailable'); }
	try { $rows = $database->sql_fetchrowset($result); }
	finally { $database->sql_freeresult($result); }
	if (!is_array($rows)) { throw new RuntimeException('Download statistics unavailable'); }
	return $rows;
}

function pafiledb_stats_summary_sql($categories)
{
	return 'SELECT COUNT(*) AS total_files, COALESCE(SUM(f.file_dls),0) AS total_downloads,'
		. ' COALESCE(ROUND(AVG(COALESCE(f.file_dls,0))),0) AS average_downloads,'
		. ' COALESCE(ROUND(AVG(v.rating)),0) AS average_rating'
		. ' FROM ' . PA_FILES_TABLE . ' f'
		. ' LEFT JOIN (SELECT votes_file,AVG(rate_point) AS rating FROM ' . PA_VOTES_TABLE . ' GROUP BY votes_file) v ON v.votes_file=f.file_id'
		. ' WHERE f.file_approved=1 AND ' . pafiledb_toplist_scope_sql($categories);
}

function pafiledb_stats_extreme_sql($categories, $sort, $direction, $rated_only = false)
{
	if (!in_array($sort, array('file_time', 'file_dls', 'rating'), true) || !in_array($direction, array('ASC', 'DESC'), true))
	{ throw new InvalidArgumentException('Invalid download statistics order'); }
	return 'SELECT f.file_id,f.file_name,COALESCE(f.file_dls,0) AS file_dls,'
		. ' COALESCE(f.file_time,0) AS file_time,'
		. ' (SELECT AVG(v.rate_point) FROM ' . PA_VOTES_TABLE . ' v WHERE v.votes_file=f.file_id) AS rating'
		. ' FROM ' . PA_FILES_TABLE . ' f WHERE f.file_approved=1 AND ' . pafiledb_toplist_scope_sql($categories)
		. ($rated_only ? ' AND EXISTS (SELECT 1 FROM ' . PA_VOTES_TABLE . ' v WHERE v.votes_file=f.file_id)' : '')
		. ' ORDER BY ' . $sort . ' ' . $direction . ',f.file_id ' . $direction . ' LIMIT 1';
}

function pafiledb_stats_client_sql($categories, $kind)
{
	if (!in_array($kind, array('downloads', 'ratings'), true)) { throw new InvalidArgumentException('Invalid download statistics kind'); }
	$download = $kind === 'downloads';
	$os = $download ? 'downloader_os' : 'voter_os';
	$browser = $download ? 'downloader_browser' : 'voter_browser';
	return 'SELECT d.' . $os . ' AS os,d.' . $browser . ' AS browser,COUNT(*) AS total'
		. ' FROM ' . ($download ? PA_DOWNLOAD_INFO_TABLE : PA_VOTES_TABLE) . ' d'
		. ' INNER JOIN ' . PA_FILES_TABLE . ' f ON f.file_id=d.' . ($download ? 'file_id' : 'votes_file')
		. ' WHERE f.file_approved=1 AND ' . pafiledb_toplist_scope_sql($categories)
		. ' GROUP BY d.' . $os . ',d.' . $browser;
}

function pafiledb_stats_client_counts($rows)
{
	$os = array('Win'=>0, 'Mac'=>0, 'Linux'=>0, 'Unix'=>0, 'Other'=>0);
	$browser = array('OPERA'=>0, 'IE'=>0, 'MOZILLA'=>0, 'NETSCAPE'=>0, 'OTHER'=>0);
	foreach ($rows as $row)
	{
		$os_key = isset($row['os']) && array_key_exists($row['os'], $os) ? $row['os'] : 'Other';
		$browser_key = isset($row['browser']) && array_key_exists($row['browser'], $browser) ? $row['browser'] : 'OTHER';
		$total = (int)$row['total'];
		$os[$os_key] += $total;
		$browser[$browser_key] += $total;
	}
	return array('os'=>$os, 'browser'=>$browser);
}

function pafiledb_stats_read($database, $categories)
{
	$summary = pafiledb_stats_rows($database, pafiledb_stats_summary_sql($categories));
	if (count($summary) !== 1) { throw new RuntimeException('Download statistics unavailable'); }
	$data = array('summary'=>$summary[0], 'categories'=>count(array_unique($categories)));
	foreach (array('newest'=>array('file_time','DESC',false), 'oldest'=>array('file_time','ASC',false),
		'popular'=>array('rating','DESC',true), 'least_popular'=>array('rating','ASC',true),
		'most_downloaded'=>array('file_dls','DESC',false), 'least_downloaded'=>array('file_dls','ASC',false)) as $name=>$options)
	{
		$rows = pafiledb_stats_rows($database, pafiledb_stats_extreme_sql($categories, $options[0], $options[1], $options[2]));
		$data[$name] = $rows ? $rows[0] : array('file_id'=>0, 'file_name'=>'', 'file_dls'=>0, 'rating'=>0);
	}
	foreach (array('downloads', 'ratings') as $kind)
	{
		$data[$kind] = pafiledb_stats_client_counts(pafiledb_stats_rows($database, pafiledb_stats_client_sql($categories, $kind)));
	}
	return $data;
}

function pafiledb_stats_message($format, $data)
{
	$summary = $data['summary'];
	$values = array('{total_files}'=>$summary['total_files'], '{total_categories}'=>$data['categories'],
		'{total_downloads}'=>$summary['total_downloads'], '{average}'=>$summary['average_rating'], '{avg_dls}'=>$summary['average_downloads']);
	foreach (array('newest'=>array('newest_file','u_newest_file'), 'oldest'=>array('oldest_file','u_oldest_file'),
		'popular'=>array('popular','u_popular'), 'least_popular'=>array('lpopular','u_lpopular'),
		'most_downloaded'=>array('most_dl','u_most_dl'), 'least_downloaded'=>array('least_dl','u_least_dl')) as $key=>$fields)
	{
		$values['{' . $fields[0] . '}'] = pafiledb_html($data[$key]['file_name']);
		$values['{' . $fields[1] . '}'] = pafiledb_html(append_sid('dload.php?action=file&file_id=' . (int)$data[$key]['file_id']));
	}
	$values['{most}'] = round((float)$data['popular']['rating'], 2);
	$values['{least}'] = round((float)$data['least_popular']['rating'], 2);
	$values['{most_no}'] = $data['most_downloaded']['file_dls'];
	$values['{least_no}'] = $data['least_downloaded']['file_dls'];
	foreach ($values as &$value) { $value = (string)$value; }
	unset($value);
	// A filename containing another placeholder must remain literal.
	return strtr($format, $values);
}
