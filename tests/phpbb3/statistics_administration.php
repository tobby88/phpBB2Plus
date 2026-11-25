<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[2])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[2]) { exit(2); }
require_once './includes/acp/auth.php';
$writer = new auth_admin();
$text = $phpbb_container->get('config_text');
$roles = array('DownloadCategoryJunior' => 'a_plus_stats_modules', 'DownloadAccessJunior' => 'a_plus_stats_settings');
if ($argv[1] === 'revoke' || $argv[1] === 'restore') { $roles['StatisticsModulesJunior'] = 'a_plus_stats_modules'; }
foreach ($roles as $name => $right)
{
	$result = $db->sql_query('SELECT user_id FROM ' . USERS_TABLE . " WHERE username='" . $db->sql_escape($name) . "'");
	$id = (int) $db->sql_fetchfield('user_id');
	$db->sql_freeresult($result);
	if ($id < 2) { throw new RuntimeException('Missing synthetic statistics operator'); }
	if ($argv[1] === 'rights') { $writer->acl_set('user', 0, $id, array($right => ACL_YES)); }
	if ($argv[1] === 'revoke' && $right === 'a_plus_stats_modules') { $writer->acl_set('user', 0, $id, array($right => ACL_NEVER)); }
	if ($argv[1] === 'restore') { $writer->acl_set('user', 0, $id, array($right => ACL_NO)); }
}
$source = 'plus_statistics_source_modules';
$current = 'plus_statistics_modules';
switch ($argv[1])
{
	case 'rights':
		$prior = $text->get($current);
		if ($prior !== $text->get($source) || $text->get('plus_statistics_source_dir') !== 'stat_modules')
		{ throw new RuntimeException('Conversion did not preserve the original statistics registry'); }
		$text->set($source, '[]');
		$upgrade = new \phpbb2plus\community\migrations\v130_statistics_admin($config, $db, $phpbb_container->get('dbal.tools'), $phpbb_root_path, $phpEx, $table_prefix);
		$upgrade->preserve_registry();
		if ($text->get($source) !== $prior) { throw new RuntimeException('The existing v127 registry did not survive the v130 upgrade'); }
		break;
	case 'revoke': break;
	case 'fresh':
		// A freshly installed community extension contains no activated reports or imported registry.
		$text->set($current, '[]');
		break;
	case 'original':
		$rows = array_column(json_decode($text->get($source), true), null, 'module_id');
		if ($rows[22]['auth_value'] !== 0 || $rows[22]['display_order'] !== 13 || $rows[20]['renderer'] !== 'unported' ||
			$rows[21]['update_time'] !== 0 || count($rows) !== 16)
		{ throw new RuntimeException('Managing visible reports altered historical or unrecognised source records'); }
		break;
	case 'restore':
		$text->set($current, $text->get($source));
		$config->set('plus_statistics_limit', 2);
		$config->set('plus_statistics_storage_ttl', 600);
		break;
	default: exit(2);
}
