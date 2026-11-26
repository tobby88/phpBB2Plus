<?php
if (!defined('IN_PHPBB')) { exit; }

use phpbb2plus\downloads\service\access as plus_dl_access;
use phpbb2plus\downloads\service\client_family as plus_dl_client;
use phpbb2plus\downloads\service\search_text as plus_dl_search;

function plus_dl_layout()
{
	// Source and destination group keys identify complete resumable batches, including duplicate historical rows.
	$column = static function ($key) { return 'auth_' . $key; };
	$auth = array_map($column, plus_dl_access::ACTIONS);
	$category_auth = array_map($column, plus_dl_access::category_actions());
	return array(
		'config' => array('settings','config_name','config_name', '', 'config_name config_value'),
		'cat' => array('categories','cat_id','cat_id','cat_id','cat_id cat_name cat_desc cat_parent parents_data cat_order cat_allow_file cat_allow_ratings cat_allow_comments cat_files cat_last_file_id cat_last_file_name cat_last_file_time ' . implode(' ', $category_auth)),
		'auth' => array('grants','group_id','legacy_group','','group_id cat_id ' . implode(' ', $auth)),
		'license' => array('licenses','license_id','license_id','license_id','license_id license_name license_text'),
		'files' => array('files','file_id','file_id','file_id','file_id user_id poster_ip file_name file_size unique_name real_name file_dir file_desc file_creator file_version file_longdesc file_ssurl file_sshot_link file_dlurl file_time file_update_time file_catid file_posticon file_license file_dls file_last file_pin file_docsurl file_approved file_broken'),
		'mirrors' => array('mirrors','mirror_id','mirror_id','mirror_id','mirror_id file_id unique_name file_dir file_dlurl mirror_location'),
		'custom' => array('fields','custom_id','field_id','field_id','custom_id custom_name custom_description data field_order field_type regex'),
		'customdata' => array('field_values','customdata_file','file_id','','customdata_file customdata_custom data'),
		'comments' => array('comments','comments_id','comment_id','comment_id','comments_id file_id comments_text comments_title comments_time comment_bbcode_uid poster_id'),
		'votes' => array('votes','votes_file','file_id','','user_id votes_ip votes_file rate_point voter_os voter_browser browser_version'),
		'download_info' => array('visitors','file_id','file_id','','file_id user_id downloader_ip downloader_os downloader_browser browser_version'),
		'file_journal' => array('import_journal','operation_id','operation_id','','operation_id item_index schema_version operation_kind actor_id file_scope file_dir filename file_bytes file_sha256 binding_hash item_state created_at completed_at'),
	);
}

function plus_dl_json($value)
{
	// ASCII JSON also round-trips supplementary characters, serialized fields and literal entities on phpBB's MySQL driver.
	$json = json_encode($value);
	if ($json === false) { throw new \RuntimeException('Invalid UTF-8 in download metadata.'); }
	return $json;
}

function plus_dl_text($value) { return utf8_encode_ucr((string) $value); }

function plus_dl_options($raw)
{
	if ($raw === null || $raw === '') { return array(); }
	// The old data uses serialized flat scalar arrays. Never instantiate PHP objects.
	$raw = (string) $raw;
	if (strlen($raw) > 65535 || !preg_match('/^a:[0-9]+:\{/', $raw)) { return array(); }
	$values = @unserialize($raw, array('allowed_classes' => false, 'max_depth' => 4));
	if (!is_array($values)) { return array(); }
	foreach ($values as $v) { if (!is_scalar($v)) { return array(); } }
	return array_map('strval', array_values($values));
}

function plus_dl_settings()
{
	global $src_db, $convert;
	static $settings = null;
	if ($settings === null)
	{
		$settings = array();
		$result = $src_db->sql_query('SELECT config_name,config_value FROM ' . $convert->src_table_prefix . 'pa_config');
		while ($r = $src_db->sql_fetchrow($result)) { $settings[$r['config_name']] = $r['config_value']; }
		$src_db->sql_freeresult($result);
	}
	return $settings;
}

function plus_dl_url_name($value, $folder)
{
	if ($value === '') { return ''; }
	$parts = parse_url($value);
	if (!$parts || isset($parts['user']) || isset($parts['pass']) || (isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), array('http','https'), true))) { return ''; }
	if (isset($parts['host']))
	{
		$base = preg_replace('/^www\./i', '', (string) get_config_value('server_name'));
		if (strcasecmp(preg_replace('/^www\./i','',$parts['host']), $base) !== 0 ||
			(isset($parts['port']) && !in_array($parts['port'], array(80,443,(int) get_config_value('server_port')), true))) { return ''; }
	}
	$decoded = rawurldecode($parts['path'] ?? '');
	$path = ltrim($decoded, '/');
	$prefix = trim((string) get_config_value('script_path'), '/');
	if ($prefix !== '' && strpos($path, $prefix . '/') === 0) { $path = substr($path, strlen($prefix) + 1); }
	while (strpos($path, './') === 0) { $path = substr($path, 2); }
	$folder = trim($folder, '/') . '/';
	if (strpos($path, $folder) !== 0) { return ''; }
	$name = substr($path, strlen($folder));
	return $name !== '' && !preg_match('~[\\\\/%:\x00-\x1f\x7f]~', $name) && $name !== '.' && $name !== '..' ? $name : '';
}

