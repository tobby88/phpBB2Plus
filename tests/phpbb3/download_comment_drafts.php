<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$complete = false;
$db->sql_return_on_error(true);
register_shutdown_function(static function () use (&$complete, $db) {
	if (!$complete || $db->get_sql_error_triggered())
	{
		fwrite(STDERR, "Download comment fixture failed: " . ($db->get_sql_error_returned()['message'] ?? 'incomplete') . "\n");
		exit(1);
	}
});
$p = $table_prefix . 'plus_dl_';
$config_text = $phpbb_container->get('config_text');
$rows = static function ($sql) use ($db) {
	$result = $db->sql_query($sql);
	$rows = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	return $rows;
};
$insert = static function ($table, $row) use ($db) {
	$db->sql_query("INSERT INTO $table " . $db->sql_build_array('INSERT', $row));
};
$setting = " WHERE config_name='max_comment_chars'";
$phase = $argv[2];
$db->sql_transaction('begin');
switch ($phase)
{
	case 'seed':
		if ($rows("SELECT file_id FROM {$p}files WHERE file_id=11145") || $rows("SELECT cat_id FROM {$p}categories WHERE cat_id=11143") ||
			$rows("SELECT comment_id FROM {$p}comments WHERE comment_id BETWEEN 11150 AND 11169"))
		{ throw new RuntimeException('Synthetic comment IDs are occupied'); }
		$config_text->set('plus_test_dl_comment_limit', $rows("SELECT config_value FROM {$p}settings" . $setting)[0]['config_value']);
		$db->sql_query("UPDATE {$p}settings SET config_value='96'" . $setting);
		$category = $rows("SELECT * FROM {$p}categories WHERE cat_id=21")[0];
		$rules = json_decode($category['access_levels'], true);
		$rules['post_comment'] = 1;
		$rules['view_comment'] = 0;
		$insert($p.'categories', array('cat_id'=>11143, 'title'=>'Comment drafts', 'allow_comments'=>1,
			'access_levels'=>json_encode($rules)) + $category);
		$file = $rows("SELECT * FROM {$p}files WHERE file_id=50")[0];
		$insert($p.'files', array('file_id'=>11145, 'title'=>'Concert notes', 'cat_id'=>11143) + $file);
		$comment = $rows("SELECT * FROM {$p}comments WHERE comment_id=91")[0];
		for ($n = 0; $n < 20; $n++)
		{
			$insert($p.'comments', array('file_id'=>11145, 'comment_id'=>11150+$n, 'posted_at'=>1,
				'title'=>'Earlier entry ' . $n, 'body'=>'<t>Earlier comment.</t>') + $comment);
		}
		break;
	case 'close':
	case 'reopen':
		$db->sql_query("UPDATE {$p}categories SET allow_comments=" . (int) ($phase === 'reopen') . " WHERE cat_id=11143");
		break;
	case 'unpublished':
	case 'published':
		$comments = $rows("SELECT COUNT(*) AS n FROM {$p}comments WHERE file_id=11145");
		$receipts = $rows("SELECT COUNT(*) AS n FROM {$p}receipts WHERE file_id=11145");
		$writes = (int) ($phase === 'published');
		if ((int) $comments[0]['n'] !== 20 + $writes || (int) $receipts[0]['n'] !== $writes)
		{ throw new RuntimeException('Preview, correction or retry changed the number of stored comments or receipts'); }
		break;
	case 'shift':
		$db->sql_query("DELETE FROM {$p}comments WHERE file_id=11145 AND comment_id=11150");
		break;
	case 'cleanup':
		$db->sql_query("UPDATE {$p}settings SET " . $db->sql_build_array('UPDATE',
			array('config_value'=>$config_text->get('plus_test_dl_comment_limit'))) . $setting);
		$config_text->delete('plus_test_dl_comment_limit');
		$db->sql_query("DELETE FROM {$p}receipts WHERE file_id=11145");
		$db->sql_query("DELETE FROM {$p}comments WHERE file_id=11145");
		$db->sql_query("DELETE FROM {$p}files WHERE file_id=11145");
		$db->sql_query("DELETE FROM {$p}categories WHERE cat_id=11143");
		break;
	default: throw new RuntimeException('Unknown comment draft fixture stage');
}
if (!$db->sql_transaction('commit')) { throw new RuntimeException('The synthetic comment fixture did not commit'); }
$complete = true;
