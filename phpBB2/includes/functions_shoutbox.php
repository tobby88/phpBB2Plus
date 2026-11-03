<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/php_compat.php';
require_once dirname(__FILE__) . '/functions_topic_highlight.php';

/** Decode the request once and retain a complete UTF-8 prefix in the byte budget. */
function phpbb_shoutbox_text($value, $maximum_bytes = 16000)
{
	$value = phpbb_request_raw_value($value);
	if (!is_string($value) || preg_match('//u', $value) !== 1) { return false; }
	$value = (string) substr(trim($value), 0, max(0, min(16000, (int) $maximum_bytes)));
	// The original was valid; only the final, at most four-byte character can
	// have been split. Drop its incomplete suffix without changing earlier text.
	for ($removed = 0; $removed < 3 && preg_match('//u', $value) !== 1; $removed++)
	{
		$value = (string) substr($value, 0, -1);
	}
	return preg_match('//u', $value) === 1 ? $value : false;
}

/** Keep the legacy prepared-message contract, checking the actual TEXT bytes. */
function phpbb_shoutbox_prepare_message($text, $html_on, $bbcode_on, $smilies_on, $bbcode_uid)
{
	if (!is_string($text) || strlen($text) > 16000 || preg_match('//u', $text) !== 1) { return false; }
	$message = prepare_message(addslashes($text), $html_on, $bbcode_on, $smilies_on, $bbcode_uid);
	if (!is_string($message)) { return false; }
	$stored = stripslashes($message);
	return strlen($stored) <= 65535 && preg_match('//u', $stored) === 1 ? $message : false;
}

/** Render stored, already escaped text before linking or marking visible words. */
function phpbb_shoutbox_render($text, $uid, $bbcode_on, $smilies_on, $highlight = '', $color = '000000')
{
	if ($bbcode_on && $uid !== '') { $text = bbencode_second_pass($text, $uid); }
	$orig_word = $replacement_word = array();
	obtain_word_list($orig_word, $replacement_word);
	if ($orig_word) { $text = preg_replace($orig_word, $replacement_word, $text); }
	$text = make_clickable($text);
	if ($smilies_on) { $text = smilies_pass($text); }
	$text = str_replace("\n", '<br />', $text);
	return phpbb_topic_highlight_apply($text, $highlight, $color);
}
