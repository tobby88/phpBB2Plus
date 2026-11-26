<?php
namespace phpbb2plus\community\password;

use phpbb\passwords\driver\driver_interface;
use phpbb\request\request_interface;

/** Read converted Plus passwords once. phpBB rewrites a successful login with its configured driver. */
class legacy implements driver_interface
{
	const PREFIX = '$PLUS2$';
	private $request;

	public function __construct(request_interface $request)
	{
		$this->request = $request;
	}

	/** Wrap fast legacy digests before writing them to the destination. */
	public static function import($stored)
	{
		if (is_string($stored) && preg_match('/^[0-9a-f]{32}$/iD', $stored))
		{
			$protected = password_hash(strtolower($stored), PASSWORD_BCRYPT, array('cost' => 10));
			if (!$protected)
			{
				throw new \RuntimeException('Could not protect a legacy password during conversion.');
			}
			return self::PREFIX . 'm' . $protected;
		}
		if (is_string($stored) && password_get_info($stored)['algoName'] !== 'unknown')
		{
			return self::PREFIX . 'a' . $stored;
		}
		// Preserve accounts without a usable password as reset-only accounts.
		return '';
	}

	public function check($password, $hash, $user_row = array())
	{
		if (!is_string($password) || strpos($hash, self::PREFIX) !== 0)
		{
			return false;
		}
		$kind = substr($hash, strlen(self::PREFIX), 1);
		if ($kind !== 'a' && $kind !== 'm')
		{
			return false;
		}
		// phpBB3 supplies an HTML-escaped, normalized value. The phpBB2 form
		// hashed the original bytes after addslashes(), including for bcrypt.
		$raw = htmlspecialchars_decode($password, ENT_COMPAT);
		foreach (array(account_request::login_field($this->request), 'cur_password') as $field)
		{
			if ($this->request->is_set_post($field) && $this->request->untrimmed_variable($field, '', true, request_interface::POST) === $password)
			{
				$raw = $this->request->raw_variable($field, '', request_interface::POST);
				break;
			}
		}
		if (!is_string($raw) || $raw === '' || strpos($raw, "\0") !== false)
		{
			return false;
		}
		$old_input = addslashes($raw);
		return strlen($old_input) <= 128 && password_verify(
			$kind === 'm' ? md5($old_input) : $old_input,
			substr($hash, strlen(self::PREFIX) + 1)
		);
	}

	public function get_prefix() { return self::PREFIX; }
	public function is_supported() { return true; }
	public function is_legacy() { return false; }
	public function hash($password) { return false; }
	public function get_settings_only($hash, $full = false) { return false; }
}
