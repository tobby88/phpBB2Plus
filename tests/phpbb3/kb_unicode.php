<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
$phase = $argv[2];
if ($phase === 'source')
{
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	$source = new mysqli('127.0.0.1', getenv('PLUS_TEST_DB_USER') ?: 'root', getenv('PLUS_TEST_DB_PASSWORD'), $argv[1], (int) (getenv('PLUS_TEST_DB_PORT') ?: 3306));
	$source->set_charset('utf8mb4');
	$source->query("INSERT INTO phpbb_kb_articles (article_id,article_category_id,article_title,article_description,article_date,article_author_id,username,
		bbcode_uid,article_body,article_type,approved,topic_id,views,article_rating,article_totalvotes) VALUES
		(26001,8,'Unicodebogen 🧭','Ärmel 𠜎', '100201',0,'Gast 🐚','testuid','Fächer 🌺[toc] KbZeile_41% 🛰️ Feld &amp;#128512; [b:testuid]東京[/b:testuid][page]Mond 🌙[toc] Ende',2,0,0,0,0,0)");
	return;
}
define('IN_PHPBB', true); $phpbb_root_path = './'; $phpEx = 'php';
require './common.php';
if ($dbname !== $argv[1] || $dbhost !== '127.0.0.1') { exit(2); }
$rows = static function ($sql) use ($db) {
	$r = $db->sql_query($sql); $v = $db->sql_fetchrowset($r); $db->sql_freeresult($r); return $v;
};
$expect = static function ($ok, $why) { if (!$ok) { throw new RuntimeException($why); } };
$p = $table_prefix . 'plus_kb_';
$state_key = 'plus_test_kb_unicode';
$state_store = $phpbb_container->get('config_text');
$native = $rows('SELECT @@character_set_client AS client, @@character_set_results AS results')[0];
$expect(in_array($native['client'], array('utf8','utf8mb3'), true) && $native['client'] === $native['results'], 'The fixture changed the native connection character set');
if (in_array($phase, array('older','upgraded','reverted'), true))
{
	$types = array_column($rows("SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$p}articles'"), 'DATA_TYPE', 'COLUMN_NAME');
	if ($phase === 'older')
	{
		$expect($types['editor_text'] === 'mediumtext' && $types['title'] === 'varchar', 'Expected the unmodified legacy KB schema');
		// Existing raw text on an old target can contain a genuine literal reference.
		$db->sql_query("INSERT INTO {$p}articles (article_id,title,description,editor_text,search_text,author_name)
			VALUES (26002,'Grüße &#128512;','','Alte Zeile &#128512;','Alte Zeile &#128512;','')");
	}
	else
	{
		$expect($types['title'] === 'varbinary' && $types['description'] === 'blob' && $types['editor_text'] === 'mediumblob' &&
			$types['search_text'] === 'mediumblob', 'Expected verbatim UTF-8 storage through native MySQLi');
		$prior = $rows("SELECT title,editor_text FROM {$p}articles WHERE article_id=26002")[0];
		$expect($prior['title'] === 'Grüße &#128512;' && $prior['editor_text'] === 'Alte Zeile &#128512;', 'Existing literal text was reinterpreted by the migration');
		if ($phase === 'upgraded') { $db->sql_query("UPDATE {$p}articles SET description='Test 🌟' WHERE article_id=26002"); }
		else { $expect($rows("SELECT description FROM {$p}articles WHERE article_id=26002")[0]['description'] === 'Test 🌟', 'Rolling back destroyed supplementary characters'); }
	}
	return;
}
if ($phase === 'prepare')
{
	$a = $rows("SELECT * FROM {$p}articles WHERE article_id=26001")[0] ?? array();
	$pages = $rows("SELECT heading,content FROM {$p}pages WHERE article_id=26001 ORDER BY page_number");
	$expect(($a['title'] ?? '') === 'Unicodebogen 🧭' && $a['description'] === 'Ärmel 𠜎' && $a['author_name'] === 'Gast 🐚' &&
		strpos($a['editor_text'], 'Fächer 🌺[toc] KbZeile_41% 🛰️ Feld &#128512; [b]東京[/b]') !== false &&
		strpos($a['search_text'], 'KbZeile_41% 🛰️ Feld &#128512;') !== false && array_column($pages, 'heading') === array('Fächer 🌺','Mond 🌙'),
		'Converted article source, searchable text, byline, title or contents headings lost Unicode');
	$expect(!$state_store->get($state_key), 'An earlier Unicode fixture is still active');
	$state_store->set($state_key, json_encode(array('article'=>$a, 'pages'=>$rows("SELECT * FROM {$p}pages WHERE article_id=26001"),
		'keep'=>$rows("SELECT * FROM {$p}settings WHERE setting_name IN ('comments','forum_id','allow_new','allow_anon')"))));
	foreach (array('comments','forum_id','allow_new','allow_anon') as $key)
	{
		$db->sql_query("INSERT INTO {$p}settings (setting_name,setting_value) VALUES ('$key','1') ON DUPLICATE KEY UPDATE setting_value='1'");
	}
	// Make the synthetic draft visible for this browser case without adding it to shared public fixture counts.
	$db->sql_query("UPDATE {$p}articles SET approval=1 WHERE article_id=26001");
}
elseif ($phase === 'saved')
{
	$a = $rows("SELECT title,description,editor_text,search_text FROM {$p}articles WHERE article_id=26001")[0];
	$expect($a['title'] === str_repeat('🧩', 255) && $a['description'] === 'Neu 😀 und &#128512;' &&
		strpos($a['editor_text'], 'Licht 🪔 &#128512;') !== false && strpos($a['search_text'], 'Licht 🪔 &#128512;') !== false,
		'The real editor lost an original code point, reference or the 255-character title');
	$post = $rows('SELECT p.post_subject FROM ' . POSTS_TABLE . " p JOIN {$p}articles a ON a.intro_post=p.post_id WHERE a.article_id=26001 AND p.plus_kb_article=26001")[0] ?? array();
	$expect(isset($post['post_subject']) && preg_match('/^\[KB\] 🧩+$/uD', html_entity_decode($post['post_subject'], ENT_QUOTES, 'UTF-8')),
		'Native article discussion subject lost a character or its reference boundary');
}
elseif ($phase === 'clean')
{
	$state = json_decode((string) $state_store->get($state_key), true);
	$topics = $rows('SELECT t.topic_id FROM ' . TOPICS_TABLE . ' t JOIN ' . POSTS_TABLE .
		' p ON p.post_id=t.topic_first_post_id WHERE p.plus_kb_article=26001');
	if ($topics)
	{
		require_once './includes/functions_admin.php';
		delete_topics('topic_id', array_column($topics, 'topic_id'));
	}
	$db->sql_query("UPDATE {$p}articles SET " . $db->sql_build_array('UPDATE', $state['article']) . ' WHERE article_id=26001');
	$db->sql_query("DELETE FROM {$p}pages WHERE article_id=26001");
	$db->sql_multi_insert($p . 'pages', $state['pages']);
	$db->sql_query("DELETE FROM {$p}submissions WHERE article_id=26001");
	$db->sql_query("DELETE FROM {$p}settings WHERE setting_name IN ('comments','forum_id','allow_new','allow_anon')");
	if ($state['keep']) { $db->sql_multi_insert($p . 'settings', $state['keep']); }
	$state_store->delete($state_key);
}
else { throw new RuntimeException('Unknown KB Unicode fixture phase'); }