function plus_dl_asset($kind, $id, $row, $preview = false)
{
	global $config, $convert, $phpbb_root_path;
	$settings = plus_dl_settings();
	$url = (string) ($preview ? $row['file_ssurl'] : $row['file_dlurl']);
	$folder = $preview ? ($settings['screenshots_dir'] ?? 'pafiledb/images/screenshots/') : ($settings['upload_dir'] ?? 'pafiledb/uploads/');
	$folder = trim($folder, '/');
	while (strpos($folder, './') === 0) { $folder = substr($folder, 2); }
	$name = $url !== '' ? plus_dl_url_name($url, $folder) : '';
	if ($name === '' && $url !== '') { return array('asset_status' => plus_dl_access::external($url) !== '' ? 'remote' : 'missing'); }
	if (!$preview && $name === '')
	{
		$name = (string) $row['unique_name'];
		$stored = trim((string) $row['file_dir'], '/');
		while (strpos($stored, './') === 0) { $stored = substr($stored, 2); }
		// Historical folders may live under the configured upload directory. Other locations need operator review.
		if ($stored !== '' && $stored !== $folder && strpos($stored, $folder . '/') !== 0) { return array('asset_status' => 'review'); }
		if ($stored !== '') { $folder = $stored; }
	}
	if ($name === '' || $folder === '' || $name[0] === '.' || preg_match('~[\\\\/:\x00-\x1f\x7f]~', $name)) { return array('asset_status' => 'missing'); }
	$base = $convert->options['forum_path'];
	if (!is_dir($base . '/' . $folder)) { return array('asset_status' => 'missing'); }
	$dir = plus_media_path($base, $folder, true);
	if (!is_file($dir . '/' . $name) || is_link($dir . '/' . $name)) { return array('asset_status' => 'missing'); }
	$from = plus_media_path($dir, $name);
	$mime = '';
	if ($preview)
	{
		$image = @getimagesize($from);
		if (!$image || !in_array($image['mime'], array('image/png','image/jpeg','image/gif','image/webp'), true)) { return array('asset_status' => 'missing'); }
		$mime = $image['mime'];
	}
	$to = plus_media_path($phpbb_root_path, trim($config['upload_path'], '/'), true);
	if ($to === $dir) { throw new \RuntimeException('Use a separate offline downloads snapshot.'); }
	$storage = 'download_' . hash_hmac('sha256', $kind . ':' . $id, $config['plus_download_media_key']);
	plus_copy_media($from, $to . '/' . $storage);
	return array('storage_name' => $storage, 'file_bytes' => filesize($from), 'asset_status' => 'ready', 'mime' => $mime);
}

function plus_dl_subjects($group)
{
	global $src_db, $convert;
	$p = $convert->src_table_prefix;
	$result = $src_db->sql_query('SELECT g.group_id,g.group_single_user,ug.user_id FROM ' . $p . 'groups g LEFT JOIN ' .
		$p . "user_group ug ON ug.group_id=g.group_id AND g.group_single_user=1 AND ug.user_pending=0 WHERE g.group_id=" . (int) $group);
	$rows = array();
	while ($r = $src_db->sql_fetchrow($result))
	{
		$personal = (bool) $r['group_single_user'];
		$id = $personal ? plus_existing_author($r['user_id']) : (int) $r['group_id'];
		if ($id && (!$personal || $id > ANONYMOUS)) { $rows[$id] = array('subject_type' => $personal ? 'u' : 'g', 'subject_id' => $id); }
	}
	$src_db->sql_freeresult($result);
	return $rows ?: array(array('subject_type' => 'x', 'subject_id' => 0));
}

