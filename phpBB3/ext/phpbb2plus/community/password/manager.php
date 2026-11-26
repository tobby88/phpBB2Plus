<?php
namespace phpbb2plus\community\password;

/** Remember an existing renewal marker only when phpBB verifies and upgrades its exact hash. */
class manager extends \phpbb\passwords\manager
{
	private $weak_before_rehash = '';

	public function check($password, $hash, $user_row = array())
	{
		$this->weak_before_rehash = '';
		$valid = parent::check($password, $hash, $user_row);
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
