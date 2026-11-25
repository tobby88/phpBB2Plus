<?php
namespace phpbb2plus\knowledgebase\acp;

use phpbb\request\request_interface;
use phpbb2plus\knowledgebase\service\settings;

class settings_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_kb_settings';
	public $page_title = 'ACP_PLUS_KB_SETTINGS';

	public function main($id, $mode)
	{
		global $auth, $phpbb_container, $request, $template;
		if (!$auth->acl_gets('a_plus_kb','a_plus_kb_settings')) { trigger_error('NO_AUTH_OPERATION', E_USER_WARNING); }
		$language = $phpbb_container->get('language');
		$language->add_lang(array('knowledgebase','info_acp_knowledgebase'), 'phpbb2plus/knowledgebase');
		$settings = $phpbb_container->get('phpbb2plus.knowledgebase.settings');
		$input = array();
		$section = '';
		$error = '';
		if ($request->server('REQUEST_METHOD') === 'POST')
		{
			$section = $request->variable('section', '', false, request_interface::POST);
			if (!isset(settings::SECTIONS[$section]) || !check_form_key('plus_kb_settings_' . $section)) { trigger_error('FORM_INVALID', E_USER_WARNING); }
			foreach (settings::SECTIONS[$section] as $name => $field)
			{
				if ($field[0] === 'bool') { $input[$name] = $request->variable($name, '0', false, request_interface::POST); }
				elseif ($request->is_set_post($name)) { $input[$name] = htmlspecialchars_decode($request->variable($name, '', true, request_interface::POST), ENT_QUOTES); }
			}
			try
			{
				$settings->save($section, $input, $request->variable('state', '', false, request_interface::POST));
				$template->assign_var('KB_SAVED', true);
			}
			catch (\DomainException $e)
			{
				$error = $language->lang($e->getMessage());
				if ($e->getMessage() !== 'ACP_PLUS_KB_SETTINGS_INVALID') { $input = array(); }
			}
		}
		foreach (settings::SECTIONS as $group => $fields)
		{
			$stored = $settings->values($group);
			add_form_key('plus_kb_settings_' . $group);
			$template->assign_block_vars('kb_sections', array('KEY' => $group, 'TITLE' => $language->lang('ACP_PLUS_KB_SET_' . strtoupper($group)),
				'STATE' => settings::revision($stored), 'TOKEN' => $template->retrieve_var('S_FORM_TOKEN'), 'ERROR' => $group === $section ? $error : ''));
			foreach ($fields as $name => $field)
			{
				$value = $group === $section && $error !== '' ? ($input[$name] ?? $stored[$name]) : $stored[$name];
				$template->assign_block_vars('kb_sections.fields', array('NAME' => $name, 'LABEL' => $language->lang('ACP_PLUS_KB_OPT_' . strtoupper($name)),
					'KIND' => $field[0], 'VALUE' => $value, 'LIMIT' => in_array($field[0], array('text','memo'), true) ? $field[2] : 0,
					'CHECKED' => $value === '1'));
				if ($field[0] === 'notify') { $choices = array($language->lang('NO'), $language->lang('PRIVATE_MESSAGE'), $language->lang('EMAIL')); }
				elseif ($field[0] === 'choice')
				{
					$choices = array();
					foreach ($field[2] as $option) { $choices[$option] = $language->lang('ACP_PLUS_KB_SORT_' . strtoupper($option)); }
				}
				elseif ($field[0] === 'forum')
				{
					$choices = array(0 => $language->lang('ACP_PLUS_KB_NO_DISCUSSION_FORUM')) + $settings->forums();
					if (!array_key_exists($stored[$name], $choices)) { $choices[$stored[$name]] = $language->lang('ACP_PLUS_KB_KEEP_FORUM', $stored[$name]); }
				}
				else { continue; }
				foreach ($choices as $key => $label)
				{
					$template->assign_block_vars('kb_sections.fields.options', array('KEY' => $key, 'LABEL' => $label, 'SELECTED' => (string) $value === (string) $key));
				}
			}
		}
		$template->assign_var('U_SAVE', $this->u_action);
	}
}
