<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
define('IN_PHPBB',true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$p = $table_prefix . 'plus_arc_';
$table = $p . 'log';
$one = static function($sql) use ($db) {
	$r = $db->sql_query($sql);
	$row = $db->sql_fetchrow($r);
	$db->sql_freeresult($r);
	return $row;
};
$phase = $argv[2];
$owner = (int) $one('SELECT user_id FROM ' . USERS_TABLE . " WHERE username_clean='historicmember'")['user_id'];
$grant = (int) $one('SELECT user_id FROM ' . USERS_TABLE . " WHERE username_clean='arcadelogjunior'")['user_id'];
if (!$grant || !$owner) { throw new RuntimeException('Missing synthetic diagnostic accounts'); }
if ($phase === 'prepare')
{
	$src = $one("SELECT * FROM $table WHERE record_no=91");
	if (!$src || (int) $src['owner_id']!==$owner || !$src['text_encoded'] ||
		\phpbb2plus\arcade\service\catalog::plain($src['log_value']) !== 'Historisches Ereignis 🛰 &lt;Quelle&gt; <Café>')
	{ throw new RuntimeException('The upgraded diagnostic log lost its source record, remapped owner or literal Unicode text'); }
	$jr = $one('SELECT rights_json FROM ' . $table_prefix . "plus_jr_delegations WHERE user_id=$grant");
	if (!$jr || json_decode($jr['rights_json'],true) !== array('a_plus_arc_log'))
	{ throw new RuntimeException('The reviewed Arcade log registration did not grant only the diagnostic module'); }
	if ($one("SELECT record_no FROM $table WHERE record_no>=780000 OR name='ERROR'"))
	{ throw new RuntimeException('The isolated diagnostic log fixture would overlap existing records'); }
	$old = $one("SELECT config_value FROM {$p}settings WHERE config_name='games_per_admin_page'");
	$config->set('plus_test_arc_diag_size',$old['config_value']);
	$next = $one("SELECT AUTO_INCREMENT AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $db->sql_escape($table) . "'");
	$config->set('plus_test_arc_diag_next',$next['n']);
	$db->sql_query("UPDATE {$p}settings SET config_value='2' WHERE config_name='games_per_admin_page'");
	$db->sql_query("INSERT INTO $table (record_no,user_id,name,log_value,recorded_at,owner_id) VALUES
		(780001,1,'BAD_GAME','&lt;literal&gt; <game>',111000,$owner),
		(780002,1,'ERROR','first',111100,$owner),(780003,2,'GAME','former synthetic account',111200,0),
		(780004,-1,'GAME_ERROR','guest synthetic',111300,0),(780005,0,'POST','unknown account',0,0),
		(780006,1,'ERROR','second',111400,$owner),(780007,1,'ERROR','third',111500,$owner)");
}
elseif ($phase === 'append')
{
	$db->sql_query("INSERT INTO $table (user_id,name,log_value,recorded_at,owner_id) VALUES (-1,'ERROR','later diagnostic',111600,0)");
	if ((int) $db->sql_nextid()!==780008) { throw new RuntimeException('Diagnostic identity sequence changed'); }
}
elseif ($phase === 'never' || $phase === 'restore')
{
	require_once './includes/acp/auth.php';
	(new auth_admin())->acl_set('user',0,$grant,array('a_plus_arc_log'=>$phase==='never' ? ACL_NEVER : ACL_NO));
}
elseif ($phase === 'verify')
{
	$ids = $one("SELECT GROUP_CONCAT(record_no ORDER BY record_no) AS ids FROM $table WHERE record_no>=780000");
	if ($ids['ids'] !== '780001,780003,780004,780005,780008' || !$one("SELECT record_no FROM $table WHERE record_no=91") ||
		(int) $one('SELECT COUNT(*) AS n FROM ' . LOG_TABLE . " WHERE log_operation='LOG_PLUS_ARC_DIAGNOSTICS' AND user_id=$grant")['n']!==2)
	{ throw new RuntimeException('Confirmed diagnostic cleanup changed unrelated rows or failed to audit the operations'); }
}
elseif ($phase === 'cleanup')
{
	require_once './includes/acp/auth.php';
	(new auth_admin())->acl_set('user',0,$grant,array('a_plus_arc_log'=>ACL_NO));
	$db->sql_query("DELETE FROM $table WHERE record_no BETWEEN 780001 AND 780008");
	$db->sql_query('DELETE FROM ' . LOG_TABLE . " WHERE log_operation='LOG_PLUS_ARC_DIAGNOSTICS' AND user_id=$grant");
	$db->sql_query("ALTER TABLE $table AUTO_INCREMENT=" . max(1,(int) $config['plus_test_arc_diag_next']));
	$db->sql_query("UPDATE {$p}settings SET config_value='" . $db->sql_escape($config['plus_test_arc_diag_size']) . "' WHERE config_name='games_per_admin_page'");
	$config->delete('plus_test_arc_diag_size');
	$config->delete('plus_test_arc_diag_next');
}
else { throw new RuntimeException('Unexpected Arcade diagnostics fixture phase'); }
