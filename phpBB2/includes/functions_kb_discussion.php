<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

// Numeric entities survive the real posting first pass and decode exactly once
// in displayed HTML. Plain fields must not acquire BBCode, HTML or auto-links.
// KB fields follow stored-text legacy decoding; account/guest names stay literal.
function kb_discussion_literal($value, $legacy_decode = true)
{
	$value = (string)$value;
	if ($legacy_decode) { $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8'); }
	return strtr($value, array('&'=>'&#38;','<'=>'&#60;','>'=>'&#62;','"'=>'&#34;',"'"=>'&#39;',
		'['=>'&#91;',']'=>'&#93;',':'=>'&#58;','.'=>'&#46;','@'=>'&#64;'));
}

function kb_discussion_author($article)
{
	global $lang;
	$registered = (int)$article['article_author_id'] > 0 ? get_kb_author((int)$article['article_author_id']) : '';
	if ($registered !== '') { return $registered; }
	$guest = isset($article['username']) ? trim((string)$article['username']) : '';
	return $guest !== '' ? $guest : $lang['Guest'];
}

// Call only inside an owning article transaction, after relation validation.
function kb_discussion_message($article)
{
	global $lang, $phpEx;
	$category = get_kb_cat($article['article_category_id']); $type = get_kb_type($article['article_type']);
	$message = '[b]' . kb_discussion_literal($lang['Category']) . ':[/b] ' . kb_discussion_literal($category['category_name']) . "\n";
	$message .= '[b]' . kb_discussion_literal($lang['Article_type']) . ':[/b] ' . kb_discussion_literal($type !== '' ? $type : $lang['None']) . "\n\n";
	$message .= '[b]' . kb_discussion_literal($lang['Article_title']) . ':[/b] ' . kb_discussion_literal($article['article_title']) . "\n";
	$message .= '[b]' . kb_discussion_literal($lang['Author']) . ':[/b] ' . kb_discussion_literal(kb_discussion_author($article), false) . "\n";
	$message .= '[b]' . kb_discussion_literal($lang['Article_description']) . ':[/b] ' . kb_discussion_literal($article['article_description']) . "\n\n";
	$message .= '[b][url=' . phpbb_board_url('kb.' . $phpEx . '?mode=article&k=' . (int)$article['article_id']) . ']' . kb_discussion_literal($lang['Read_full_article']) . '[/url][/b]';
	return $message;
}

function kb_insert_discussion_post($message, $subject, $forum, $poster, $username, $signature, $topic = null, $update = '', $bump = 1)
{
	global $db;
	if (!($db instanceof PhpbbKbPublicationDatabase) || !$db->transactional || $db->finished) { kb_publication_fail(); }
	$forum = kb_counter_category_id($forum); $first = 0;
	if ($topic !== null) {
		$topic = kb_counter_category_id($topic);
		$rows = $db->rows('SELECT p.post_id FROM ' . TOPICS_TABLE . ' t INNER JOIN ' . POSTS_TABLE . ' p ON p.post_id=t.topic_first_post_id AND p.topic_id=t.topic_id AND p.forum_id=t.forum_id WHERE t.topic_id=' . $topic . ' AND t.forum_id=' . $forum . ' FOR UPDATE');
		if (count($rows) !== 1) { kb_publication_fail('KB_deletion_relation'); }
		$first = kb_counter_category_id($rows[0]['post_id']);
	}
	// Both forum subject columns hold 60 characters of already escaped text.
	// Bound the stored representation without splitting UTF-8 or an HTML entity.
	$escaped = phpbb_stored_text($subject); $subject = ''; $length = 0;
	if (preg_match_all('/&(?:\#[0-9]+|\#x[0-9a-f]+|[a-z][a-z0-9]+);|./isu', $escaped, $tokens) === false) { kb_publication_fail(); }
	foreach ($tokens[0] as $token) {
		$size = $token[0] === '&' ? strlen($token) : 1;
		if ($length + $size > 60) { break; }
		$subject .= $token; $length += $size;
	}
	// The generated labels/link use BBCode; every metadata field remains text.
	$result = insert_post($message, $subject, $forum, $poster, $username, $signature,
		$topic, $update, $bump, POST_NORMAL, false, false, 0, '', 0, 1, 0);
	if ($first) {
		$db->sql_query('UPDATE ' . POSTS_TABLE . ' SET enable_html=0,enable_bbcode=1,enable_smilies=0 WHERE post_id=' . $first . ' AND topic_id=' . $topic . ' AND forum_id=' . $forum);
		$rows = $db->rows('SELECT enable_html,enable_bbcode,enable_smilies FROM ' . POSTS_TABLE . ' WHERE post_id=' . $first . ' AND topic_id=' . $topic . ' AND forum_id=' . $forum);
		if (count($rows) !== 1 || (string)$rows[0]['enable_html'] !== '0' || (string)$rows[0]['enable_bbcode'] !== '1' || (string)$rows[0]['enable_smilies'] !== '0') { kb_publication_fail(); }
	}
	return $result;
}
