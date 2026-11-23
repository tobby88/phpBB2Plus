<?php
// Convert the pre-hierarchy category-zero collection of a known account.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(2); }
require_once __DIR__.'/innodb_migration.php';

function plus_legacy_album_tables($prefix)
{
	if (!is_string($prefix) || !preg_match('/^[A-Za-z0-9_]{1,40}$/D', $prefix))
	{
		throw new RuntimeException('Invalid Album namespace');
	}
	return array('pictures'=>$prefix.'album', 'categories'=>$prefix.'album_cat',
		'comments'=>$prefix.'album_comment', 'settings'=>$prefix.'album_config', 'users'=>$prefix.'users');
}

function plus_legacy_album_groups($value)
{
	$ids = array();
	foreach (preg_split('/[\s,]+/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY) as $part)
	{
		if (!preg_match('/^[0-9]{1,8}$/D', $part) || (int) $part > 16777215)
		{
			return null;
		}
		if ((int) $part > 0) { $ids[(int) $part] = (int) $part; }
	}
	ksort($ids, SORT_NUMERIC);
	return implode(',', $ids);
}

function plus_legacy_album_plan($db, $prefix)
{
	if (mysqli_character_set_name($db) !== 'utf8mb4') { throw new RuntimeException('Album migration requires utf8mb4'); }
	$names = plus_legacy_album_tables($prefix);
	if (plus_storage_metadata($db, $names['pictures']) === null)
	{
		return array('installed'=>false, 'owners'=>array(), 'pictures'=>0, 'skipped'=>array(), 'policy'=>null);
	}
	$t = array_map('plus_storage_identifier', $names);
	$plan = array('installed'=>true, 'owners'=>array(), 'pictures'=>0, 'skipped'=>array(), 'policy'=>null);
	$collections = plus_storage_rows($db, 'SELECT pic_user_id, COUNT(*) AS pictures FROM '.$t['pictures'].
		' WHERE pic_cat_id=0 GROUP BY pic_user_id ORDER BY pic_user_id');
	if (!$collections) { return $plan; }
	foreach ($names as $table)
	{
		if (plus_storage_metadata($db, $table) === null)
		{
			throw new RuntimeException('Album migration requires all existing Album and account tables');
		}
	}
	$policy = null;
	$settings = array_column(plus_storage_rows($db, 'SELECT config_name,config_value FROM '.$t['settings'].
		" WHERE config_name IN ('personal_gallery_view','personal_gallery_private')"), 'config_value', 'config_name');
	if (isset($settings['personal_gallery_view']) && in_array((string) $settings['personal_gallery_view'], array('-1','0','3'), true))
	{
		// The old reader uses guest (-1), member (0), or private group (3).
		$view = (int) $settings['personal_gallery_view'];
		$groups = $view === 3 ? plus_legacy_album_groups(isset($settings['personal_gallery_private']) ? $settings['personal_gallery_private'] : '') : '';
		if ($groups !== null) { $policy = array('view'=>$view, 'groups'=>$groups); }
	}
	$plan['policy'] = $policy;

	foreach ($collections as $row)
	{
		$owner = (int) $row['pic_user_id'];
		$count = (int) $row['pictures'];
		$reason = null;
		$root = 0;
		if ($owner <= 0)
		{
			$reason = 'guest_or_unassigned_owner';
		}
		elseif (!$policy)
		{
			$reason = 'unknown_legacy_visibility';
		}
		elseif (!plus_storage_rows($db, 'SELECT user_id FROM '.$t['users'].' WHERE user_id='.$owner.' AND user_active=1'))
		{
			$reason = 'missing_or_inactive_account';
		}
		else
		{
			$roots = plus_storage_rows($db, 'SELECT cat_id, cat_view_level, cat_view_groups FROM '.$t['categories'].
				' WHERE cat_user_id='.$owner.' AND cat_parent=0 AND cat_id>0 ORDER BY cat_id LIMIT 2');
			if (count($roots) > 1 || (!$roots && plus_storage_rows($db, 'SELECT cat_id FROM '.$t['categories'].
				' WHERE cat_user_id='.$owner.' LIMIT 1')))
			{
				$reason = 'ambiguous_hierarchy';
			}
			elseif ($roots)
			{
				$current = $roots[0];
				if ((int) $current['cat_view_level'] !== $policy['view'] ||
					($policy['view'] === 3 && plus_legacy_album_groups($current['cat_view_groups']) !== $policy['groups']))
				{
					$reason = 'different_root_visibility';
				}
				else { $root = (int) $current['cat_id']; }
			}
		}
		if ($reason !== null)
		{
			if (!isset($plan['skipped'][$reason])) { $plan['skipped'][$reason] = 0; }
			$plan['skipped'][$reason] += $count;
			continue;
		}
		$plan['owners'][$owner] = array('pictures'=>$count, 'root'=>$root);
		$plan['pictures'] += $count;
	}
	return $plan;
}

function plus_legacy_album_print_plan($plan, $applied = false)
{
	if (!$applied)
	{
		echo 'Legacy personal Album pictures eligible: '.$plan['pictures'].' ('.count($plan['owners'])." galleries)\n";
	}
	$labels = array(
		'guest_or_unassigned_owner'=>'guest or unassigned owner',
		'unknown_legacy_visibility'=>'unknown legacy visibility',
		'missing_or_inactive_account'=>'missing or inactive account',
		'ambiguous_hierarchy'=>'ambiguous category hierarchy',
		'different_root_visibility'=>'different existing root visibility',
	);
	foreach ($plan['skipped'] as $reason => $pictures)
	{
		echo 'Left for manual review ('.$labels[$reason].'): '.$pictures." pictures\n";
	}
}

function plus_legacy_album_lock_tables($db, $names)
{
	$keys = array('settings'=>'config_name', 'users'=>'user_id', 'categories'=>'cat_id', 'pictures'=>'pic_id', 'comments'=>'comment_id');
	foreach ($keys as $kind => $key)
	{
		$name = $names[$kind];
		$table = plus_storage_identifier($name);
		$meta = plus_storage_metadata($db, $name);
		if (!$meta || $meta['ENGINE'] !== 'InnoDB' || strtolower($meta['ROW_FORMAT']) !== 'dynamic' ||
			$meta['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci')
		{
			throw new RuntimeException('Complete the canonical storage upgrade before moving Album pictures');
		}
		$primary = plus_storage_rows($db, 'SHOW INDEX FROM '.$table." WHERE Key_name='PRIMARY'");
		if (count($primary) !== 1 || $primary[0]['Column_name'] !== $key)
		{
			throw new RuntimeException('Album migration requires canonical primary keys');
		}
		$result = mysqli_query($db, 'SELECT '.plus_storage_identifier($key).' FROM '.$table.
			' ORDER BY '.plus_storage_identifier($key).' FOR UPDATE', MYSQLI_USE_RESULT);
		if (!$result) { throw new RuntimeException('Cannot lock Album migration participants'); }
		try { while (mysqli_fetch_row($result)) {} }
		finally { mysqli_free_result($result); }
		if (mysqli_errno($db)) { throw new RuntimeException('Album migration could not finish locking participants'); }
	}

	// The row locks keep this storage/side-effect check valid until commit.
	foreach (array('categories','pictures','comments') as $kind)
	{
		$name = $names[$kind];
		$hex = "'".strtoupper(bin2hex($name))."'";
		$triggers = plus_storage_rows($db, 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS'.
			' WHERE TRIGGER_SCHEMA=DATABASE() AND HEX(EVENT_OBJECT_TABLE)='.$hex.' LIMIT 1');
		$references = plus_storage_rows($db, 'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE'.
			' WHERE REFERENCED_TABLE_NAME IS NOT NULL AND ((TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)='.$hex.
			') OR (REFERENCED_TABLE_SCHEMA=DATABASE() AND HEX(REFERENCED_TABLE_NAME)='.$hex.')) LIMIT 1');
		if ($triggers || $references) { throw new RuntimeException('Custom Album triggers or references require manual review'); }
		foreach (plus_storage_rows($db, 'SHOW FULL COLUMNS FROM '.plus_storage_identifier($name)) as $column)
		{
			if (preg_match('/(?:VIRTUAL|STORED|PERSISTENT) GENERATED|GENERATED (?:VIRTUAL|STORED|PERSISTENT)/i', $column['Extra']) ||
				stripos($column['Extra'], 'on update') !== false)
			{
				throw new RuntimeException('Custom derived Album columns require manual review');
			}
		}
	}
}

function plus_legacy_album_add_category($db, $table, $owner, $parent, $policy)
{
	$title = $parent ? 'Legacy photos' : 'Personal gallery';
	$groups = $policy['groups'] === '' ? "''" : '0x'.bin2hex($policy['groups']);
	// No new upload or moderation access. The legacy list did not filter
	// pictures by approval, so preserve that visibility and the stored flag.
	plus_storage_query($db, 'INSERT INTO '.$table.' (cat_title, cat_desc, cat_order, cat_view_level, cat_view_groups,'.
		' cat_upload_level, cat_rate_level, cat_comment_level, cat_edit_level, cat_delete_level, cat_moderator_groups,'.
		" cat_approval, cat_parent, cat_user_id) VALUES ('".$title."', '', 0, ".$policy['view'].', '.$groups.
		", 1, 1, 1, 1, 1, '', 0, ".$parent.', '.$owner.')');
	$id = (int) mysqli_insert_id($db);
	if ($id < 1 || $id > 16777215 || plus_storage_rows($db, 'SHOW WARNINGS'))
	{
		throw new RuntimeException('Album migration could not create an exact destination category');
	}
	$current = plus_storage_rows($db, 'SELECT cat_title,cat_parent,cat_user_id,cat_view_level,cat_view_groups,'.
		' cat_upload_level,cat_rate_level,cat_comment_level,cat_edit_level,cat_delete_level,cat_moderator_groups,cat_approval'.
		' FROM '.$table.' WHERE cat_id='.$id);
	$expected = array($title, $parent, $owner, $policy['view'], $policy['groups'], 1, 1, 1, 1, 1, '', 0);
	if (count($current) !== 1 || array_map('strval', array_values($current[0])) !== array_map('strval', $expected))
	{
		throw new RuntimeException('New Album category did not retain the planned access conditions');
	}
	return $id;
}

function plus_legacy_album_digest($db, $sql, $category_field, $expected_category)
{
	$result = mysqli_query($db, $sql, MYSQLI_USE_RESULT);
	if (!$result) { throw new RuntimeException('Cannot verify Album migration data'); }
	$hash = hash_init('sha256');
	try
	{
		while ($row = mysqli_fetch_assoc($result))
		{
			$row[$category_field] = (string) $expected_category;
			foreach ($row as $key => $value) { if ($value !== null) { $row[$key] = (string) $value; } }
			hash_update($hash, serialize($row));
		}
		if (mysqli_errno($db)) { throw new RuntimeException('Album migration verification was interrupted'); }
	}
	finally { mysqli_free_result($result); }
	return hash_final($hash);
}

function plus_legacy_album_apply($db, $prefix, $backup, $maintenance, $wait = 15)
{
	if ($backup !== true || $maintenance !== true || !is_int($wait) || $wait < 1 || $wait > 30)
	{
		throw new RuntimeException('Album migration requires a verified backup, stopped writers and a bounded wait');
	}
	$names = plus_legacy_album_tables($prefix);
	$connection = plus_storage_rows($db, 'SELECT DATABASE() AS db, CONNECTION_ID() AS id, @@SESSION.autocommit AS automatic,'.
		' @@SESSION.sql_mode AS mode, @@SESSION.innodb_lock_wait_timeout AS row_wait, @@SESSION.lock_wait_timeout AS metadata_wait')[0];
	if (!$connection['db'] || (string) $connection['automatic'] !== '1')
	{
		throw new RuntimeException('Album migration requires an idle autocommit connection');
	}
	// This refuses an active caller transaction before changing its session.
	plus_storage_query($db, 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
	$open = false;
	$committing = false;
	$failure = null;
	$held = array();
	try
	{
		plus_storage_query($db, 'START TRANSACTION');
		$open = true;
		foreach (array('plus_innodb_'.sha1($connection['db']), 'album_files_'.sha1($connection['db']."\0".$prefix)) as $lock)
		{
			$owner = plus_storage_rows($db, "SELECT IS_USED_LOCK('".$lock."') AS owner")[0]['owner'];
			if ($owner !== null && (string) $owner === (string) $connection['id'])
			{
				throw new RuntimeException('Cannot reuse a lock already held by the caller');
			}
			$row = plus_storage_rows($db, "SELECT GET_LOCK('".$lock."',0) AS acquired")[0];
			if ((string) $row['acquired'] !== '1') { throw new RuntimeException('An Album writer or migration is running'); }
			$held[] = $lock;
		}
		plus_storage_query($db, 'SET SESSION innodb_lock_wait_timeout='.$wait);
		plus_storage_query($db, 'SET SESSION lock_wait_timeout='.$wait);
		plus_storage_query($db, "SET SESSION sql_mode=CONCAT_WS(',',NULLIF(@@SESSION.sql_mode,''),'STRICT_ALL_TABLES')");
		$t = array_map('plus_storage_identifier', $names);
		// Lock before building the transactional plan so it uses fresh data.
		if (plus_storage_metadata($db, $names['pictures']) !== null)
		{
			plus_legacy_album_lock_tables($db, $names);
		}
		$plan = plus_legacy_album_plan($db, $prefix);
		$comments = 0;
		foreach ($plan['owners'] as $owner => $collection)
		{
			$root = $collection['root'];
			if (!$root) { $root = plus_legacy_album_add_category($db, $t['categories'], $owner, 0, $plan['policy']); }
			$category = plus_legacy_album_add_category($db, $t['categories'], $owner, $root, $plan['policy']);
			$where = ' WHERE p.pic_cat_id=0 AND p.pic_user_id='.$owner;
			$join = $t['comments'].' c INNER JOIN '.$t['pictures'].' p ON p.pic_id=c.comment_pic_id';
			$before_pictures = plus_legacy_album_digest($db, 'SELECT p.* FROM '.$t['pictures'].' p'.$where.
				' ORDER BY p.pic_id', 'pic_cat_id', $category);
			$before_comments = plus_legacy_album_digest($db, 'SELECT c.* FROM '.$join.$where.
				' ORDER BY c.comment_id', 'comment_cat_id', $category);
			plus_storage_query($db, 'UPDATE '.$join.' SET c.comment_cat_id='.$category.$where);
			$comments += mysqli_affected_rows($db);
			if (plus_storage_rows($db, 'SHOW WARNINGS')) { throw new RuntimeException('Album comment category update was not exact'); }
			plus_storage_query($db, 'UPDATE '.$t['pictures'].' p SET pic_cat_id='.$category.$where);
			if (mysqli_affected_rows($db) !== $collection['pictures'] || plus_storage_rows($db, 'SHOW WARNINGS'))
			{
				throw new RuntimeException('Album migration affected an unexpected number of pictures');
			}
			$after = ' WHERE p.pic_cat_id='.$category.' AND p.pic_user_id='.$owner;
			$after_pictures = plus_legacy_album_digest($db, 'SELECT p.* FROM '.$t['pictures'].' p'.$after.
				' ORDER BY p.pic_id', 'pic_cat_id', $category);
			$after_comments = plus_legacy_album_digest($db, 'SELECT c.* FROM '.$join.$after.
				' ORDER BY c.comment_id', 'comment_cat_id', $category);
			$stale = plus_storage_rows($db, 'SELECT c.comment_id FROM '.$join.$after.' AND NOT (c.comment_cat_id <=> '.$category.') LIMIT 1');
			if ($before_pictures !== $after_pictures || $before_comments !== $after_comments || $stale)
			{
				throw new RuntimeException('Album migration readback differs from the planned changes');
			}
		}
		$committing = true;
		plus_storage_query($db, 'COMMIT');
		$committing = false;
		$open = false;
		$plan['updated_comments'] = $comments;
	}
	catch (Exception $error) { $failure = $error; }
	catch (Error $error) { $failure = $error; }

	try
	{
		if ($open) { @mysqli_rollback($db); }
		plus_storage_query($db, "SET SESSION sql_mode='".mysqli_real_escape_string($db, $connection['mode'])."'");
		plus_storage_query($db, 'SET SESSION innodb_lock_wait_timeout='.(int) $connection['row_wait']);
		plus_storage_query($db, 'SET SESSION lock_wait_timeout='.(int) $connection['metadata_wait']);
	}
	catch (Exception $error) { $failure = $failure ?: $error; }
	catch (Error $error) { $failure = $failure ?: $error; }
	foreach (array_reverse($held) as $lock)
	{
		try { plus_storage_query($db, "DO RELEASE_LOCK('".$lock."')"); }
		catch (Exception $error) { $failure = $failure ?: $error; }
		catch (Error $error) { $failure = $failure ?: $error; }
	}
	if ($committing)
	{
		throw new RuntimeException('Album commit was not confirmed. Inspect current data before retrying.', 0, $failure);
	}
	if ($failure) { throw $failure; }
	return $plan;
}
