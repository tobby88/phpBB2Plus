<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
if ($argv[2] === 'source')
{
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	$db = new mysqli('127.0.0.1', getenv('PLUS_TEST_DB_USER') ?: 'root', getenv('PLUS_TEST_DB_PASSWORD'), $argv[1],
		(int) (getenv('PLUS_TEST_DB_PORT') ?: 3306));
	$db->set_charset('utf8mb4');
	$insert = $db->prepare('INSERT INTO phpbb_acronyms (acronym_id,acronym,description) VALUES (?,?,?)');
	foreach (array(array(5201, 'KöFo', 'Kölner Forum'), array(5202, 'KöFo AG', 'KöFo & "Arbeitsgruppe" 🧭'),
		array(5203, 'A&T', 'A &amp; T')) as list($id, $term, $meaning))
	{
		$insert->bind_param('iss', $id, $term, $meaning);
		$insert->execute();
	}
	foreach (array(array('phpbb_posts_text', 'post_text', 'post_id=1'),
		array('phpbb_kb_articles', 'article_body', 'article_id=214')) as list($table, $field, $where))
	{
		$db->query("UPDATE $table SET $field=CONCAT($field,' KöFo AG · KöFo · Lexikonprobe.') WHERE $where");
		if ($db->affected_rows !== 1) { throw new RuntimeException('Missing acronym source fixture'); }
	}
	return;
}
define('IN_PHPBB', true);
$phpbb_root_path = './'; $phpEx = 'php'; require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
if (in_array($argv[2], array('older', 'upgraded'), true))
{
	$result = $db->sql_query('SELECT * FROM ' . $table_prefix . 'plus_acronyms ORDER BY acronym_id');
	$rows = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	if (count($rows) < 3) { throw new RuntimeException('Reverting or reapplying the module lost stored dictionary entries'); }
	echo json_encode($rows, JSON_THROW_ON_ERROR);
	return;
}
if ($argv[2] !== 'converted') { exit(2); }
$dictionary = $phpbb_container->get('phpbb2plus.community.acronym_dictionary');
$short = '<abbr title="Kölner Forum">KöFo</abbr>';
$long = '<abbr title="KöFo &amp; &quot;Arbeitsgruppe&quot; 🧭">KöFo AG</abbr>';
$literal = '<abbr title="A &amp;amp; T">A&amp;T</abbr>';
$protected = '<pre><code>KöFo</code></pre><abbr title="KöFo">KöFo AG</abbr><!-- KöFo -->';
$markup = '<a title="KöFo > Forum" href="./?q=KöFo">KöFo AG</a> KöFo KöForum köfo A&amp;T';
$expected = '<a title="KöFo > Forum" href="./?q=KöFo">' . $long . '</a> ' . $short . ' KöForum köfo ' . $literal;
if ($dictionary->decorate($markup . $protected) !== $expected . $protected) { throw new RuntimeException('The dictionary changed protected markup or matched a partial term'); }
$result = $db->sql_query('SELECT d.rights_json FROM ' . $table_prefix . 'plus_jr_delegations d JOIN ' . USERS_TABLE .
	" u ON u.user_id=d.user_id WHERE u.username_clean='acronymjunior'");
$grants = $db->sql_fetchfield('rights_json');
$db->sql_freeresult($result);
if (json_decode((string) $grants, true) !== array('a_plus_acronyms')) { throw new RuntimeException('Missing original dictionary-only permission'); }
