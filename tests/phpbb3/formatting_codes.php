<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }

if ($argv[2] === 'source')
{
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	$old = new mysqli('127.0.0.1', getenv('PLUS_TEST_DB_USER') ?: 'root', getenv('PLUS_TEST_DB_PASSWORD'), $argv[1],
		(int) (getenv('PLUS_TEST_DB_PORT') ?: 3306));
	$old->set_charset('utf8mb4');
	// These are the stored Plus forms, including its authoring UID on both delimiters.
	$old->query("UPDATE phpbb_kb_articles SET bbcode_uid='acrokb' WHERE article_id=214 AND bbcode_uid=''");
	foreach (array(array('phpbb_posts_text','post_text','post_id=1','bbcode_uid'),
		array('phpbb_kb_articles','article_body','article_id=214','bbcode_uid'),
		array('phpbb_privmsgs_text','privmsgs_text','privmsgs_text_id=111','privmsgs_bbcode_uid')) as list($table, $column, $where, $id_column))
	{
		$uid = $old->query("SELECT $id_column FROM $table WHERE $where")->fetch_row()[0];
		$sample = " [align=right:$uid][s:$uid]Überholt[/s:$uid] [highlight=#ffcc00:$uid]Grün &amp; Gelb[/highlight:$uid][/align:$uid]";
		$q = $old->prepare("UPDATE $table SET $column=CONCAT($column,?) WHERE $where");
		$q->bind_param('s', $sample);
		$q->execute();
		if ($q->affected_rows !== 1) { throw new RuntimeException('Missing source formatting fixture'); }
	}
	return;
}
define('IN_PHPBB', true);
// The clean-install check runs before the converter wizard is opened.
if ($argv[2] === 'native' && is_dir('./install')) { define('IN_INSTALL', true); }
$phpbb_root_path = './'; $phpEx = 'php'; require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$user->session_begin(); $auth->acl($user->data); $user->setup();
$renderer = $phpbb_container->get('text_formatter.renderer');
function formatting_require($ok, $message)
{
	if (!$ok) { throw new RuntimeException($message); }
}
function formatting_xpath($html)
{
	$doc = new DOMDocument();
	$doc->loadHTML('<html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
	return new DOMXPath($doc);
}
if ($argv[2] === 'converted')
{
	foreach (array(array(POSTS_TABLE,'post_text','post_id=1'), array(PRIVMSGS_TABLE,'message_text','msg_id=111'),
		array($table_prefix . 'plus_kb_pages','content','article_id=214 AND page_number=1')) as list($table,$column,$where))
	{
		$r = $db->sql_query("SELECT $column FROM $table WHERE $where");
		$xml = $db->sql_fetchfield($column); $db->sql_freeresult($r);
		$xpath = formatting_xpath($renderer->render($xml));
		formatting_require($xpath->query('//div[contains(@style,"text-align:right")]/s[text()="Überholt"]')->length === 1 &&
			$xpath->query('//span[contains(@style,"background-color:#ffcc00") and text()="Grün & Gelb"]')->length === 1,
			'Native formatted content missing from ' . $column);
		formatting_require(strpos(\s9e\TextFormatter\Unparser::unparse($xml), '[align=right][s]Überholt[/s]') !== false,
			'Editable Plus formatting lost its original tags in ' . $column);
	}
}
elseif ($argv[2] === 'native')
{
	$sample = "[center][s]Alt[/s][/center] [font=Georgia]Schrift[/font] [poet]A\nB[/poet] [poet=alt]C[/poet] [hr] " .
		"[glow=red]Licht[/glow] [shadow=blue]Schatten[/shadow] [fliph]Horizontal[/fliph] [flipv]Vertikal[/flipv] [fade]Verlauf[/fade]";
	foreach (array(true, false) as $enabled)
	{
		$xml = $sample;
		generate_text_for_storage($xml, $uid, $bits, $flags, $enabled, false, false);
		formatting_require(\s9e\TextFormatter\Unparser::unparse($xml) === $sample, 'Format examples changed in the editor');
		$xpath = formatting_xpath($renderer->render($xml));
		foreach (array('//div[contains(@style,"text-align:center")]/s','//span[contains(@style,"font-family") and text()="Schrift"]',
			'//div[@class="bbcode-poem" and contains(.,"B")]','//div[@class="bbcode-poem" and text()="C"]','//hr',
			'//span[contains(@style,"text-shadow") and text()="Licht"]','//span[contains(@style,"text-shadow") and text()="Schatten"]',
			'//span[contains(@style,"scaleX")]','//span[contains(@style,"scaleY")]','//span[contains(@style,"linear-gradient")]') as $query)
		{
			formatting_require($xpath->query($query)->length === (int) $enabled, 'Unexpected native output for ' . $query . ' with BBCode ' . (int) $enabled);
		}
	}
}
else { exit(2); }