function plus_dl_rows($short, $r, $subjects)
{
	global $convert, $src_db, $table_prefix, $db;
	if ($short === 'config') { return array(array('config_name' => $r['config_name'], 'config_value' => plus_dl_text($r['config_value']))); }
	$source = array('source_json' => plus_dl_json($r));
	$id = static function ($value) { $user = plus_existing_author($value); return $user === ANONYMOUS ? 0 : $user; };
	switch ($short)
	{
		case 'cat':
			$levels = array();
			foreach (plus_dl_access::ACTIONS as $action) { if (isset($r['auth_' . $action])) { $levels[$action] = (int) $r['auth_' . $action]; } }
			$data = array('cat_id'=>$r['cat_id'], 'parent_id'=>(int) $r['cat_parent'], 'position'=>(int) $r['cat_order'],
				'title'=>plus_dl_text($r['cat_name']), 'summary'=>plus_dl_text($r['cat_desc']), 'allow_files'=>(int) $r['cat_allow_file'] === 1,
				'allow_ratings'=>(bool) $r['cat_allow_ratings'], 'allow_comments'=>(bool) $r['cat_allow_comments'], 'access_levels'=>plus_dl_json($levels));
			break;
		case 'auth':
			$allowed = array();
			foreach (plus_dl_access::ACTIONS as $action) { if (!empty($r['auth_' . $action])) { $allowed[] = $action; } }
			$rows = array();
			foreach ($subjects as $subject) { $rows[] = $subject + $source + array('cat_id'=>$r['cat_id'], 'legacy_group'=>$r['group_id'], 'allowed'=>plus_dl_json($allowed)); }
			return $rows;
		case 'license': $data = array('license_id'=>$r['license_id'], 'title'=>plus_dl_text($r['license_name']), 'terms'=>plus_dl_text($r['license_text'])); break;
		case 'files':
			$data = array('file_id'=>$r['file_id'], 'cat_id'=>(int) $r['file_catid'], 'owner_id'=>$id($r['user_id']), 'license_id'=>(int) $r['file_license'],
				'title'=>plus_dl_text($r['file_name']), 'summary'=>plus_dl_text($r['file_desc']), 'added_at'=>(int) $r['file_time'], 'updated_at'=>(int) $r['file_update_time'],
				'last_download'=>(int) $r['file_last'], 'downloads'=>max(0,(int) $r['file_dls']), 'pinned'=>(bool) $r['file_pin'],
				'approved'=>(int) $r['file_approved'] === 1, 'broken'=>(bool) $r['file_broken']);
			$media = plus_dl_asset('file', $r['file_id'], $r);
			$data += \phpbb2plus\downloads\service\file_display::imported($r);
			$data += plus_dl_search::file($data,$r);
			unset($media['mime']);
			$data += $media;
			if (!empty($r['file_ssurl']))
			{
				$preview = plus_dl_asset('preview', $r['file_id'], $r, true);
				if ($preview['asset_status'] === 'ready') { $data += array('preview_name'=>$preview['storage_name'], 'preview_type'=>$preview['mime']); }
				elseif ($preview['asset_status'] !== 'remote') { $data['preview_unavailable'] = 1; }
			}
			break;
		case 'mirrors':
			$data = array('mirror_id'=>$r['mirror_id'], 'file_id'=>$r['file_id'], 'location'=>plus_dl_text($r['mirror_location'])) + plus_dl_asset('mirror', $r['mirror_id'], $r);
			unset($data['mime']);
			break;
		case 'custom':
			$data = array('field_id'=>$r['custom_id'], 'position'=>$r['field_order'], 'field_type'=>$r['field_type'], 'title'=>plus_dl_text($r['custom_name']),
				'description'=>plus_dl_text($r['custom_description']), 'options_json'=>plus_dl_json(plus_dl_options($r['data'])));
			break;
		case 'customdata':
			$result = $db->sql_query('SELECT field_type FROM ' . $table_prefix . 'plus_dl_fields WHERE field_id=' . (int) $r['customdata_custom']);
			$type = (int) $db->sql_fetchfield('field_type');
			$db->sql_freeresult($result);
			$values = in_array($type, array(4,5), true) ? plus_dl_options($r['data']) : array($r['data']);
			$data = array('file_id'=>$r['customdata_file'], 'field_id'=>$r['customdata_custom'], 'values_json'=>plus_dl_json($values));
			break;
		case 'comments':
			$uid = (string) $r['comment_bbcode_uid'];
			if ($uid !== '' && !preg_match('/^[a-z0-9]{1,10}$/iD', $uid)) { throw new \RuntimeException('Invalid download comment BBCode identifier.'); }
			$convert->row['post_id'] = (int) $r['comments_id'];
			$convert->row['post_time'] = (int) $r['comments_time'];
			$convert->row['old_bbcode_uid'] = $uid;
			$data = array('comment_id'=>$r['comments_id'], 'file_id'=>$r['file_id'], 'owner_id'=>$id($r['poster_id']), 'posted_at'=>$r['comments_time'],
				'title'=>plus_dl_text($r['comments_title']), 'body'=>plus_convert_message($r['comments_text']));
			$data += plus_dl_search::comment($data);
			break;
		case 'votes': $data = array('file_id'=>$r['votes_file'], 'owner_id'=>$id($r['user_id']), 'ip_address'=>plus_registration_ip($r['votes_ip']),
			'points'=>(int) $r['rate_point']) + plus_dl_client::imported($r,'voter'); break;
		case 'download_info': $data = array('file_id'=>$r['file_id'], 'owner_id'=>$id($r['user_id']),
			'ip_address'=>plus_registration_ip($r['downloader_ip'])) + plus_dl_client::imported($r,'downloader'); break;
		case 'file_journal': $data = array('operation_id'=>$r['operation_id'], 'item_index'=>$r['item_index'], 'item_state'=>$r['item_state']); break;
		default: throw new \LogicException('Unknown download conversion table.');
	}
	return array($data + $source);
}

