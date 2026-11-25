<?php
function delegation_fixture_grants()
{
	$words = md5('GeneralWord_Censoradmin_words.php');
	return implode('|', array($words, md5('KB_titleTypes_manadmin_kb_types.php'), md5('GeneralSmiliesadmin_smilies.php'),
		$words, str_repeat('e', 32), 'unknown', md5('UsersDisallowadmin_disallow.php'), md5('KB_titleArt_manadmin_kb_art.php')));
}

function seed_delegation($db)
{
	$db->query("INSERT INTO phpbb_users(user_id,username,user_active,user_password,user_lang,user_email)
		SELECT 91,'JuniorFixture',1,user_password,'english','junior@example.invalid' FROM phpbb_users WHERE user_id=2");
	$db->query("INSERT INTO phpbb_users(user_id,username,user_active,user_password,user_lang,user_email)
		SELECT 92,'DormantJunior',0,user_password,'english','dormant@example.invalid' FROM phpbb_users WHERE user_id=2");
	$insert = $db->prepare('INSERT INTO phpbb_jr_admin_users (user_id,user_jr_admin,start_date,update_date,admin_notes,notes_view) VALUES (?,?,100123,200456,?,?)');
	foreach (array(
		array(1, '', 'Private source note', 0),
		array(2, md5('UsersRanksadmin_ranks.php'), '', 0),
		array(91, delegation_fixture_grants(), 'Fixture <only> & 😀', 1),
		array(92, md5('UsersDisallowadmin_disallow.php'), 'Inactive note', 1),
		array(1888, md5('UsersRanksadmin_ranks.php'), '', 0),
	) as $fixture)
	{
		list($id, $grants, $note, $visible) = $fixture;
		$insert->bind_param('issi', $id, $grants, $note, $visible);
		$insert->execute();
	}
}

function verify_delegation($db)
{
	$rows = array_column($db->query('SELECT * FROM p3_plus_jr_records ORDER BY source_user')->fetch_all(MYSQLI_ASSOC), null, 'source_user');
	$active = $rows[91];
	$modules = json_decode($active['report_json'], true);
	$review = array_pop($modules);
	check_fixture($review['route'] === 'admin_kb_art.php' && in_array($review['result'], array('delegated', 'target-module-too-broad'), true),
		'The article review delegation has no audited decision');
	$expected = array('a_words', 'a_plus_kb_types');
	if ($review['result'] === 'delegated') { $expected[] = 'a_plus_kb_articles'; }
	check_fixture(base64_decode($active['modules_b64'], true) === delegation_fixture_grants() &&
		base64_decode($active['notes_b64'], true) === 'Fixture <only> & 😀' && (int) $active['notes_view'] === 1 &&
		(int) $active['start_date'] === 100123 && (int) $active['update_date'] === 200456, 'Junior Admin source grants or notes changed');
	check_fixture(json_decode($active['rights_json'], true) === $expected &&
		array_column($modules, 'result') === array('delegated', 'delegated', 'manual-review', 'delegated', 'unresolved-module', 'invalid-hash', 'modified-source-module') &&
		$modules[2]['route'] === 'admin_smilies.php', 'Junior Admin module decisions diverged: ' . $active['report_json']);
	$historic = (int) $db->query("SELECT user_id FROM p3_users WHERE username='HistoricMember'")->fetch_row()[0];
	check_fixture((int) $rows[1]['user_id'] === $historic && !$rows[1]['notes_view'] && $rows[1]['rights_json'] === '[]' &&
		$rows[2]['account_state'] === 'full-administrator' && $rows[2]['rights_json'] === '[]' &&
		$rows[92]['account_state'] === 'inactive-account' && $rows[92]['rights_json'] === '[]' &&
		$rows[1888]['account_state'] === 'missing-account' && (int) $rows[1888]['user_id'] === 0,
		'Junior Admin account provenance or remapped user ID lost');
	$delegates = array_map('intval', array_column($db->query('SELECT user_id FROM p3_users WHERE plus_jr_delegate=1')->fetch_all(MYSQLI_ASSOC), 'user_id'));
	check_fixture($delegates === array(91), 'An unintended account received delegated privileges');
}
