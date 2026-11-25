<?php
if (!defined('IN_PHPBB')) { exit; }

function plus_colour_value($colour) { return \phpbb2plus\colours\service\palette::hex($colour); }
function plus_colour_legend($hidden) { return (int) !$hidden; }

function plus_finish_colours()
{
	global $db, $config, $table_prefix;
	$table = $table_prefix . 'plus_colours';
	// A deleted source category cannot start colouring its old members again if an ID is reused.
	foreach (array(USERS_TABLE, GROUPS_TABLE) as $owners)
	{
		$db->sql_query("UPDATE $owners SET plus_colour_id=0 WHERE plus_colour_id>0 AND plus_colour_id NOT IN (SELECT colour_id FROM $table)");
	}
	$result = $db->sql_query_limit("SELECT colour_id FROM $table",1);
	$has_palette = (bool) $db->sql_fetchfield('colour_id');
	$db->sql_freeresult($result);
	if ($has_palette)
	{
		// The source legend is authoritative. Newly created native legend groups can still appear alongside it.
		$db->sql_query('UPDATE ' . GROUPS_TABLE . ' SET group_legend=0 WHERE group_type=' . GROUP_SPECIAL . ' AND ' .
			$db->sql_in_set('group_name',array('ADMINISTRATORS','GLOBAL_MODERATORS')));
	}
	$config->set('plus_convert_colours_due',-1,false);
}

function plus_apply_converted_colours()
{
	global $db, $config, $table_prefix;
	$palette = new \phpbb2plus\colours\service\palette($db,$config,$table_prefix . 'plus_colours');
	$after = max(0,(int) $config['plus_convert_colours_due']);
	while ($after = $palette->batch($after,true))
	{
		$config->set('plus_convert_colours_due',$after,false);
	}
	$config->delete('plus_convert_colours_due');
}
