<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$rows = static function ($sql) use ($db) {
	$r = $db->sql_query($sql); $v = $db->sql_fetchrowset($r); $db->sql_freeresult($r); return $v;
};
$flags = $table_prefix . 'plus_flags';
$source = $rows("SELECT option_value,label,image FROM $flags WHERE source_id=250")[0];
$id = (int) $source['option_value'];
$field = $phpbb_container->get('phpbb2plus.community.country_flags')->field_id();
$german = (int) $rows("SELECT lang_id FROM " . LANG_TABLE . " WHERE lang_iso='de'")[0]['lang_id'];
$lang_option = " WHERE field_id=$field AND option_id=" . ($id - 1) . " AND lang_id=$german";
switch ($argv[2])
{
	case 'older':
	case 'upgraded':
		$upgraded = $argv[2] === 'upgraded';
		if ((bool) $phpbb_container->get('dbal.tools')->sql_column_exists($flags, 'catalogue_state') !== $upgraded)
		{ throw new RuntimeException('Unexpected country flag upgrade state'); }
		if ($upgraded && !$rows("SELECT option_value FROM $flags WHERE source_id=250 AND catalogue_state=0"))
		{ throw new RuntimeException('Previously converted catalogue entry was not retained'); }
		// Hash native translations, member selections and the original filename-to-option map.
		$archive = array($rows("SELECT option_value,source_id,source_file,label,image FROM $flags ORDER BY option_value"),
			$rows('SELECT lang_id,option_id,lang_value FROM ' . PROFILE_FIELDS_LANG_TABLE . " WHERE field_id=$field ORDER BY lang_id,option_id"),
			$rows('SELECT user_id,pf_plus_country FROM ' . PROFILE_FIELDS_DATA_TABLE . ' ORDER BY user_id'));
		echo hash('sha256', serialize($archive)); break;
	case 'initial':
		$record = $rows("SELECT rights_json,modules_b64 FROM {$table_prefix}plus_jr_records WHERE source_user=61 AND user_id=61")[0];
		if (json_decode($record['rights_json'],true) !== array('a_plus_flags') || base64_decode($record['modules_b64']) !== md5('UsersFlagsadmin_flags.php'))
		{ throw new RuntimeException('Lost the exact single-module flag grant'); }
		if (!copy('images/plusflags/' . $source['image'], 'images/plusflags/stage-flag.png')) { throw new RuntimeException('Missing synthetic raster'); }
		// A second, synthetic profile-only image lets this editor exercise re-registration
		// without changing the other members' imported country choices.
		$orphan = (int) $rows("SELECT MAX(option_value) AS n FROM $flags")[0]['n'] + 1;
		$db->sql_query("INSERT INTO $flags " . $db->sql_build_array('INSERT', array('option_value'=>$orphan, 'source_id'=>0,
			'source_file'=>'relisted.png', 'label'=>'Profile-only fixture', 'image'=>$source['image'])));
		$db->sql_query('INSERT INTO ' . PROFILE_FIELDS_LANG_TABLE . ' (field_id,lang_id,option_id,field_type,lang_value)
			SELECT field_id,lang_id,' . ($orphan - 1) . ",field_type,'Profile-only fixture' FROM " . PROFILE_FIELDS_LANG_TABLE . " WHERE field_id=$field AND option_id=0");
		$db->sql_query('UPDATE ' . PROFILE_FIELDS_DATA_TABLE . " SET pf_plus_country=$orphan WHERE user_id=61");
		echo json_encode(array('source'=>$id, 'field'=>$field, 'orphan'=>$orphan)); break;
	case 'selection':
		echo (int) $rows('SELECT pf_plus_country FROM ' . PROFILE_FIELDS_DATA_TABLE . ' WHERE user_id=61')[0]['pf_plus_country']; break;
	case 'native-label':
	case 'restore-label':
		$text = $argv[2] === 'native-label' ? 'Eigene Übersetzung' : $source['label'];
		$db->sql_query('UPDATE ' . PROFILE_FIELDS_LANG_TABLE . ' SET ' . $db->sql_build_array('UPDATE', array('lang_value'=>$text)) . $lang_option); break;
	case 'never':
	case 'restore-grant':
		require_once './includes/acp/auth.php';
		(new auth_admin())->acl_set('user',0,61,array('a_plus_flags' => $argv[2] === 'never' ? ACL_NEVER : ACL_NO));
		$auth->acl_clear_prefetch(61);
		if ($argv[2] === 'restore-grant') { unlink('images/plusflags/stage-flag.png'); }
		break;
	default: exit(2);
}
