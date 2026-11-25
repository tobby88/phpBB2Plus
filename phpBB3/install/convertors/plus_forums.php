<?php
if (!defined('IN_PHPBB')) { exit; }

/** Allocate non-overlapping IDs and validate the entire tree before changing any target rows. */
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
	foreach ($nodes as $key => $node)
	{
		if ($node['parent'] !== '' && !isset($nodes[$node['parent']]))
		{
			throw new \RuntimeException('Unknown parent ' . $node['parent'] . ' of Plus forum/category ' . $key);
		}
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
	if ($position !== count($nodes) * 2)
	{
		throw new \RuntimeException('A cycle in the Plus forum tree must be resolved before conversion.');
	}
	return $nodes;
}

function plus_restore_forum_tree()
{
	global $src_db, $db, $convert, $auth;
	$result = $src_db->sql_query('SELECT cat_id, cat_main_type, cat_main, cat_order, cat_title, cat_desc
		FROM ' . $convert->src_table_prefix . 'categories ORDER BY cat_id');
	$categories = $src_db->sql_fetchrowset($result);
	$src_db->sql_freeresult($result);
	$result = $src_db->sql_query('SELECT forum_id, cat_id, main_type, forum_order, forum_link, forum_link_hit_count, forum_link_hit
		FROM ' . $convert->src_table_prefix . 'forums ORDER BY forum_id');
	$forums = $src_db->sql_fetchrowset($result);
	$src_db->sql_freeresult($result);
	$nodes = plus_forum_layout($categories, $forums);
	$result = $src_db->sql_query('SELECT config_value FROM ' . $convert->src_table_prefix . "config WHERE config_name='prune_enable'");
	$prune_enabled = (string) $src_db->sql_fetchfield('config_value') === '1';
	$src_db->sql_freeresult($result);

	$db->sql_transaction('begin');
	try
	{
		// The standard converter creates flat categories, including a fallback
		// category for forums nested below other forums. Rebuild only these rows.
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
		foreach ($nodes as $node)
		{
			$fields = array('parent_id' => $node['parent'] === '' ? 0 : $nodes[$node['parent']]['id'],
				'forum_parents' => '', 'left_id' => $node['left_id'], 'right_id' => $node['right_id']);
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
