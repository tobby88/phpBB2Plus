<?php
define('IN_PHPBB', true);
define('CTRACKER_REQUEST_LIMITER_NO_AUTO_RUN', true);
$forum_root = dirname(dirname(__DIR__)) . '/phpBB2/';
require $forum_root . 'ctracker/engines/ct_request_limiter.php';
function routing_assert($expected, $script, $post, $get)
{
	$profile = ctracker_request_limit_profile($script, $post, $get);
	if ($profile[0] !== $expected)
	{
		fwrite(STDERR, 'Limiter routing mismatch for ' . $script . ': expected ' . $expected . ', got ' . $profile[0] . "\n");
		exit(1);
	}
}
// profile.php dispatches a nonempty scalar GET mode before POST mode.
routing_assert('register', 'profile.php', array('mode'=>'editprofile'), array('mode'=>'register'));
routing_assert('account', 'profile.php', array('mode'=>''), array('mode'=>'sendpassword'));
routing_assert('account', 'profile.php', array('mode'=>array('x')), array('mode'=>'email'));
routing_assert('register', 'profile.php', array('mode'=>'register'), array('mode'=>''));
routing_assert('register', 'profile.php', array('mode'=>'register'), array('mode'=>array('x')));
routing_assert('write', 'profile.php', array('mode'=>'register'), array('mode'=>'viewprofile'));
routing_assert('write', 'profile.php', array('mode'=>'register', 'signature'=>'text'), array());
// dload.php falls back to GET on empty/non-scalar POST and accepts ?module suffixes.
routing_assert('account', 'dload.php', array('action'=>''), array('action'=>'email'));
routing_assert('upload', 'dload.php', array('action'=>array('x')), array('action'=>'user_upload'));
routing_assert('account', 'dload.php', array('action'=>'email?extra'), array());
routing_assert('upload', 'dload.php', array('action'=>'user_upload?extra'), array());
routing_assert('content', 'dload.php', array('action'=>'rate?extra'), array());
routing_assert('content', 'dload.php', array('action'=>'post_comment?extra'), array());
routing_assert('write', 'dload.php', array('action'=>'main'), array('action'=>'email'));
// AJAX intentionally keeps POST precedence even if its value is empty.
routing_assert('write', 'ajax.php', array('mode'=>''), array('mode'=>'edit_post_text'));
routing_assert('content', 'ajax.php', array(), array('mode'=>'edit_post_text'));

// Execute the real, side-effect-free routing assignments from each endpoint,
// not another hand-copied version of the limiter's decision algorithm.
function routing_source_block($file, $start, $end)
{
	$source = file_get_contents($GLOBALS['forum_root'] . $file);
	$first = strpos($source, $start);
	$last = $first === false ? false : strpos($source, $end, $first);
	if ($first === false || $last === false) { fwrite(STDERR, "Endpoint routing extraction changed: $file\n"); exit(1); }
	return substr($source, $first, $last - $first);
}
$profile_code = routing_source_block('profile.php', '$get_mode =', "\n\tif ( \$mode == 'viewprofile' )");
$dload_code = routing_source_block('dload.php', '$post_action =', '//===================================================');
$module_code = routing_source_block('dload.php', '$action_mod = array();', "\nif (!isset(\$actions");
if (strpos($module_code, '$module_action') === false) { fwrite(STDERR, "Download module routing missing.\n"); exit(1); }
$values = array(null, '', 0, false, array('x'), 'register', 'sendpassword', 'email', 'viewprofile', 'REGISTER', 'user_upload', 'post_comment', 'email?extra', 'user_upload?extra', 'rate?extra', 'main');
foreach ($values as $post_value)
{
	foreach ($values as $get_value)
	{
		foreach (array('', '0', 'text', array('x')) as $signature)
		{
			$_POST = array('mode'=>$post_value, 'signature'=>$signature);
			$_GET = array('mode'=>$get_value);
			eval($profile_code);
			$actual_mode = strtolower($mode);
			$expected = $actual_mode === 'register' ? 'register' : (in_array($actual_mode, array('sendpassword', 'email'), true) ? 'account' : 'write');
			routing_assert($expected, 'profile.php', $_POST, $_GET);
		}
		$_POST = array('action'=>$post_value);
		$_GET = array('action'=>$get_value);
		eval($dload_code);
		eval($module_code);
		$actual_action = strtolower($action_mod[0]);
		$expected = $actual_action === 'email' ? 'account' : ($actual_action === 'user_upload' ? 'upload' : (in_array($actual_action, array('rate', 'post_comment'), true) ? 'content' : 'write'));
		routing_assert($expected, 'dload.php', $_POST, $_GET);
	}
}
echo "CrackerTracker routing tests passed.\n";
