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
		$sample .= " [stream:$uid]https://archive.example.test/audio.ogg[/stream:$uid] " .
			"[video width=320 height=200:$uid]https://archive.example.test/film.webm[/video:$uid]";
		$sample .= " [scrollup:$uid]Höher[/scrollup:$uid] [marq=right:$uid]Weiter[/marq:$uid]";
		$sample .= " [right:$uid]https://archive.example.test/bild.jpg[/right:$uid]";
		$sample .= " [RIGHT]https://archive.example.test/literal.png[/RIGHT]";
		$sample .= " [google]Grün &amp; Kaffee+Tee?[/google]";
		$q = $old->prepare("UPDATE $table SET $column=CONCAT($column,?) WHERE $where");
		$q->bind_param('s', $sample);
		$q->execute();
		if ($q->affected_rows !== 1) { throw new RuntimeException('Missing source formatting fixture'); }
	}
	$disabled = ' [scrollup]Fest[/scrollup] [ram]https://archive.example.test/plain.ogg[/ram] [google]Plain query[/google]';
	foreach (array(array('phpbb_posts','enable_bbcode','post_id=40','phpbb_posts_text','post_text','post_id=40'),
		array('phpbb_privmsgs','privmsgs_enable_bbcode','privmsgs_id=112','phpbb_privmsgs_text','privmsgs_text','privmsgs_text_id=112'))
		as list($table,$flag,$where,$body_table,$field,$body_where))
	{
		$old->query("UPDATE $table SET $flag=0 WHERE $where");
		$q = $old->prepare("UPDATE $body_table SET $field=CONCAT($field,?) WHERE $body_where");
		$q->bind_param('s', $disabled);
		$q->execute();
		if ($q->affected_rows !== 1) { throw new RuntimeException('Missing disabled-BBCode example'); }
	}
	return;
}
define('IN_PHPBB', true);
// Set up the normal board session while its conversion wizard is still present.
if ($argv[2] === 'native' && is_dir('./install')) { define('IN_LOGIN', true); }
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
		formatting_require(strpos(\s9e\TextFormatter\Unparser::unparse($xml), '[RIGHT]https://archive.example.test/literal.png[/RIGHT]') !== false &&
			strpos($xml, 'src="https://archive.example.test/literal.png"') === false, 'An unmarked source image was promoted to a native IMG in ' . $column);
		$xpath = formatting_xpath($renderer->render($xml));
		$search = 'https://www.google.com/search?q=' . rawurlencode('Grün & Kaffee+Tee?');
		formatting_require($xpath->query('//a[@href="' . $search . '" and text()="Grün & Kaffee+Tee?"]')->length === 1,
			'The converted search link changed its Unicode label or query in ' . $column);
		formatting_require($xpath->query('//audio[@controls and @src="https://archive.example.test/audio.ogg"]/a[@href="https://archive.example.test/audio.ogg"]')->length === 1 &&
			$xpath->query('//video[@width="320" and @height="200" and @controls]/a[@href="https://archive.example.test/film.webm"]')->length === 1,
			'Converted audio or video was missing from ' . $column);
		formatting_require($xpath->query('//div[contains(@style,"text-align:right")]/s[text()="Überholt"]')->length === 1 &&
			$xpath->query('//span[contains(@style,"background-color:#ffcc00") and text()="Grün & Gelb"]')->length === 1,
			'Native formatted content missing from ' . $column);
		formatting_require($xpath->query('//span[@class="plus-scroll plus-scroll-up"]//span[text()="Höher"]')->length === 1 &&
			$xpath->query('//span[@class="plus-scroll plus-scroll-right"]//span[text()="Weiter"]')->length === 1,
			'Missing scrolling text from ' . $column);
		formatting_require(strpos(\s9e\TextFormatter\Unparser::unparse($xml), '[align=right][s]Überholt[/s]') !== false,
			'Editable Plus formatting lost its original tags in ' . $column);
		foreach (array(true, false) as $images)
		{
			$renderer->set_viewimg($images);
			$view = formatting_xpath($renderer->render($xml));
			formatting_require($view->query('//span[contains(@style,"float:right")]/img[@src="https://archive.example.test/bild.jpg" and @class="postimage"]')->length === (int) $images,
				'An imported aligned picture lost its native image settings in ' . $column);
		}
		$renderer->set_viewimg(true);
	}
	foreach (array(array(POSTS_TABLE,'post_text','post_id=40'),array(PRIVMSGS_TABLE,'message_text','msg_id=112')) as list($table,$column,$where))
	{
		$r = $db->sql_query("SELECT $column,enable_bbcode FROM $table WHERE $where");
		$row = $db->sql_fetchrow($r); $db->sql_freeresult($r);
		$html = $renderer->render($row[$column]);
		formatting_require(!$row['enable_bbcode'] && strpos($html,'[scrollup]Fest[/scrollup]') !== false &&
			strpos($html,'class="plus-scroll') === false && strpos($html,'<audio') === false && strpos($html,'google.com/search') === false,
			'Disabled source BBCode became active in ' . $column);
	}
}
elseif ($argv[2] === 'native')
{
	$parser = $phpbb_container->get('text_formatter.parser');
	$image_code = '[LEFT]https://archive.example.test/links.png[/LEFT] [right]https://archive.example.test/rechts.jpeg[/right] ' .
		'[img]https://archive.example.test/normal.jpg[/img] [right]https://archive.example.test/unmatched.txt[/right] [code][left]https://archive.example.test/beispiel.png[/left][/code]';
	$parser->enable_bbcodes();
	$parser->enable_bbcode('url');
	$query = '[google]Öl & A+B? #1[/google] [url=https://archive.example.test/]Normal[/url] [code][google]Beispiel[/google][/code]';
	$links = $parser->parse($query);
	$view = formatting_xpath($renderer->render($links));
	$destination = 'https://www.google.com/search?q=' . rawurlencode('Öl & A+B? #1');
	formatting_require($view->query('//a[@href="' . $destination . '"]')->length === 1 && $view->query('//a')->length === 2 &&
		\s9e\TextFormatter\Unparser::unparse($links) === $query, 'The search shortcut changed ordinary links, code or editable text');
	$parser->disable_bbcode('url');
	$no_links = $parser->parse($query);
	$parser->enable_bbcode('url');
	formatting_require(strpos($no_links, 'google.com/search') === false, 'A blocked native link was active through the search shortcut');
	$parser->enable_bbcode('img');
	$image_xml = $parser->parse($image_code);
	$renderer->set_viewimg(true);
	$view = formatting_xpath($renderer->render($image_xml));
	formatting_require($view->query('//span[contains(@style,"float:left")]/img[contains(@src,"links.png")]')->length === 1 &&
		$view->query('//span[contains(@style,"float:right")]/img[contains(@src,"rechts.jpeg")]')->length === 1 && $view->query('//img')->length === 3 &&
		\s9e\TextFormatter\Unparser::unparse($image_xml) === $image_code, 'Aligned and ordinary images did not share their native rendering or editable source');
	$renderer->set_viewimg(false);
	formatting_require(formatting_xpath($renderer->render($image_xml))->query('//img')->length === 0, 'A reader who disabled images still received image markup');
	$renderer->set_viewimg(true);
	$parser->disable_bbcode('img');
	$no_images = $parser->parse($image_code);
	$parser->enable_bbcode('img');
	formatting_require(strpos($no_images, '<IMG ') === false && \s9e\TextFormatter\Unparser::unparse($no_images) === $image_code,
		'An aligned picture escaped the normal native image posting switch');
	$sample = "[center][s]Alt[/s][/center] [font=Georgia]Schrift[/font] [poet]A\nB[/poet] [poet=alt]C[/poet] [hr] " .
		"[glow=red]Licht[/glow] [shadow=blue]Schatten[/shadow] [fliph]Horizontal[/fliph] [flipv]Vertikal[/flipv] [fade]Verlauf[/fade]";
	foreach (array('left','right','up','down') as $direction)
	{
		$sample .= " [scroll$direction]Direct[/scroll$direction] [marq=$direction]Parameter[/marq]";
	}
	$sample .= ' [marq=unexpected]Unchanged[/marq] [code][scrollup]Example[/scrollup][/code]';
	foreach (array(true, false) as $enabled)
	{
		$xml = $sample;
		generate_text_for_storage($xml, $uid, $bits, $flags, $enabled, false, false);
		formatting_require(\s9e\TextFormatter\Unparser::unparse($xml) === $sample, 'Format examples changed in the editor');
		$xpath = formatting_xpath($renderer->render($xml));
		foreach (array('left','right','up','down') as $direction)
		{
			formatting_require($xpath->query('//span[@class="plus-scroll plus-scroll-' . $direction . '"]')->length === (int) $enabled * 2,
				'Scrolling direction or code samples changed');
		}
		foreach (array('//div[contains(@style,"text-align:center")]/s','//span[contains(@style,"font-family") and text()="Schrift"]',
			'//div[@class="bbcode-poem" and contains(.,"B")]','//div[@class="bbcode-poem" and text()="C"]','//hr',
			'//span[contains(@style,"text-shadow") and text()="Licht"]','//span[contains(@style,"text-shadow") and text()="Schatten"]',
			'//span[contains(@style,"scaleX")]','//span[contains(@style,"scaleY")]','//span[contains(@style,"linear-gradient")]') as $query)
		{
			formatting_require($xpath->query($query)->length === (int) $enabled, 'Unexpected native output for ' . $query . ' with BBCode ' . (int) $enabled);
		}
	}
	// An old preview may have mixed the literal delimiters with other native tags.
	$older = '<r><B><s>[b]</s>Vorher<e>[/b]</e></B> [align=right][s]Grün <I><s>[i]</s>Öl<e>[/i]</e></I> &#128512;[/s][/align] [hr] ' .
		'<CODE><s>[code]</s>[s]Beispiel[/s]<e>[/code]</e></CODE> [scrolldown]Süden[/scrolldown] [align=falsch]Wort[/align] [s]offen</r>';
	$service = $phpbb_container->get('phpbb2plus.community.legacy_formatting');
	$stored = new \ArrayObject(array('text' => $no_images, 'flags' => OPTION_FLAG_BBCODE));
	$service->older_content($stored);
	formatting_require($stored['text'] === $no_images, 'Viewing a post re-enabled images disallowed by the posting form');
	$stored = new \ArrayObject(array('text' => $no_links, 'flags' => OPTION_FLAG_BBCODE));
	$service->older_content($stored);
	formatting_require($stored['text'] === $no_links, 'Viewing a post turned an unlinked search term into a link');
	$media_old = '<r><B><s>[b]</s>Ton<e>[/b]</e></B> [ram]<URL url="https://archive.example.test/a.ogg">https://archive.example.test/a.ogg</URL>[/ram] ' .
		'[left]<URL url="https://archive.example.test/links.gif">https://archive.example.test/links.gif</URL>[/left] ' .
		'[video width=699 height=499]https://archive.example.test/f.webm[/video] [video width=700 height=499]https://archive.example.test/v.webm[/video] ' .
		'<CODE><s>[code]</s>[ram]https://archive.example.test/example[/ram]<e>[/code]</e></CODE></r>';
	foreach (array(true, false) as $enabled)
	{
		$event = new \ArrayObject(array('text'=>$media_old, 'flags'=>$enabled ? OPTION_FLAG_BBCODE : 0));
		$service->older_content($event);
		$media_xml = $event['text'];
		$xpath = formatting_xpath($renderer->render($media_xml));
		formatting_require($xpath->query('//audio[@preload="none"]')->length === (int) $enabled &&
			$xpath->query('//span[contains(@style,"float:left")]/img[contains(@src,"links.gif")]')->length === 0 &&
			$xpath->query('//video')->length === (int) $enabled &&
			\s9e\TextFormatter\Unparser::unparse($media_xml) === \s9e\TextFormatter\Unparser::unparse($media_old),
			'Historical players changed dimensions, code samples or the editable source');
	}
	foreach (array(true, false) as $enabled)
	{
		$event = new \ArrayObject(array('rowset_data' => array('post_text' => $older, 'bbcode_bitfield' => 'QA=='), 'row' => array('enable_bbcode' => $enabled)));
		$service->topic_post($event);
		$row = $event['rowset_data'];
		$xml = $row['post_text'];
		formatting_require(\s9e\TextFormatter\Unparser::unparse($xml) === \s9e\TextFormatter\Unparser::unparse($older) &&
			strpos($xml, '<B><s>[b]</s>Vorher<e>[/b]</e></B>') !== false && strpos($xml, '[s]Beispiel[/s]') !== false &&
			strpos($xml, '[align=falsch]Wort[/align] [s]offen') !== false, 'Earlier markup or its editor source changed');
		$xpath = formatting_xpath($renderer->render($xml));
		formatting_require($xpath->query('//div[contains(@style,"text-align:right")]/s/*[text()="Öl"]')->length === (int) $enabled &&
			$xpath->query('//span[@class="plus-scroll plus-scroll-down"]')->length === (int) $enabled &&
			$xpath->query('//hr')->length === (int) $enabled && strpos($xml, '<I><s>[i]</s>Öl<e>[/i]</e></I>') !== false,
			'Earlier text styles ignored BBCode=' . (int) $enabled . ' or lost an italic child: ' . $xml);
		formatting_require($enabled ? $row['bbcode_bitfield'] === 'QA==' : $row['bbcode_bitfield'] === '',
			'An earlier bitfield overrode the stored BBCode preference');
		$second = new \ArrayObject(array('text' => $xml, 'flags' => $enabled ? OPTION_FLAG_BBCODE : 0));
		$service->older_content($second);
		formatting_require($second['text'] === $xml, 'Viewing earlier formatting twice changed it again');
	}
	// Simulate an established board whose last native BBCode ID is already taken.
	// A second application of the migration must keep that definition and its ID.
	$overflow = $config['plus_formatting_overflow'];
	$db->sql_transaction('begin');
	try
	{
		$db->sql_query('DELETE FROM ' . BBCODES_TABLE . " WHERE bbcode_tag IN ('s','fade')");
		$db->sql_query('UPDATE ' . BBCODES_TABLE . ' SET ' . $db->sql_build_array('UPDATE', array(
			'bbcode_id' => BBCODE_LIMIT, 'bbcode_tpl' => '<aside class="board-center">{TEXT}</aside>')) . " WHERE bbcode_tag='center'");
		$migration = new \phpbb2plus\community\migrations\v172_formatting_codes($config, $db,
			$phpbb_container->get('dbal.tools'), $phpbb_root_path, $phpEx, $table_prefix);
		$migration->install_codes();
		$migration->install_codes();
		formatting_require($config['plus_formatting_overflow'] === 's,fade', 'The migration reused an occupied native ID');
		$db->sql_query('DELETE FROM ' . BBCODES_TABLE . " WHERE bbcode_tag IN ('scrollup','font=')");
		$scrolling = new \phpbb2plus\community\migrations\v185_scrolling_text($config, $db,
			$phpbb_container->get('dbal.tools'), $phpbb_root_path, $phpEx, $table_prefix);
		$scrolling->install_scrolling();
		$scrolling->install_scrolling();
		formatting_require($config['plus_formatting_overflow'] === 's,fade,scrollup', 'Adding scrolling text changed unrelated definitions or exhausted native IDs');
		$objects = $phpbb_container->get('text_formatter.s9e.factory')->regenerate();
		$xml = $objects['parser']->parse('[center]Board[/center] [s]Alt[/s] [fade]Ende[/fade] [scrollup]Up[/scrollup]');
		$xpath = formatting_xpath($objects['renderer']->render($xml));
		formatting_require($xpath->query('//aside[@class="board-center" and text()="Board"]')->length === 1 &&
			$xpath->query('//span[@class="plus-scroll plus-scroll-up"]')->length === 1 &&
			$xpath->query('//s[text()="Alt"]')->length === 1 && $xpath->query('//span[contains(@style,"linear-gradient")]')->length === 1,
			'The native fallback or pre-existing board template was lost');
	}
	finally
	{
		$db->sql_transaction('rollback');
		$config->set('plus_formatting_overflow', $overflow);
		$cache->destroy('sql', BBCODES_TABLE);
		\phpbb2plus\community\service\smilie_sign::reset_formatter($phpbb_container);
	}
}
else { exit(2); }
