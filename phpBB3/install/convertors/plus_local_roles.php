<?php
if (!defined('IN_PHPBB')) { exit; }

/** Clear a converted forum option while preserving the native role catalogue for future forums. */
function plus_clear_local_option($option, $forums)
{
	global $db;
	$option = (int) $option;
	$r = $db->sql_query('SELECT d.role_id,d.auth_option_id,d.auth_setting FROM ' . ACL_ROLES_DATA_TABLE . ' d WHERE d.role_id IN
		(SELECT role_id FROM ' . ACL_ROLES_DATA_TABLE . ' WHERE auth_option_id=' . $option . ') ORDER BY d.role_id,d.auth_option_id');
	$roles = array();
	while ($row = $db->sql_fetchrow($r))
	{
		$roles[(int) $row['role_id']][(int) $row['auth_option_id']] = (int) $row['auth_setting'];
	}
	$db->sql_freeresult($r);

	foreach (array_chunk(array_map('intval',$forums),32) as $ids)
	{
		$scope = $db->sql_in_set('forum_id',$ids);
		foreach (array('group_id'=>ACL_GROUPS_TABLE,'user_id'=>ACL_USERS_TABLE) as $key=>$table)
		{
			if ($roles)
			{
				$role_scope = $db->sql_in_set('auth_role_id',array_keys($roles));
				$r = $db->sql_query("SELECT $key,forum_id,auth_role_id,auth_option_id,auth_setting FROM $table WHERE $scope AND (auth_role_id=0 OR $role_scope)");
				$direct = $expanded = array();
				while ($row = $db->sql_fetchrow($r))
				{
					$forum = (int) $row['forum_id']; $who = (int) $row[$key]; $role = (int) $row['auth_role_id'];
					if (!$role)
					{
						$direct[$forum][$who][(int) $row['auth_option_id']] = (int) $row['auth_setting'];
						continue;
					}
					foreach ($roles[$role] as $other=>$setting)
					{
						if ($other === $option || $setting === ACL_NO) { continue; }
						if (!isset($expanded[$forum][$who][$other]) || ($key === 'group_id' && $setting === ACL_NEVER))
						{
							$expanded[$forum][$who][$other] = $setting;
						}
					}
				}
				$db->sql_freeresult($r);
				$insert = array();
				foreach ($expanded as $forum=>$principals)
				{
					foreach ($principals as $who=>$options)
					{
						foreach ($options as $other=>$setting)
						{
							if (isset($direct[$forum][$who][$other]))
							{
								// Individual explicit settings take precedence. An explicit group NEVER wins across all its grants.
								$old = $direct[$forum][$who][$other];
								if ($key === 'group_id' && $old !== $setting && ($old === ACL_NO || $setting === ACL_NEVER))
								{
									$db->sql_query("UPDATE $table SET auth_setting=$setting WHERE forum_id=$forum AND $key=$who AND auth_role_id=0 AND auth_option_id=$other");
								}
								continue;
							}
							$insert[] = array($key=>$who,'forum_id'=>$forum,'auth_role_id'=>0,'auth_option_id'=>$other,'auth_setting'=>$setting);
						}
					}
				}
				$db->sql_query("DELETE FROM $table WHERE $scope AND $role_scope");
				foreach (array_chunk($insert,100) as $batch) { $db->sql_multi_insert($table,$batch); }
			}
			$db->sql_query("DELETE FROM $table WHERE $scope AND auth_role_id=0 AND auth_option_id=$option");
		}
	}
}
