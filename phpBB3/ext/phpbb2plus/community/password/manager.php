<?php
namespace phpbb2plus\community\password;

class manager extends \phpbb\passwords\manager
{
	private $weak_before_rehash = '';
	private $login_input;

	/** Keep significant characters through the DB provider's trim and presence check. */
	public function with_login_input($password, callable $login)
	{
		if ($password !== '0' && trim($password) === $password)
		{
			return $login($password);
		}
		// Only the native provider sees this per-call token. Both the hash check
		// and any successful rehash receive exactly the original credential.
		$token = bin2hex(random_bytes(24));
		$previous = $this->login_input;
		$this->login_input = array($token, $password);
		try
		{
			return $login($token);
		}
		finally
		{
			$this->login_input = $previous;
		}
	}

	private function exact_input($password)
	{
		return $this->login_input !== null && $password === $this->login_input[0] ? $this->login_input[1] : $password;
	}

	public function hash($password, $type = '')
	{
		return parent::hash($this->exact_input($password), $type);
	}

	public function check($password, $hash, $user_row = array())
	{
		$this->weak_before_rehash = '';
		$valid = parent::check($this->exact_input($password), $hash, $user_row);
		if ($valid && $this->convert_flag && !empty($user_row['plus_pw_weak_hash']) &&
			hash_equals($user_row['plus_pw_weak_hash'], hash('sha256', $hash)))
		{
			$this->weak_before_rehash = $user_row['plus_pw_weak_hash'];
		}
		return $valid;
	}

	public function take_weak_hash()
	{
		$previous = $this->weak_before_rehash;
		$this->weak_before_rehash = '';
		return $previous;
	}
}
