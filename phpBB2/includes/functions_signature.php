<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

// prepare_message has a legacy slash boundary only when HTML is enabled.
// Both preview and storage must pass raw form text through that boundary once.
function phpbb_signature_prepare($text, $html, $bbcode, $smilies, $uid)
{
	$text = prepare_message($html ? addslashes($text) : $text, $html, $bbcode, $smilies, $uid);
	return $html ? stripslashes($text) : $text;
}

function phpbb_signature_strip_uid($text, $uid)
{
	$text = (string) $text; $uid = (string) $uid;
	if ($uid === '') { return $text; }
	$decoded = preg_replace('/:(([a-z0-9]+:)?)' . preg_quote($uid, '/') . '(=|\])/i', '$3', $text);
	return $decoded === null ? $text : $decoded;
}

function phpbb_signature_edit_text($text, $uid)
{
	// Reverse only the storage entities, once. The caller separately escapes
	// this raw value for the textarea; never unescape rendered HTML instead.
	return unprepare_message(phpbb_signature_strip_uid($text, $uid));
}

function phpbb_signature_render($text, $uid, $html, $bbcode, $smilies)
{
	global $lang;
	$text = (string) $text; $uid = (string) $uid;
	if ($text === '') { return $lang['sig_none']; }
	// Stored text is already prepared. Re-preparing it would encode entities
	// twice and recompile BBCode. Escape only literal HTML if now disabled.
	if (!$html) { $text = str_replace(array('<', '>'), array('&lt;', '&gt;'), $text); }
	if ($uid !== '') { $text = $bbcode ? bbencode_second_pass($text, $uid) : phpbb_signature_strip_uid($text, $uid); }
	$text = make_clickable($text);
	if ($smilies) { $text = smilies_pass($text); }
	return '_________________<br />' . str_replace("\n", "\n<br />\n", $text);
}
