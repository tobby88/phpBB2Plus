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
		if ($level === 0 && $native !== 'f_plus_news' && !(int) $forum['auth_view'] && !(int) $forum['auth_read'])
		{
			$grants['group_id'][$groups['GUESTS']] = true;
			if ($native === 'f_download' && isset($groups['BOTS'])) { $grants['group_id'][$groups['BOTS']] = true; }
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

function plus_forum_permissions()
{
	global $db, $src_db, $convert, $phpbb_container;
	$mapping = array('f_plus_calendar'=>'auth_cal','f_plus_news'=>'auth_news','f_sticky'=>'auth_sticky',
		'f_announce'=>'auth_announce','f_announce_global'=>'auth_global_announce','f_poll'=>'auth_pollcreate','f_vote'=>'auth_vote');
	if (defined('MOD_ATTACHMENT')) { $mapping += array('f_attach'=>'auth_attachments','f_download'=>'auth_download'); }
	$r = $db->sql_query('SELECT auth_option,auth_option_id FROM ' . ACL_OPTIONS_TABLE . ' WHERE ' . $db->sql_in_set('auth_option',array_keys($mapping)));
	$options = array_column($db->sql_fetchrowset($r),'auth_option_id','auth_option');
	$db->sql_freeresult($r);
	if (array_diff_key($mapping,$options)) { throw new \RuntimeException('The destination is missing a Plus forum permission'); }
	$r = $db->sql_query('SELECT group_id,group_name FROM ' . GROUPS_TABLE . ' WHERE group_type=' . GROUP_SPECIAL);
	$groups = array_column($db->sql_fetchrowset($r),'group_id','group_name');
	$db->sql_freeresult($r);
	foreach (array('ADMINISTRATORS','REGISTERED','GUESTS') as $name)
	{
		if (!isset($groups[$name])) { throw new \RuntimeException('The destination is missing a native permission group'); }
	}

	$p = $convert->src_table_prefix;
	$columns = implode(',',array_values($mapping));
	$r = $src_db->sql_query("SELECT forum_id,auth_view,auth_read,$columns FROM {$p}forums ORDER BY forum_id");
	$forums = $src_db->sql_fetchrowset($r);
	$src_db->sql_freeresult($r);
	$db->sql_transaction('begin');
	try
	{
		plus_clear_local_options(array_values($options),array_column($forums,'forum_id'));
		foreach (array_chunk($forums,32) as $batch)
		{
			$assignments = array();
			$sql = 'SELECT a.forum_id,a.auth_mod,a.' . implode(',a.',array_values($mapping)) . ",g.group_id,g.group_single_user,ug.user_id
				FROM {$p}auth_access a JOIN {$p}groups g ON g.group_id=a.group_id LEFT JOIN {$p}user_group ug
				ON g.group_single_user=1 AND ug.group_id=g.group_id AND ug.user_pending=0 WHERE " .
				$src_db->sql_in_set('a.forum_id',array_column($batch,'forum_id'));
			$r = $src_db->sql_query($sql);
			$rows = $src_db->sql_fetchrowset($r);
			$src_db->sql_freeresult($r);
			foreach ($rows as $row)
			{
				$row['principal'] = $row['group_single_user'] ? 'user_id' : 'group_id';
				$row['id'] = $row['group_single_user'] ? plus_existing_author($row['user_id']) : (int) $row['group_id'];
				if ($row['group_single_user'] && $row['id'] === ANONYMOUS) { continue; }
				$assignments[(int) $row['forum_id']][] = $row;
			}
			$insert = array('group_id'=>array(),'user_id'=>array());
			foreach ($batch as $forum)
			{
				$id = (int) $forum['forum_id'];
				foreach ($mapping as $native=>$source)
				{
					foreach (plus_forum_grants($native,$source,$forum,$groups,$assignments[$id] ?? array()) as $field=>$principals)
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
		$db->sql_transaction('commit');
	}
	catch (\Throwable $e) { $db->sql_transaction('rollback'); throw $e; }
	$phpbb_container->get('cache.driver')->destroy('_role_cache');
	$phpbb_container->get('auth')->acl_clear_prefetch();
}
