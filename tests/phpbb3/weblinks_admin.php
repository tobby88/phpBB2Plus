<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
define('IN_PHPBB',true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
use phpbb2plus\weblinks\service\directory;
use phpbb2plus\weblinks\service\management;
$entries = $table_prefix . 'plus_wl_entries';
$cats = $table_prefix . 'plus_wl_categories';
$jr = $table_prefix . 'plus_jr_records';
$grants = $table_prefix . 'plus_jr_delegations';
$text = $phpbb_container->get('config_text');
$rows = static function($sql) use ($db) {
	$r = $db->sql_query($sql);
	$rows = $db->sql_fetchrowset($r);
	$db->sql_freeresult($r);
	return $rows;
};
$check = static function($ok,$why) { if (!$ok) { throw new RuntimeException($why); } };
$first = static function($table,$key,$id) use ($rows) { return $rows("SELECT * FROM $table WHERE $key=$id")[0] ?? array(); };
$saved = json_decode((string) $text->get('plus_wl_acp_fixture'),true);
$admin_settings = $phpbb_container->get('phpbb2plus.weblinks.settings');
$class = '\phpbb2plus\weblinks\migrations\v101_administration';
function wl_custom_source($assert)
{
	global $convert;
	require_once '../finished-installer/convertors/plus_junior_admin.php';
	require_once '../finished-installer/convertors/plus_media.php';
	$legacy = realpath('../legacy');
	$root = dirname($legacy) . '/weblinks-customised';
	foreach (array($root,$root . '/includes',$root . '/staff') as $dir) { if (!is_dir($dir)) { mkdir($dir); } }
	foreach (array('staff/admin_links.php','staff/admin_links_cat.php','staff/admin_links_config.php','staff/pagestart.php',
		'includes/functions_jr_admin.php','includes/functions_jr_admin_auth.php','includes/functions_link_admin.php',
		'includes/functions_link_submission.php','includes/functions_link_settings.php','includes/functions_link_categories.php',
		'includes/functions.php','includes/php_compat.php') as $file) { copy($legacy . '/' . $file,$root . '/' . $file); }
	$convert = (object) array('options'=>array('forum_path'=>$root));
	file_put_contents($root . '/includes/functions_link_categories.php',"\n/* Local category workflow */\n",FILE_APPEND);
	$registry = plus_jr_registry();
	$category = $registry[md5('LinksCategoryadmin_links_cat.php')];
	$entry = $registry[md5('LinksAdd_newadmin_links.php?mode=add')];
	$assert(!empty($category['customised']) && empty($category['option']) && count($entry['options'])===4 &&
		!in_array('a_plus_wl_publish',$entry['options'],true),'A customised category writer or the separate public publish right was automatically delegated');
}
switch ($argv[2])
{
	case 'setup':
		$check(!$saved,'Website administration fixture is already active');
		// v100 reported these historical registrations for manual review. One operator assigned review alone.
		$previous = $first($jr,'source_user',35);
		$previous_grant = $first($grants,'user_id',35)['rights_json'];
		$held = json_decode($previous['report_json'],true);
		unset($held[0]['options'],$held[0]['option']);
		$held[0]['result'] = 'manual-review';
		$db->sql_query("UPDATE $jr SET " . $db->sql_build_array('UPDATE',array('report_json'=>json_encode($held),'rights_json'=>'[]')) . ' WHERE source_user=35');
		$db->sql_query("UPDATE $grants SET rights_json='[\"a_plus_wl_review\"]' WHERE user_id=35");
		$original = $rows("SELECT * FROM $entries ORDER BY link_id");
		$categories = $rows("SELECT * FROM $cats ORDER BY cat_id");
		$roles = $rows("SELECT source_user,report_json,rights_json FROM $jr ORDER BY source_user");
		$source = $text->get('plus_wl_source_settings');
		$native = $admin_settings->read();
		$auto = array();
		foreach (array($entries,$cats) as $table)
		{
			$auto[$table] = (int) $rows("SELECT AUTO_INCREMENT AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table'")[0]['n'];
		}
		$record = array('settings'=>$native,'cats'=>$categories,'auto'=>$auto,'roles'=>$first($grants,'user_id',36)['rights_json'],
			'prior_source'=>array_intersect_key($previous,array_flip(array('report_json','rights_json'))),'prior_grant'=>$previous_grant,
			'language'=>$first(USERS_TABLE,'user_id',37)['user_lang'],
			'original'=>hash('sha256',serialize(array($roles,$rows("SELECT * FROM $grants ORDER BY user_id"),$original,$categories,$native,$source))));
		$text->set('plus_wl_acp_fixture',json_encode($record));
		$mt = $table_prefix . 'migrations';
		$state = $rows("SELECT migration_name FROM $mt WHERE migration_name LIKE '%weblinks%v101_administration'");
		$name = $state ? $state[0]['migration_name'] : $class;
		$installed = "SELECT migration_name FROM $mt WHERE migration_name='" . $db->sql_escape($name) . "'";
		$m = $phpbb_container->get('migrator');
		$m->load_migration_state();
		for ($i=0;$i<40 && $rows($installed);$i++) { $m->revert($name); }
		// Edition upgrades run in a new PHP request, with a new permissions object and module discovery cache.
		break;
	case 'upgrade':
		$permission = $phpbb_container->get('migrator.tool.permission');
		// Dropping a native option may preserve its opposite (local) side. v100 had neither scope.
		foreach (management::RIGHTS as $right) { $permission->remove($right,true); $permission->remove($right,false); }
		$mt = $table_prefix . 'migrations';
		$before_options = $rows('SELECT auth_option FROM ' . ACL_OPTIONS_TABLE . " WHERE auth_option LIKE 'a_plus_wl_%' ORDER BY auth_option");
		$check(!$rows("SELECT migration_name FROM $mt WHERE migration_name LIKE '%weblinks%v101_administration'") &&
			array_column($before_options,'auth_option') === array('a_plus_wl_publish','a_plus_wl_review'),'Unexpected pre-upgrade permissions');
		$m = $phpbb_container->get('migrator');
		$m->load_migration_state();
		$m->set_migrations(array($class));
		for ($i=0;$i<40 && !$m->finished();$i++) { $m->update(); }
		$options = $rows('SELECT auth_option FROM ' . ACL_OPTIONS_TABLE . ' WHERE ' . $db->sql_in_set('auth_option',management::RIGHTS));
		$modes = $rows('SELECT module_mode FROM ' . MODULES_TABLE . " WHERE module_basename LIKE '%weblinks%management_module'");
		$check($m->finished() && count($options)===5 && count($modes)===4,
			'Incomplete v100 upgrade: ' . json_encode(array($m->finished(),$options,$modes)));
		$check($saved['original'] === hash('sha256',serialize(array($rows("SELECT source_user,report_json,rights_json FROM $jr ORDER BY source_user"),$rows("SELECT * FROM $grants ORDER BY user_id"),
			$rows("SELECT * FROM $entries ORDER BY link_id"),$rows("SELECT * FROM $cats ORDER BY cat_id"),$admin_settings->read(),$text->get('plus_wl_source_settings')))),
			'The upgrade changed directory records, raw settings or existing delegation decisions');
		$db->sql_query("UPDATE $jr SET " . $db->sql_build_array('UPDATE',$saved['prior_source']) . ' WHERE source_user=35');
		$db->sql_query("UPDATE $grants SET " . $db->sql_build_array('UPDATE',array('rights_json'=>$saved['prior_grant'])) . ' WHERE user_id=35');
		require_once __DIR__ . '/delegation.php';
		foreach (array(35,36,37,38) as $id)
		{
			$r = $first($jr,'source_user',$id);
			$check(json_decode($r['rights_json'],true) === (array) delegation_fixture_roles()[$id][2],'Wrong imported scope for source user ' . $id . ': ' . $r['report_json']);
		}
		wl_custom_source($check);
		foreach (array(941=>'ACP fixture links',942=>'ACP disposable sites') as $id=>$title)
		{
			$db->sql_query("INSERT INTO $cats " . $db->sql_build_array('INSERT',array('cat_id'=>$id,'title'=>$title,'position'=>$id)));
		}
		foreach (array(941001=>array(941,1),941002=>array(941,2),942001=>array(942,1),942002=>array(942,0)) as $id=>$case)
		{
			$r = $first($entries,'link_id',4111);
			$r['link_id'] = $id; $r['cat_id'] = $case[0]; $r['status'] = $case[1]; $r['title'] = 'ACP fixture ' . $id;
			$db->sql_query("INSERT INTO $entries " . $db->sql_build_array('INSERT',$r));
		}
		$config->set('plus_wl_lock_submit_site',1);
		$config->set('plus_wl_inputs_valid',0);
		break;
	case 'edited':
	case 'moved':
		$old = $first($entries,'link_id',4111);
		$r = $first($entries,'link_id',941001);
		foreach (array('logo','local_logo','source_user','user_id','joined','hits','last_ip','submitter_ip','status','destination') as $field)
		{ $check($r[$field] === $old[$field],'Unrelated historical website data changed: ' . $field); }
		$moved = $argv[2] === 'moved';
		$check((int) $r['cat_id'] === ($moved ? 711 : 941) && $r['description'] === 'Updated &amp; verified café','The ACP edit or category move did not persist');
		$draft = $first($entries,'link_id',941002);
		$check((int) $draft['status'] === 2 && (int) $draft['cat_id'] === ($moved ? 711 : 941),'A declined item was revived or left behind');
		if ($moved)
		{
			$new = $rows("SELECT * FROM $entries WHERE title='ACP submitted without a logo'")[0];
			$check((int) $new['cat_id']===711 && (int) $new['status']===0 && (int) $new['user_id']===35 &&
				(int) $new['hits']===0 && $new['logo']==='' && !$first($cats,'cat_id',941),'New ACP entry has an incorrect owner, state or destination');
		}
		break;
	case 'revoke-edit':
		$db->sql_query("UPDATE $grants SET rights_json='[\"a_plus_wl_delete\"]' WHERE user_id=36");
		break;
	case 'revoke-review':
		$db->sql_query("UPDATE $grants SET rights_json='[\"a_plus_wl_edit\",\"a_plus_wl_delete\"]' WHERE user_id=36");
		break;
	case 'german':
		$db->sql_query('UPDATE ' . USERS_TABLE . " SET user_lang='de' WHERE user_id=37 AND username='LinkCategoryJunior'");
		break;
	case 'settings':
		$values = $admin_settings->read();
		$check((int) $config['plus_wl_inputs_valid']===1 && (int) $values['linkspp']===3 && (int) $values['display_interval']===9000 &&
			!$values['lock_submit_site'] && $values['allow_no_logo'] && $values['local_logo']===$saved['settings']['local_logo'] &&
			strpos($text->get('plus_wl_source_settings'),'Altoption')!==false,'Repaired active settings, historical artwork or raw legacy options were lost');
		break;
	case 'removed':
		$check(!$first($entries,'link_id',941002) && !$first($entries,'link_id',942001) && !$first($entries,'link_id',942002) &&
			!$first($cats,'cat_id',942) && $first($entries,'link_id',941001) &&
			is_file($config['upload_path'] . '/' . $first($entries,'link_id',4111)['local_logo']),'Confirmed removals affected another record or a shared logo');
		break;
	case 'cleanup':
		if (!$saved) { break; }
		$db->sql_query("DELETE FROM $entries WHERE link_id IN (941001,941002,942001,942002) OR title='ACP submitted without a logo'");
		$db->sql_query("DELETE FROM $cats WHERE cat_id IN (941,942) OR title IN ('ACP newly created','ACP renamed category')");
		foreach ($saved['cats'] as $r) { $db->sql_query("UPDATE $cats SET position=" . (int) $r['position'] . ' WHERE cat_id=' . (int) $r['cat_id']); }
		foreach ($saved['auto'] as $table=>$next) { if (in_array($table,array($entries,$cats),true)) { $db->sql_query("ALTER TABLE $table AUTO_INCREMENT=" . (int) $next); } }
		foreach ($saved['settings'] as $key=>$value) { $config->set('plus_wl_' . $key,$value); }
		$db->sql_query("UPDATE $grants SET " . $db->sql_build_array('UPDATE',array('rights_json'=>$saved['roles'])) . ' WHERE user_id=36');
		$db->sql_query("UPDATE $jr SET " . $db->sql_build_array('UPDATE',$saved['prior_source']) . ' WHERE source_user=35');
		$db->sql_query("UPDATE $grants SET " . $db->sql_build_array('UPDATE',array('rights_json'=>$saved['prior_grant'])) . ' WHERE user_id=35');
		$db->sql_query('UPDATE ' . USERS_TABLE . ' SET ' . $db->sql_build_array('UPDATE',array('user_lang'=>$saved['language'] ?? 'en')) . ' WHERE user_id=37');
		$text->delete('plus_wl_acp_fixture');
		break;
	default: throw new RuntimeException('Unexpected directory administration fixture');
}
