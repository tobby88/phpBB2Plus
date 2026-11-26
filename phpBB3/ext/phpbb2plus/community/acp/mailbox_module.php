<?php
namespace phpbb2plus\community\acp;

use phpbb\request\request_interface;
use phpbb2plus\community\service\mailbox_counters;

class mailbox_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_mailbox';
	public $page_title = 'ACP_PLUS_MAILBOX';

	public function main($module, $mode)
	{
		global $auth, $db, $phpbb_container, $request, $template, $user;
		if (!$auth->acl_get('a_user')) { trigger_error('NO_AUTH_OPERATION', E_USER_WARNING); }
		$language = $phpbb_container->get('language');
		$language->add_lang('info_acp_mailbox', 'phpbb2plus/community');
		$counters = new mailbox_counters($db);
		$after = max(0, $request->variable('after', 0));
		$form = 'plus_mailbox_counters';
		if ($request->is_set_post('repair'))
		{
			if (!check_form_key($form)) { trigger_error('FORM_INVALID', E_USER_WARNING); }
			$id = $request->variable('repair', 0, false, request_interface::POST);
			$revision = $request->variable('revision', '', false, request_interface::POST);
			$db->sql_transaction('begin');
			try
			{
				$changed = $counters->repair($id, $revision);
				if ($changed) { $phpbb_container->get('log')->add('admin', $user->data['user_id'], $user->ip, 'LOG_PLUS_MAILBOX_COUNTERS', false, array($id)); }
				$db->sql_transaction('commit');
			}
			catch (\Throwable $error) { $db->sql_transaction('rollback'); throw $error; }
			$template->assign_var('PLUS_MAILBOX_RESULT', $language->lang($changed ? 'PLUS_MAILBOX_CHANGED' : 'PLUS_MAILBOX_STALE', $id));
		}
		add_form_key($form);
		$page = $counters->page($after);
		$template->assign_vars(array('S_PLUS_MAILBOX_ACTION' => $this->u_action . '&amp;after=' . $after,
			'PLUS_MAILBOX_CHECKED' => $language->lang('PLUS_MAILBOX_CHECKED', $page['checked']),
			'U_PLUS_MAILBOX_NEXT' => $page['next'] ? $this->u_action . '&amp;after=' . $page['next'] : ''));
		foreach ($page['changes'] as $row)
		{
			$template->assign_block_vars('mailboxes', array_change_key_case($row, CASE_UPPER) + array('REVISION' => mailbox_counters::revision($row)));
		}
	}
}
