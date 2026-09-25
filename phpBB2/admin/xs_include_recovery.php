<?php
if (!defined('IN_PHPBB') || !defined('IN_XS')) { die('Hacking attempt'); }
require_once dirname(__DIR__) . '/includes/functions_style_import_recovery.php';

if (!function_exists('xs_import_recovery_html')) {
function xs_import_recovery_html($jobs)
{
	global $lang, $userdata, $phpEx;
	if (!$jobs) { return ''; }
	$html = '<h2>' . $lang['xs_recovery_title'] . '</h2><p>' . $lang['xs_recovery_explain'] . '</p>';
	$html .= '<table class="forumline" width="100%" cellpadding="4" cellspacing="1"><tr><th>' . $lang['xs_template'] . '</th><th>' . $lang['xs_recovery_status'] . '</th><th>' . $lang['xs_options'] . '</th></tr>';
	foreach ($jobs as $job)
	{
		$html .= '<tr><td class="row1">' . htmlspecialchars($job['t'], ENT_QUOTES, 'UTF-8') . '</td><td class="row2">' . $lang['xs_recovery_' . $job['s']] . '</td><td class="row1">';
		if ($job['s'] === 'prepared')
		{
			foreach (array('resume','rollback') as $action)
			{
				$html .= '<form action="' . htmlspecialchars(append_sid('xs_import.' . $phpEx), ENT_QUOTES, 'UTF-8') . '" method="post" style="display:inline">';
				foreach (array('sid'=>$userdata['session_id'], 'recovery_action'=>$action, 'recovery_template'=>$job['t'], 'recovery_operation'=>$job['o']) as $key=>$value)
				{ $html .= '<input type="hidden" name="' . $key . '" value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '" />'; }
				$html .= '<input type="submit" class="mainoption" value="' . $lang['xs_recovery_' . $action] . '" /></form> ';
			}
		}
		else { $html .= $lang['xs_recovery_final']; }
		$html .= '</td></tr>';
	}
	return $html . '</table><br />';
}
}

// Existing recovery is selected only by current database receipts, not by
// filesystem paths or FTP values supplied in a URL. Preflight before the FTP
// form can save settings/connect, then the worker rechecks everything again.
if (isset($HTTP_POST_VARS['recovery_action']) || isset($HTTP_GET_VARS['recovery_action']))
{
	if (defined('DEMO_MODE')) { xs_error($lang['xs_permission_denied']); }
	phpbb_admin_require_post_session();
	try
	{
		list($recovery_action, $recovery_template, $recovery_operation) = phpbb_style_import_recovery_request($HTTP_POST_VARS);
		if ($recovery_action === 'start') { phpbb_acl_error('xs_import_failed'); }
		phpbb_style_import_recovery_jobs($db, $HTTP_POST_VARS);
	}
	catch (Exception $error) { xs_error($lang['xs_recovery_failed']); }
	catch (Error $error) { xs_error($lang['xs_recovery_failed']); }
	$params = array('recovery_action'=>$recovery_action, 'recovery_template'=>$recovery_template, 'recovery_operation'=>$recovery_operation);
	if (!get_ftp_config(append_sid('xs_import.' . $phpEx), $params, true)) { xs_exit(); }
	xs_ftp_connect(append_sid('xs_import.' . $phpEx), $params, true);
	try
	{
		list($publisher, $recovery_base, $recovery_identity) = phpbb_style_import_recovery_environment($phpbb_root_path, $ftp === XS_FTP_LOCAL, '../templates/', $ftp, $board_config);
		$recovered = phpbb_style_import_recovery($db, $HTTP_POST_VARS, $publisher, $recovery_base, $recovery_identity);
	}
	catch (Exception $error) { xs_error($lang['xs_recovery_failed'] . '<br /><br />' . $lang['xs_import_back']); }
	catch (Error $error) { xs_error($lang['xs_recovery_failed'] . '<br /><br />' . $lang['xs_import_back']); }
	xs_message($lang['Information'], $lang['xs_recovery_' . $recovered['state']] . '<br /><br />' . $lang['xs_import_back']);
}

try { $recovery_jobs = phpbb_style_import_recovery_jobs($db); }
catch (Exception $error) { xs_error($lang['xs_recovery_failed']); }
catch (Error $error) { xs_error($lang['xs_recovery_failed']); }
$template->assign_vars(array('IMPORT_RECOVERY'=>xs_import_recovery_html($recovery_jobs)));
