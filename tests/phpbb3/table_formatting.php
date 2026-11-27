<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
function table_fixture_text($uid = '')
{
	$u = $uid === '' ? '' : ':' . $uid;
	return '[table=width: 90%; border-collapse: collapse' . $u . "]\n" .
		'[cell=color: navy; padding: 2px 4px; letter-spacing: 3px' . $u . ']Zelle [b' . $u . ']Grüße[/b' . $u . '] ☆[/cell' . $u . "]\n" .
		'[cell=text-align: center' . $u . '][table=' . $u . '][cell=' . $u . ']Innen[/cell' . $u . '][/table' . $u . '][/cell' . $u . '][/table' . $u . ']';
}
if ($argv[2] === 'source')
{
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	$s = new mysqli('127.0.0.1', getenv('PLUS_TEST_DB_USER') ?: 'root', getenv('PLUS_TEST_DB_PASSWORD'), $argv[1], (int) (getenv('PLUS_TEST_DB_PORT') ?: 3306));
	$s->set_charset('utf8mb4');
	$s->query("UPDATE phpbb_kb_articles SET bbcode_uid='tabkb' WHERE article_id=214 AND bbcode_uid=''");
	foreach (array(array('phpbb_posts_text','post_text','bbcode_uid','post_id=1'),
		array('phpbb_privmsgs_text','privmsgs_text','privmsgs_bbcode_uid','privmsgs_text_id=111'),
		array('phpbb_kb_articles','article_body','bbcode_uid','article_id=214')) as list($table, $column, $uid, $where))
	{
		$mark = $s->query("SELECT $uid FROM $table WHERE $where")->fetch_row()[0];
		if (!$mark) { throw new RuntimeException('Missing source BBCode UID'); }
		$value = "\n" . str_replace("\n", '<br />', table_fixture_text($mark));
		$stmt = $s->prepare("UPDATE $table SET $column=CONCAT($column,?) WHERE $where");
		$stmt->bind_param('s', $value); $stmt->execute();
		if ($stmt->affected_rows !== 1) { throw new RuntimeException('Missing Plus table source fixture'); }
	}
	return;
}
define('IN_PHPBB', true);
$phpbb_root_path = './'; $phpEx = 'php'; require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$sample = table_fixture_text();
$key = 'test_plus_table_formatting';
$store = $phpbb_container->get('config_text');
$old = '<r><B><s>[b]</s>Vorher<e>[/b]</e></B> [table=width: 90%]<br/>' . "\n" . '[cell=color: navy]Zelle ' .
	'<B><s>[b]</s>Grüße<e>[/b]</e></B> 😀[/cell][cell=][table=][cell=]Innen[/cell][/table][/cell][/table] ' .
	'<CODE><s>[code]</s>[table=][cell=]Beispiel[/cell][/table]<e>[/code]</e></CODE> Nachher</r>';
