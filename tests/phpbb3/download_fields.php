<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
define('IN_PHPBB',true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$p = $table_prefix . 'plus_dl_';
$rows = static function ($sql) use ($db) {
	$result = $db->sql_query($sql);
	$rows = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	return $rows;
};
$insert = static function ($table,$data) use ($db,$p) {
	$db->sql_query("INSERT INTO $p$table " . $db->sql_build_array('INSERT',$data));
};
switch ($argv[2])
{
	case 'runtime':
		$file = $rows("SELECT * FROM {$p}files WHERE file_id=53")[0];
		$insert('files',array('file_id'=>9830)+$file);
		foreach ($rows("SELECT * FROM {$p}field_values WHERE file_id=53") as $row)
		{
			unset($row['row_id']);
			$insert('field_values',array('file_id'=>9830)+$row);
		}
		break;
	case 'unchanged':
		$columns = 'field_id,values_json,source_json';
		$old = $rows("SELECT $columns FROM {$p}field_values WHERE file_id=53 ORDER BY field_id");
		$copy = $rows("SELECT $columns FROM {$p}field_values WHERE file_id=9830 ORDER BY field_id");
		if (!$old || $old !== $copy) { throw new RuntimeException('A file edit changed archived values despite an identical selection'); }
		break;
	case 'completed':
		$before = $rows("SELECT source_json FROM {$p}field_values WHERE field_id=86 AND file_id=53")[0];
		$changed = $rows("SELECT values_json,source_json FROM {$p}field_values WHERE field_id=86 AND file_id=9830")[0];
		if (!$before['source_json'] || $changed['source_json'] !== '{}' || json_decode($changed['values_json'],true) !==
			array('Vergriffen &amp;amp; 📗','Weitere')) { throw new RuntimeException('Changing a selection corrupted the file values'); }
		$new = $rows("SELECT file_id FROM {$p}files WHERE title='Prüfling'");
		if (count($new)!==1) { throw new RuntimeException('The member submission was lost or duplicated'); }
		$new = (int) $new[0]['file_id'];
		$values = $rows("SELECT d.field_type,v.values_json,v.source_json FROM {$p}field_values v JOIN {$p}fields d ON d.field_id=v.field_id WHERE v.file_id=$new ORDER BY d.field_type");
		$actual = array_map(array(\phpbb2plus\downloads\service\field_data::class,'values'),$values);
		if ($actual !== array(array('PA-22'),array("Zwei\nZeilen"),array('Archiv &amp; 📗'),array('Aktuell'),
			array('Archiv &amp; 📗','Weitere'),array('Aktuell'))) { throw new RuntimeException('A new download did not save all six field types'); }
		$db->sql_query("DELETE FROM {$p}receipts WHERE file_id=$new");
		$db->sql_query("DELETE FROM {$p}field_values WHERE file_id=$new");
		$db->sql_query("DELETE FROM {$p}files WHERE file_id=$new");
		$db->sql_query("DELETE FROM {$p}field_values WHERE file_id=9830");
		$db->sql_query("DELETE FROM {$p}files WHERE file_id=9830");
		break;
	default: exit(2);
}
