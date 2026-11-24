<?php
if (!defined('IN_PHPBB')) { exit; }

function plus_import_flags()
{
	global $db, $src_db, $convert, $table_prefix, $phpbb_root_path;
	$prefix = $convert->src_table_prefix;
	$root = plus_media_path($phpbb_root_path, 'images/plusflags', true);
	$flags = array();
	$registered = array();
	$result = $src_db->sql_query("SELECT flag_id,flag_name,flag_image FROM {$prefix}flags ORDER BY flag_name,flag_id");
	while ($row = $src_db->sql_fetchrow($result))
	{
		$flags[] = array('source_id'=>(int) $row['flag_id'],'label'=>(string) $row['flag_name'],'source_file'=>(string) $row['flag_image']);
		$registered[(string) $row['flag_image']] = true;
	}
	$src_db->sql_freeresult($result);
	// Plus also displayed a user's original filename after an administrator
	// removed its catalogue entry. Preserve those selections as additional options.
	$result = $src_db->sql_query("SELECT DISTINCT user_from_flag FROM {$prefix}users WHERE user_id>0 AND user_from_flag IS NOT NULL AND user_from_flag<>'' ORDER BY user_from_flag");
	while ($row = $src_db->sql_fetchrow($result))
	{
		$name = (string) $row['user_from_flag'];
		if ($name !== 'blank.gif' && !isset($registered[$name]))
		{
			$flags[] = array('source_id'=>0,'label'=>pathinfo($name,PATHINFO_FILENAME),'source_file'=>$name);
		}
	}
	$src_db->sql_freeresult($result);
	foreach ($flags as &$flag)
	{
		$image = plus_profile_image('images/flags', $flag['source_file']);
		$flag['image'] = '';
		if ($image)
		{
			$flag['image'] = hash_file('sha256',$image['source']) . '.' . $image['ext'];
			plus_copy_media($image['source'],$root . '/' . $flag['image']);
		}
		if ($flag['label'] === '') { $flag['label'] = $flag['source_file']; }
	}
	unset($flag);
	(new \phpbb2plus\community\profile\country_flags($db,$table_prefix))->replace($flags);
}

function plus_country_value($reference)
{
	global $db, $table_prefix;
	if ($reference === '' || $reference === null || $reference === 'blank.gif') { return 1; }
	static $values;
	if ($values === null)
	{
		$values = array();
		$result = $db->sql_query('SELECT source_file,option_value FROM ' . $table_prefix . 'plus_flags ORDER BY option_value');
		while ($row = $db->sql_fetchrow($result))
		{
			if (!isset($values[$row['source_file']])) { $values[$row['source_file']] = (int) $row['option_value']; }
		}
		$db->sql_freeresult($result);
	}
	return $values[utf8_encode_ucr($reference)] ?? 1;
}
