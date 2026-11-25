<?php
if (!defined('IN_PHPBB')) { exit; }

require_once __DIR__ . '/plus_local_roles.php';

/** Convert a legacy local publishing right into individual and group phpBB ACL grants. */
function plus_forum_permission($native)
{
	global $db, $src_db, $convert, $phpbb_container;
	$source_options = array('f_plus_calendar'=>'auth_cal', 'f_plus_news'=>'auth_news', 'f_attach'=>'auth_attachments', 'f_download'=>'auth_download');
	if (!isset($source_options[$native])) { throw new \InvalidArgumentException('Unknown Plus forum permission.'); }
	$source = $source_options[$native];
	$p = $convert->src_table_prefix;
	$result = $db->sql_query("SELECT auth_option_id FROM " . ACL_OPTIONS_TABLE . " WHERE auth_option='$native'");
	$option = (int) $db->sql_fetchfield('auth_option_id');
	$db->sql_freeresult($result);
	if (!$option) { throw new \RuntimeException('The destination permission is missing: ' . $native); }
	$groups = array();
	$result = $db->sql_query('SELECT group_id,group_name FROM ' . GROUPS_TABLE . ' WHERE group_type=' . GROUP_SPECIAL);
	while ($r = $db->sql_fetchrow($result)) { $groups[$r['group_name']] = (int) $r['group_id']; }
	$db->sql_freeresult($result);
	$result = $src_db->sql_query("SELECT forum_id,$source AS permission_level FROM {$p}forums ORDER BY forum_id");
	$forums = $src_db->sql_fetchrowset($result);
	$src_db->sql_freeresult($result);
	$db->sql_transaction('begin');
	try
	{
		// The migration seeded a default for fresh boards. An existing Plus
		// board already has its own publishing policy for each forum.
		plus_clear_local_option($option,array_column($forums,'forum_id'));
		foreach ($forums as $forum)
		{
			$id = (int) $forum['forum_id'];
			$level = (int) $forum['permission_level'];
			$grant = array('group_id'=>array(), 'user_id'=>array());
			if (in_array($level,array(0,1,2,3,5),true)) { $grant['group_id'][$groups['ADMINISTRATORS']] = true; }
			if ($level === 0 && $source !== 'auth_news')
			{
				$grant['group_id'][$groups['GUESTS']] = true;
				if ($native === 'f_download' && isset($groups['BOTS'])) { $grant['group_id'][$groups['BOTS']] = true; }
			}
			if ($level === 0 || $level === 1)
			{
				$grant['group_id'][$groups['REGISTERED']] = true;
				if (isset($groups['REGISTERED_COPPA'])) { $grant['group_id'][$groups['REGISTERED_COPPA']] = true; }
			}
			if ($level === 2 || $level === 3)
			{
				$condition = $level === 2 ? "(a.$source<>0 OR a.auth_mod<>0)" : 'a.auth_mod<>0';
				$sql = "SELECT g.group_id,g.group_single_user,ug.user_id FROM {$p}auth_access a JOIN {$p}groups g ON g.group_id=a.group_id
					LEFT JOIN {$p}user_group ug ON g.group_single_user=1 AND ug.group_id=g.group_id AND ug.user_pending=0
					WHERE a.forum_id=$id AND $condition";
				$result = $src_db->sql_query($sql);
				$principals = $src_db->sql_fetchrowset($result);
				$src_db->sql_freeresult($result);
				foreach ($principals as $r)
				{
					if (!$r['group_single_user']) { $grant['group_id'][(int) $r['group_id']] = true; }
					else
					{
						$member = plus_existing_author($r['user_id']);
						if ($member !== ANONYMOUS) { $grant['user_id'][$member] = true; }
					}
				}
			}
			foreach ($grant as $field=>$principals)
			{
				$table = $field === 'group_id' ? ACL_GROUPS_TABLE : ACL_USERS_TABLE;
				$rows = array();
				foreach (array_keys($principals) as $principal)
				{
					$rows[] = array($field=>$principal,'forum_id'=>$id,'auth_option_id'=>$option,'auth_role_id'=>0,'auth_setting'=>ACL_YES);
				}
				foreach (array_chunk($rows,100) as $batch) { $db->sql_multi_insert($table,$batch); }
			}
		}
		$db->sql_transaction('commit');
	}
	catch (\Throwable $e) { $db->sql_transaction('rollback'); throw $e; }
	$phpbb_container->get('cache.driver')->destroy('_role_cache');
	$phpbb_container->get('auth')->acl_clear_prefetch();
}
