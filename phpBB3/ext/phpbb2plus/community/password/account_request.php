<?php
namespace phpbb2plus\community\password;

use phpbb\request\request_interface;

class account_request extends \phpbb\request\request
{
	/** Use the native password reader for the three account fields still read with variable(). */
	public function variable($var_name, $default, $multibyte = false, $super_global = request_interface::REQUEST)
	{
		if ($default === '' && $multibyte && in_array($super_global, array(request_interface::REQUEST, request_interface::POST), true) &&
			in_array($var_name, array('new_password', 'password_confirm', 'cur_password'), true))
		{
			return parent::untrimmed_variable($var_name, $default, $multibyte, $super_global);
		}
		return parent::variable($var_name, $default, $multibyte, $super_global);
	}

	/** phpBB names the ACP reauthentication field after the form credential. */
	public static function login_field(request_interface $request)
	{
		$credential = $request->variable('credential', '', false, request_interface::POST);
		$field = 'password_' . $credential;
		return preg_match('/^[a-f0-9]{32}$/D', $credential) && $request->is_set_post($field) ? $field : 'password';
	}
}
