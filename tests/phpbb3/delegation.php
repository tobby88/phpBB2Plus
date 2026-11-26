<?php
function delegation_fixture_grants()
{
	$words = md5('GeneralWord_Censoradmin_words.php');
	return implode('|', array($words, md5('KB_titleTypes_manadmin_kb_types.php'), md5('GeneralSmiliesadmin_smilies.php'),
		$words, str_repeat('e', 32), 'unknown', md5('UsersDisallowadmin_disallow.php'),
		md5('DownloadCat_manage_titleadmin_pa_category.php'), md5('KB_titleArt_manadmin_kb_art.php')));
}

function delegation_fixture_roles()
{
	return array(
		35 => array('LinkAddJunior', 'LinksAdd_newadmin_links.php?mode=add',
			array('a_plus_wl_create','a_plus_wl_edit','a_plus_wl_delete','a_plus_wl_review')),
		36 => array('LinkManageJunior', 'LinksManageadmin_links.php?mode=view',
			array('a_plus_wl_create','a_plus_wl_edit','a_plus_wl_delete','a_plus_wl_review')),
		37 => array('LinkCategoryJunior', 'LinksCategoryadmin_links_cat.php', 'a_plus_wl_categories'),
		38 => array('LinkSettingsJunior', 'LinksConfigurationadmin_links_config.php', 'a_plus_wl_settings'),
		43 => array('DownloadCheckerJunior', 'DownloadFcheckeradmin_pa_fchecker.php', 'a_plus_dl_checker'),
		44 => array('ArcadeCategoryJunior', 'ArcadeCategoriesadmin_arcade_cats.php', 'a_plus_arc_categories'),
		45 => array('ArcadeScoresJunior', 'ArcadeEditadmin_arcade_games.php?mode=edit_games', 'a_plus_arc_scores'),
		46 => array('DownloadCategoryJunior', 'DownloadCat_manage_titleadmin_pa_category.php', 'a_plus_dl_categories'),
		47 => array('DownloadAccessJunior', 'DownloadPermissionsadmin_pa_catauth.php', 'a_plus_dl_perms'),
		48 => array('AlbumCategoryJunior', 'Photo_AlbumCategoriesadmin_album_cat.php', 'a_plus_album_categories'),
		49 => array('CreditsJunior', 'GeneralHacks_Listadmin_hacks_list.php', 'a_plus_credits'),
		50 => array('AlbumAccessJunior', 'Photo_AlbumPermissionsadmin_album_auth.php', 'a_plus_album_auth'),
		51 => array('DownloadLicenseJunior', 'DownloadLicense_titleadmin_pa_license.php', 'a_plus_dl_licenses'),
		52 => array('DownloadFieldsJunior', 'DownloadMfieldtitleadmin_pa_custom.php', 'a_plus_dl_fields'),
		53 => array('AlbumPersonalJunior', 'Photo_AlbumPersonal_Galleriesadmin_album_personal.php', 'a_plus_album_personal_auth'),
		54 => array('StatisticsModulesJunior', 'StatisticsStatistics_managementadmin_statistics.php?mode=manage', 'a_plus_stats_modules'),
		55 => array('StatisticsSettingsJunior', 'StatisticsStatistics_configadmin_statistics.php?mode=config', 'a_plus_stats_settings'),
		56 => array('DownloadFilesJunior', 'DownloadFile_manage_titleadmin_pa_file.php',
			array('a_plus_dl_edit', 'a_plus_dl_approval', 'a_plus_dl_delete', 'a_plus_dl_mirrors')),
		57 => array('DownloadSettingsJunior', 'DownloadSettingsadmin_pa_settings.php', 'a_plus_dl_settings'),
		58 => array('AlbumCacheJunior', 'Photo_AlbumClear_Cacheadmin_album_clearcache.php', 'a_plus_album_cache'),
		59 => array('TrackerSettingsJunior', 'ctracker_module_categoryctracker_module_9admin_cracker_tracker.php?modu=9', 'a_plus_ct_settings'),
		60 => array('MemberNoticeJunior', 'ctracker_module_categoryctracker_module_4admin_cracker_tracker.php?modu=4', 'a_plus_ct_notice'),
		61 => array('CountryFlagJunior', 'UsersFlagsadmin_flags.php', 'a_plus_flags'),
	);
}

