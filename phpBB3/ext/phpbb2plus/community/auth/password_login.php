<?php
namespace phpbb2plus\community\auth;

use phpbb\request\request_interface;

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
		$result = parent::login($username, $password);
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
