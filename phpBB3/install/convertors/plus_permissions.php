<?php
if (!defined('IN_PHPBB')) { exit; }

require_once __DIR__ . '/plus_local_roles.php';

/** Evaluate one Plus ACL level without expanding a real group into its present-day members. */
function plus_forum_grants($native,$source,$forum,$groups,$assignments)
{
	$grants = array('group_id'=>array(),'user_id'=>array());
	$level = (int) $forum[$source];
	if (!in_array($level,array(0,1,2,3,5),true)) { return $grants; }
	$grants['group_id'][$groups['ADMINISTRATORS']] = true;
	if ($level === 0 || $level === 1)
	{
		$grants['group_id'][$groups['REGISTERED']] = true;
		if (isset($groups['REGISTERED_COPPA'])) { $grants['group_id'][$groups['REGISTERED_COPPA']] = true; }
		$access_option = $native === 'f_list' || $native === 'f_read';
		if ($level === 0 && !in_array($native, array('f_plus_news', 'f_delete', 'f_softdelete'), true) && ($access_option || (!(int) $forum['auth_view'] && !(int) $forum['auth_read'])))
		{
			$grants['group_id'][$groups['GUESTS']] = true;
			if (($access_option || $native === 'f_download') && isset($groups['BOTS'])) { $grants['group_id'][$groups['BOTS']] = true; }
		}
	}
	elseif ($level === 2 || $level === 3)
	{
		foreach ($assignments as $row)
		{
			if ($row['auth_mod'] || ($level === 2 && $row[$source])) { $grants[$row['principal']][$row['id']] = true; }
		}
	}
	return $grants;
}

/** phpBB2 checked both visibility and reading before displaying topic titles or posts. */
function plus_forum_read_grants($forum,$groups,$rows)
{
	$view = plus_forum_grants('f_list','auth_view',$forum,$groups,$rows);
	$read = plus_forum_grants('f_read','auth_read',$forum,$groups,$rows);
	$v = (int) $forum['auth_view'];
	$r = (int) $forum['auth_read'];
	$conditional = array('group_id'=>array(),'user_id'=>array());
	if ($v === 0 || ($v === 1 && $r !== 0)) { $both = $read; }
	elseif ($r === 0 || $r === 1) { $both = $view; }
	else
	{
		$both = $view_only = $read_only = array();
		foreach (array('user_id','group_id') as $key)
		{
			$both[$key] = array_intersect_key($view[$key],$read[$key]);
			$view_only[$key] = array_diff_key($view[$key],$both[$key]);
			$read_only[$key] = array_diff_key($read[$key],$both[$key]);
		}
		// A member can combine distinct groups or a personal grant with a group.
		// Two distinct personal grants alone can never identify the same member.
		if (($view_only['group_id'] && ($read_only['group_id'] || $read_only['user_id'])) || ($read_only['group_id'] && $view_only['user_id']))
		{
			$conditional = $read_only;
		}
	}
	return array('f_list'=>$view,'f_read'=>$both,'f_list_topics'=>$both,'f_search'=>$both,'f_print'=>$both,'f_plus_read_if_listed'=>$conditional);
}

