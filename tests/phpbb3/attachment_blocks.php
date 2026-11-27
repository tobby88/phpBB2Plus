<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
$phase = $argv[2];
if ($phase === 'source')
{
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	$source = new mysqli('127.0.0.1', getenv('PLUS_TEST_DB_USER') ?: 'root', getenv('PLUS_TEST_DB_PASSWORD'),
		$argv[1], (int) (getenv('PLUS_TEST_DB_PORT') ?: 3306));
	$source->set_charset('utf8mb4');
	// Keep the older source collation covered alongside a full-width utf8mb4 label in the CI matrix.
	if (PHP_VERSION_ID < 80000) { $source->query('ALTER TABLE phpbb_forbidden_extensions MODIFY extension VARCHAR(100) CHARACTER SET latin1 NOT NULL'); }
	else { $source->query("INSERT INTO phpbb_forbidden_extensions (ext_id,extension) VALUES (255,'" . str_repeat('🧭', 100) . "')"); }
	$source->query("INSERT INTO phpbb_forbidden_extensions (ext_id,extension) VALUES (251,' QcArt '),(252,'qcart'),(253,'früh.test'),(254,'blocknote')");
	$source->query("INSERT INTO phpbb_extensions (ext_id,group_id,extension) SELECT 251,group_id,'qcart' FROM phpbb_extensions WHERE extension='zip'");
	return;
}
define('IN_PHPBB', true);
$phpbb_root_path = './'; $phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
require_once './includes/acp/auth.php';
$rows = static function ($sql) use ($db) {
	$r = $db->sql_query($sql); $rows = $db->sql_fetchrowset($r); $db->sql_freeresult($r); return $rows;
};
$expect = static function ($ok, $message) { if (!$ok) { throw new \RuntimeException($message); } };
$table = $table_prefix . 'plus_attach_forbidden';
$right = 'a_plus_attach_block';
if ($phase === 'older' || $phase === 'upgraded')
{
	$installed = $phase === 'upgraded';
	$exists = $phpbb_container->get('dbal.tools')->sql_table_exists($table);
	$option = $rows('SELECT auth_option_id FROM ' . ACL_OPTIONS_TABLE . " WHERE auth_option='$right'");
	$module = $rows('SELECT module_id FROM ' . MODULES_TABLE . " WHERE module_langname='ACP_PLUS_ATTACH_BLOCKS'");
	$expect($exists === $installed && (bool) $option === $installed && (bool) $module === $installed, 'Forbidden extensions upgrade incomplete');
	if ($installed)
	{
		$rules = $phpbb_container->get('phpbb2plus.community.attachment_blocks');
		$expect(!$rows("SELECT rule_id FROM $table") && $rules->blocked('php4') && !$rules->blocked('blocknote'), 'Upgrade guessed historic custom rules or lost fixed defaults');
		$wide = str_repeat('🧭', 100);
		$db->sql_query("INSERT INTO $table " . $db->sql_build_array('INSERT', array('extension_name'=>$wide)));
		$id = (int) $db->sql_nextid();
		$expect($rows("SELECT extension_name FROM $table WHERE rule_id=$id")[0]['extension_name'] === $wide, 'The upgraded native connection lost the original 100-character extension spelling');
		$db->sql_query("DELETE FROM $table WHERE rule_id=$id");
	}
	return;
}
$text = $phpbb_container->get('config_text');
$key = 'plus_test_attachment_blocks';
$state = json_decode((string) $text->get($key), true);
if ($phase === 'prepare')
{
	$expect(!$state, 'Attachment block fixture already active');
	$rules = array_column($rows("SELECT * FROM $table WHERE source_id BETWEEN 251 AND 254 ORDER BY source_id"), null, 'source_id');
	$expect(count($rules) === 4 && $rules[251]['extension_name'] === ' QcArt ' && $rules[251]['extension_key'] === 'qcart' &&
		$rules[252]['extension_key'] === 'qcart' && $rules[253]['extension_name'] === 'früh.test' && $rules[253]['extension_key'] === '',
		'Source rule IDs, spelling, conflicts or duplicate keys changed');
	if (PHP_VERSION_ID >= 80000)
	{
		$wide = $rows("SELECT extension_name,extension_key FROM $table WHERE source_id=255")[0] ?? array();
		$expect(($wide['extension_name'] ?? '') === str_repeat('🧭', 100) && $wide['extension_key'] === '',
			'An archived four-byte source spelling was lost or admitted as an actual file type');
	}
	$junior = $rows('SELECT u.user_id,u.username,j.rights_json FROM ' . USERS_TABLE . ' u JOIN ' . $table_prefix .
		"plus_jr_records j ON j.user_id=u.user_id WHERE j.source_user=279")[0] ?? array();
	$expect(($junior['username'] ?? '') === 'AttachmentBlockJunior' && json_decode($junior['rights_json'], true) === array($right),
		'Forbidden-list registration failed to map to its individual scope');
	$native = $rows('SELECT e.extension_id,e.group_id,g.allow_group FROM ' . EXTENSIONS_TABLE . ' e JOIN ' . EXTENSION_GROUPS_TABLE .
		" g ON g.group_id=e.group_id WHERE e.extension='qcart'")[0] ?? array();
	$expect(!empty($native['allow_group']), 'Conflicting native allow-list entry was lost');
	// Build a separate metadata row over already synthetic image bytes to demonstrate that an old
	// attachment with this conflicting extension can still be read. Never unlink the shared file.
	$file = $rows('SELECT * FROM ' . ATTACHMENTS_TABLE . ' WHERE attach_id=50')[0];
	$file['real_filename'] = 'Archived.qcart'; $file['extension'] = 'qcart'; $file['download_count'] = 0; $file['thumbnail'] = 0;
	unset($file['attach_id']);
	$db->sql_query('INSERT INTO ' . ATTACHMENTS_TABLE . ' ' . $db->sql_build_array('INSERT', $file));
	$state = array('attachment'=>(int) $db->sql_nextid(), 'user'=>(int) $junior['user_id'], 'extension'=>(int) $native['extension_id'],
		'group'=>(int) $native['group_id'], 'rules'=>array_column($rules, 'rule_id', 'source_id'), 'conflicts'=>array($rules[251], $rules[252]));
	$text->set($key, json_encode($state)); echo json_encode($state);
}
elseif ($phase === 'revoke') { (new auth_admin())->acl_set('user', 0, $state['user'], array($right=>ACL_NEVER)); }
elseif ($phase === 'state')
{
	echo json_encode(array(
		'custom'=>array_column($rows("SELECT rule_id,extension_name FROM $table WHERE source_id=0 ORDER BY rule_id"), 'rule_id', 'extension_name'),
		'native'=>array_column($rows('SELECT extension,group_id FROM ' . EXTENSIONS_TABLE . " WHERE extension IN ('blocknote','paperfixture','qcart')"), 'group_id', 'extension'),
		'uploads'=>count($rows('SELECT attach_id FROM ' . ATTACHMENTS_TABLE . " WHERE real_filename='Fixture.qcart'"))));
}
elseif ($phase === 'clean' && $state)
{
	(new auth_admin())->acl_set('user', 0, $state['user'], array($right=>ACL_NO));
	$db->sql_query('DELETE FROM ' . ATTACHMENTS_TABLE . ' WHERE attach_id=' . (int) $state['attachment'] . " AND real_filename='Archived.qcart'");
	$db->sql_query("DELETE FROM $table WHERE source_id=0 AND extension_name IN ('pencase','othercase','nevercase')");
	foreach ($state['conflicts'] as $row)
	{
		if (!$rows("SELECT rule_id FROM $table WHERE rule_id=" . (int) $row['rule_id'])) { $db->sql_query("INSERT INTO $table " . $db->sql_build_array('INSERT', $row)); }
	}
	$db->sql_query('DELETE FROM ' . EXTENSIONS_TABLE . " WHERE extension='paperfixture'");
	$cache->destroy('_extensions');
	$text->delete($key);
}
else { throw new \RuntimeException('Unknown attachment block fixture phase'); }
