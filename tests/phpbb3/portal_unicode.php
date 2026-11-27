<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
$phase = $argv[2];
$fragment = "<P data-sign='original' title='🪐'>&#x1F600; / 🪐 / &amp;#128512; / &copy;</P>\n" .
	"<!-- <img src='unused-portal-probe.gif'> -->\n<IMG ALT='𠜎 &amp;#128512; >' SRC = 'templates/fisubsilversh/images/news/topicnews.gif' />";
if ($phase === 'source')
{
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	$source = new mysqli('127.0.0.1',getenv('PLUS_TEST_DB_USER'),getenv('PLUS_TEST_DB_PASSWORD'),$argv[1],(int) getenv('PLUS_TEST_DB_PORT'));
	$source->set_charset('utf8mb4');
	$sql = $source->prepare("UPDATE phpbb_portal SET portal_value=CONCAT(portal_value,?) WHERE portal_name='welcome_text' AND LOCATE(?,portal_value)=0");
	$sql->bind_param('ss',$fragment,$fragment);
	$sql->execute();
	return;
}
define('IN_PHPBB',true); $phpbb_root_path='./'; $phpEx='php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$text = $phpbb_container->get('config_text');
$key = 'plus_portal_welcome';
$source_key = 'plus_portal_welcome_source';
$backup = 'plus_test_portal_unicode';
$old = '<p>Altes &#x1F600; und &amp;#x1F600;</p>';
$expect = static function ($ok,$why) { if (!$ok) { throw new RuntimeException($why); } };
if ($phase === 'prepare')
{
	$expect(!$text->get($backup),'Portal Unicode fixture already active');
	$pair = $text->get_array(array($key,$source_key));
	$e = \phpbb2plus\community\service\welcome::editor_settings($text,array())[$key];
	$expect(preg_match('~SRC = "(images/plusportal/[^"]+)"~',$e,$asset) === 1 &&
		strpos($e,str_replace("'templates/fisubsilversh/images/news/topicnews.gif'",'"' . $asset[1] . '"',$fragment)) !== false,
		'Image relocation changed source markup or normalized a character reference');
	$expect($pair[$key] === utf8_encode_ucr($e) && $pair[$source_key] !== '','Missing backwards-readable portal HTML or exact source');
	$verify = \phpbb2plus\community\service\welcome::storage($e);
	$text->set_array($verify);
	$expect($text->get_array(array($key,$source_key)) === $pair,'Repeated portal storage changed the original text');
	$r = $db->sql_query('SELECT @@character_set_client AS conn'); $conn = $db->sql_fetchfield('conn'); $db->sql_freeresult($r);
	$expect(in_array($conn,array('utf8','utf8mb3'),true),'The portal changed the connection character set');
	$r = $db->sql_query("SELECT DATA_TYPE,CHARACTER_SET_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND
		TABLE_NAME='" . $db->sql_escape($table_prefix . 'config_text') . "' AND COLUMN_NAME='config_value'");
	$column = $db->sql_fetchrow($r); $db->sql_freeresult($r);
	$expect($column && $column['DATA_TYPE'] === 'mediumtext' && in_array($column['CHARACTER_SET_NAME'],array('utf8','utf8mb3'),true),
		'The welcome stored its raw source by changing the native config_text schema');
	$text->set($backup,json_encode($pair,JSON_THROW_ON_ERROR));
}
elseif ($phase === 'old')
{
	$text->delete($source_key);
	$text->set($key,$old);
}
elseif ($phase === 'older-write')
{
	$expect($text->get($source_key) !== '','The Unicode source was not retained');
	$text->set($key,'<em>Ältere Änderung &amp;#128512;</em>');
}
elseif ($phase === 'clean')
{
	$text->set_array(json_decode($text->get($backup),true,512,JSON_THROW_ON_ERROR));
	$text->delete($backup);
}
else { throw new RuntimeException('Unknown portal Unicode phase'); }
