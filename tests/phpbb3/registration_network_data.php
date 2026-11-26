<?php
function seed_registration_network($db)
{
	$db->query("UPDATE phpbb_users SET user_reg_host='archived-board.example.invalid' WHERE user_id=2");
	// Source member 1 is remapped by the native wizard. Preserve even old display
	// text containing HTML punctuation, without rendering it as markup in the ACP.
	$db->query("UPDATE phpbb_users SET user_reg_ip='2001:0DB8:0000:0000:0000:0000:0000:0007',user_reg_host='archived<&>.example.invalid' WHERE user_id=1");
	$db->query("UPDATE phpbb_users SET user_reg_ip='c000026b' WHERE username='RegistrationNetworkJunior'");
	if ($db->affected_rows !== 1) { throw new RuntimeException('The synthetic IPv4 source address was not assigned'); }
}
