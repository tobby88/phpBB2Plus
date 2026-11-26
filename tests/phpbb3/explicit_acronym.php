<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
$example = '[acronym="Schüler & Archiv"]Abi[/acronym]';
if ($argv[2] === 'source')
{
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	$s = new mysqli('127.0.0.1', getenv('PLUS_TEST_DB_USER') ?: 'root', getenv('PLUS_TEST_DB_PASSWORD'), $argv[1], (int) (getenv('PLUS_TEST_DB_PORT') ?: 3306));
	$s->set_charset('utf8mb4');
	$s->query("UPDATE phpbb_kb_articles SET bbcode_uid='acrokb' WHERE article_id=214 AND bbcode_uid=''");
	foreach (array(array('phpbb_posts_text','post_text','post_id=1','testuid'), array('phpbb_kb_articles','article_body','article_id=214','acrokb'),
		array('phpbb_privmsgs_text','privmsgs_text','privmsgs_text_id=111','privtest')) as list($table, $column, $where, $uid))
	{
		$text = ' [acronym:' . $uid . '="Schüler &amp; Archiv"]Abi[/acronym:' . $uid . ']';
		$stmt = $s->prepare("UPDATE $table SET $column=CONCAT($column,?) WHERE $where"); $stmt->bind_param('s', $text); $stmt->execute();
		if ($stmt->affected_rows !== 1) { throw new RuntimeException('Missing acronym source fixture'); }
	}
	return;
}
define('IN_PHPBB', true);
$phpbb_root_path = './'; $phpEx = 'php'; require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$key = 'test_plus_explicit_acronym';
$store = $phpbb_container->get('config_text');
$source = static function ($table, $where, $field) use ($db) {
	$r = $db->sql_query("SELECT $field FROM $table WHERE $where"); $value = $db->sql_fetchfield($field); $db->sql_freeresult($r); return $value;
};
$legacy = '<r><B><s>[b]</s>Vorher<e>[/b]</e></B> [acronym="Alte Grüße &amp; Rat"]A<I><s>[i]</s>B<e>[/i]</e></I>C[/acronym] <CODE><s>[code]</s>[acronym="Beispiel"]Code[/acronym]<e>[/code]</e></CODE> Nachher</r>';
switch ($argv[2])
{
	case 'fresh':
		$xml = $example; $uid = $bits = ''; $flags = 0;
		generate_text_for_storage($xml, $uid, $bits, $flags, true, true, true);
		if (strpos($xml, '<PLUSACRONYM ') === false || \s9e\TextFormatter\Unparser::unparse($xml) !== $example)
		{ throw new RuntimeException('A clean phpBB3 board did not register the native acronym BBCode'); }
		if (\phpbb2plus\community\service\explicit_acronym::title(str_repeat('A', 254) . '😀') !== str_repeat('A', 254))
		{ throw new RuntimeException('The historical title bound cut a multibyte character'); }
		$row = array('post_text' => $legacy, 'bbcode_bitfield' => 'QA==');
		$projection = new \ArrayObject(array('rowset_data' => $row, 'row' => array()));
		$phpbb_container->get('phpbb2plus.community.legacy_formatting')->topic_post($projection);
		if ($projection['rowset_data'] !== $row) { throw new RuntimeException('A projection without a stored BBCode option lost its native flags'); }
		break;
	case 'converted':
		foreach (array(array(POSTS_TABLE,'post_id=1','post_text'), array(PRIVMSGS_TABLE,'msg_id=111','message_text'),
			array($table_prefix . 'plus_kb_pages','article_id=214 AND page_number=1','content')) as $where)
		{
			$xml = $source(...$where);
			if (strpos($xml, '<PLUSACRONYM ') === false || strpos(\s9e\TextFormatter\Unparser::unparse($xml), $example) === false)
			{ throw new RuntimeException('The converter lost a native acronym in ' . $where[0]); }
		}
		$body = $source($table_prefix . 'plus_kb_articles','article_id=214','editor_text');
		if (strpos($body, $example) === false || strpos($body, ':acrokb') !== false) { throw new RuntimeException('The KB editor retained a source UID'); }
		break;
	case 'older':
		if ($store->get($key) !== null || \phpbb2plus\community\service\explicit_acronym::custom_definition($db)) { throw new RuntimeException('Conflicting acronym fixture'); }
		$pm = $source(PRIVMSGS_TABLE,'msg_id=111','message_text');
		$page = $source($table_prefix . 'plus_kb_pages','article_id=214 AND page_number=1','content');
		$store->set($key, json_encode(array($source(POSTS_TABLE,'post_id=1','post_text'), $source(POSTS_TABLE,'post_id=1','enable_bbcode'),
			$source($table_prefix . 'plus_kb_articles','article_id=214','editor_text'), $pm, $page, $source(POSTS_TABLE,'post_id=1','bbcode_bitfield'))));
		$upgraded = \phpbb2plus\community\service\explicit_acronym::upgrade($legacy);
		if (\s9e\TextFormatter\Unparser::unparse($upgraded) !== \s9e\TextFormatter\Unparser::unparse($legacy))
		{ throw new RuntimeException('Recognition changed previously stored editor text'); }
		$db->sql_query('UPDATE ' . POSTS_TABLE . ' SET ' . $db->sql_build_array('UPDATE', array('post_text' => $legacy, 'enable_bbcode' => 1)) . ' WHERE post_id=1');
		$db->sql_query('UPDATE ' . PRIVMSGS_TABLE . ' SET ' . $db->sql_build_array('UPDATE', array('message_text' => $legacy)) . ' WHERE msg_id=111');
		$plain = '<t>' . htmlspecialchars(\s9e\TextFormatter\Unparser::unparse($page), ENT_NOQUOTES, 'UTF-8') . '</t>';
		$db->sql_query('UPDATE ' . $table_prefix . 'plus_kb_pages SET ' . $db->sql_build_array('UPDATE', array('content' => $plain)) . ' WHERE article_id=214 AND page_number=1');
		$body = str_replace('[acronym=', '[acronym:acrokb=', $source($table_prefix . 'plus_kb_articles','article_id=214','editor_text'));
		$db->sql_query('UPDATE ' . $table_prefix . 'plus_kb_articles SET ' . $db->sql_build_array('UPDATE', array('editor_text' => $body)) . ' WHERE article_id=214');
		$mismatch = 'Different draft ' . $body;
		if ($phpbb_container->get('phpbb2plus.knowledgebase.editor')->editor_text(array('article_id' => 214, 'editor_text' => $mismatch)) !== $mismatch)
		{ throw new RuntimeException('A different article draft was changed without matching source text'); }
		break;
	case 'off':
		if ($source(POSTS_TABLE,'post_id=1','post_text') !== $legacy) { throw new RuntimeException('Viewing a previous preview rewrote the old post'); }
		$db->sql_query('UPDATE ' . POSTS_TABLE . " SET enable_bbcode=0,bbcode_bitfield='QA==' WHERE post_id=1");
		break;
	case 'custom':
		$db->sql_query('UPDATE ' . POSTS_TABLE . ' SET enable_bbcode=1 WHERE post_id=1');
		require_once './includes/acp/acp_bbcodes.php';
		$acp = new \acp_bbcodes();
		$usage = '[acronym={TEXT1}]{TEXT2}[/acronym]';
		$template = '<em class="test-native-acronym" title="{TEXT1}">{TEXT2}</em>';
		$definition = $acp->build_regexp($usage, $template);
		$id = max(100, (int) $source(BBCODES_TABLE,'1=1','MAX(bbcode_id)') + 1);
		$db->sql_query('INSERT INTO ' . BBCODES_TABLE . ' ' . $db->sql_build_array('INSERT',
			$definition + array('bbcode_id' => $id, 'bbcode_match' => $usage, 'bbcode_tpl' => $template, 'bbcode_helpline' => 'Acronym fixture', 'display_on_posting' => 0)));
		$cache->destroy('sql', BBCODES_TABLE);
		\phpbb2plus\community\service\smilie_sign::reset_formatter($phpbb_container);
		break;
	case 'restore':
		$orig = json_decode((string) $store->get($key), true);
		if (!$orig) { throw new RuntimeException('Missing original acronym test content'); }
		$db->sql_query('UPDATE ' . POSTS_TABLE . ' SET ' . $db->sql_build_array('UPDATE',
			array('post_text' => $orig[0], 'enable_bbcode' => $orig[1], 'bbcode_bitfield' => $orig[5])) . ' WHERE post_id=1');
		$db->sql_query('UPDATE ' . $table_prefix . 'plus_kb_articles SET ' . $db->sql_build_array('UPDATE', array('editor_text' => $orig[2])) . ' WHERE article_id=214');
		$db->sql_query('UPDATE ' . PRIVMSGS_TABLE . ' SET ' . $db->sql_build_array('UPDATE', array('message_text' => $orig[3])) . ' WHERE msg_id=111');
		$db->sql_query('UPDATE ' . $table_prefix . 'plus_kb_pages SET ' . $db->sql_build_array('UPDATE', array('content' => $orig[4])) . ' WHERE article_id=214 AND page_number=1');
		$db->sql_query('DELETE FROM ' . BBCODES_TABLE . " WHERE bbcode_tag='acronym' AND bbcode_helpline='Acronym fixture'");
		$cache->destroy('sql', BBCODES_TABLE);
		\phpbb2plus\community\service\smilie_sign::reset_formatter($phpbb_container);
		$store->delete($key);
		break;
	default: exit(2);
}
