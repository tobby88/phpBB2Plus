<?php
/***************************************************************************
 *                             album_nuffload_pbar.php
 *                            -------------------
 *   Author                : Nuffmon
 *   Email                 : nuffmon@hotmail.com
 *   Version               : 1.4.2
 *   Last Update           : 15/11/2005
 *
 ***************************************************************************/

define('IN_PHPBB', true);
$phpbb_root_path = './';
$album_root_path = $phpbb_root_path . 'album_mod/';
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.'.$phpEx);

// Start session management
$userdata = session_pagestart($user_ip, PAGE_ALBUM);
init_userprefs($userdata);
// End session management

// Get general album information
include($album_root_path . 'album_common.'.$phpEx);

function hms($sec)
{
	$thetime = str_pad(intval(intval($sec) / 3600), 2, '0', STR_PAD_LEFT) . ':'
		. str_pad(intval(($sec / 60) % 60), 2, '0', STR_PAD_LEFT) . ':'
		. str_pad(intval($sec % 60), 2, '0', STR_PAD_LEFT);
	return $thetime;
}

function upload_progress_file_size($filename)
{
	clearstatcache(true, $filename);
	return is_file($filename) ? max(0, intval(filesize($filename))) : 0;
}

// The progress endpoint is only meaningful for an active upload. Showing a
// regular forum message for direct visits avoids an empty or unstyled page.
if (!isset($_REQUEST['sessionid']))
{
	message_die(GENERAL_MESSAGE, $lang['No_upload_in_progress']);
}

$sessionid = (string) $_REQUEST['sessionid'];
if (!preg_match('/^[a-f0-9]{32}$/i', $sessionid))
{
	message_die(GENERAL_ERROR, $lang['No_upload_in_progress']);
}

$tmp_path = rtrim($album_config['path_to_bin'], '/\\') . '/tmp/';
$info_file = $tmp_path . $sessionid . '_flength';
$data_file = $tmp_path . $sessionid . '_postdata';
$received_file = $tmp_path . $sessionid . '_received';
$complete_file = $tmp_path . $sessionid . '_complete';

// Short JSON requests avoid the buffering that prevented the legacy streaming
// response from updating in current FPM/web-server combinations.
if (isset($_REQUEST['status']))
{
	header('Content-Type: application/json; charset=UTF-8');
	header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
	header('Pragma: no-cache');

	if (isset($_REQUEST['cleanup']))
	{
		@unlink($received_file);
		@unlink($complete_file);
		@unlink($info_file);
		@unlink($data_file);
		echo json_encode(array('ok' => true));
		exit;
	}

	$total_size = upload_progress_file_size($info_file) > 0 ? intval(trim(@file_get_contents($info_file))) : 0;
	$current_size = upload_progress_file_size($data_file);
	$received = is_file($received_file);
	$complete = is_file($complete_file);
	if ($total_size <= 0 && $received)
	{
		$total_size = intval(trim(@file_get_contents($received_file)));
	}

	$start_time = is_file($info_file) ? intval(filemtime($info_file)) : time();
	$elapsed = max(0, time() - $start_time);
	$speed = ($current_size > 0 && $elapsed > 0) ? ($current_size / $elapsed) : 0;
	$remaining = ($speed > 0 && $total_size > $current_size) ? (($total_size - $current_size) / $speed) : 0;
	$percent = ($total_size > 0) ? intval(floor(($current_size / $total_size) * 100)) : 0;
	$percent = max(0, min(($received || $complete) ? 100 : 99, $percent));

	$state = 'waiting';
	if ($complete)
	{
		$state = 'complete';
		$percent = 100;
		$current_size = max($current_size, $total_size);
	}
	else if ($received)
	{
		$state = 'processing';
		$percent = 100;
		$current_size = max($current_size, $total_size);
	}
	else if ($total_size > 0 || $current_size > 0)
	{
		$state = 'uploading';
	}

	echo json_encode(array(
		'state' => $state,
		'percent' => $percent,
		'current' => $current_size,
		'total' => $total_size,
		'speed_kb' => round($speed / 1024, 2),
		'elapsed' => hms($elapsed),
		'remaining' => hms($remaining),
		'done' => $complete
	));
	exit;
}

$gen_simple_header = TRUE;
$page_title = $lang['upload_in_progress'];
include($phpbb_root_path . 'includes/page_header.'.$phpEx);

$template->set_filenames(array('body' => 'album_nuffload_pbar_body.tpl'));
$template->assign_vars(array(
	'L_UPLOAD_IN_PROGRESS' => $lang['upload_in_progress'],
	'L_TIME_ELAPSED' => $lang['time_elapsed'],
	'L_TIME_REMAINING' => $lang['time_remaining'],
	'L_UPLOAD_WAITING' => $lang['upload_waiting'],
	'L_UPLOAD_WAITING_JSON' => json_encode($lang['upload_waiting']),
	'L_UPLOAD_PROCESSING_JSON' => json_encode($lang['upload_processing']),
	'L_UPLOAD_COMPLETE_JSON' => json_encode($lang['upload_complete']),
	'L_UPLOAD_STALLED_JSON' => json_encode($lang['upload_stalled']),
	'UPLOAD_SESSION_JSON' => json_encode($sessionid),
	'STATUS_URL' => "album_nuffload_pbar.$phpEx?sessionid=$sessionid&amp;status=1",
	'MAX_IDLE_POLLS' => max(60, intval($album_config['max_pause']) * 2),
	'CLOSE_ON_FINISH' => !empty($album_config['close_on_finish']) ? 'true' : 'false',
	'L_NUFFLOAD_VERSION' => 'v1.4.2'
));
$template->pparse('body');
include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
?>
