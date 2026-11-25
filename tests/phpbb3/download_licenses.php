<?php
// The licence workflows use only shared fixture binaries in a disposable local board.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
define('IN_PHPBB',true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$p = $table_prefix . 'plus_dl_';
$row = static function ($sql) use ($db) {
	$result = $db->sql_query($sql);
	$value = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	return $value;
};
$insert = static function ($table,$data) use ($db,$p) { $db->sql_query("INSERT INTO $p$table " . $db->sql_build_array('INSERT',$data)); };
switch ($argv[2])
{
	case 'agreements':
		$name = 'Probe &amp; Regeln 📜';
		$terms = "<p>Grüße <strong>aus dem Archiv</strong> 📜</p><ol><li>Text &lt;Beispiel&gt;</li></ol>\n" .
			'<table><tr><th colspan="2">Abschnitt</th></tr></table><a href="faq.php">Hinweise</a>';
		$insert('licenses',array('license_id'=>9610,'title'=>utf8_encode_ucr($name),'terms'=>utf8_encode_ucr($terms),
			'source_json'=>json_encode(array('license_name'=>$name,'license_text'=>$terms))));
		$file = $row("SELECT * FROM {$p}files WHERE file_id=50");
		$insert('files',array('file_id'=>9611,'license_id'=>9610,'downloads'=>0)+$file);
		$mirror = $row("SELECT * FROM {$p}mirrors WHERE mirror_id=8");
		$insert('mirrors',array('file_id'=>9611,'mirror_id'=>9612)+$mirror);
		break;
	case 'revised':
		$db->sql_query("UPDATE {$p}licenses SET " . $db->sql_build_array('UPDATE',array('terms'=>'<p>Neue Bedingungen</p>')) . ' WHERE license_id=9610');
		break;
	case 'unaccepted':
	case 'accepted':
		$file = $row("SELECT downloads FROM {$p}files WHERE file_id=9611");
		$visitors = $row("SELECT COUNT(*) AS n FROM {$p}visitors WHERE file_id=9611");
		$accepted = $argv[2] === 'accepted';
		if ((int) $file['downloads'] !== ($accepted ? 2 : 0) || (int) $visitors['n'] !== (int) $accepted)
		{ throw new RuntimeException('Licence confirmation counted an unseen revision or lost an accepted delivery'); }
		if ($accepted)
		{
			$db->sql_query("DELETE FROM {$p}visitors WHERE file_id=9611");
			$db->sql_query("DELETE FROM {$p}mirrors WHERE mirror_id=9612");
			$db->sql_query("DELETE FROM {$p}files WHERE file_id=9611");
			$db->sql_query("DELETE FROM {$p}licenses WHERE license_id=9610");
		}
		break;
	default: exit(2);
}
