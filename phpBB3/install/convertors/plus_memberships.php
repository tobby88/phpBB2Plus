<?php
if (!defined('IN_PHPBB')) { exit; }

/** The source records group management separately from approved group membership. */
function plus_membership_sections($section)
{
	$leader = isset($section['execute_first']);
	$group = $leader ? 'groups.group_id' : 'user_group.group_id';
	$account = $leader ? 'groups.group_moderator' : 'user_group.user_id';
	$section['where'] .= " AND users.user_id=$account AND users.user_id>0";
	$section['group_by'] = $section['order_by'] = "$group, $account";
	if ($leader)
	{
		$section['left_join'] = 'groups LEFT JOIN user_group ON user_group.group_id=groups.group_id AND user_group.user_id=groups.group_moderator';
	}
	// The wizard accepts simple source columns. Two grouped passes express the
	// aggregate without a query per member and still resume at a stable row boundary.
	$parts = array();
	foreach (array(0,1) as $pending)
	{
		$section['having'] = "MIN(CASE WHEN user_group.user_pending=0 THEN 0 ELSE 1 END)=$pending";
		foreach ($section as $key=>$field)
		{
			if (is_int($key) && $field[0] === 'user_pending') { $section[$key] = array('user_pending',$pending,''); }
		}
		$parts[] = $section;
		unset($section['query_first'],$section['execute_first']);
	}
	return $parts;
}

/** Native options outside the Plus forum policy that originate in a personal phpBB2 posting grant. */
function plus_personal_post_options()
{
	return array('auth_post'=>array('f_post','f_bbcode','f_smilies','f_img','f_sigs','f_postcount','f_subscribe','f_print','f_email'),
		'auth_reply'=>array('f_reply'),'auth_edit'=>array('f_edit'));
}

/** The stock converter assigned these users local options before checking if their memberships were approved. */
function plus_reconcile_personal_memberships($forum,$assignments,$affected)
{
	global $db;
	if (!$affected) { return; }
	$id = (int) $forum['forum_id'];
	foreach (array_chunk(array_keys($affected),100) as $chunk)
	{
		$db->sql_query('DELETE FROM ' . ACL_USERS_TABLE . " WHERE forum_id=$id AND " . $db->sql_in_set('user_id',$chunk));
	}
	$moderators = $post_options = array();
	foreach ($assignments as $row)
	{
		$user = $row['id'];
		if ($row['principal'] !== 'user_id' || !isset($affected[$user])) { continue; }
		if ($row['auth_mod']) { $moderators[$user] = true; }
		foreach (plus_personal_post_options() as $source=>$options)
		{
			$level = (int) $forum[$source];
			if (($level === 2 && ($row[$source] || $row['auth_mod'])) || ($level === 3 && $row['auth_mod']))
			{
				foreach ($options as $option) { $post_options[$user][$option] = true; }
			}
		}
	}
	foreach (array_keys($moderators) as $user)
	{
		mass_auth('user_role',$id,$user,'MOD_STANDARD');
		mass_auth('user_role',$id,$user,'FORUM_STANDARD');
	}
	foreach ($post_options as $user=>$options) { mass_auth('user',$id,$user,array_keys($options),ACL_YES); }
	// The normal forum conversion now applies the remaining options, including listing and reading.
}
