<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
$title = 'Foto zum Kontowechsel – GRÜN😀au & &#128512;';
$description = 'Am Ufer 𠜎 mit &amp; und &#x1F600;';
$body = 'Kommentar zum Kontowechsel – Grüße 😀 und &#128512;';
$alias = 'SeeFOTO𠜎ern';
if ($argv[2] === 'source')
{
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	$source = new mysqli('127.0.0.1', getenv('PLUS_TEST_DB_USER') ?: 'root', getenv('PLUS_TEST_DB_PASSWORD'), $argv[1], (int) (getenv('PLUS_TEST_DB_PORT') ?: 3306));
	$source->set_charset('utf8mb4');
	// The Plus source escaped text once when saving it. Deliberately include references typed as text.
	$text = static function ($value) use ($source) { return "'" . $source->real_escape_string(htmlspecialchars($value, ENT_QUOTES, 'UTF-8')) . "'"; };
	$source->query('UPDATE phpbb_album SET pic_title=' . $text($title) . ',pic_desc=' . $text($description) . ',pic_username=' . $text($alias) . ' WHERE pic_id=105 AND pic_user_id=87');
	if ($source->affected_rows !== 1) { throw new RuntimeException('Missing original picture sample'); }
	$source->query('UPDATE phpbb_album_comment SET comment_text=' . $text($body) . ',comment_username=' . $text($alias) . ' WHERE comment_id=96 AND comment_pic_id=105 AND comment_user_id=87');
	if ($source->affected_rows !== 1) { throw new RuntimeException('Missing original comment sample'); }
	return;
}
define('IN_PHPBB', true);
$phpbb_root_path = './'; $phpEx = 'php'; require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$pics = $table_prefix . 'plus_album_pics';
$comments = $table_prefix . 'plus_album_comments';
$read = static function ($sql) use ($db) {
	$r = $db->sql_query($sql); $row = $db->sql_fetchrow($r); $db->sql_freeresult($r); return $row;
};
switch ($argv[2])
{
	case 'converted':
		$original = $read("SELECT title,description,author_name FROM $pics WHERE pic_id=105 AND author_id=87");
		$comment = $read("SELECT body,author_name FROM $comments WHERE pic_id=105 AND comment_id=96 AND author_id=87");
		if ($original !== array('title'=>$title, 'description'=>$description, 'author_name'=>$alias) || $comment !== array('body'=>$body, 'author_name'=>$alias))
		{ throw new RuntimeException('The converter lost original album text, bylines or literal character references'); }
		// Reuse the imported plain name through the genuine public guest-byline branch.
		$db->sql_query("UPDATE $pics SET author_id=" . ANONYMOUS . ' WHERE pic_id=105 AND author_id=87');
		break;
	case 'upgrade':
		// Exercise an earlier gallery schema without touching the installed gallery's rows.
		$p = $table_prefix . 'unicode_fixture_';
		$t = $p . 'plus_album_';
		$db->sql_query("CREATE TABLE {$t}pics (title TEXT NOT NULL, description MEDIUMTEXT, author_name VARCHAR(255) NOT NULL DEFAULT '',
			source_filename VARCHAR(255) NOT NULL DEFAULT '') ENGINE=InnoDB DEFAULT CHARACTER SET utf8 COLLATE utf8_bin");
		$db->sql_query("CREATE TABLE {$t}comments (body MEDIUMTEXT NOT NULL, author_name VARCHAR(255) NOT NULL DEFAULT '') ENGINE=InnoDB DEFAULT CHARACTER SET utf8 COLLATE utf8_bin");
		try
		{
			$legacy = array('title'=>'Früher &#128512;', 'description'=>null, 'author_name'=>'Ria &amp; Ada', 'source_filename'=>'älter.png');
			$db->sql_query("INSERT INTO {$t}pics " . $db->sql_build_array('INSERT', $legacy));
			$migration = new \phpbb2plus\gallery\migrations\v115_unicode_text($config, $db, $phpbb_container->get('dbal.tools'), './', 'php', $p);
			$migration->preserve_text_bytes();
			$latest = array('body'=>$body, 'author_name'=>$alias);
			$db->sql_query("INSERT INTO {$t}comments " . $db->sql_build_array('INSERT', $latest));
			$migration->preserve_text_bytes();
			if ($read("SELECT * FROM {$t}pics") !== $legacy || $read("SELECT * FROM {$t}comments") !== $latest)
			{ throw new RuntimeException('An existing plain-text gallery or a repeated update changed stored bytes'); }
		}
		finally
		{
			$db->sql_query("DROP TABLE {$t}comments, {$t}pics");
		}
		break;
	case 'uploaded':
		$row = $read("SELECT pic_id,title,description,source_filename FROM $pics WHERE source_filename='Archiv𠜎😀.png'");
		if (!$row || $row['title'] !== 'Archiv𠜎😀' || $row['source_filename'] !== 'Archiv𠜎😀.png' || $row['description'] !== 'Datei 😀 & &#128512;')
		{ throw new RuntimeException('Native multipart upload changed the original filename, default title or description'); }
		echo (int) $row['pic_id'];
		break;
	case 'restore':
		$db->sql_query("UPDATE $pics SET " . $db->sql_build_array('UPDATE', array('title'=>'Foto zum Kontowechsel','description'=>'','author_name'=>'','author_id'=>87)) . ' WHERE pic_id=105');
		$db->sql_query("UPDATE $comments SET " . $db->sql_build_array('UPDATE', array('body'=>'Kommentar zum Kontowechsel','author_name'=>'',
			'edited_at'=>100116,'edit_count'=>1,'editor_id'=>87)) . ' WHERE comment_id=96 AND pic_id=105');
		break;
	default: exit(2);
}
