<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
if ($argv[2] === 'source')
{
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	$c = new mysqli('127.0.0.1', getenv('PLUS_TEST_DB_USER') ?: 'root', getenv('PLUS_TEST_DB_PASSWORD'), $argv[1], (int) (getenv('PLUS_TEST_DB_PORT') ?: 3306));
	$c->set_charset('utf8mb4');
	foreach (array(array('phpbb_posts_text', 'post_text', 'post_id=1'), array('phpbb_kb_articles', 'article_body', 'article_id=214')) as list($table, $body, $where))
	{
		$c->query("UPDATE $table SET $body=CONCAT($body,' [schild=19 fontcolor=#026646 shadowcolor= shieldshadow=0]Grüße vom Archiv[/schild]') WHERE $where");
		if ($c->affected_rows !== 1) { throw new RuntimeException('Missing synthetic source message for sign conversion'); }
	}
	return;
}
define('IN_PHPBB', true);
$phpbb_root_path = './'; $phpEx = 'php'; require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$store = $phpbb_container->get('config_text');
$key = 'test_plus_smilie_signs';
$row = static function () use ($db) {
	$result = $db->sql_query('SELECT post_text,enable_bbcode FROM ' . POSTS_TABLE . ' WHERE post_id=1');
	$post = $db->sql_fetchrow($result); $db->sql_freeresult($result); return $post;
};
$historic = '<r><B><s>[b]</s>Unberührt<e>[/b]</e></B> [schild=2 fontcolor=223344 shadowcolor=445566 shieldshadow=0]Grüße <E>:)</E> &amp; Mühle[/schild] <CODE><s>[code]</s>[schild=3]Literal[/schild]<e>[/code]</e></CODE></r>';
switch ($argv[2])
{
	case 'converted':
		$post = $row();
		$result = $db->sql_query('SELECT content FROM ' . $table_prefix . 'plus_kb_pages WHERE article_id=214 AND page_number=1');
		$kb = $db->sql_fetchfield('content'); $db->sql_freeresult($result);
		foreach (array('forum' => $post['post_text'], 'article' => $kb) as $where => $xml)
		{
			if (strpos($xml, '<PLUSSMILIESIGN ') === false || strpos($xml, 'Grüße vom Archiv') === false ||
				strpos(\s9e\TextFormatter\Unparser::unparse($xml), '[schild=19 fontcolor=#026646 shadowcolor= shieldshadow=0]Grüße vom Archiv[/schild]') === false)
			{ throw new RuntimeException('The full converter did not recognise and preserve the historical sign in the ' . $where); }
		}
		break;
	case 'historical':
		if ($store->get($key) !== null) { throw new RuntimeException('Unexpected saved sign fixture'); }
		$upgraded = \phpbb2plus\community\service\legacy_smilie_signs::upgrade($historic);
		if (\s9e\TextFormatter\Unparser::unparse($upgraded) !== \s9e\TextFormatter\Unparser::unparse($historic))
		{ throw new RuntimeException('An older sign changed other markup in the native editor'); }
		$store->set($key, json_encode($row()));
		$db->sql_query('UPDATE ' . POSTS_TABLE . ' SET ' . $db->sql_build_array('UPDATE', array('post_text' => $historic, 'enable_bbcode' => 1)) . ' WHERE post_id=1');
		break;
	case 'disabled':
		if ($row()['post_text'] !== $historic) { throw new RuntimeException('Viewing an old sign changed its source'); }
		$db->sql_query('UPDATE ' . POSTS_TABLE . ' SET enable_bbcode=0 WHERE post_id=1');
		break;
	case 'cleanup':
		$original = json_decode((string) $store->get($key), true);
		if (!$original) { throw new RuntimeException('The original synthetic post is missing'); }
		$db->sql_query('UPDATE ' . POSTS_TABLE . ' SET ' . $db->sql_build_array('UPDATE', $original) . ' WHERE post_id=1');
		$store->delete($key);
		break;
	default: exit(2);
}
