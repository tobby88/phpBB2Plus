<?php
namespace phpbb2plus\community\auth;

use phpbb2plus\community\password\account_request;

class password_login extends \phpbb\auth\provider\db
{
	private $rules, $renewal_manager;

	public function set_policy($rules, $renewal_manager)
	{
		$this->rules = $rules;
		$this->renewal_manager = $renewal_manager;
	}

	public function login($username, $password)
	{
		$result = $this->renewal_manager->with_login_input($password, function ($input) use ($username) {
			return parent::login($username, $input);
		});
		$previous = $this->renewal_manager->take_weak_hash();
		if ($result['status'] === LOGIN_SUCCESS && $previous !== '')
		{
			// Rehashing a verified password changes its storage representation,
			// but only a real replacement can consume the existing renewal marker.
			$row = $result['user_row'];
			$digest = hash('sha256', $row['user_password']);
			$this->db->sql_query('UPDATE ' . USERS_TABLE . " SET plus_pw_weak_hash = '" . $this->db->sql_escape($digest) . "'
				WHERE user_id = " . (int) $row['user_id'] . ' AND user_passchg = ' . (int) $row['user_passchg'] .
				" AND user_password = '" . $this->db->sql_escape($row['user_password']) . "' AND plus_pw_weak_hash = '" . $this->db->sql_escape($previous) . "'");
		}
		if ($result['status'] === LOGIN_SUCCESS && $this->rules->enabled())
		{
			// Use the stored name even if the member signed in with another letter case.
			$result['plus_weak_password'] = (bool) $this->rules->submitted_error($this->request,
				account_request::login_field($this->request), $password, $result['user_row']['username']);
		}
		return $result;
	}
}
