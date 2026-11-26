<?php
if (!defined('IN_PHPBB')) { exit; }

// validate_data() supports a phpbb_validate_* override. The native reset
// controller uses this same entry point after it verifies the recovery token.
if (!function_exists('phpbb_validate_password'))
{
	function phpbb_validate_password($password)
	{
		global $phpbb_container;
		$native = validate_password($password);
		return $native ?: $phpbb_container->get('phpbb2plus.community.password_forms')->validate($password);
	}
}
