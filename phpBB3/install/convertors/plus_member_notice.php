<?php
if (!defined('IN_PHPBB')) { exit; }

function plus_import_member_notice()
{
	global $phpbb_container, $src_db, $convert;
	$table = $convert->src_table_prefix . 'ctracker_config';
	$message = '';
	$format = 'text';
	if ((new \phpbb\db\tools\factory())->get($src_db)->sql_table_exists($table))
	{
		$result = $src_db->sql_query("SELECT ct_config_name,BINARY ct_config_value AS notice_value FROM $table
			WHERE ct_config_name IN ('global_message','global_message_type')");
		while ($row = $src_db->sql_fetchrow($result))
		{
			if ($row['ct_config_name'] === 'global_message') { $message = (string) $row['notice_value']; }
			if ($row['ct_config_name'] === 'global_message_type' && $row['notice_value'] === '0') { $format = 'link'; }
		}
		$src_db->sql_freeresult($result);
	}
	// Preserve the editable source bytes. The member view decodes entities once, like Plus.
	// Receipts were already copied with their accounts. Rerunning the final wizard step must not mark them unread.
	$state = array('revision' => substr(hash('sha256', $format . "\0" . $message), 0, 32), 'format' => $format, 'message' => $message);
	$phpbb_container->get('config_text')->set(\phpbb2plus\community\service\member_notice::KEY, json_encode($state, JSON_THROW_ON_ERROR));
}