function seed_delegation($db)
{
	$db->query("INSERT INTO phpbb_users(user_id,username,user_active,user_password,user_lang,user_email)
		SELECT 91,'JuniorFixture',1,user_password,'english','junior@example.invalid' FROM phpbb_users WHERE user_id=2");
	$db->query("INSERT INTO phpbb_users(user_id,username,user_active,user_password,user_lang,user_email)
		SELECT 92,'DormantJunior',0,user_password,'english','dormant@example.invalid' FROM phpbb_users WHERE user_id=2");
	$insert = $db->prepare('INSERT INTO phpbb_jr_admin_users (user_id,user_jr_admin,start_date,update_date,admin_notes,notes_view) VALUES (?,?,100123,200456,?,?)');
	$rows = array(
		array(1, '', 'Private source note', 0),
		array(2, md5('UsersRanksadmin_ranks.php'), '', 0),
		array(91, delegation_fixture_grants(), 'Fixture <only> & 😀', 1),
		array(92, md5('UsersDisallowadmin_disallow.php'), 'Inactive note', 1),
		array(1888, md5('UsersRanksadmin_ranks.php'), '', 0),
	);
	$member = $db->prepare("INSERT INTO phpbb_users(user_id,username,user_active,user_password,user_lang,user_email)
		SELECT ?,?,1,user_password,'english',? FROM phpbb_users WHERE user_id=2");
	foreach (delegation_fixture_roles() as $id => $role)
	{
		list($name, $registration) = $role;
		$email = strtolower($name) . '@example.invalid';
		$member->bind_param('iss', $id, $name, $email);
		$member->execute();
		$rows[] = array($id, md5($registration), '', 0);
	}
	foreach ($rows as $fixture)
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
	$expected = array('a_words', 'a_plus_kb_types', 'a_plus_dl_categories');
	if ($review['result'] === 'delegated') { $expected[] = 'a_plus_kb_articles'; }
	check_fixture(base64_decode($active['modules_b64'], true) === delegation_fixture_grants() &&
		base64_decode($active['notes_b64'], true) === 'Fixture <only> & 😀' && (int) $active['notes_view'] === 1 &&
		(int) $active['start_date'] === 100123 && (int) $active['update_date'] === 200456, 'Junior Admin source grants or notes changed');
	check_fixture(json_decode($active['rights_json'], true) === $expected &&
		array_column($modules, 'result') === array('delegated', 'delegated', 'manual-review', 'delegated', 'unresolved-module', 'invalid-hash', 'modified-source-module', 'delegated') &&
		$modules[2]['route'] === 'admin_smilies.php', 'Junior Admin module decisions diverged: ' . $active['report_json']);
	$historic = (int) $db->query("SELECT user_id FROM p3_users WHERE username='HistoricMember'")->fetch_row()[0];
	check_fixture((int) $rows[1]['user_id'] === $historic && !$rows[1]['notes_view'] && $rows[1]['rights_json'] === '[]' &&
		$rows[2]['account_state'] === 'full-administrator' && $rows[2]['rights_json'] === '[]' &&
		$rows[92]['account_state'] === 'inactive-account' && $rows[92]['rights_json'] === '[]' &&
		$rows[1888]['account_state'] === 'missing-account' && (int) $rows[1888]['user_id'] === 0,
		'Junior Admin account provenance or remapped user ID lost');
	foreach (delegation_fixture_roles() as $id => $role)
	{
		$row = $rows[$id];
		$report = json_decode($row['report_json'], true);
		$options = (array) $role[2];
		check_fixture((int) $row['user_id'] === $id && base64_decode($row['modules_b64'], true) === md5($role[1])
			&& json_decode($row['rights_json'], true) === $options && count($report) === 1 && $report[0]['result'] === 'delegated'
			&& ($report[0]['options'] ?? array($report[0]['option'] ?? null)) === $options,
			'Isolated delegation for ' . $role[0] . ' was not reconstructed: ' . $row['report_json']);
	}
	$delegates = array_map('intval', array_column($db->query('SELECT user_id FROM p3_users WHERE plus_jr_delegate=1 ORDER BY user_id')->fetch_all(MYSQLI_ASSOC), 'user_id'));
	check_fixture($delegates === array_merge(array_keys(delegation_fixture_roles()), array(91)), 'An unintended account received delegated privileges');
}
