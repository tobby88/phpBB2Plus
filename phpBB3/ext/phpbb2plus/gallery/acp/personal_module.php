<?php
namespace phpbb2plus\gallery\acp;

use phpbb\request\request_interface;
use phpbb2plus\gallery\service\personal_settings;

class personal_module
{
	public $u_action;
	public $tpl_name;
	public $page_title;

	public function main($id, $mode)
	{
		global $auth, $phpbb_container, $request, $template, $user;
		if (!$auth->acl_get('a_plus_gallery')) { trigger_error('NO_AUTH_OPERATION', E_USER_WARNING); }
		$language = $phpbb_container->get('language');
		$language->add_lang('info_acp_gallery', 'phpbb2plus/gallery');
		$this->tpl_name = 'acp_plus_personal_albums';
		$this->page_title = 'ACP_PLUS_PERSONAL_ALBUMS';
		$settings = $phpbb_container->get('phpbb2plus.gallery.personal_settings');
		$correction = array();
		if ($request->server('REQUEST_METHOD') === 'POST')
		{
			if (!check_form_key('plus_personal_albums')) { trigger_error('FORM_INVALID', E_USER_WARNING); }
			$values = array();
			foreach (personal_settings::DEFAULTS as $name => $default) { $values[$name] = $request->variable($name, '', false, request_interface::POST); }
			try
			{
				if ($settings->save($values, $request->variable('revision', '', false, request_interface::POST)))
				{
					$phpbb_container->get('log')->add('admin', $user->data['user_id'], $user->ip, 'LOG_PLUS_PERSONAL_ALBUMS');
				}
				$template->assign_var('S_SAVED', true);
			}
			catch (\InvalidArgumentException $e)
			{
				$correction = $values;
				$field = $language->lang('PLUS_ALBUM_CFG_' . strtoupper($e->getMessage()));
				$template->assign_var('ALBUM_ERROR', $language->lang('PLUS_ALBUM_CONFIG_INVALID', $field));
			}
			catch (\DomainException $e) { $template->assign_var('ALBUM_ERROR', $language->lang($e->getMessage())); }
		}
		$stored = $settings->snapshot();
		foreach (personal_settings::DEFAULTS as $name => $default)
		{
			$current = (string) ($stored[$name] ?? $default);
			$options = personal_settings::OPTIONS[$name] ?? array();
			$value = $correction[$name] ?? $current;
			if ($options && !array_key_exists($value, $options)) { $value = $current; }
			$template->assign_block_vars('controls', array('NAME' => $name, 'LABEL' => $language->lang('PLUS_ALBUM_CFG_' . strtoupper($name)),
				'VALUE' => $value, 'IS_SELECT' => !empty($options)));
			foreach ($options as $key => $label)
			{
				$template->assign_block_vars('controls.options', array('VALUE' => $key, 'LABEL' => $language->lang('PLUS_ALBUM_CFG_' . $label), 'SELECTED' => (string) $key === $value));
			}
			if ($options && !array_key_exists($current, $options))
			{
				$template->assign_block_vars('controls.options', array('VALUE' => $current, 'LABEL' => $language->lang('PLUS_ALBUM_CFG_IMPORTED', $current), 'SELECTED' => $value === $current));
			}
		}
		add_form_key('plus_personal_albums');
		$template->assign_vars(array('U_ACTION' => $this->u_action, 'REVISION' => personal_settings::revision($stored)));
	}
}
