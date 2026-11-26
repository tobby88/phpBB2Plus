<?php
if (!defined('IN_PHPBB')) { exit; }

use phpbb2plus\community\service\post_icons;

function plus_import_post_icons()
{
	global $db,$src_db,$convert,$config,$phpbb_container;
	$result = $src_db->sql_query('SELECT schema_version,config_value FROM ' . $convert->src_table_prefix . 'post_icon_config WHERE config_id=1');
	$row = $src_db->sql_fetchrow($result);
	$src_db->sql_freeresult($result);
	if (!$row || (int) $row['schema_version'] !== 1 || strlen($row['config_value']) > 4194304)
	{
		throw new \RuntimeException('Missing or unsupported Plus post icon catalogue.');
	}
	// Read the database snapshot as data. No source-board PHP or serialized classes are loaded.
	$options = array('allowed_classes'=>false);
	if (PHP_VERSION_ID >= 70400) { $options['max_depth'] = 12; }
	$catalogue = @unserialize($row['config_value'],$options);
	if (!is_array($catalogue) || !isset($catalogue['icons'],$catalogue['special']) || !is_array($catalogue['icons']) ||
		!is_array($catalogue['special']) || count($catalogue['icons']) > 256 || count($catalogue['special']) > 64)
	{
		throw new \RuntimeException('Invalid Plus post icon catalogue.');
	}
	$icons = $ids = array();
	foreach ($catalogue['icons'] as $entry)
	{
		if (!is_array($entry) || !isset($entry['ind'],$entry['img'],$entry['auth'],$entry['alt']) ||
			!is_scalar($entry['ind']) || !preg_match('/^\d{1,5}$/D',(string) $entry['ind']) ||
			(int) $entry['ind'] > 65535 || isset($ids[(int) $entry['ind']]) ||
			!in_array($entry['auth'],array(0,1,3,5,'0','1','3','5'),true) ||
			!is_string($entry['alt']) || !preg_match('/^\w{1,100}$/D',$entry['alt']) ||
			!is_string($entry['img']) || strlen($entry['img']) > 255)
		{
			throw new \RuntimeException('Invalid Plus post icon definition.');
		}
		$id = (int) $entry['ind'];
		$ids[$id] = true;
		if (!$id) { continue; }
		$file = $entry['img'] === '' ? false : plus_profile_image('', $entry['img'], true);
		$image = $file ? 'plus/' . plus_public_bitmap($file,'icons/plus') : '';
		if (!$file && $entry['img'] !== '') { plus_profile_media_issue('post-icon',$id,$entry['img'],'unavailable'); }
		$icons[] = array('id'=>$id,'image'=>$image,'level'=>(int) $entry['auth'],'label'=>$entry['alt'],
			'width'=>$file ? $file['width'] : 0,'height'=>$file ? $file['height'] : 0);
	}
	$defaults = array();
	foreach (post_icons::TYPES as $type)
	{
		$special = $catalogue['special'][$type] ?? null;
		if (!is_array($special) || !isset($special['icon']) || !is_scalar($special['icon']) ||
			!preg_match('/^\d{1,5}$/D',(string) $special['icon']) || !isset($ids[(int) $special['icon']]))
		{
			throw new \RuntimeException('Invalid Plus post icon default for ' . $type);
		}
		$defaults[$type] = (int) $special['icon'];
	}
	post_icons::import($db,$config,ICONS_TABLE,$icons,$defaults,true);
	$phpbb_container->get('cache.driver')->destroy('_icons');
}

function plus_post_icon_id($source_id)
{
	global $db;
	static $map;
	$id = (int) $source_id;
	if ($id < 1) { return 0; }
	if ($map === null)
	{
		$map = array();
		$r = $db->sql_query('SELECT plus_icon_id,icons_id FROM ' . ICONS_TABLE . ' WHERE plus_icon_id>0');
		while ($row = $db->sql_fetchrow($r)) { $map[(int) $row['plus_icon_id']] = (int) $row['icons_id']; }
		$db->sql_freeresult($r);
	}
	return $map[$id] ?? 0;
}

function plus_enable_post_icons()
{
	global $db,$phpbb_container;
	$db->sql_query('UPDATE ' . FORUMS_TABLE . ' SET enable_icons=1 WHERE forum_type=' . FORUM_POST);
	$result = $db->sql_query("SELECT auth_option_id FROM " . ACL_OPTIONS_TABLE . " WHERE auth_option='f_icons'");
	$option = (int) $db->sql_fetchfield('auth_option_id');
	$db->sql_freeresult($result);
	if (!$option) { throw new \RuntimeException('The native post-icon permission is missing.'); }
	// In Plus the icon picker accompanied both the new-topic and reply forms. Native posting ACLs still govern both.
	$groups = $db->sql_in_set('g.group_name',array('GUESTS','REGISTERED','REGISTERED_COPPA','ADMINISTRATORS','GLOBAL_MODERATORS'));
	$db->sql_query('INSERT INTO ' . ACL_GROUPS_TABLE . ' (group_id,forum_id,auth_option_id,auth_role_id,auth_setting)
		SELECT g.group_id,f.forum_id,' . $option . ',0,' . ACL_YES . ' FROM ' . GROUPS_TABLE . ' g CROSS JOIN ' . FORUMS_TABLE . ' f
		WHERE g.group_type=' . GROUP_SPECIAL . ' AND ' . $groups . ' AND f.plus_recovery_role=0 AND f.forum_type=' . FORUM_POST . '
		AND NOT EXISTS (SELECT 1 FROM ' . ACL_GROUPS_TABLE . ' a WHERE a.forum_id=f.forum_id AND a.group_id=g.group_id AND a.auth_option_id=' . $option . ')');
	$phpbb_container->get('auth')->acl_clear_prefetch();
}
