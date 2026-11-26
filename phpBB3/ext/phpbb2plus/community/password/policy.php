<?php
namespace phpbb2plus\community\password;

use phpbb\request\request_interface;

class policy
{
	private $config;

	public function __construct($config) { $this->config = $config; }

	public function enabled() { return !empty($this->config['plus_pw_rules']); }

	public function minimum() { return max(1, min(72, (int) $this->config['plus_pw_min_bytes'])); }

	public function name_error($password, $username)
	{
		return !empty($this->config['plus_pw_no_name']) && $password === $username ? 'PLUS_PW_NAME_ERROR' : false;
	}

	/** Match the initial form bytes to the value that the native caller validated. */
	public function submitted_error($request, $field, $password, $username, $optional = false)
	{
		$normalized = htmlspecialchars_decode($password, ENT_COMPAT);
		$original = $normalized;
		if ($request->is_set_post($field))
		{
			$submitted = $request->raw_variable($field, '', request_interface::POST);
			// phpBB removes NUL while reading a form field.
			if (!is_string($submitted) || strpos($submitted, "\0") !== false) { return 'PLUS_PW_INVALID'; }
			$canonical = $request->untrimmed_variable($field, '', true, request_interface::POST);
			if ($canonical === $password) { $original = $submitted; }
		}
		if ($optional && $password === '') { return false; }
		$name = htmlspecialchars_decode($username, ENT_COMPAT);
		return $this->error($original, $name) ?: $this->name_error($normalized, $name);
	}

	/** Both arguments are user-entered UTF-8 text, without HTML entities. */
	public function error($password, $username)
	{
		if (!$this->enabled()) { return false; }
		if (!is_string($password) || strpos($password, "\0") !== false) { return 'PLUS_PW_INVALID'; }
		$bytes = strlen(addslashes($password));
		if ($bytes < $this->minimum() || $bytes > 72) { return 'PLUS_PW_LENGTH_ERROR'; }
		if ($error = $this->name_error($password, $username)) { return $error; }
		if (!empty($this->config['plus_pw_letters_digits']) &&
			(preg_match('/\p{L}/u', $password) !== 1 || preg_match('/\p{Nd}/u', $password) !== 1))
		{
			return 'PLUS_PW_LETTER_DIGIT_ERROR';
		}
		return false;
	}
}