function plus_forum_permissions()
{
	global $db, $src_db, $convert, $phpbb_container, $config;
	$mapping = array('f_plus_calendar'=>'auth_cal','f_plus_news'=>'auth_news','f_sticky'=>'auth_sticky',
		'f_announce'=>'auth_announce','f_announce_global'=>'auth_global_announce','f_poll'=>'auth_pollcreate','f_vote'=>'auth_vote',
		'f_delete'=>'auth_delete','f_softdelete'=>'auth_delete');
	if (defined('MOD_ATTACHMENT')) { $mapping += array('f_attach'=>'auth_attachments','f_download'=>'auth_download'); }
	$names = array_merge(array_keys($mapping),array('f_list','f_read','f_list_topics','f_search','f_print','f_plus_read_if_listed','f_postcount'));
	$r = $db->sql_query('SELECT auth_option,auth_option_id FROM ' . ACL_OPTIONS_TABLE . ' WHERE ' . $db->sql_in_set('auth_option',$names));
	$options = array_column($db->sql_fetchrowset($r),'auth_option_id','auth_option');
	$db->sql_freeresult($r);
	if (array_diff($names,array_keys($options))) { throw new \RuntimeException('The destination is missing a Plus forum permission'); }
	$r = $db->sql_query('SELECT group_id,group_name FROM ' . GROUPS_TABLE . ' WHERE group_type=' . GROUP_SPECIAL);
	$groups = array_column($db->sql_fetchrowset($r),'group_id','group_name');
	$db->sql_freeresult($r);
	foreach (array('ADMINISTRATORS','REGISTERED','GUESTS') as $name)
	{
		if (!isset($groups[$name])) { throw new \RuntimeException('The destination is missing a native permission group'); }
	}

	$p = $convert->src_table_prefix;
	$columns = array_unique(array_merge(array('auth_view','auth_read'),array_values($mapping),array_keys(plus_personal_post_options())));
	$r = $src_db->sql_query('SELECT forum_id,count_posts,' . implode(',',$columns) . " FROM {$p}forums ORDER BY forum_id");
	$forums = $src_db->sql_fetchrowset($r);
	$src_db->sql_freeresult($r);
	$db->sql_transaction('begin');
	try
	{
		$conditional_forums = array();
		foreach (array_chunk($forums,32) as $batch)
		{
			$assignments = $affected = array();
			$sql = 'SELECT a.forum_id,a.auth_mod,a.' . implode(',a.',$columns) . ",g.group_id,g.group_single_user,ug.user_id,ug.user_pending,u.user_id AS source_account
				FROM {$p}auth_access a JOIN {$p}groups g ON g.group_id=a.group_id LEFT JOIN {$p}user_group ug
				ON g.group_single_user=1 AND ug.group_id=g.group_id LEFT JOIN {$p}users u ON u.user_id=ug.user_id AND u.user_id>0 WHERE " .
				$src_db->sql_in_set('a.forum_id',array_column($batch,'forum_id'));
			$r = $src_db->sql_query($sql);
			$rows = $src_db->sql_fetchrowset($r);
			$src_db->sql_freeresult($r);
			foreach ($rows as $row)
			{
				$row['principal'] = $row['group_single_user'] ? 'user_id' : 'group_id';
				$row['id'] = (int) $row['group_id'];
				if ($row['group_single_user'])
				{
					if ($row['user_id'] === null) { continue; }
					$row['id'] = (int) phpbb_user_id((int) $row['user_id']);
					if ($row['source_account'] === null || $row['user_pending'] === null || (int) $row['user_pending'] !== 0)
					{
						$affected[(int) $row['forum_id']][$row['id']] = true;
						continue;
					}
				}
				$assignments[(int) $row['forum_id']][] = $row;
			}
			foreach ($batch as $forum)
			{
				$id = (int) $forum['forum_id'];
				if (isset($affected[$id])) { plus_reconcile_personal_memberships($forum,$assignments[$id] ?? array(),$affected[$id]); }
			}
			plus_clear_local_options(array_values($options),array_column($batch,'forum_id'));
			$insert = array('group_id'=>array(),'user_id'=>array());
			foreach ($batch as $forum)
			{
				$id = (int) $forum['forum_id'];
				$rows = $assignments[$id] ?? array();
				$grants = plus_forum_read_grants($forum,$groups,$rows);
				if ($grants['f_plus_read_if_listed']['user_id'] || $grants['f_plus_read_if_listed']['group_id']) { $conditional_forums[] = $id; }
				foreach ($mapping as $native=>$source)
				{
					$grants[$native] = plus_forum_grants($native,$source,$forum,$groups,$rows);
				}
				if (!empty($forum['count_posts']))
				{
					// Reply-only writers earned the same credit as members who could start a topic.
					$post = plus_forum_grants('f_postcount','auth_post',$forum,$groups,$rows);
					$reply = plus_forum_grants('f_postcount','auth_reply',$forum,$groups,$rows);
					$grants['f_postcount'] = array('group_id'=>$post['group_id'] + $reply['group_id'], 'user_id'=>$post['user_id'] + $reply['user_id']);
				}
				foreach ($grants as $native=>$grant)
				{
					foreach ($grant as $field=>$principals)
					{
						foreach (array_keys($principals) as $who)
						{
							$insert[$field][] = array($field=>$who,'forum_id'=>$id,'auth_option_id'=>(int) $options[$native],'auth_role_id'=>0,'auth_setting'=>ACL_YES);
						}
					}
				}
			}
			foreach (array('group_id'=>ACL_GROUPS_TABLE,'user_id'=>ACL_USERS_TABLE) as $key=>$target)
			{
				foreach (array_chunk($insert[$key],100) as $items) { $db->sql_multi_insert($target,$items); }
			}
		}
		$phpbb_container->get('config_text')->set('plus_read_scopes',implode(',',$conditional_forums));
		$config->set('plus_conditional_read',(int) (bool) $conditional_forums);
		$config->set('plus_print_read_acl', 1);
		$config->set('plus_post_delete_policy', (string) get_config_value('post_delete_allow_permanent') === '1' ? 1 : 0);
		$db->sql_transaction('commit');
	}
	catch (\Throwable $e) { $db->sql_transaction('rollback'); throw $e; }
	$phpbb_container->get('cache.driver')->destroy('_role_cache');
	$phpbb_container->get('auth')->acl_clear_prefetch();
}
