<?php
if (!defined('IN_PHPBB')) { exit; }

function plus_banner_asset($id, $type, $reference, $field)
{
	global $convert, $phpbb_root_path, $config;
	static $previous = null, $asset = array();
	$key = array((int) $id, (int) $type, (string) $reference);
	if ($key === $previous) { return $asset[$field]; }
	$previous = $key;
	$asset = array('name'=>'', 'mime'=>'', 'bytes'=>0, 'remote'=>'');
	if ((int) $type === 2 || trim((string) $reference) === '') { return $asset[$field]; }

	$url = html_entity_decode(trim((string) $reference), ENT_QUOTES, 'UTF-8');
	$parts = parse_url($url);
	if ($parts === false || isset($parts['user']) || isset($parts['pass'])) { return $asset[$field]; }
	if (isset($parts['host']) && strcasecmp($parts['host'], (string) get_config_value('server_name')) !== 0)
	{
		$asset['remote'] = utf8_encode_ucr(\phpbb2plus\banners\service\catalog::external($url));
		return $asset[$field];
	}
	$file = rawurldecode($parts['path'] ?? '');
	$board_path = '/' . trim((string) get_config_value('script_path'), '/');
	if ($board_path !== '/' && strpos($file, $board_path . '/') === 0) { $file = substr($file, strlen($board_path)); }
	if (strpos($file, './') === 0) { $file = substr($file, 2); }
	$file = ltrim($file, '/');
	try { $path = plus_media_path($convert->options['forum_path'], $file); }
	catch (\RuntimeException $e) { $path = ''; }
	$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
	$allowed = (int) $type === 6 ? array('swf') : array('png','jpg','jpeg','gif','webp');
	$mime = $path && in_array($ext, $allowed, true) ? plus_arcade_asset_type($path, $ext, false) : '';
	if (!$mime) { plus_profile_media_issue('banner', $id, $reference, 'unavailable'); return $asset[$field]; }
	$target = plus_media_path($phpbb_root_path, trim($config['upload_path'], '/'), true);
	$hash = hash_file('sha256', $path);
	if ($hash === false) { throw new \RuntimeException('Cannot read a local banner file.'); }
	$asset['name'] = 'banner_' . $hash;
	plus_copy_media($path, $target . '/' . $asset['name']);
	$asset['bytes'] = filesize($path);
	$asset['mime'] = $mime;
	return $asset[$field];
}

function plus_banner_schema($prefix, $truncate)
{
	$table = $prefix . 'plus_banners';
	$banner = array('target'=>$table, 'primary'=>'banner.banner_id', 'autoincrement'=>'banner_id',
		'query_first'=>array(array('target', $truncate . $table), array('target', $truncate . $prefix . 'plus_banner_receipts'),
			array('target', $truncate . $prefix . 'plus_banner_forms')));
	$fields = array('banner_id'=>'banner_id', 'display_name'=>'banner_name', 'description'=>'banner_description', 'target_url'=>'banner_url',
		'spot'=>'banner_spot','forum_id'=>'banner_forum','owner_id'=>'banner_owner','source_owner'=>'banner_owner','clicks'=>'banner_click',
		'views'=>'banner_view','weight'=>'banner_weigth','active'=>'banner_active','time_mode'=>'banner_timetype','time_begin'=>'time_begin',
		'time_end'=>'time_end','date_begin'=>'date_begin','date_end'=>'date_end','audience'=>'banner_level','audience_mode'=>'banner_level_type',
		'notes'=>'banner_comment','media_type'=>'banner_type','width'=>'banner_width','height'=>'banner_height','filter_clicks'=>'banner_filter',
		'filter_seconds'=>'banner_filter_time');
	foreach ($fields as $target=>$old)
	{
		$fn = 'intval';
		if ($target === 'owner_id') { $fn = 'plus_existing_author'; }
		elseif (in_array($target, array('clicks','views'), true)) { $fn = 'strval'; }
		elseif (in_array($target, array('display_name','description','target_url','notes'), true)) { $fn = 'plus_dl_text'; }
		$banner[] = array($target, 'banner.' . $old, $fn);
	}
	foreach (array('asset_name'=>'name','asset_mime'=>'mime','asset_bytes'=>'bytes','remote_asset'=>'remote') as $target=>$field)
	{
		$banner[] = array($target, array('banner.banner_id','banner.banner_type','banner.banner_name',$field), 'plus_banner_asset');
	}
	$hits = $prefix . 'plus_banner_hits';
	return array($banner, array('target'=>$hits,
		'order_by'=>'banner_stats.banner_id,banner_stats.click_date,banner_stats.click_user,banner_stats.click_ip,banner_stats.user_duration',
		'query_first'=>array('target', $truncate . $hits),
		array('banner_id','banner_stats.banner_id','intval'), array('hit_time','banner_stats.click_date','intval'),
		array('source_user','banner_stats.click_user','intval'), array('user_id','banner_stats.click_user','plus_existing_author'),
		array('ip','banner_stats.click_ip','plus_registration_ip'), array('duration','banner_stats.user_duration','intval')));
}

function plus_banner_next_id()
{
	global $src_db, $convert, $db, $table_prefix;
	$r = $src_db->sql_query("SELECT AUTO_INCREMENT AS next_id FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" .
		$src_db->sql_escape($convert->src_table_prefix . 'banner') . "'");
	$next = (int) $src_db->sql_fetchfield('next_id');
	$src_db->sql_freeresult($r);
	\phpbb2plus\banners\service\management::reserve_legacy_ids($db,$table_prefix . 'plus_banner',$next);
}
