<?php
function seed_warning_cards($db)
{
	$db->query("UPDATE phpbb_config SET config_value='3' WHERE config_name='max_user_bancard'");
	$db->query('UPDATE phpbb_users SET user_warnings=5, user_blocktime=1000 WHERE user_id=1');
	$db->query('UPDATE phpbb_users SET user_warnings=9 WHERE user_id=-1');
	foreach (array(63 => 'ImportedFounderFixture', 64 => 'PermanentCardFixture', 65 => 'TemporaryCardFixture', 66 => 'EmailCardFixture') as $id => $username)
	{
		$db->query("INSERT INTO phpbb_users (user_id,username,user_password,user_active,user_lang,user_email)
			SELECT $id,'$username',user_password,1,'english','member$id@example.invalid' FROM phpbb_users WHERE user_id=2");
	}
	$db->query('UPDATE phpbb_users SET user_warnings=2 WHERE user_id=64');
	$db->query('UPDATE phpbb_users SET user_blocktime=2147483647 WHERE user_id=65');
	$db->query('UPDATE phpbb_users SET user_level=1,user_regdate=100500 WHERE user_id=63');
	$db->query("UPDATE phpbb_users SET user_email='old+address@example.invalid' WHERE user_id=66");
	// This missing source ID is where the native converter will move source account 1.
	$missing = (int) $db->query('SELECT MAX(user_id)+1 FROM phpbb_users')->fetch_row()[0];
	$db->query("INSERT INTO phpbb_banlist (ban_id,ban_userid,ban_ip,ban_ip_mask,ban_email) VALUES
		(601,64,'c00002ff','ffffffff','@suspended.example.invalid'),
		(602,0,'ff0001ff','',''),(603,63,'c0000280','ff00ffff',''),
		(604,$missing,'','','old+address@example.invalid'),(605,-1,'','','')");
}

function verify_warning_cards($db, $historic, $after = false)
{
	$row = $db->query("SELECT plus_card_count,user_warnings,user_last_warning FROM p3_users WHERE user_id=$historic")->fetch_assoc();
	check_fixture((int) $row['plus_card_count'] === ($after ? 0 : 5) && (int) $row['user_warnings'] === ($after ? 1 : 0)
		&& ($after ? (int) $row['user_last_warning'] > 0 : (int) $row['user_last_warning'] === 0),
		'The native warning count or date became mixed with the historical card count');
	check_fixture((int) $db->query("SELECT COUNT(*) FROM p3_warnings WHERE user_id=$historic")->fetch_row()[0] === ($after ? 1 : 0),
		'Unexpected individual warning events for a historical counter');
	check_fixture((int) $db->query("SELECT COUNT(*) FROM p3_banlist WHERE ban_userid IN ($historic,1)")->fetch_row()[0] === 0,
		'An orphan, guest ID, expired block or historical threshold produced a ban');
	$bans = $db->query('SELECT * FROM p3_banlist');
	$permanent = $temporary = 0;
	$ips = $emails = array();
	while ($ban = $bans->fetch_assoc())
	{
		check_fixture((int) $ban['plus_card_legacy'] === 1 && (int) $ban['ban_start'] === 0 && $ban['ban_reason'] === '' && $ban['ban_give_reason'] === '',
			'The converter invented the beginning or reason of a ban');
		check_fixture((int) ((int) $ban['ban_userid'] > 0) + (int) ($ban['ban_ip'] !== '') + (int) ($ban['ban_email'] !== '') === 1,
			'Each native ban must be individually editable');
		if ((int) $ban['ban_userid'] === 64 && !(int) $ban['ban_end']) { ++$permanent; }
		if ((int) $ban['ban_userid'] === 65 && (int) $ban['ban_end'] === 2147483647) { ++$temporary; }
		if ($ban['ban_ip'] !== '') { $ips[] = $ban['ban_ip']; }
		if ($ban['ban_email'] !== '') { $emails[] = $ban['ban_email']; }
	}
	sort($ips);
	sort($emails);
	check_fixture($permanent === 1 && $temporary === 1 && $ips === array('192.*.2.128','192.0.2.255','255.0.1.*')
		&& $emails === array('*@suspended.example.invalid','old+address@example.invalid'), 'Legacy ban rules changed scope or lifetime');
	check_fixture((int) $db->query('SELECT user_type FROM p3_users WHERE user_id=63')->fetch_row()[0] === 3
		&& (int) $db->query('SELECT COUNT(*) FROM p3_banlist WHERE ban_userid=63 AND ban_end=0')->fetch_row()[0] === 1,
		'An explicitly blocked old administrator lost its identity or ban');
	check_fixture((int) $db->query("SELECT config_value FROM p3_config WHERE config_name='plus_card_threshold'")->fetch_row()[0] === 3
		&& (int) $db->query('SELECT plus_card_count FROM p3_users WHERE user_id=1')->fetch_row()[0] === 0, 'Threshold or Anonymous card state is wrong');
	if ($after)
	{
		check_fixture((int) $db->query("SELECT COUNT(*) FROM p3_log WHERE log_operation='LOG_PLUS_CARDS_RESET'")->fetch_row()[0] === 2
			&& (int) $db->query('SELECT plus_card_count FROM p3_users WHERE user_id=64')->fetch_row()[0] === 0,
			'The confirmed historical resets were not audited');
	}
}
