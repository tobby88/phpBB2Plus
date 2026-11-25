<?php
namespace phpbb2plus\arcade\acp;

use phpbb\request\request_interface;
use phpbb2plus\arcade\service\settings;

class settings_module
{
	public $u_action;
	public $tpl_name;
	public $page_title;

	public function main($id, $mode)
	{
		global $auth, $phpbb_container, $request, $template, $user;
		$language = $phpbb_container->get('language');
		$language->add_lang('info_acp_arcade', 'phpbb2plus/arcade');
		if (!$auth->acl_get('a_plus_arcade')) { trigger_error('NO_AUTH_OPERATION', E_USER_WARNING); }
		$this->tpl_name = 'acp_plus_arcade_settings';
		$this->page_title = 'ACP_PLUS_ARCADE_SETTINGS';
		$settings = $phpbb_container->get('phpbb2plus.arcade.settings');
		if ($request->is_set_post('section'))
		{
			$group = $request->variable('section', '', false, request_interface::POST);
			if (!isset(settings::GROUPS[$group]) || !check_form_key('plus_arc_settings_' . $group)) { trigger_error('FORM_INVALID', E_USER_WARNING); }
			$values = array();
			foreach (settings::GROUPS[$group] as $name=>$bounds)
			{
				$values[$name] = $request->variable($name, $bounds[2] === 1 ? '0' : '', false, request_interface::POST);
			}
			$revision = $request->variable('revision', '', false, request_interface::POST);
			try
			{
				$phpbb_container->get('phpbb2plus.arcade.sessions')->locked(function () use ($settings, $group, $values, $revision, $phpbb_container, $user) {
					if ($settings->save($group, $values, $revision))
					{
						$phpbb_container->get('log')->add('admin', $user->data['user_id'], $user->ip, 'LOG_PLUS_ARCADE_SETTINGS');
					}
				});
				$template->assign_var('S_SAVED', true);
			}
			catch (\DomainException $e) { $template->assign_var('ARC_ERROR', $language->lang($e->getMessage())); }
		}
		foreach (settings::GROUPS as $group=>$fields)
		{
			$stored = $settings->snapshot($group);
			add_form_key('plus_arc_settings_' . $group);
			$template->assign_block_vars('sections', array('KEY'=>$group, 'TITLE'=>$language->lang('PLUS_ARC_CONFIG_' . strtoupper($group)),
				'REVISION'=>settings::revision($stored), 'TOKEN'=>$template->retrieve_var('S_FORM_TOKEN')));
			foreach ($fields as $name=>$bounds)
			{
				$template->assign_block_vars('sections.fields', array('KEY'=>$name, 'LABEL'=>$language->lang('PLUS_ARC_OPT_' . strtoupper($name)),
					'IS_BOOL'=>$bounds[2] === 1, 'VALUE'=>$stored[$name] ?? $bounds[0], 'MIN'=>$bounds[1], 'MAX'=>$bounds[2]));
			}
		}
		$template->assign_var('U_ACTION', $this->u_action);
	}
}
