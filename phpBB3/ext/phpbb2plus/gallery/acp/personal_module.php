<?php
namespace phpbb2plus\gallery\acp;

use phpbb\request\request_interface;
use phpbb2plus\gallery\service\settings;
use phpbb2plus\gallery\service\upload_archive;

class personal_module
{
	public $u_action;
	public $tpl_name;
	public $page_title;

	public function main($id, $mode)
	{
		global $phpbb_container, $request, $template, $user;
		$phpbb_container->get('phpbb2plus.gallery.admin_access')->authorize('settings');
		$language = $phpbb_container->get('language');
		$language->add_lang('info_acp_gallery', 'phpbb2plus/gallery');
		$this->tpl_name = 'acp_plus_personal_albums';
		$pages = array(
			'settings' => array('ACP_PLUS_PERSONAL_ALBUMS','plus_personal_albums','LOG_PLUS_PERSONAL_ALBUMS','PLUS_ALBUM_CFG_EXPLAIN','personal'),
			'media' => array('ACP_PLUS_ALBUM_UPLOADS','plus_album_upload_policy','LOG_PLUS_ALBUM_UPLOADS','PLUS_ALBUM_CFG_MEDIA_EXPLAIN','media'),
			'display' => array('ACP_PLUS_ALBUM_DISPLAY','plus_album_display','LOG_PLUS_ALBUM_DISPLAY','PLUS_ALBUM_CFG_DISPLAY_EXPLAIN','display'),
		);
		if (!isset($pages[$mode])) { trigger_error('NO_MODE', E_USER_WARNING); }
		list($this->page_title, $form_key, $log, $explain, $section) = $pages[$mode];
		$settings = $phpbb_container->get('phpbb2plus.gallery.settings');
		$correction = array();
		if ($request->server('REQUEST_METHOD') === 'POST')
		{
			if (!check_form_key($form_key)) { trigger_error('FORM_INVALID', E_USER_WARNING); }
			$values = array();
			foreach (settings::defaults($mode) as $name => $default) { $values[$name] = $request->variable($name, '', false, request_interface::POST); }
			try
			{
				if ($settings->save($values, $request->variable('revision', '', false, request_interface::POST), $mode))
				{
					$phpbb_container->get('log')->add('admin', $user->data['user_id'], $user->ip, $log);
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
		$stored = $settings->snapshot($mode);
		foreach (settings::defaults($mode) as $name => $default)
		{
			$current = (string) ($stored[$name] ?? $default);
			$options = settings::options($name);
			$bounds = settings::BOUNDS[$name] ?? array(-1,2147483647);
			$value = $correction[$name] ?? $current;
			if ($options && !array_key_exists($value, $options)) { $value = $current; }
			$template->assign_block_vars('controls', array('NAME' => $name, 'LABEL' => $language->lang('PLUS_ALBUM_CFG_' . strtoupper($name)),
				'VALUE' => $value, 'MIN' => min((float) $current, $bounds[0]), 'MAX' => max((float) $current, $bounds[1]),
				'IS_SELECT' => !empty($options), 'IS_INTERVAL' => $name === 'new_pic_check_interval'));
			foreach ($options as $key => $label)
			{
				$template->assign_block_vars('controls.options', array('VALUE' => $key, 'LABEL' => $language->lang('PLUS_ALBUM_CFG_' . $label), 'SELECTED' => (string) $key === $value));
			}
			if ($options && !array_key_exists($current, $options))
			{
				$template->assign_block_vars('controls.options', array('VALUE' => $current, 'LABEL' => $language->lang('PLUS_ALBUM_CFG_IMPORTED', $current), 'SELECTED' => $value === $current));
			}
		}
		add_form_key($form_key);
		$template->assign_vars(array('U_ACTION' => $this->u_action, 'REVISION' => settings::revision($stored), 'ALBUM_SETTINGS_SECTION' => $section,
			'S_ZIP_MISSING' => $mode === 'media' && !upload_archive::available(),
			'ALBUM_SETTINGS_TITLE' => $language->lang($this->page_title), 'ALBUM_SETTINGS_EXPLAIN' => $language->lang($explain)));
	}
}
