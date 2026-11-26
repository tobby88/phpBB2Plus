<?php
if (!defined('IN_PHPBB')) { exit; }

function plus_quota_member($old)
{
	$id = (int) $old;
	if ($id === -1) { return ANONYMOUS; }
	if ($id < 1) { return 0; }
	$target = plus_existing_author($id);
	return $target === ANONYMOUS ? 0 : $target;
}

function plus_quota_group($old)
{
	global $src_db, $convert;
	static $groups = null;
	if ($groups === null)
	{
		$groups = array();
		$r = $src_db->sql_query('SELECT group_id FROM ' . $convert->src_table_prefix . 'groups WHERE group_single_user=0');
		while ($row = $src_db->sql_fetchrow($r)) { $groups[(int) $row['group_id']] = true; }
		$src_db->sql_freeresult($r);
	}
	return isset($groups[(int) $old]) ? (int) $old : 0;
}

function plus_attachment_defaults()
{
	global $config, $src_db, $convert;
	// In the old MOD max_filesize applied to individual files in both composers.
	// max_filesize_pm was the accumulated allowance of each side of a mailbox.
	$names = array('default_upload_quota'=>'plus_attach_default_post','default_pm_quota'=>'plus_attach_default_pm',
		'max_filesize_pm'=>'plus_attach_base_pm','max_filesize'=>'max_filesize_pm');
	$r = $src_db->sql_query('SELECT config_name,config_value FROM ' . $convert->src_table_prefix . 'attachments_config WHERE ' .
		$src_db->sql_in_set('config_name',array_keys($names)));
	while ($row = $src_db->sql_fetchrow($r)) { $config->set($names[$row['config_name']], (string) $row['config_value']); }
	$src_db->sql_freeresult($r);
	$config->set('plus_attach_quotas',1);
	$config->set_atomic('plus_attach_upload_types', '-1', '1');
}

function plus_quota_schema($prefix, $truncate)
{
	$p = $prefix . 'plus_attach_';
	return array(array('target'=>$p . 'limits', 'primary'=>'quota_limits.quota_limit_id', 'autoincrement'=>'limit_id',
		'query_first'=>array('target',$truncate . $p . 'limits'),
		array('limit_id','quota_limits.quota_limit_id','intval'),array('label','quota_limits.quota_desc','utf8_encode_ucr'),
		array('bytes','quota_limits.quota_limit','strval')),
		array('target'=>$p . 'quotas','order_by'=>'attach_quota.user_id,attach_quota.group_id,attach_quota.quota_type,attach_quota.quota_limit_id',
			'query_first'=>array(array('target',$truncate . $p . 'quotas'),array('target',$truncate . $p . 'pending')),
			array('source_user','attach_quota.user_id','intval'),array('source_group','attach_quota.group_id','intval'),
			array('user_id','attach_quota.user_id','plus_quota_member'),array('group_id','attach_quota.group_id','plus_quota_group'),
			array('kind','attach_quota.quota_type','intval'),array('limit_id','attach_quota.quota_limit_id','intval')));
}