function plus_dl_group($short, $value)
{
	global $src_db, $db, $convert, $table_prefix;
	list($dest,$source_key,$target_key,,$columns) = plus_dl_layout()[$short];
	$source = $convert->src_table_prefix . 'pa_' . $short;
	$target = $table_prefix . 'plus_dl_' . $dest;
	$subjects = $short === 'auth' ? plus_dl_subjects($value) : array();
	$result = $src_db->sql_query('SELECT ' . str_replace(' ', ',', $columns) . " FROM $source WHERE $source_key=" . $src_db->_sql_validate_value($value));
	$db->sql_transaction('begin');
	try
	{
		$db->sql_query("DELETE FROM $target WHERE $target_key=" . $db->_sql_validate_value($value));
		$batch = array();
		while ($r = $src_db->sql_fetchrow($result))
		{
			foreach (plus_dl_rows($short, $r, $subjects) as $row)
			{
				$batch[] = $row;
				if (count($batch) === 64) { $db->sql_multi_insert($target, $batch); $batch = array(); }
			}
		}
		if ($batch) { $db->sql_multi_insert($target, $batch); }
		$db->sql_transaction('commit');
	}
	catch (\Throwable $e) { $db->sql_transaction('rollback'); throw $e; }
	finally { $src_db->sql_freeresult($result); }
}

function plus_dl_mail_policy()
{
	global $src_db,$db,$convert,$table_prefix;
	$source = $convert->src_table_prefix . 'ctracker_config';
	$tools = (new \phpbb\db\tools\factory())->get($src_db);
	$target = $table_prefix . 'plus_dl_settings';
	$values = array('massmail_protection'=>'1','massmail_time'=>'20');
	if (in_array($source,$tools->sql_list_tables(),true))
	{
		// An incomplete installed policy used to prevent delivery and can be repaired in the ACP.
		$values = array_fill_keys(array_keys($values),'');
		$result = $src_db->sql_query("SELECT ct_config_name,ct_config_value FROM $source WHERE ct_config_name IN ('massmail_protection','massmail_time')");
		try { while ($row = $src_db->sql_fetchrow($result)) { $values[$row['ct_config_name']]=$row['ct_config_value']; } }
		finally { $src_db->sql_freeresult($result); }
	}
	$db->sql_transaction('begin');
	try
	{
		foreach ($values as $name=>$value)
		{
			$db->sql_query("DELETE FROM $target WHERE config_name='" . $db->sql_escape($name) . "'");
			$db->sql_query("INSERT INTO $target " . $db->sql_build_array('INSERT',array('config_name'=>$name,'config_value'=>$value)));
		}
		$db->sql_transaction('commit');
	}
	catch (\Throwable $e) { $db->sql_transaction('rollback'); throw $e; }
}

function plus_dl_schema($prefix, $truncate)
{
	$sections = array();
	foreach (plus_dl_layout() as $short => $mapping)
	{
		list($dest,$source_key,,$primary) = $mapping;
		$target = $prefix . 'plus_dl_' . $dest;
		$column = 'pa_' . $short . '.' . $source_key;
		$s = array('target' => $target, 'group_by' => $column, 'order_by' => $column, 'query_first' => array('target', $truncate . $target),
			array('', array($short, $column), 'plus_dl_group'));
		// pa_config has been imported. The recommendation cooldown lived in CrackerTracker.
		if ($short === 'cat') { $s['execute_first']='plus_dl_mail_policy();'; }
		if ($short === 'files')
		{
			$s['query_first'] = array($s['query_first'], array('target', $truncate . $prefix . 'plus_dl_receipts'),
				array('target', $truncate . $prefix . 'plus_dl_searches'), array('target',$truncate . $prefix . 'plus_dl_mail_receipts'));
		}
		if ($primary) { $s['autoincrement'] = $primary; }
		$sections[] = $s;
	}
	return $sections;
}
