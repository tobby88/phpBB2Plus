<?php
if (!defined('IN_PHPBB')) { exit; }

/** Stage one posting forum per source ID for native permission conversion. The final Plus tree is restored afterwards. */
function plus_insert_forums()
{
	global $src_db, $db, $convert, $user;
	$prefix = $convert->src_table_prefix;
	// Read the original UTF-8 bytes even when the converter shares a connection with a three-byte destination.
	$result = $src_db->sql_query('SELECT f.forum_id,CAST(f.forum_name AS BINARY) AS forum_name,CAST(f.forum_desc AS BINARY) AS forum_desc,
		f.forum_status,f.prune_enable,f.prune_next,
		p.rule_count,p.prune_days,p.prune_freq FROM ' . $prefix . 'forums f LEFT JOIN
		(SELECT forum_id,COUNT(*) AS rule_count,MIN(prune_days) AS prune_days,MIN(prune_freq) AS prune_freq
		FROM ' . $prefix . 'forum_prune GROUP BY forum_id) p ON p.forum_id=f.forum_id ORDER BY f.forum_id');
	$forums = $src_db->sql_fetchrowset($result);
	$src_db->sql_freeresult($result);
	$enabled = (string) get_config_value('prune_enable') === '1';
	$parent = $forums ? (int) end($forums)['forum_id'] + 1 : 1;
	$db->sql_query($convert->truncate_statement . FORUMS_TABLE);
	$db->sql_transaction('begin');
	try
	{
		// The native ACL step grants broader rights to rows with no parent.
		// Keep every source forum below a temporary category until its own ACLs are in place.
		$category = array('forum_id' => $parent, 'forum_type' => FORUM_CAT, 'forum_name' => $user->lang['CATEGORY'],
			'forum_desc' => '', 'forum_rules' => '', 'forum_parents' => '', 'left_id' => 1, 'right_id' => 2 * count($forums) + 2);
		$db->sql_query('INSERT INTO ' . FORUMS_TABLE . ' ' . $db->sql_build_array('INSERT', $category));
		$left = 2;
		foreach (array_chunk($forums, 100) as $chunk)
		{
			$rows = array();
			foreach ($chunk as $forum)
			{
				$unique = (int) $forum['rule_count'] === 1;
				$days = $unique ? (int) $forum['prune_days'] : 0;
				$frequency = $unique ? (int) $forum['prune_freq'] : 0;
				$rows[] = array('forum_id' => (int) $forum['forum_id'], 'parent_id' => $parent, 'left_id' => $left, 'right_id' => $left + 1,
					'forum_type' => FORUM_POST, 'forum_name' => utf8_encode_ucr(htmlspecialchars($forum['forum_name'], ENT_COMPAT, 'UTF-8')),
					'forum_desc' => utf8_encode_ucr(htmlspecialchars($forum['forum_desc'], ENT_COMPAT, 'UTF-8')),
					'forum_status' => is_item_locked($forum['forum_status']), 'forum_parents' => '', 'forum_rules' => '',
					'forum_flags' => phpbb_forum_flags(), 'forum_options' => 0, 'enable_icons' => 0,
					'enable_prune' => (int) ($enabled && (string) $forum['prune_enable'] === '1' && $days && $frequency),
					'prune_next' => (int) $forum['prune_next'], 'prune_days' => $days, 'prune_viewed' => 0, 'prune_freq' => $frequency);
				$left += 2;
			}
			$db->sql_multi_insert(FORUMS_TABLE, $rows);
		}
		$db->sql_transaction('commit');
	}
	catch (\Throwable $error) { $db->sql_transaction('rollback'); throw $error; }
}

/** Retain every sound edge, cutting just one edge per cycle or missing parent. */
function plus_detach_broken_branches(&$nodes)
{
	$done = array();
	$detached = false;
	foreach (array_keys($nodes) as $key)
	{
		$path = array();
		while ($key !== '' && !isset($done[$key]))
		{
			if (isset($path[$key]))
			{
				$cycle = array_slice(array_keys($path), $path[$key]);
				sort($cycle, SORT_STRING);
				$key = $cycle[0];
				$reason = 'cycle';
			}
			else
			{
				$path[$key] = count($path);
				$parent = $nodes[$key]['parent'];
				if ($parent === '' || isset($nodes[$parent])) { $key = $parent; continue; }
				$reason = 'missing-parent';
			}
			$nodes[$key]['old_parent'] = $nodes[$key]['parent'];
			$nodes[$key]['reason'] = $reason;
			$nodes[$key]['parent'] = 'recovery';
			$detached = true;
			break;
		}
		foreach ($path as $visited => $offset) { $done[$visited] = true; }
	}
	return $detached;
}

function plus_recovery_label($key)
{
	global $phpbb_container;
	static $language = null;
	if (!$language)
	{
		$language = clone $phpbb_container->get('language');
		$language->set_user_language(plus_convert_locale(get_config_value('default_lang')), true);
		$language->add_lang('conversion', 'phpbb2plus/community');
	}
	return $language->lang($key);
}

/** Allocate native category IDs and rebuild nested-set bounds from the source relationships. */
function plus_forum_layout($categories, $forums)
{
	$nodes = $children = array();
	$next_id = 0;
	foreach ($forums as $forum)
	{
		$next_id = max($next_id, (int) $forum['forum_id']);
		$id = (int) $forum['forum_id'];
		$parent = (int) $forum['cat_id'];
		$nodes['f' . $id] = array('id' => $id, 'type' => 'f', 'row' => $forum,
			'parent' => $parent ? ($forum['main_type'] ?: 'c') . $parent : '', 'order' => (int) $forum['forum_order']);
	}
	foreach ($categories as $category)
	{
		$id = (int) $category['cat_id'];
		$parent = (int) $category['cat_main'];
		$type = $category['cat_main_type'] ?: 'c';
		$nodes['c' . $id] = array('id' => ++$next_id, 'type' => 'c', 'row' => $category,
			'parent' => $parent && ($type !== 'c' || $parent !== $id) ? $type . $parent : '',
			'order' => (int) $category['cat_order'] + ($category['cat_main_type'] ? 0 : 9000000));
	}
	if (plus_detach_broken_branches($nodes))
	{
		// A generated recovery container must never take a possible phpBB2 forum number.
		$nodes['recovery'] = array('id'=>max(65536, $next_id + 1), 'type'=>'c', 'parent'=>'', 'order'=>PHP_INT_MAX,
			'row'=>array('cat_id'=>0, 'cat_title'=>plus_recovery_label('PLUS_RECOVERED_FORUMS'), 'cat_desc'=>''));
	}
	foreach ($nodes as $key => $node)
	{
		$children[$node['parent']][] = $key;
	}
	foreach ($children as &$siblings)
	{
		usort($siblings, function ($a, $b) use (&$nodes) {
			return array($nodes[$a]['order'], $nodes[$a]['type'], $nodes[$a]['id'])
				<=> array($nodes[$b]['order'], $nodes[$b]['type'], $nodes[$b]['id']);
		});
	}
	unset($siblings);
	$position = 0;
	$walk = function ($parent) use (&$walk, &$nodes, &$children, &$position) {
		foreach (isset($children[$parent]) ? $children[$parent] : array() as $key)
		{
			$nodes[$key]['left_id'] = ++$position;
			$walk($key);
			$nodes[$key]['right_id'] = ++$position;
		}
	};
	$walk('');
	return $nodes;
}

function plus_restore_forum_tree()
{
	global $src_db, $db, $convert, $auth;
	$result = $src_db->sql_query('SELECT cat_id, cat_main_type, cat_main, cat_order, cat_title, cat_desc
		FROM ' . $convert->src_table_prefix . 'categories ORDER BY cat_id');
	$categories = $src_db->sql_fetchrowset($result);
	$src_db->sql_freeresult($result);
	$result = $src_db->sql_query('SELECT f.forum_id, f.cat_id, f.main_type, f.forum_order, f.forum_link, f.forum_link_hit_count, f.forum_link_hit,
		(SELECT COUNT(*) FROM ' . $convert->src_table_prefix . 'forum_prune p WHERE p.forum_id=f.forum_id) AS prune_rules
		FROM ' . $convert->src_table_prefix . 'forums f ORDER BY f.forum_id');
	$forums = $src_db->sql_fetchrowset($result);
	$src_db->sql_freeresult($result);
	$nodes = plus_forum_layout($categories, $forums);
	$result = $src_db->sql_query('SELECT config_value FROM ' . $convert->src_table_prefix . "config WHERE config_name='prune_enable'");
	$prune_enabled = (string) $src_db->sql_fetchfield('config_value') === '1';
	$src_db->sql_freeresult($result);

	$db->sql_transaction('begin');
	try
	{
		// Discard provisional categories and their native grants, including
		// flat categories from a conversion started with an older preview.
		$result = $db->sql_query('SELECT forum_id FROM ' . FORUMS_TABLE . ' WHERE forum_type = ' . FORUM_CAT);
		$flat = $db->sql_fetchrowset($result);
		$db->sql_freeresult($result);
		if ($flat)
		{
			$where = $db->sql_in_set('forum_id', array_map('intval', array_column($flat, 'forum_id')));
			foreach (array(ACL_GROUPS_TABLE, ACL_USERS_TABLE, FORUMS_TABLE) as $table)
			{
				$db->sql_query("DELETE FROM $table WHERE $where");
			}
		}
		foreach ($nodes as $key => $node)
		{
			$fields = array('parent_id' => $node['parent'] === '' ? 0 : $nodes[$node['parent']]['id'],
				'forum_parents' => '', 'left_id' => $node['left_id'], 'right_id' => $node['right_id'],
				'plus_recovery_role'=>(int) ($key === 'recovery'), 'plus_recovery_parent'=>utf8_encode_ucr($node['old_parent'] ?? ''),
				'plus_recovery_reason'=>$node['reason'] ?? '');
			$row = $node['row'];
			if ($node['type'] === 'c')
			{
				$fields += array('forum_id' => $node['id'], 'forum_type' => FORUM_CAT, 'plus_cal_src_cat' => (int) $row['cat_id'], 'forum_rules' => '',
					'plus_legacy_category' => (int) $row['cat_id'], 'forum_name' => plus_convert_byline($row['cat_title']),
					'forum_desc' => plus_convert_byline($row['cat_desc']));
				$db->sql_query('INSERT INTO ' . FORUMS_TABLE . ' ' . $db->sql_build_array('INSERT', $fields));
				continue;
			}
			$link = trim(html_entity_decode((string) $row['forum_link'], ENT_QUOTES, 'UTF-8'));
			if ($link !== '')
			{
				$parts = parse_url($link);
				if ($parts === false || preg_match('/[\x00-\x20\x7f\\\\]/', $link) || strpos($link, '//') === 0
					|| isset($parts['scheme']) && (!in_array(strtolower($parts['scheme']), array('https', 'http'), true) || empty($parts['host'])))
				{
					throw new \RuntimeException('Review the link destination of forum ' . $node['id'] . ' before conversion.');
				}
				$fields += array('forum_type' => FORUM_LINK, 'forum_link' => utf8_htmlspecialchars($link),
					'forum_flags' => (int) $row['forum_link_hit_count'] ? FORUM_FLAG_LINK_TRACK : 0,
					'forum_posts_approved' => (int) $row['forum_link_hit']);
			}
			else
			{
				// Unlike stock phpBB2, Plus age pruning protects polls and announcements
				// and treats a sticky topic like an ordinary topic of the same age.
				$fields['forum_flags'] = (phpbb_forum_flags() & ~(FORUM_FLAG_PRUNE_POLL | FORUM_FLAG_PRUNE_ANNOUNCE)) | FORUM_FLAG_PRUNE_STICKY;
				// Plus ran an automatic rule only when exactly one source row existed.
				if ((int) $row['prune_rules'] !== 1) { $fields['enable_prune'] = 0; }
			}
			$db->sql_query('UPDATE ' . FORUMS_TABLE . ' SET ' . $db->sql_build_array('UPDATE', $fields) . ' WHERE forum_id=' . $node['id']);
		}
		$invalid_rule = 'enable_prune<>1 OR forum_type<>' . FORUM_POST . ' OR prune_days=0 OR prune_freq=0';
		$db->sql_query('UPDATE ' . FORUMS_TABLE . ' SET enable_prune=0 WHERE enable_prune<>0' .
			($prune_enabled ? ' AND (' . $invalid_rule . ')' : ''));
		// Categories are containers. Grant list access to groups and individual
		// users with a listable descendant. Posting forum permissions stay native.
		foreach ($nodes as $node)
		{
			if ($node['type'] !== 'c') { continue; }
			$descendants = array();
			foreach ($nodes as $child)
			{
				if ($child['type'] === 'f' && $child['left_id'] > $node['left_id'] && $child['right_id'] < $node['right_id'])
				{
					$descendants[] = $child['id'];
				}
			}
			mass_auth('group_role', $node['id'], 'administrators', 'FORUM_FULL');
			if (!$descendants) { continue; }
			$grants = array('group' => $auth->acl_group_raw_data(false, 'f_list', $descendants),
				'user' => $auth->acl_user_raw_data(false, 'f_list', $descendants));
			foreach ($grants as $kind => $entries)
			{
				foreach ($entries as $principal => $access)
				{
					foreach ($access as $permission)
					{
						if (isset($permission['f_list']) && (int) $permission['f_list'] === ACL_YES)
						{
							mass_auth($kind, $node['id'], (int) $principal, array('f_', 'f_list'), ACL_YES);
							break;
						}
					}
				}
			}
		}
		$auth->acl_clear_prefetch();
		$db->sql_transaction('commit');
	}
	catch (\Throwable $error)
	{
		$db->sql_transaction('rollback');
		throw $error;
	}
}
