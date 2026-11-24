<?php
if (!defined('IN_PHPBB')) { exit; }

function plus_gallery_text($value)
{
	return html_entity_decode((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function plus_gallery_owner($id)
{
	return (int) $id > 0 ? plus_existing_author($id) : 0;
}

function plus_gallery_preflight()
{
	global $src_db, $convert, $config, $phpbb_root_path;
	$result = $src_db->sql_query_limit('SELECT operation_kind FROM ' . $convert->src_table_prefix . "album_file_jobs
		WHERE operation_state NOT IN ('published','complete','abandoned')", 1);
	$job = $src_db->sql_fetchfield('operation_kind');
	$src_db->sql_freeresult($result);
	if ($job !== false)
	{
		throw new \RuntimeException('The source album has unfinished file operations. Finish recovery on the offline source before converting its media.');
	}
	$result = $src_db->sql_query('SELECT config_value FROM ' . $convert->src_table_prefix . "album_sp_config WHERE config_name='use_watermark'");
	$uses_mark = (int) $src_db->sql_fetchfield('config_value') === 1;
	$src_db->sql_freeresult($result);
	if ($uses_mark || is_file($convert->options['forum_path'] . '/album_mod/mark.png'))
	{
		$mark = plus_media_path($convert->options['forum_path'], 'album_mod/mark.png');
		$info = @getimagesize($mark);
		if (!$info || $info[2] !== IMAGETYPE_PNG) { throw new \RuntimeException('The gallery watermark is not a readable PNG.'); }
		$dest = plus_media_path($phpbb_root_path, trim($config['upload_path'], '/'), true);
		plus_copy_media($mark, $dest . '/gallery_mark_' . hash_hmac('sha256', 'watermark', $config['plus_gallery_media_key']));
	}
}

function plus_gallery_grants($category, $policies)
{
	global $src_db, $db, $convert, $table_prefix;
	$capabilities = array();
	foreach ($policies as $action => $list)
	{
		foreach (preg_split('/[\s,]+/', trim((string) $list), -1, PREG_SPLIT_NO_EMPTY) as $value)
		{
			if (ctype_digit($value) && (int) $value > 0) { $capabilities[(int) $value][$action] = true; }
		}
	}
	$table = $table_prefix . 'plus_album_grants';
	$grants = array();
	if ($capabilities)
	{
		$p = $convert->src_table_prefix;
		$result = $src_db->sql_query("SELECT g.group_id,g.group_single_user,ug.user_id FROM {$p}groups g
			LEFT JOIN {$p}user_group ug ON ug.group_id=g.group_id AND g.group_single_user=1 AND ug.user_pending=0
			WHERE " . $src_db->sql_in_set('g.group_id', array_keys($capabilities)));
		$groups = $src_db->sql_fetchrowset($result);
		$src_db->sql_freeresult($result);
		foreach ($groups as $group)
		{
			$single = (int) $group['group_single_user'] === 1;
			$id = $single ? plus_existing_author($group['user_id']) : (int) $group['group_id'];
			if ($single && $id === ANONYMOUS) { continue; }
			$type = $single ? 'u' : 'g';
			foreach (array_keys($capabilities[$group['group_id']]) as $action)
			{
				$grants[$type . '/' . $id . '/' . $action] = array('cat_id' => $category, 'capability' => $action,
					'subject_type' => $type, 'subject_id' => $id);
			}
		}
	}
	$db->sql_transaction('begin');
	$db->sql_query("DELETE FROM $table WHERE cat_id=" . (int) $category);
	if ($grants) { $db->sql_multi_insert($table, array_values($grants)); }
	$db->sql_transaction('commit');
}

function plus_gallery_category_grants($id)
{
	global $convert;
	$policies = array();
	foreach (array('view','upload','rate','comment','edit','delete','moderator') as $action)
	{
		$policies[$action] = $convert->row['cat_' . $action . '_groups'];
	}
	plus_gallery_grants((int) $id, $policies);
}

function plus_gallery_category($cat, $owner)
{
	global $db, $config, $table_prefix;
	static $known = array(), $personal = array();
	$cat = (int) $cat;
	$table = $table_prefix . 'plus_album_cats';
	if ($cat > 0)
	{
		if (!isset($known[$cat]))
		{
			$result = $db->sql_query("SELECT cat_id FROM $table WHERE cat_id=$cat");
			$exists = $db->sql_fetchfield('cat_id') !== false;
			$db->sql_freeresult($result);
			if (!$exists) { throw new \RuntimeException('Missing source album category ' . $cat); }
			$known[$cat] = true;
		}
		return $cat;
	}
	// Early personal albums stored pic_cat_id=0. Resolve each owner's original or
	// create a distinct root using that board's actual personal-gallery policy.
	$source_owner = max(0, (int) $owner);
	if (isset($personal[$source_owner])) { return $personal[$source_owner]; }
	$owner_id = plus_existing_author($source_owner);
	$result = $db->sql_query_limit("SELECT cat_id FROM $table WHERE source_owner=$source_owner AND owner_id=$owner_id AND parent_id=0 ORDER BY cat_id", 1);
	$id = $db->sql_fetchfield('cat_id');
	$db->sql_freeresult($result);
	if ($id === false)
	{
		$result = $db->sql_query('SELECT setting_name,setting_value FROM ' . $table_prefix . "plus_album_settings WHERE scope='album'");
		$settings = array();
		while ($row = $db->sql_fetchrow($result)) { $settings[$row['setting_name']] = $row['setting_value']; }
		$db->sql_freeresult($result);
		$missing = $owner_id === ANONYMOUS;
		$view = $missing ? 1 : (int) ($settings['personal_gallery_view'] ?? 1);
		$data = array('source_owner' => $source_owner, 'owner_id' => $owner_id, 'parent_id' => 0,
			'title' => $config['default_lang'] === 'de' ? 'Persönliches Album' : 'Personal album',
			'description' => '', 'view_level' => $view, 'upload_level' => $missing ? 1 : 3,
			'rate_level' => $view, 'comment_level' => $view, 'edit_level' => 3, 'delete_level' => 3, 'approval' => 0);
		$db->sql_transaction('begin');
		$db->sql_query("INSERT INTO $table " . $db->sql_build_array('INSERT', $data));
		$id = (int) $db->sql_nextid();
		$groups = $missing ? '' : ($settings['personal_gallery_private'] ?? '');
		plus_gallery_grants($id, array('view' => $groups, 'rate' => $groups, 'comment' => $groups));
		$db->sql_transaction('commit');
	}
	return $personal[$source_owner] = (int) $id;
}

function plus_gallery_file_field($id, $filename, $field)
{
	global $convert, $config, $phpbb_root_path;
	static $last = null, $info = array();
	$id = (int) $id;
	if ($last !== $id)
	{
		if (!$filename || strpbrk($filename, "/\\") !== false) { throw new \RuntimeException('Invalid source filename for picture ' . $id); }
		$uploads = plus_media_path($convert->options['forum_path'], 'album_mod/upload', true);
		$source = plus_media_path($uploads, $filename);
		$dimensions = @getimagesize($source);
		$types = array(IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_GIF => 'image/gif');
		if (!$dimensions || !isset($types[$dimensions[2]])) { throw new \RuntimeException('Unsupported or damaged image for picture ' . $id); }
		$target = plus_media_path($phpbb_root_path, trim($config['upload_path'], '/'), true);
		if (empty($config['plus_gallery_media_key']) || $target === $uploads) { throw new \RuntimeException('Use a separate initialized gallery destination.'); }
		$storage = 'gallery_' . hash_hmac('sha256', 'picture:' . $id, $config['plus_gallery_media_key']);
		plus_copy_media($source, $target . '/' . $storage);
		$info = array('storage_name' => $storage, 'media_type' => $types[$dimensions[2]], 'width' => $dimensions[0],
			'height' => $dimensions[1], 'bytes' => filesize($source));
		$last = $id;
	}
	return $info[$field];
}

function plus_gallery_schema($prefix, $truncate)
{
	$t = $prefix . 'plus_album_';
	$schema = array();
	foreach (array('album' => 'album_config', 'sp' => 'album_sp_config') as $scope => $source)
	{
		$section = array('target' => $t . 'settings', 'primary' => $source . '.config_name',
			array('scope', $scope, ''), array('setting_name', $source . '.config_name', ''), array('setting_value', $source . '.config_value', ''));
		if ($scope === 'album') { $section['execute_first'] = 'plus_gallery_preflight();'; $section['query_first'] = array('target', $truncate . $t . 'settings'); }
		$schema[] = $section;
	}
	$cats = array('target' => $t . 'cats', 'primary' => 'album_cat.cat_id', 'autoincrement' => 'cat_id',
		'query_first' => array(array('target',$truncate . $t . 'cats'),array('target',$truncate . $t . 'grants')),
		array('cat_id','album_cat.cat_id','intval'), array('parent_id','album_cat.cat_parent','intval'),
		array('owner_id','album_cat.cat_user_id','plus_gallery_owner'), array('source_owner','album_cat.cat_user_id','intval'),
		array('title','album_cat.cat_title','plus_gallery_text'), array('description','album_cat.cat_desc','plus_gallery_text'),
		array('sort_order','album_cat.cat_order','intval'), array('approval','album_cat.cat_approval','intval'));
	foreach (array('view','upload','rate','comment','edit','delete','moderator') as $action)
	{
		if ($action !== 'moderator') { $cats[] = array($action . '_level','album_cat.cat_' . $action . '_level','intval'); }
		$cats[] = array('', 'album_cat.cat_' . $action . '_groups','');
	}
	$cats[] = array('', 'album_cat.cat_id', 'plus_gallery_category_grants');
	$schema[] = $cats;
	$pictures = array('target' => $t . 'pics', 'primary' => 'album.pic_id', 'autoincrement' => 'pic_id',
		'query_first' => array('target', $truncate . $t . 'pics'),
		array('pic_id','album.pic_id','intval'), array('cat_id',array('album.pic_cat_id','album.pic_user_id'),'plus_gallery_category'),
		array('author_id','album.pic_user_id','plus_existing_author'), array('author_name','album.pic_username','plus_gallery_text'),
		array('author_removed',array('album.pic_user_id','album.pic_username'),'plus_removed_author'),
		array('author_ip','album.pic_user_ip','plus_registration_ip'), array('title','album.pic_title','plus_gallery_text'),
		array('description','album.pic_desc','plus_gallery_text'), array('created_at','album.pic_time','intval'),
		array('views','album.pic_view_count','intval'), array('locked','album.pic_lock','intval'), array('approved','album.pic_approval','intval'),
		array('source_filename','album.pic_filename',''));
	foreach (array('storage_name','media_type','width','height','bytes') as $field)
	{
		$pictures[] = array($field, array('album.pic_id','album.pic_filename',$field), 'plus_gallery_file_field');
	}
	$schema[] = $pictures;
	$schema[] = array('target' => $t . 'comments', 'primary' => 'album_comment.comment_id', 'autoincrement' => 'comment_id',
		'query_first' => array('target', $truncate . $t . 'comments'),
		array('comment_id','album_comment.comment_id','intval'), array('pic_id','album_comment.comment_pic_id','intval'),
		array('cat_id',array('album.pic_cat_id','album.pic_user_id'),'plus_gallery_category'),
		array('author_id','album_comment.comment_user_id','plus_existing_author'), array('author_name','album_comment.comment_username','plus_gallery_text'),
		array('author_removed',array('album_comment.comment_user_id','album_comment.comment_username'),'plus_removed_author'),
		array('author_ip','album_comment.comment_user_ip','plus_registration_ip'), array('body','album_comment.comment_text','plus_gallery_text'),
		array('created_at','album_comment.comment_time','intval'), array('edited_at','album_comment.comment_edit_time','intval'),
		array('edit_count','album_comment.comment_edit_count','intval'), array('editor_id','album_comment.comment_edit_user_id','plus_gallery_owner'),
		'where' => 'album_comment.comment_pic_id=album.pic_id');
	$schema[] = array('target' => $t . 'ratings', 'order_by' => 'album_rate.rate_pic_id, album_rate.rate_user_id, album_rate.rate_user_ip, album_rate.rate_point, album_rate.rate_hon_point',
		'query_first' => array('target', $truncate . $t . 'ratings'),
		array('pic_id','album_rate.rate_pic_id','intval'), array('user_id','album_rate.rate_user_id','plus_existing_author'),
		array('user_ip','album_rate.rate_user_ip','plus_registration_ip'), array('points','album_rate.rate_point','intval'),
		array('honour_points','album_rate.rate_hon_point','intval'), 'where' => 'album_rate.rate_pic_id=album.pic_id');
	return $schema;
}
