<?php
if (!defined('IN_PHPBB')) { exit; }

function plus_absence_settings()
{
	global $config;
	$source=get_config();
	foreach (array('users_allow_absence'=>array('plus_away_members',0),'mod_able_sent_absent'=>array('plus_away_mod_contact',0),
		'absent_button'=>array('plus_away_by_name',1)) as $old=>$setting)
	{
		$config->set($setting[0],isset($source[$old]) ? (int)((string)$source[$old]==='1') : $setting[1]);
	}
}

/** Both original checks used the account's moderator level, independently of individual forum and Junior Admin grants. */
function plus_moderator_profile_permissions()
{
	global $src_db,$db,$convert,$auth;
	$r = $db->sql_query('SELECT auth_option,auth_option_id FROM ' . ACL_OPTIONS_TABLE .
		" WHERE auth_option IN ('u_plus_away_mod','u_plus_profile_visits')");
	$options = array_column($db->sql_fetchrowset($r), 'auth_option_id', 'auth_option');
	$db->sql_freeresult($r);
	if (count($options) !== 2) { throw new \RuntimeException('Missing Plus moderator profile permissions'); }
	// The stock converter rebuilds group rights. Restore each group default without replacing native assignments or denials.
	foreach ($options as $name => $option)
	{
		$groups = $name === 'u_plus_away_mod' ? array('GLOBAL_MODERATORS') : array('GLOBAL_MODERATORS','ADMINISTRATORS');
		$db->sql_query('INSERT INTO ' . ACL_GROUPS_TABLE . ' (group_id,forum_id,auth_option_id,auth_role_id,auth_setting)
			SELECT g.group_id,0,' . (int) $option . ',0,' . ACL_YES . ' FROM ' . GROUPS_TABLE . ' g WHERE g.group_type=' . GROUP_SPECIAL .
			' AND ' . $db->sql_in_set('g.group_name',$groups) . ' AND NOT EXISTS (SELECT 1 FROM ' . ACL_GROUPS_TABLE .
			' a WHERE a.group_id=g.group_id AND a.forum_id=0 AND a.auth_option_id=' . (int) $option . ')');
	}
	$add=static function($ids) use ($db,$options)
	{
		$db->sql_query('INSERT INTO ' . ACL_USERS_TABLE . ' (user_id,forum_id,auth_option_id,auth_role_id,auth_setting)
			SELECT u.user_id,0,o.auth_option_id,0,' . ACL_YES . ' FROM ' . USERS_TABLE . ' u CROSS JOIN ' . ACL_OPTIONS_TABLE . ' o
			WHERE ' . $db->sql_in_set('u.user_id',$ids) . ' AND ' . $db->sql_in_set('o.auth_option_id',array_map('intval',array_values($options))) .
			' AND NOT EXISTS (SELECT 1 FROM ' . ACL_USERS_TABLE . ' a WHERE a.user_id=u.user_id AND a.forum_id=0 AND a.auth_option_id=o.auth_option_id)');
	};
	$ids=array();
	$r=$src_db->sql_query('SELECT user_id FROM ' . $convert->src_table_prefix . 'users WHERE user_level=2 AND user_id>0');
	while ($row=$src_db->sql_fetchrow($r))
	{
		$ids[]=(int)phpbb_user_id($row['user_id']);
		if (count($ids)===500) { $add($ids); $ids=array(); }
	}
	$src_db->sql_freeresult($r);
	if ($ids) { $add($ids); }
	$auth->acl_clear_prefetch();
}

function plus_absence_text($stored)
{
	// The original profile editor stored htmlspecialchars() once. Retain literal markup and any deliberately typed entities.
	// phpBB's native database connection uses three-byte UTF-8. JSON stores supplementary characters losslessly as ASCII.
	return json_encode(htmlspecialchars_decode((string)$stored,ENT_QUOTES));
}