$old = \s9e\TextFormatter\Utils::encodeUnicodeSupplementaryCharacters($old);
$read = static function ($table, $fields, $where) use ($db) {
	$r = $db->sql_query("SELECT $fields FROM $table WHERE $where"); $row = $db->sql_fetchrow($r); $db->sql_freeresult($r); return $row;
};
switch ($argv[2])
{
	case 'fresh':
		$declarations = 'WIDTH: 80%; height: auto; min-width: 1.75rem; max-width: 640px; vertical-align: MIDDLE; color: #AbC; ' .
			'background-color: snow; padding: 1px 2em 3rem 4%; margin: 2px; border-spacing: 4px; border-collapse: separate; ' .
			'border: 1px dashed Navy; line-height: 2; width: wider';
		$expected = 'width: 80%; height: auto; min-width: 1.75rem; max-width: 640px; vertical-align: middle; color: #abc; ' .
			'background-color: snow; padding: 1px 2em 3rem 4%; margin: 2px; border-spacing: 4px; border-collapse: separate; border: 1px dashed navy';
		if (\phpbb2plus\community\service\table_formatting::style($declarations) !== $expected)
		{ throw new RuntimeException('The allowed original table styles changed'); }
		$cases = array($sample => 2, '[table=][cell=]offen[/table]' => 0, '[cell=]ohne Tabelle[/cell]' => 0,
			'[table]ohne Gleichheitszeichen[/table]' => 0, '[table=][cell=]normal[/cell][cell][/table]' => 0,
			'[table=][cell=][b]Kreuzung[/cell][/b][/table]' => 0,
			'[table=]draußen[cell=]innen[/cell][/table] [table=][cell=]danach[/cell][/table]' => 1,
			'[quote][table=][cell=][code]wörtlich [/table][/code][/cell][/table][/quote]' => 1);
		foreach ($cases as $src => $count)
		{
			$xml = $src; $uid = $bits = ''; $flags = 0;
			generate_text_for_storage($xml, $uid, $bits, $flags, true, true, true);
			if (substr_count($xml, '<PLUSTABLE ') !== $count || \s9e\TextFormatter\Unparser::unparse($xml) !== $src)
			{ throw new RuntimeException('Native table structure or exact editor text differed for a synthetic sample'); }
		}
		$recovered = \phpbb2plus\community\service\legacy_tables::upgrade($old);
		if (substr_count($recovered, '<PLUSTABLE ') !== 2 || \s9e\TextFormatter\Unparser::unparse($recovered) !== \s9e\TextFormatter\Unparser::unparse($old) ||
			strpos($recovered, '<B><s>[b]</s>Grüße<e>[/b]</e></B>') === false || \phpbb2plus\community\service\legacy_tables::upgrade($recovered) !== $recovered)
		{ throw new RuntimeException('Older literal table recovery changed native child markup or the unparsed text'); }
		$native = new \s9e\TextFormatter\Configurator();
		$native->BBCodes->addCustom('[cell={TEXT1}]{TEXT2}[/cell]', '<i title="{TEXT1}">{TEXT2}</i>');
		\phpbb2plus\community\service\table_formatting::configure($native);
		if (isset($native->BBCodes['TABLE']) || $native->BBCodes['CELL']->tagName === 'PLUSCELL' || !isset($native->tags['PLUSTABLE']))
		{ throw new RuntimeException('A pre-existing cell BBCode was combined with an incompatible Plus table'); }
		$other = new \s9e\TextFormatter\Configurator();
		$other->BBCodes->addCustom('[plustable]{TEXT}[/plustable]', '<p>{TEXT}</p>');
		\phpbb2plus\community\service\table_formatting::configure($other);
		if (isset($other->BBCodes['TABLE'])) { throw new RuntimeException('A native XML tag name was taken over by a Plus table'); }
		break;
	case 'converted':
		foreach (array(array(POSTS_TABLE,'post_text','post_id=1'), array(PRIVMSGS_TABLE,'message_text','msg_id=111'),
			array($table_prefix . 'plus_kb_pages','content','article_id=214 AND page_number=1')) as list($table,$field,$where))
		{
			$xml = $read($table, $field, $where)[$field];
			if (strpos(\s9e\TextFormatter\Unparser::unparse($xml), $sample) === false || substr_count($xml, '<PLUSTABLE ') !== 2)
			{ throw new RuntimeException('Missing converted table markup or an unremoved source UID in ' . $field); }
		}
		break;
	case 'older':
		if ($store->get($key) !== null) { throw new RuntimeException('Conflicting old table fixture'); }
		$store->set($key, json_encode($read(POSTS_TABLE, 'post_text,enable_bbcode,bbcode_bitfield', 'post_id=1')));
		$db->sql_query('UPDATE ' . POSTS_TABLE . ' SET ' . $db->sql_build_array('UPDATE',
			array('post_text'=>$old, 'enable_bbcode'=>1, 'bbcode_bitfield'=>'QA==')) . ' WHERE post_id=1');
		break;
	case 'disabled':
		$db->sql_query('UPDATE ' . POSTS_TABLE . ' SET enable_bbcode=0 WHERE post_id=1');
		break;
	case 'custom':
		if ($read(BBCODES_TABLE, 'bbcode_id', "LOWER(bbcode_tag) IN ('table','table=','cell','cell=')")) { throw new RuntimeException('Conflicting native table fixture'); }
		require_once './includes/acp/acp_bbcodes.php';
		$acp = new \acp_bbcodes();
		$usage = '[table={TEXT1}]{TEXT2}[/table]';
		$template = '<div class="test-native-table" title="{TEXT1}">{TEXT2}</div>';
		$id = max(100, (int) $read(BBCODES_TABLE, 'MAX(bbcode_id) AS last_id', '1=1')['last_id'] + 1);
		$definition = $acp->build_regexp($usage, $template);
		$db->sql_query('INSERT INTO ' . BBCODES_TABLE . ' ' . $db->sql_build_array('INSERT', $definition + array(
			'bbcode_id'=>$id, 'bbcode_match'=>$usage, 'bbcode_tpl'=>$template, 'bbcode_helpline'=>'Table fixture', 'display_on_posting'=>0)));
		$db->sql_query('UPDATE ' . POSTS_TABLE . ' SET enable_bbcode=1 WHERE post_id=1');
		$cache->destroy('sql', BBCODES_TABLE);
		\phpbb2plus\community\service\smilie_sign::reset_formatter($phpbb_container);
		break;
	case 'restore':
		$row = json_decode($store->get($key), true);
		if (!$row) { throw new RuntimeException('Missing original table fixture'); }
		$db->sql_query('UPDATE ' . POSTS_TABLE . ' SET ' . $db->sql_build_array('UPDATE', $row) . ' WHERE post_id=1');
		$db->sql_query('DELETE FROM ' . BBCODES_TABLE . " WHERE bbcode_helpline='Table fixture' AND bbcode_match='[table={TEXT1}]{TEXT2}[/table]'");
		$cache->destroy('sql', BBCODES_TABLE);
		\phpbb2plus\community\service\smilie_sign::reset_formatter($phpbb_container);
		$store->delete($key);
		break;
	default: exit(2);
}
