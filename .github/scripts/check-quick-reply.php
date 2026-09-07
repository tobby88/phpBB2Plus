<?php
define('IN_PHPBB', true); define('ADMIN', 1); define('FORUM_LOCKED', 1); define('TOPIC_LOCKED', 1);
function quick_assert($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function append_sid($url) { return $url; }
class QuickTemplateFixture
{
	var $blocks = array(); var $vars = array();
	function set_filenames($files) {}
	function assign_block_vars($name, $values) { $this->blocks[$name] = $values; }
	function assign_vars($values) { $this->vars = array_merge($this->vars, $values); }
	function assign_var_from_handle($name, $handle) {}
}
$forum_root = dirname(dirname(__DIR__)) . '/phpBB2/';
$source = file_get_contents($forum_root . 'templates/fisubsilversh/quick_reply.tpl');
$markup = preg_replace('~<script\b[^>]*>.*?</script>|<!--.*?-->~si', '', $source);
// The fragment is included within an existing topic table cell. Every nested
// form/row/cell must close without consuming that caller-owned structure.
$markup = '<table><tr><td>' . $markup . '</td></tr></table>';
preg_match_all('~<(/?)(form|table|tr|td|th)\b[^>]*>~i', $markup, $tags, PREG_SET_ORDER);
$stack = array();
foreach ($tags as $tag)
{
	$name = strtolower($tag[2]);
	if ($tag[1] === '/') { quick_assert(array_pop($stack) === $name, 'Crossed quick-reply table/form boundaries'); continue; }
	$parent = $stack ? end($stack) : '';
	if ($name === 'tr') { quick_assert($parent === 'table', 'Rows need their own table, not the caller cell/form'); }
	if ($name === 'td' || $name === 'th') { quick_assert($parent === 'tr', 'Cells need an explicit row'); }
	if ($name === 'form') { quick_assert(!in_array('form', $stack, true), 'Forms must not nest'); }
	$stack[] = $name;
}
quick_assert(!$stack, 'Every owned table/form must close');
quick_assert(strpos($source, 'accept-charset="UTF-8"') !== false, 'Quick replies explicitly submit UTF-8');
quick_assert(strpos($source, "theSelection = theSelection ? String(theSelection) : '';") !== false, 'Empty/null Selection objects must not be treated as selected text');
quick_assert(strpos($source, 'message.setSelectionRange(') !== false, 'Modern smilies/quotes must retain the insertion position');
foreach (array('sid', 'mode', 't', 'last_msg', 'quick_quote', 'attach_sig', 'notify', 'message', 'preview', 'post') as $name)
{
	quick_assert(preg_match('~name=[\'\"]' . $name . '[\'\"]~', $source) === 1, 'Retain expected posting field ' . $name);
}
$lang = array();
foreach (array('Username','Preview','Options','Submit','Cancel','Attach_signature','Notify','Quick_Reply_smilies',
	'QuoteSelelected','QuoteSelelectedEmpty','Empty_message','Quick_quote','Quick_Reply','Quick_add_smilies') as $key) { $lang[$key] = $key; }
$_GET = $_POST = array(); $phpEx = 'php'; $topic_id = 42; $is_watching_topic = 0;
foreach (array('allowed','denied','forum-locked','topic-locked','empty') as $case)
{
	$template = new QuickTemplateFixture();
	$userdata = array('session_id' => 'fixture-session', 'session_logged_in' => true, 'user_attachsig' => true, 'user_notify' => true, 'user_level' => 0);
	$is_auth = array('auth_reply' => $case !== 'denied');
	$forum_topic_data = array('forum_status' => $case === 'forum-locked' ? 1 : 0, 'topic_status' => $case === 'topic-locked' ? 1 : 0);
	$total_posts = $case === 'empty' ? 0 : 1;
	$postrow = array(array('bbcode_uid' => 'fixtureuid', 'username' => 'Fixture', 'post_text' => "Grüße 😀 <script> ' \" & [b:fixtureuid]text[/b:fixtureuid]"));
	include $forum_root . 'quick_reply.php';
	quick_assert(isset($template->blocks['quick_reply']) === ($case === 'allowed'), 'Quick-reply rendering must preserve permission/locked/empty checks: ' . $case);
	if ($case === 'allowed')
	{
		$fields = $template->blocks['quick_reply'];
		quick_assert($fields['SID'] === 'fixture-session' && $fields['TOPIC_ID'] === 42, 'Retain session and topic fields');
		quick_assert(strpos($fields['LAST_MESSAGE'], '<script>') === false && strpos($fields['LAST_MESSAGE'], '&lt;script&gt;') !== false, 'Previous message must remain escaped in its hidden attribute');
		quick_assert(strpos($fields['LAST_MESSAGE'], 'fixtureuid') === false && strpos($fields['LAST_MESSAGE'], 'Grüße 😀') !== false, 'Previous quote retains Unicode and removes stored BBCode IDs');
	}
}
echo "Quick-reply structure and rendering checks passed.\n";
