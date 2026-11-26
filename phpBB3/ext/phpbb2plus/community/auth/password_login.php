<?php
namespace phpbb2plus\community\auth;

use phpbb\request\request_interface;

class password_login extends \phpbb\auth\provider\db
{
	private $rules;

	public function set_rules($rules) { $this->rules = $rules; }

	public function login($username, $password)
	{
		$result = parent::login($username, $password);
		if ($result['status'] === LOGIN_SUCCESS && $this->rules->enabled())
		{
			// The DB provider checks the trimmed, HTML-escaped credential. Use the
			// stored account name even when the member typed a different letter case.
			$field = 'password';
			if (!$this->request->is_set_post($field))
			{
				$credential = $this->request->variable('credential', '', false, request_interface::POST);
				if (preg_match('/^[a-f0-9]{32}$/D', $credential)) { $field .= '_' . $credential; }
			}
			$result['plus_weak_password'] = (bool) $this->rules->submitted_error($this->request, $field,
				trim($password), $result['user_row']['username']);
		}
		return $result;
	}
}
