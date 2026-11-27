<?php
if (!defined('IN_PHPBB')) { exit; }

function plus_daily_last_active($session_time, $last_visit)
{
	return (int) $session_time > 0 ? (int) $session_time : max(0, (int) $last_visit);
}

function plus_portal_image($url, $index)
{
	$parts = parse_url($url);
	if (!$parts || empty($parts['path']) || isset($parts['user']) || isset($parts['pass'])) { return $url; }
	if (isset($parts['host']) && strcasecmp($parts['host'],trim((string) get_config_value('server_name'))) !== 0) { return $url; }
	if (isset($parts['scheme']) && !in_array(strtolower($parts['scheme']),array('http','https'),true)) { return ''; }
	$path = rawurldecode($parts['path']);
	$board_path = '/' . trim((string) get_config_value('script_path'),'/');
	if ($board_path !== '/' && strpos($path,$board_path . '/') === 0) { $path = substr($path,strlen($board_path)); }
	if (strpos($path,'./') === 0) { $path = substr($path,2); }
	$path = ltrim($path,'/');
	if (in_array($path,array('album_pic.php','album_thumbnail.php','album_picm.php'),true))
	{
		return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
	}
	$directory = dirname($path);
	$bitmap = plus_profile_image($directory === '.' ? '' : $directory,basename($path),true);
	if (!$bitmap) { plus_profile_media_issue('portal',$index,$url,'unavailable'); return ''; }
	return 'images/plusportal/' . plus_public_bitmap($bitmap,'plusportal');
}

function plus_portal_settings()
{
	global $src_db, $convert, $phpbb_container, $config;
	$p = $convert->src_table_prefix;
	$text = $phpbb_container->get('config_text');
	$result = $src_db->sql_query("SELECT portal_name,portal_value FROM {$p}portal");
	while ($row = $src_db->sql_fetchrow($result))
	{
		$value = $row['portal_value'];
		switch ($row['portal_name'])
		{
			case 'welcome_text':
				$welcome = \phpbb2plus\community\service\welcome::migrate_images($value,'plus_portal_image');
				$text->set_array(\phpbb2plus\community\service\welcome::storage($welcome));
				break;
			case 'exceptional_forums':
			case 'poll_forum':
			case 'cat_id':
				$ids = array_filter(array_map('trim',explode(',',$value)),static function ($id) { return preg_match('/^[1-9][0-9]{0,8}$/D',$id); });
				$key = array('cat_id'=>'picture_categories','poll_forum'=>'poll_forums','exceptional_forums'=>'exclude');
				$text->set('plus_portal_' . $key[$row['portal_name']],implode(',',array_unique($ids)));
				break;
			case 'number_recent_files': $config->set('plus_portal_downloads',max(0,min(100,(int) $value))); break;
			case 'pics_number': $config->set('plus_portal_pictures',max(0,min(100,(int) $value))); break;
			case 'pics_thumbsize': $config->set('plus_portal_picture_size',max(1,min(1024,(int) $value))); break;
			case 'pics_all': $config->set('plus_portal_picture_personal',(int) (bool) $value); break;
			case 'pics_sort': $config->set('plus_portal_picture_random',(int) ($value === '1')); break;
			case 'number_recent_topics': $config->set('plus_portal_recent',max(0,min(100,(int) $value))); break;
			case 'last_seen': $config->set('plus_portal_last_seen',max(0,min(100,(int) $value))); break;
			case 'number_top_posters': $config->set('plus_portal_top_posters',max(0,min(100,(int) $value))); break;
		}
	}
	$src_db->sql_freeresult($result);
	$index_logos = false;
	$result = $src_db->sql_query("SELECT config_name,config_value FROM {$p}plus WHERE config_name IN ('show_shoutbox','show_boardstats','enable_banners','show_links','show_last_visit')");
	while ($r = $src_db->sql_fetchrow($result))
	{
		if ($r['config_name'] === 'show_shoutbox')
		{
			$config->set('plus_portal_shouts', \phpbb2plus\community\service\shoutbox::converted_placement($r['config_value']));
		}
		elseif ($r['config_name'] === 'show_last_visit') { $config->set('plus_daily_mode', in_array($r['config_value'], array('1', '2'), true) ? (int) $r['config_value'] : 0); }
		elseif ($r['config_name'] === 'enable_banners') { $config->set('plus_banners_enabled',(int) (bool) $r['config_value']); }
		elseif ($r['config_name'] === 'show_links') { $index_logos = !empty($r['config_value']); }
		else { $config->set('plus_portal_stats',(int) (bool) $r['config_value']); }
	}
	$src_db->sql_freeresult($result);
	$config->set('plus_wl_index_logos',(int) $index_logos);
	$result = $src_db->sql_query("SELECT config_name,config_value FROM {$p}config WHERE config_name IN ('display_viewonline','display_viewonline_over')");
	while ($r = $src_db->sql_fetchrow($result))
	{
		if ($r['config_name'] === 'display_viewonline_over') { $config->set('plus_daily_force', (int) (bool) $r['config_value']); }
		else { $config->set('plus_daily_scope', in_array($r['config_value'], array('1', '2'), true) ? (int) $r['config_value'] : 0); }
	}
	$src_db->sql_freeresult($result);
}
