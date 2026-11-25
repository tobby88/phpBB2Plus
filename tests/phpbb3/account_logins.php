<?php
function seed_account_logins($db, $enabled)
{
	$db->query("UPDATE phpbb_ctracker_config SET ct_config_value='" . (int) $enabled . "' WHERE ct_config_name IN ('login_history','login_ip_check')");
	$db->query("UPDATE phpbb_ctracker_config SET ct_config_value='3' WHERE ct_config_name='login_history_count'");
	$db->query("UPDATE phpbb_ctracker_config SET ct_config_value='" . (int) $enabled . "' WHERE ct_config_name='pw_control'");
	$db->query("UPDATE phpbb_ctracker_config SET ct_config_value='90' WHERE ct_config_name='pw_validity'");
	$now = time();
	$days = $enabled ? 50000 : 0;
	$db->query("UPDATE phpbb_config SET config_value='$days' WHERE config_name='max_password_age'");
	$db->query("UPDATE phpbb_users SET user_passwd_change=$now WHERE user_passwd_change=0 AND user_id>0");
	$db->query("INSERT INTO phpbb_users (user_id,username,user_password,user_lang,user_email,user_passwd_change)
		SELECT 141,'RenewalMember',user_password,'english','renewal@example.invalid',0 FROM phpbb_users WHERE user_id=2");
	$old = $now - 100 * 86400;
	// The three dates had independent meanings in Plus. A recent reset request
	// and a fresh native-age timestamp must not hide the tracker's older reminder.
	$db->query("UPDATE phpbb_users SET ct_last_pw_change=$old,ct_last_pw_reset=$now,user_passwd_change=$now WHERE user_id=1");
	$db->query("UPDATE phpbb_users SET ct_last_pw_change=$old,ct_last_pw_reset=$now,user_passwd_change=-9999 WHERE user_id=59");
	$db->query("UPDATE phpbb_users SET ct_last_pw_change=0,ct_last_pw_reset=$old,user_passwd_change=$old WHERE user_id=2");
	$db->query("UPDATE phpbb_users SET ct_enable_ip_warn=1,ct_last_ip='2001:db8:22::3',ct_last_used_ip='2001:db8:33::4' WHERE user_id=1");
	$db->query("UPDATE phpbb_users SET ct_enable_ip_warn=0 WHERE user_id=2");
	$missing = (int) $db->query('SELECT MAX(user_id)+1 FROM phpbb_users')->fetch_row()[0];
	$db->query("INSERT INTO phpbb_ctracker_loginhistory (ct_login_id,ct_user_id,ct_login_time,ct_login_ip) VALUES
		(10,1,1570000000,NULL),(82,2,1560000000,'Alt & <Netz> 📷'),(83,87,1560000000,'198.51.100.87'),
		(91,-1,1690000000,'203.0.113.91'),(92,$missing,1690000000,'203.0.113.92'),
		(9223372036854775810,1,1600000000,'2001:db8:11::81'),(9223372036854775811,1,1600000000,'2001:db8:11::82')");
	// A lowered display limit can leave dormant histories longer than a wizard batch.
	$rows = array();
	for ($i=1; $i<=201; ++$i) { $rows[] = '(' . (2000+$i) . ",89,1500000000,'192.0.2.$i')"; }
	$db->query('INSERT INTO phpbb_ctracker_loginhistory (ct_login_id,ct_user_id,ct_login_time,ct_login_ip) VALUES ' . implode(',', $rows));
}

function check_imported_logins($db, $member)
{
	$rows = $db->query("SELECT login_ip FROM p3_plus_account_logins WHERE user_id=$member ORDER BY login_time DESC,entry_id DESC")->fetch_all();
	check_fixture(array_column($rows, 0) === array('2001:db8:11::82','2001:db8:11::81',''), 'Imported login dates, tied unsigned IDs, unknown address or account mapping changed');
	check_fixture((int) $db->query('SELECT COUNT(*) FROM p3_plus_account_logins')->fetch_row()[0] === 206, 'A missing account, guest or failed login acquired private history');
	$dormant = $db->query('SELECT login_ip FROM p3_plus_account_logins WHERE user_id=89 ORDER BY entry_id')->fetch_all();
	check_fixture(count($dormant) === 201 && $dormant[0][0] === '192.0.2.1' && $dormant[200][0] === '192.0.2.201', 'A dormant login history was truncated or duplicated between import batches');
	$owner = $db->query("SELECT plus_login_warn,plus_login_previous,plus_login_current FROM p3_users WHERE user_id=$member")->fetch_row();
	check_fixture((int) $owner[0] === 1 && $owner[1] === '2001:db8:22::3' && $owner[2] === '2001:db8:33::4', 'Lost the account network preference or IP pair');
	check_fixture((int) $db->query('SELECT plus_login_warn FROM p3_users WHERE user_id=2')->fetch_row()[0] === 0, 'The other member should not see network notices');
	$dates = $db->query("SELECT user_passchg,plus_pw_native_origin,plus_pw_notice_change FROM p3_users WHERE user_id=$member")->fetch_row();
	check_fixture((int) $dates[0] === (int) $dates[1] && (int) $dates[0] - (int) $dates[2] === 100 * 86400,
		'The independent native renewal and tracker reminder dates changed during conversion');
	check_fixture((int) $db->query('SELECT user_passchg FROM p3_users WHERE user_id=59')->fetch_row()[0] === 0,
		'The negative source sentinel became a native password change date');
	$policy = $db->query("SELECT config_value FROM p3_config WHERE config_name='plus_pw_age_policy'")->fetch_row()[0];
	$interval = $db->query("SELECT config_value FROM p3_config WHERE config_name='chg_passforce'")->fetch_row()[0];
	$tracker = $db->query("SELECT config_value FROM p3_config WHERE config_name='plus_pw_reminder'")->fetch_row()[0];
	check_fixture((int) $policy === 1 && (int) $interval === ((int) $tracker ? 50000 : 0),
		'The native mandatory password interval lost the source setting');
	$markers = $db->query('SELECT plus_pw_renewal_seen FROM p3_users WHERE user_id IN (59,141) ORDER BY user_id')->fetch_all();
	check_fixture(array_map('intval', array_column($markers, 0)) === array(1,0), 'Known renewal prompt and first login became indistinguishable');
}
