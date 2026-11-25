<?php
// Called by the isolated native print/exports fixture after its ordinary 72 posts exist.
if (!defined('IN_PHPBB') || PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $dbhost !== '127.0.0.1' ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $dbname)) { exit(2); }
$auth->acl($user->data);
$writer = new mysqli('127.0.0.1', getenv('PLUS_TEST_DB_USER') ?: 'root', getenv('PLUS_TEST_DB_PASSWORD'), $dbname, (int) (getenv('PLUS_TEST_DB_PORT') ?: 3306));
$writer->set_charset('utf8mb4');
$original = $writer->query('SELECT post_text FROM p3_posts WHERE topic_id=981010 AND post_id=982071')->fetch_row()[0];
$large_original = $writer->query('SELECT post_text FROM p3_posts WHERE topic_id=981010 AND post_id=982003')->fetch_row()[0];
$changed = false;
$first_batch_size = 0;
$during_first_batch = static function ($event) use (&$changed, &$first_batch_size, $writer) {
	if (!$changed && in_array(982000, $event['post_list']))
	{
		$first_batch_size = count($event['post_list']);
		$writer->query("UPDATE p3_posts SET post_text='<t>Changed between export batches</t>' WHERE topic_id=981010 AND post_id=982071");
		$changed = true;
	}
};
$event_name = 'phpbb2plus.community.preview_bylines';
$phpbb_dispatcher->addListener($event_name, $during_first_batch);
try
{
	$writer->query("UPDATE p3_posts SET post_text=CONCAT('<t>', REPEAT('B', 2097152), '</t>') WHERE topic_id=981010 AND post_id=982003");
	$response = $phpbb_container->get('phpbb2plus.community.topic_export')->download(981010, 0);
	ob_start();
	try { $response->sendContent(); $body = ob_get_contents(); } finally { ob_end_clean(); }
	if (!$changed || $first_batch_size !== 3 || strpos($body, str_repeat('B', 2097152)) === false ||
		strpos($body, 'Print body 982071') === false || strpos($body, 'Changed between export batches') !== false)
	{ throw new RuntimeException('A completed text download mixed posts from two database revisions'); }
	if (strpos($writer->query('SELECT post_text FROM p3_posts WHERE topic_id=981010 AND post_id=982071')->fetch_row()[0], 'Changed between export batches') === false)
	{ throw new RuntimeException('The concurrent committed edit was not exercised'); }
}
finally
{
	$phpbb_dispatcher->removeListener($event_name, $during_first_batch);
	$writer->query("UPDATE p3_posts SET post_text='" . $writer->real_escape_string($original) . "' WHERE topic_id=981010 AND post_id=982071");
	$writer->query("UPDATE p3_posts SET post_text='" . $writer->real_escape_string($large_original) . "' WHERE topic_id=981010 AND post_id=982003");
	$writer->close();
}

// An old nontransactional participant must not silently produce a mixed file.
$db->sql_query('ALTER TABLE ' . ZEBRA_TABLE . ' ENGINE=MyISAM');
try
{
	$unavailable = false;
	try { $phpbb_container->get('phpbb2plus.community.topic_export')->download(981010, 0); }
	catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $unavailable = $error->getStatusCode() === 503; }
	if (!$unavailable) { throw new RuntimeException('A nontransactional archive was served'); }
}
finally { $db->sql_query('ALTER TABLE ' . ZEBRA_TABLE . ' ENGINE=InnoDB'); }
