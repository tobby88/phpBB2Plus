<?php
namespace phpbb2plus\community\acp;

use phpbb\request\request_interface;
use phpbb2plus\community\auth\admin_session;

class file_integrity_module
{
	public $u_action;
	public $page_title = 'ACP_PLUS_CT_FILES';
	public $tpl_name = 'acp_plus_program_files';
	private function allowed($fresh = false)
	{
		global $auth, $db, $user;
		return admin_session::allows($db, $auth, $user, 'a_plus_ct_files', $fresh);
	}

	public function main($module, $mode)
	{
		global $phpbb_container, $request, $template, $user;
		$post_request = $request->server('REQUEST_METHOD') === 'POST';
		if ($mode !== 'main' || !$this->allowed($post_request)) { send_status_line(403, 'Forbidden'); trigger_error('NO_AUTH_OPERATION', E_USER_WARNING); }
		$language = $phpbb_container->get('language');
		$language->add_lang('info_acp_program_files', 'phpbb2plus/community');
		$checks = $phpbb_container->get('phpbb2plus.community.program_integrity');
		$key = 'plus_ct_program_files';
		try
		{
			$catalog = $checks->catalog();
			if ($post_request)
			{
				$post = request_interface::POST;
				$revision = $request->variable('revision', -1, false, $post);
				$action = $request->variable('action', '', false, $post);
				if (!check_form_key($action === 'adopt' ? $key . '_adopt_' . $revision : $key)) { trigger_error('FORM_INVALID', E_USER_WARNING); }
				if ($action === 'confirm')
				{
					$row = $checks->state();
					if ($revision !== (int) $row['revision'] || !$checks->can_adopt($row, $catalog)) { throw new \DomainException('PLUS_CT_FILES_NOT_READY'); }
					add_form_key($key . '_adopt_' . $revision);
					$template->assign_vars(array('CONFIRM' => true, 'REVISION' => $revision, 'U_ACTION' => $this->u_action));
					return;
				}
				$checks->change($action, $revision, function () { return $this->allowed(true); });
				if ($action === 'adopt')
				{
					$phpbb_container->get('log')->add('admin', $user->data['user_id'], $user->ip, 'LOG_PLUS_CT_FILES_REFERENCE');
					$template->assign_var('ADOPTED', true);
				}
			}
			$row = $checks->state();
		}
		catch (\DomainException $error)
		{
			if ($error->getMessage() === 'NO_AUTH_OPERATION') { send_status_line(403, 'Forbidden'); }
			trigger_error($error->getMessage(), E_USER_WARNING);
		}
		add_form_key($key);
		$matches = $row['package_id'] === $catalog['package'];
		$template->assign_vars(array('U_ACTION' => $this->u_action, 'REVISION' => (int) $row['revision'], 'TOTAL' => count($catalog['paths']),
			'REFERENCE' => $row['reference_time'] ? $user->format_date($row['reference_time']) : '',
			'SCAN_STALE' => $row['scan_start'] && !$matches, 'SCAN_TIME' => $row['scan_end'] ? $user->format_date($row['scan_end']) : '',
			'PENDING' => $matches && !$row['scan_end'], 'CURSOR' => (int) $row['file_cursor'],
			'CAN_ADOPT' => $checks->can_adopt($row, $catalog)));
		if (!$matches || !$row['scan_end']) { return; }
		$all = (bool) $request->variable('all', 0);
		$offset = max(0, $request->variable('start', 0));
		$report = $checks->report($row, $all, $offset);
		$base = $this->u_action . '&amp;all=' . (int) $all . '&amp;start=';
		$template->assign_vars(array('REPORT' => true, 'ALL' => $all, 'U_FILTER' => $this->u_action . '&amp;all=' . (int) !$all,
			'U_PREV' => $offset > 0 ? $base . max(0, $offset-50) : '', 'U_NEXT' => $offset+50 < $report['total'] ? $base . ($offset+50) : ''));
		foreach ($report['counts'] as $status => $count)
		{
			if ($count) { $template->assign_block_vars('counts', array('LABEL' => $language->lang('PLUS_CT_FILES_' . strtoupper($status)), 'COUNT' => $count)); }
		}
		foreach ($report['paths'] as $path => $status)
		{
			$template->assign_block_vars('entries', array('NAME' => htmlspecialchars($path, ENT_QUOTES, 'UTF-8'), 'STATUS' => $language->lang('PLUS_CT_FILES_' . strtoupper($status))));
		}
	}
}
