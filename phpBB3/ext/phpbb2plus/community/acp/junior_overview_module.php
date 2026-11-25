<?php
namespace phpbb2plus\community\acp;

class junior_overview_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_junior_overview';
	public $page_title = 'PLUS_JR_OVERVIEW';

	public function main($id, $mode)
	{
		global $auth, $db, $table_prefix, $template, $user;
		if ($mode !== 'main' || !$auth->has_delegated_acp())
		{
			send_status_line(403, 'Forbidden');
			trigger_error('NO_AUTH_OPERATION', E_USER_WARNING);
		}
		$user->add_lang_ext('phpbb2plus/community', 'junior_admin');
		$result = $db->sql_query('SELECT start_date,update_date,notes_view,notes_b64 FROM ' . $table_prefix . 'plus_jr_records WHERE user_id=' . (int) $user->data['user_id']);
		$row = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);
		if (!$row) { trigger_error('NO_AUTH_OPERATION', E_USER_WARNING); }
		$template->assign_vars(array('JR_START' => $row['start_date'] ? $user->format_date($row['start_date']) : '',
			'JR_UPDATE' => $row['update_date'] ? $user->format_date($row['update_date']) : '',
			'JR_NOTE' => $row['notes_view'] ? base64_decode($row['notes_b64'], true) : ''));
	}
}
