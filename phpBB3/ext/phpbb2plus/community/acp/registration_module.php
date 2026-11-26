<?php
namespace phpbb2plus\community\acp;

use phpbb\request\request_interface;

class registration_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_registration';
	public $page_title = 'ACP_PLUS_USER_REGISTER';

	private function deny()
	{
		send_status_line(403, 'Forbidden');
		trigger_error('NO_AUTH_OPERATION', E_USER_WARNING);
	}

	public function main($module, $mode)
	{
		global $phpbb_container, $phpbb_root_path, $phpEx, $user, $request, $config, $template;
		require_once $phpbb_root_path . 'includes/functions_user.' . $phpEx;
		$accounts = $phpbb_container->get('phpbb2plus.community.account_registration');
		try { $accounts->authorize(); } catch (\DomainException $e) { $this->deny(); }
		$user->add_lang(array('ucp', 'acp/users'));
		$user->add_lang_ext('phpbb2plus/community', array('info_acp_registration', 'password_rules'));
		$policy = $phpbb_container->get('phpbb2plus.community.password_policy');
		$profiles = $phpbb_container->get('profilefields.manager');
		$user->profile_fields = array();
		$post = request_interface::POST;
		$data = array(
			'username' => $request->variable('username', '', true, $post),
			'email' => strtolower($request->variable('email', '', false, $post)),
			'new_password' => $request->untrimmed_variable('new_password', '', true, $post),
			'lang' => $request->variable('lang', (string) $config['default_lang'], false, $post),
			'style' => $request->variable('style', (int) $config['default_style'], false, $post),
			'tz' => $request->variable('tz', (string) $config['board_timezone'], false, $post),
			'dateformat' => $request->variable('dateformat', (string) $config['default_dateformat'], true, $post),
		);
		$errors = array();
		if ($request->server('REQUEST_METHOD') === 'POST')
		{
			if (!check_form_key('plus_account_register')) { trigger_error('FORM_INVALID', E_USER_WARNING); }
			$keys = validate_data($data, array(
				'username' => array(array('string', false, $config['min_name_chars'], $config['max_name_chars']), array('username', '')),
				'new_password' => array('string', false, $config['min_pass_chars'], 0),
				'email' => array(array('string', false, 6, 60), array('user_email', '')),
				'tz' => array('timezone'), 'lang' => array('language_iso_name'),
			));
			if (validate_password($data['new_password'])) { $keys[] = 'INVALID_CHARS_NEW_PASSWORD'; }
			if ($policy->enabled() && ($error = $policy->submitted_error($request, 'new_password', $data['new_password'], $data['username'])))
			{ $keys[] = $error; }
			if (!hash_equals($data['new_password'], $request->untrimmed_variable('password_confirm', '', true, $post))) { $keys[] = 'NEW_PASSWORD_ERROR'; }
			if (!phpbb_style_is_active($data['style'])) { $keys[] = 'ACP_PLUS_REGISTER_STYLE'; }
			if ($data['dateformat'] === '' || utf8_strlen($data['dateformat']) > 64) { $keys[] = 'ACP_PLUS_REGISTER_DATE'; }
			$errors = array_map(array($user, 'lang'), array_unique($keys));
			$fields = array();
			$profiles->submit_cp_field('register', $user->get_iso_lang_id(), $fields, $errors);
			if (!$errors)
			{
				$password = $phpbb_container->get('passwords.manager')->hash($data['new_password']);
				if (!$password) { trigger_error('ACP_PLUS_REGISTER_FAILED', E_USER_WARNING); }
				try
				{
					$accounts->create($data, $password, $fields, !empty($config['new_member_post_limit']), $phpbb_container->get('log'));
				}
				catch (\DomainException $e)
				{
					if ($e->getMessage() === 'NO_AUTH_OPERATION') { $this->deny(); }
					if (!in_array($e->getMessage(), array('ACP_PLUS_REGISTER_FAILED', 'ACP_PLUS_REGISTER_STYLE', 'WRONG_DATA_LANG',
						'USERNAME_TAKEN_USERNAME', 'EMAIL_TAKEN_EMAIL'), true)) { throw $e; }
					$errors[] = $user->lang($e->getMessage());
				}
				if (!$errors) { trigger_error($user->lang('ACP_PLUS_REGISTERED', $data['username']) . adm_back_link($this->u_action)); }
			}
		}
		add_form_key('plus_account_register');
		$profiles->generate_profile_fields('register', $user->get_iso_lang_id());
		$timezone = phpbb_validate_timezone($data['tz']) ? $config['board_timezone'] : $data['tz'];
		phpbb_timezone_select($template, $user, $timezone);
		$now = $user->create_datetime('now', new \DateTimeZone($timezone));
		foreach (array_keys($user->lang['dateformats']) as $format)
		{
			$template->assign_block_vars('register_formats', array('FORMAT' => $format, 'EXAMPLE' => $now->format($format, true)));
		}
		$template->assign_vars(array('U_ACTION' => $this->u_action, 'REGISTER_NAME' => $data['username'], 'REGISTER_EMAIL' => $data['email'],
			'REGISTER_LANGUAGES' => language_select($data['lang']), 'REGISTER_STYLES' => style_select($data['style']),
			'REGISTER_DATE_FORMAT' => $data['dateformat'], 'REGISTER_ERRORS' => implode('<br>', $errors),
			'REGISTER_PASSWORD_HINT' => $user->lang($config['pass_complex'] . '_EXPLAIN', $user->lang('CHARACTERS_XY', (int) $config['min_pass_chars'])),
			'REGISTER_PLUS_HINT' => $policy->enabled() ? $user->lang('PLUS_PW_BYTES_HINT', $policy->minimum()) : '',
			'REGISTER_NAME_RULE' => $policy->enabled() && !empty($config['plus_pw_no_name']),
			'REGISTER_LETTER_DIGIT_RULE' => $policy->enabled() && !empty($config['plus_pw_letters_digits'])));
	}
}
