<?php
if (!defined('IN_PHPBB')) { exit; }

use phpbb2plus\weblinks\service\directory as plus_weblink_directory;

function plus_weblink_source($database, $prefix)
{
	$tools = (new \phpbb\db\tools\factory())->get($database);
	$present = 0;
	foreach (array('links','link_categories','link_config') as $name)
	{
		if ($tools->sql_table_exists($prefix . $name)) { $present++; }
	}
	if ($present && $present !== 3) { throw new \RuntimeException('The source website directory is missing a table. Restore the directory tables before converting.'); }
	return $present === 3;
}

function plus_weblink_text($value)
{
	return plus_weblink_directory::encode(html_entity_decode((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
}

function plus_weblink_logo($id, $reference)
{
	global $convert, $phpbb_root_path, $config;
	$url = html_entity_decode(trim((string) $reference),ENT_QUOTES,'UTF-8');
	if ($url === '') { return ''; }
	$parts = parse_url($url);
	if (!$parts || isset($parts['user']) || isset($parts['pass'])) { return ''; }
	$scheme = strtolower($parts['scheme'] ?? '');
	if ($scheme && !in_array($scheme,array('http','https'),true)) { return ''; }
	if (isset($parts['host']) && strcasecmp($parts['host'],(string) get_config_value('server_name')) !== 0) { return ''; }
	$file = rawurldecode($parts['path'] ?? '');
	$board = '/' . trim((string) get_config_value('script_path'),'/');
	if ($board !== '/' && strpos($file,$board . '/') === 0) { $file = substr($file,strlen($board)); }
	if (strpos($file,'./') === 0) { $file = substr($file,2); }
	$file = ltrim($file,'/');
	$ext = strtolower(pathinfo($file,PATHINFO_EXTENSION));
	$types = array('gif'=>'image/gif','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp');
	try { $path = plus_media_path($convert->options['forum_path'],$file); }
	catch (\RuntimeException $e) { $path = ''; }
	$info = $path && isset($types[$ext]) ? @getimagesize($path) : false;
	if (!$info || $info['mime'] !== $types[$ext])
	{
		plus_profile_media_issue('weblink-logo',$id,$reference,'unavailable');
		return 'unavailable';
	}
	$hash = hash_file('sha256',$path);
	if ($hash === false) { throw new \RuntimeException('Cannot read a website directory logo.'); }
	$name = 'weblink_' . $hash . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
	$target = plus_media_path($phpbb_root_path,trim($config['upload_path'],'/'),true);
	plus_copy_media($path,$target . '/' . $name);
	return $name;
}

function plus_weblink_schema($prefix, $truncate)
{
	$p = $prefix . 'plus_wl_';
	return array(array('target'=>$p . 'categories','primary'=>'link_categories.cat_id','autoincrement'=>'cat_id',
		'query_first'=>array('target',$truncate . $p . 'categories'),
		array('cat_id','link_categories.cat_id','intval'),array('title','link_categories.cat_title','plus_weblink_text'),
		array('position','link_categories.cat_order','intval')),
		array('target'=>$p . 'entries','primary'=>'links.link_id','autoincrement'=>'link_id',
		'query_first'=>array('target',$truncate . $p . 'entries'),
		array('link_id','links.link_id','intval'),array('cat_id','links.link_category','intval'),
		array('title','links.link_title','plus_weblink_text'),array('description','links.link_desc','plus_weblink_text'),
		array('destination','links.link_url','plus_weblink_text'),array('logo','links.link_logo_src','plus_weblink_text'),
		array('local_logo',array('links.link_id','links.link_logo_src'),'plus_weblink_logo'),
		array('joined','links.link_joined','intval'),array('status','links.link_active','plus_weblink_state'),
		array('hits','links.link_hits','strval'),array('user_id','links.user_id','plus_existing_author'),array('source_user','links.user_id','intval'),
		array('submitter_ip','links.user_ip','plus_registration_ip'),array('last_ip','links.last_user_ip','plus_registration_ip')));
}

function plus_weblink_state($value) { return (string) $value === '1' ? 1 : 0; }

function plus_weblink_settings()
{
	global $convert,$src_db,$db,$table_prefix,$phpbb_container,$config;
	$source = $convert->src_table_prefix;
	$result = $src_db->sql_query("SELECT config_name,config_value FROM {$source}link_config ORDER BY HEX(config_name),HEX(config_value)");
	$rows = $src_db->sql_fetchrowset($result);
	$src_db->sql_freeresult($result);
	// Retain original spellings, unknown preferences and duplicates for administrators reviewing the conversion.
	$phpbb_container->get('config_text')->set('plus_wl_source_settings',json_encode($rows));
	$values = plus_weblink_directory::DEFAULTS;
	$seen = array();
	$valid = true;
	$ranges = array('linkspp'=>array(1,100),'width'=>array(1,1000),'height'=>array(1,1000),
		'display_interval'=>array(250,2147483647),'display_logo_num'=>array(1,1000));
	foreach ($rows as $row)
	{
		$key = strtolower(rtrim($row['config_name'],' '));
		if (!array_key_exists($key,$values)) { continue; }
		$value = $row['config_value'];
		$ok = !isset($seen[$key]);
		if (isset($ranges[$key]))
		{
			$ok = $ok && ctype_digit(trim($value)) && (int) $value >= $ranges[$key][0] && (int) $value <= $ranges[$key][1];
			$value = (int) $value;
		}
		elseif ($key !== 'site_url' && $key !== 'site_logo') { $ok = $ok && in_array($value,array('0','1'),true); }
		elseif ($key === 'site_url') { $value = plus_weblink_directory::external(html_entity_decode($value,ENT_QUOTES,'UTF-8')); }
		$seen[$key] = true;
		$values[$key] = $ok ? $value : ($key === 'lock_submit_site' ? 1 : plus_weblink_directory::DEFAULTS[$key]);
		if (!$ok && in_array($key,array('lock_submit_site','allow_no_logo','email_notify','pm_notify'),true)) { $valid = false; }
	}
	// phpBB2 falls back to the packaged board button when no site logo was set.
	$logo = $values['site_logo'] ?: 'images/links/web_logo88a.gif';
	$config->set('plus_wl_local_logo',plus_weblink_logo(0,$logo));
	$config->set('plus_wl_inputs_valid',(int) $valid);
	foreach ($values as $k=>$value) { $config->set('plus_wl_' . $k,is_string($value) ? plus_weblink_text($value) : $value); }
	foreach (array('links'=>'entries','link_categories'=>'categories') as $old=>$new)
	{
		$result = $src_db->sql_query("SELECT AUTO_INCREMENT AS next_id FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" .
			$src_db->sql_escape($source . $old) . "'");
		$next = (int) $src_db->sql_fetchfield('next_id');
		$src_db->sql_freeresult($result);
		if ($next>1) { $db->sql_query('ALTER TABLE ' . $table_prefix . 'plus_wl_' . $new . ' AUTO_INCREMENT=' . $next); }
	}
}
