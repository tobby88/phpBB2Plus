<?php
namespace phpbb2plus\community\acp;

use phpbb\request\request_interface;
use phpbb2plus\community\attachment\forbidden_extensions;

class attachment_blocks_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_attachment_blocks';
	public $page_title = 'ACP_PLUS_ATTACH_BLOCKS';

	public function main($id, $mode)
	{
		global $auth, $phpbb_container, $request, $table_prefix, $template, $user;
		if ($mode !== 'manage' || !defined('IN_ADMIN') || !IN_ADMIN || empty($user->data['session_admin']) ||
			empty($user->data['is_registered']) || !empty($user->data['user_perm_from']) || !$auth->acl_get(forbidden_extensions::PERMISSION))
		{ send_status_line(403, 'Forbidden'); trigger_error('NO_AUTH_OPERATION', E_USER_WARNING); }
		$rules = $phpbb_container->get('phpbb2plus.community.attachment_blocks');
		$language = $phpbb_container->get('language');
		$language->add_lang('acp/attachments');
		$language->add_lang('info_acp_attachment_blocks', 'phpbb2plus/community');
		if ($request->server('REQUEST_METHOD') === 'POST')
		{
			if (!check_form_key('plus_attach_blocks')) { trigger_error('FORM_INVALID', E_USER_WARNING); }
			try
			{
				$rules->save($request->variable('revision', -1, false, request_interface::POST),
					trim($request->variable('extension', '', true, request_interface::POST)),
					$request->variable('remove', array(0), false, request_interface::POST), $user, $auth, $phpbb_container->get('log'), $table_prefix);
				redirect($this->u_action);
			}
			catch (\DomainException $e)
			{
				if ($e->getMessage() === 'NO_AUTH_OPERATION') { send_status_line(403, 'Forbidden'); trigger_error('NO_AUTH_OPERATION', E_USER_WARNING); }
				$template->assign_var('BLOCK_ERROR', $language->lang($e->getMessage()));
			}
		}
		add_form_key('plus_attach_blocks');
		$template->assign_vars(array('U_BLOCKS'=>$this->u_action, 'BLOCK_REVISION'=>$rules->revision()));
		foreach ($rules->listing() as $row)
		{
			$template->assign_block_vars('block_rule', array('ID'=>$row['rule_id'], 'NAME'=>$row['extension_name'], 'FIXED'=>$row['fixed'],
				'CONFLICT'=>$row['conflict'], 'HISTORICAL'=>$row['extension_key'] === ''));
		}
	}
}
