<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class password_forms implements EventSubscriberInterface
{
	private $rules, $config, $user, $auth, $db, $request, $template, $language;
	private $username = '';
	private $reset_user = 0;

	public function __construct($rules, $config, $user, $auth, $db, $request, $template, $language)
	{
		$this->rules = $rules;
		$this->config = $config;
		$this->user = $user;
		$this->auth = $auth;
		$this->db = $db;
		$this->request = $request;
		$this->template = $template;
		$this->language = $language;
	}

	public static function getSubscribedEvents()
	{
		return array(
			'core.ucp_register_data_before' => 'registration',
			'core.ucp_profile_reg_details_data' => 'account',
			'core.acp_users_overview_before' => 'manage',
			'core.ucp_reset_password_modify_select_sql' => 'reset',
			'core.page_footer' => 'hint',
			'core.adm_page_footer' => 'hint',
			'core.acp_board_config_edit_add' => 'settings',
		);
	}

	private function prepare($username, $reset_user = 0)
	{
		if (!$this->rules->enabled()) { return; }
		$this->username = $username;
		$this->reset_user = $reset_user;
		$this->language->add_lang('password_rules', 'phpbb2plus/community');
		require_once __DIR__ . '/../password/validate.php';
	}

	public function registration($event) { $this->prepare($event['data']['username']); }

	public function account($event)
	{
		$rename = $this->config['allow_namechange'] && $this->auth->acl_get('u_chgname');
		$this->prepare($rename ? $event['data']['username'] : $this->user->data['username']);
	}

	public function manage($event)
	{
		$this->prepare($this->request->variable('user', $event['user_row']['username'], true));
	}

	public function reset($event) { $this->prepare('', (int) $event['user_id']); }

	public function validate($password)
	{
		$username = $this->username;
		if ($password !== '' && $this->reset_user && !empty($this->config['plus_pw_no_name']))
		{
			// The native controller has accepted the reset token by this point.
			$result = $this->db->sql_query('SELECT username FROM ' . USERS_TABLE . ' WHERE user_id = ' . $this->reset_user);
			$username = $this->db->sql_fetchfield('username');
			$this->db->sql_freeresult($result);
			if ($username === false) { return 'NO_USER'; }
		}
		return $this->rules->submitted_error($this->request, 'new_password', $password, $username, true);
	}

	public function hint()
	{
		if (!$this->rules->enabled()) { return; }
		foreach (array('L_PASSWORD_EXPLAIN', 'L_CHANGE_PASSWORD_EXPLAIN') as $key)
		{
			$native = $this->template->retrieve_var($key);
			if (!$native) { continue; }
			$this->language->add_lang('password_rules', 'phpbb2plus/community');
			$hints = array($this->language->lang('PLUS_PW_BYTES_HINT', $this->rules->minimum()));
			if ($this->config['plus_pw_no_name']) { $hints[] = $this->language->lang('PLUS_PW_NAME_ERROR'); }
			if ($this->config['plus_pw_letters_digits']) { $hints[] = $this->language->lang('PLUS_PW_LETTER_DIGIT_ERROR'); }
			if ((int) $this->config['min_pass_chars'] > 1 || $this->config['pass_complex'] !== 'PASS_TYPE_ANY') { $hints[] = $native; }
			$this->template->assign_var($key, implode(' ', $hints));
		}
	}

	public function settings($event)
	{
		$display = $event['display_vars'];
		if (!isset($display['vars']['pass_complex'])) { return; }
		$this->language->add_lang('password_rules', 'phpbb2plus/community');
		$fields = array();
		foreach ($display['vars'] as $key => $definition)
		{
			$fields[$key] = $definition;
			if ($key !== 'pass_complex') { continue; }
			foreach (array('plus_pw_rules', 'plus_pw_min_bytes', 'plus_pw_no_name', 'plus_pw_letters_digits') as $name)
			{
				$length = $name === 'plus_pw_min_bytes';
				$fields[$name] = array('lang' => strtoupper($name), 'validate' => $length ? 'int:0:72' : 'bool',
					'type' => $length ? 'number:0:72' : 'radio:yes_no', 'explain' => true);
			}
		}
		$display['vars'] = $fields;
		$event['display_vars'] = $display;
	}
}
