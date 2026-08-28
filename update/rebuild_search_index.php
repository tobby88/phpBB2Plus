<?php
/**
 * Rebuild the phpBB 2 search index from every stored post.
 *
 * Dry-run: php update/rebuild_search_index.php
 * Apply:   php update/rebuild_search_index.php --apply --backup-confirmed
 */

if (PHP_SAPI !== 'cli')
{
	http_response_code(404);
	exit(2);
}

$apply = in_array('--apply', $argv, true);
$backup_confirmed = in_array('--backup-confirmed', $argv, true);
if ($apply && !$backup_confirmed)
{
	fwrite(STDERR, "Refusing to rebuild without --backup-confirmed.\n");
	exit(2);
}

@set_time_limit(0);
$project_root = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'phpBB2';
chdir($project_root);

define('IN_PHPBB', true);
define('IN_ADMIN', true);
$phpbb_root_path = './';
include($phpbb_root_path . 'extension.inc');

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'phpBB search index CLI';
$_SERVER['REQUEST_METHOD'] = 'CLI';
$_SERVER['SCRIPT_NAME'] = '/update/rebuild_search_index.php';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
$HTTP_SERVER_VARS = $_SERVER;

include($phpbb_root_path . 'common.' . $phpEx);
include($phpbb_root_path . 'includes/functions_search.' . $phpEx);

function rebuild_query_or_fail($sql, $description)
{
	global $db;
	$result = $db->sql_query($sql);
	if (!$result)
	{
		$error = $db->sql_error();
		fwrite(STDERR, $description . ': ' . $error['message'] . "\nSQL: $sql\n");
		exit(3);
	}
	return $result;
}

function rebuild_scalar($sql, $description)
{
	global $db;
	$result = rebuild_query_or_fail($sql, $description);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	return $row ? (int) array_values($row)[0] : 0;
}

$post_count = rebuild_scalar('SELECT COUNT(*) AS item_count FROM ' . POSTS_TEXT_TABLE, 'Could not count posts');
$word_count = rebuild_scalar('SELECT COUNT(*) AS item_count FROM ' . SEARCH_WORD_TABLE, 'Could not count search words');
$match_count = rebuild_scalar('SELECT COUNT(*) AS item_count FROM ' . SEARCH_MATCH_TABLE, 'Could not count search matches');

echo ($apply ? 'APPLY' : 'DRY RUN') . " search index rebuild\n";
echo "Posts: $post_count\n";
echo "Existing words: $word_count\n";
echo "Existing matches: $match_count\n";

if (!$apply)
{
	echo "Dry run only. No database changes were made.\n";
	exit(0);
}

rebuild_query_or_fail('TRUNCATE TABLE ' . SEARCH_TABLE, 'Could not clear cached search results');
rebuild_query_or_fail('TRUNCATE TABLE ' . SEARCH_MATCH_TABLE, 'Could not clear search matches');
rebuild_query_or_fail('TRUNCATE TABLE ' . SEARCH_WORD_TABLE, 'Could not clear search words');

$result = rebuild_query_or_fail(
	'SELECT post_id, post_subject, post_text FROM ' . POSTS_TEXT_TABLE . ' ORDER BY post_id',
	'Could not read posts'
);
$processed = 0;
while ($row = $db->sql_fetchrow($result))
{
	add_search_words(
		'single',
		(int) $row['post_id'],
		stripslashes($row['post_text']),
		stripslashes($row['post_subject'])
	);
	$processed++;
	if ($processed % 250 === 0 || $processed === $post_count)
	{
		fwrite(STDERR, "Indexed $processed/$post_count posts\n");
	}
}
$db->sql_freeresult($result);

remove_common('global', 4 / 10);

$word_count = rebuild_scalar('SELECT COUNT(*) AS item_count FROM ' . SEARCH_WORD_TABLE, 'Could not count rebuilt words');
$match_count = rebuild_scalar('SELECT COUNT(*) AS item_count FROM ' . SEARCH_MATCH_TABLE, 'Could not count rebuilt matches');
$orphan_matches = rebuild_scalar(
	'SELECT COUNT(*) AS item_count FROM ' . SEARCH_MATCH_TABLE . ' m LEFT JOIN ' . SEARCH_WORD_TABLE .
	' w ON w.word_id = m.word_id WHERE w.word_id IS NULL',
	'Could not verify rebuilt matches'
);

$config_updates = array('dbmtnc_rebuild_pos' => '-1', 'dbmtnc_rebuild_end' => '0');
foreach ($config_updates as $name => $value)
{
	rebuild_query_or_fail(
		"UPDATE " . CONFIG_TABLE . " SET config_value = '$value' WHERE config_name = '$name'",
		"Could not update $name"
	);
}
@unlink($phpbb_root_path . 'cache/config.' . $phpEx);

echo "Rebuild complete.\n";
echo "Indexed posts: $processed\n";
echo "Words: $word_count\n";
echo "Matches: $match_count\n";
echo "Orphan matches: $orphan_matches\n";

exit($processed === $post_count && $word_count > 0 && $match_count > 0 && $orphan_matches === 0 ? 0 : 4);
