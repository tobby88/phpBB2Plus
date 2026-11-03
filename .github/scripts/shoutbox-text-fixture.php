<?php
// Shared actual-controller fixture. Never bootstrap a real board or account.
if (PHP_SAPI !== 'cli') { exit(2); }
define('IN_PHPBB', true); define('PHPBB_LEGACY_REQUEST_ESCAPED', isset($argv[1]) && $argv[1] === 'legacy');
$sx_app = dirname(dirname(__DIR__)) . '/phpBB2/'; $phpbb_root_path = $sx_app; $phpEx = 'php'; $table_prefix = 'fixture_';
require $sx_app.'includes/php_compat.php'; require $sx_app.'includes/constants.php';
require $sx_app.'includes/functions_post.php'; require $sx_app.'includes/bbcode.php'; require $sx_app.'includes/functions_shoutbox.php';
$sx_checks = $sx_cases = 0;
function sx_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } $GLOBALS['sx_checks']++; }
class SxExit extends RuntimeException {}
function message_die($level, $message) { throw new SxExit($message); }
function create_date($format, $time, $zone) { return 'Synthetic date'; }
class SxTemplate
{
	var $vars = array();
	function make_filename($file) { sx_check($file === 'bbcode.tpl', 'Only actual standard BBCode template'); return $GLOBALS['sx_app'].'templates/fisubsilversh/bbcode.tpl'; }
	function set_filenames($files) {}
	function assign_vars($values) { $this->vars = array_merge($this->vars, $values); }
	function assign_var($key, $value) { $this->vars[$key] = $value; }
	function assign_var_from_handle($key, $handle) { $this->vars[$key] = 'synthetic rendered '.$handle; }
}
function sx_part($source, $start, $end, $last = false)
{
	$a = $last ? strrpos($source, $start) : strpos($source, $start); $b = $a === false ? false : strpos($source, $end, $a);
	sx_check($a !== false && $b !== false, 'Actual complete shoutbox controller boundaries'); return substr($source, $a, $b-$a);
}
$sx_fragments = array();
foreach (array('shoutbox.php','shoutbox_max.php') as $file)
{
	$s = file_get_contents($sx_app.$file); sx_check(strpos($s, "'includes/functions_shoutbox.'") !== false, 'Both actual entry points load the common text pipeline');
	$init = sx_part($s, '$refresh =', '$s_hidden_fields =');
	$full = $file === 'shoutbox_max.php'; $from = $full ? 'if ($refresh || $preview)' : 'if ($refresh)';
	$flow = sx_part($s, $from, $full ? "if (\$mode=='delete'" : '// see if we need offset');
	if ($full) { $flow = preg_replace('/\belse\s*$/D', '', $flow); }
	$identity = $full ? sx_part($s, '$username = $userdata[', $from) : '';
	$errors = sx_part($s, "if( \$error_msg != '' )", '$template->pparse', true);
	$sx_fragments[$file] = array($init,$identity,$flow,$errors);
}
$source = file_get_contents($sx_app.'includes/functions.php');
foreach (array('obtain_word_list','phpbb_profile_text') as $name)
{
	sx_check(preg_match('/^function '.$name.'\(.*?^\}/ms', $source, $part) === 1, 'Actual formatting/censor helper'); eval($part[0]);
}
function sx_run($route, $raw, $action = 'post', $flags = array(0,0,0), $options = array())
{
	global $db,$template,$board_config,$userdata,$user_ip,$phpbb_root_path,$phpEx,$lang,$bbcode_tpl;
	$template = new SxTemplate(); unset($GLOBALS['global_orig_word'], $GLOBALS['global_replacement_word']);
	$guest = !empty($options['guest']); $userdata = array('user_id'=>$guest?ANONYMOUS:4,'user_level'=>USER,'session_logged_in'=>!$guest,'username'=>"A\\B O'Reilly <b>ü</b>",'session_id'=>str_repeat('a',32));
	$user_ip = '7f000001'; $is_auth = array('auth_post'=>empty($options['no_auth']) && !$guest);
	$board_config = array('allow_html_tags'=>'b,i,u','default_dateformat'=>'Y','board_timezone'=>0,'smilies_path'=>'images/smiles','flood_interval'=>isset($options['flood'])?$options['flood']:0,'prune_shouts'=>isset($options['prune'])?$options['prune']:0);
	$_POST = array('message'=>$raw,'username'=>$guest?'':'Spoofed name','sid'=>isset($options['sid'])?$options['sid']:$userdata['session_id']);
	$_POST[$action === 'post' ? 'shout' : ($action === 'refresh' ? 'auto_refresh' : 'preview')] = '1';
	if (PHPBB_LEGACY_REQUEST_ESCAPED) { $_POST = phpbb_addslashes_recursive($_POST); }
	$_SERVER['REQUEST_METHOD'] = isset($options['method'])?$options['method']:'POST';
	$fragments = $GLOBALS['sx_fragments'][$route]; eval($fragments[0]); eval($fragments[1]);
	$html_on = $flags[0]; $bbcode_on = $flags[1]; $smilies_on = $flags[2]; $rejected = false;
	try { eval($fragments[2]); } catch (SxExit $exit) { $rejected = true; }
	if (!$rejected) { eval($fragments[3]); }
	return array('text'=>$shout_text,'error'=>$error,'error_msg'=>$error_msg,'rejected'=>$rejected,'vars'=>$template->vars,'uid'=>isset($bbcode_uid)?$bbcode_uid:'','username'=>$username);
}
function sx_render_stored($stored, $uid, $flags)
{
	if ($flags[1]) { $stored = bbencode_second_pass($stored, $uid); }
	$orig = $repl = array(); obtain_word_list($orig,$repl); if ($orig) { $stored = preg_replace($orig,$repl,$stored); }
	$stored = make_clickable($stored); if ($flags[2]) { $stored = smilies_pass($stored); }
	return str_replace("\n", '<br />', $stored);
}
function sx_language($name)
{
	global $lang; $lang = array(); require $GLOBALS['sx_app'].'language/lang_'.$name.'/lang_main.php';
	sx_check(isset($lang['Shoutbox_invalid_text'],$lang['Shoutbox_format_failed']), 'Both localized feedback messages');
}
error_reporting(E_ALL); set_error_handler(function ($severity,$message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
