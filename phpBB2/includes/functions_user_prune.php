<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_user_removal.php';

function phpbb_prune_days($value)
{
	$days = phpbb_removal_id($value);
	if ($days > 36500) { phpbb_removal_error('Removal_invalid'); }
	return $days;
}
function phpbb_prune_validate_policy($policy)
{
	if (!is_array($policy) || array_keys($policy) !== array('mode','days','at') || !in_array($policy['mode'], array('user_id','prune_0','prune_1','prune_2','prune_3','prune_4'), true)
		|| !is_int($policy['at']) || $policy['at'] <= 0 || $policy['at'] > time()) { phpbb_removal_error('Removal_invalid'); }
	$policy['days'] = phpbb_prune_days($policy['days']);
	return $policy;
}
function phpbb_prune_policy($request)
{
	if (!is_array($request) || !isset($request['mode']) || !is_string($request['mode'])) { phpbb_removal_error('Removal_invalid'); }
	$mode = $request['mode'];
	if ($mode === 'zero_poster') { $mode = 'prune_0'; }
	if ($mode === 'not_login') { $mode = 'prune_1'; }
	$days = isset($request['days']) ? $request['days'] : ($mode === 'user_id' ? 1 : null);
	return phpbb_prune_validate_policy(array('mode'=>$mode,'days'=>$days,'at'=>time()));
}
function phpbb_prune_where($policy)
{
	$policy = phpbb_prune_validate_policy($policy);
	$where = 'user_id > 0 AND user_level <> ' . ADMIN;
	if ($policy['mode'] === 'user_id') { return $where; }
	$where .= ' AND user_regdate < ' . ($policy['at'] - 86400 * $policy['days']);
	switch ($policy['mode'])
	{
		case 'prune_0': return $where . ' AND user_posts = 0';
		case 'prune_1': return $where . ' AND user_lastvisit = 0';
		case 'prune_2': return $where . " AND user_lastvisit = 0 AND user_active = 0 AND user_actkey <> ''";
		case 'prune_3': return $where . ' AND user_lastvisit < ' . ($policy['at'] - 86400 * 60);
		case 'prune_4': return $where . ' AND user_lastvisit > user_regdate AND user_posts * 864000 < user_lastvisit - user_regdate';
	}
	phpbb_removal_error('Removal_invalid');
}
function phpbb_prune_user_remove($database, $post, $id = null, $policy = null)
{
	if (!is_array($post) || isset($post['delete'])) { phpbb_removal_error('Removal_invalid'); }
	if ($id !== null)
	{
		if (!isset($post['confirm']) || !is_scalar($post['confirm']) || isset($post['removal_resume']) || isset($post['removal_cancel'])) { phpbb_removal_error('Removal_invalid'); }
		$post['delete'] = phpbb_removal_id($id);
		$policy = phpbb_prune_validate_policy($policy);
	}
	elseif (isset($post['confirm']) || isset($post['mode']) || isset($post['days']) || isset($post['del_user'])) { phpbb_removal_error('Removal_invalid'); }
	return phpbb_user_remove($database, $post, 'prune', $policy);
}
function phpbb_prune_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function phpbb_prune_pending_html($database)
{
	global $lang, $userdata, $phpbb_root_path, $phpEx;
	$db = new PhpbbRemovalDatabase($database); phpbb_removal_actor($db, 'prune');
	$rows = phpbb_removal_rows($db, 'SELECT j.job_id,j.user_id,j.username,j.removal_state,u.user_id AS existing_user FROM ' . USER_REMOVALS_TABLE . ' j LEFT JOIN ' . USERS_TABLE . " u ON u.user_id = j.user_id WHERE j.removal_mode = 'prune' ORDER BY j.created_at,j.job_id LIMIT 100");
	if (!$rows) { return ''; }
	$html = '<h2>' . phpbb_prune_html($lang['Removal_pending_title']) . '</h2><p>' . phpbb_prune_html($lang['Removal_pending_explain']) . '</p>';
	foreach ($rows as $row)
	{
		if (!preg_match('/^[a-f0-9]{32}$/D', $row['job_id'])) { continue; }
		$state = 'Removal_state_' . $row['removal_state'];
		$html .= '<form method="post" action="' . phpbb_prune_html(append_sid($phpbb_root_path . 'delete_users.' . $phpEx)) . '"><p><strong>' . phpbb_prune_html($row['username']) . ' (#' . (int) $row['user_id'] . ')</strong> — ' . phpbb_prune_html(isset($lang[$state]) ? $lang[$state] : $row['removal_state']);
		$html .= ' <button type="submit" class="liteoption" name="removal_resume" value="' . $row['job_id'] . '">' . phpbb_prune_html($lang['Removal_resume']) . '</button>';
		if ($row['existing_user'] !== null || $row['removal_state'] === 'prepared') { $html .= ' <button type="submit" class="liteoption" name="removal_cancel" value="' . $row['job_id'] . '">' . phpbb_prune_html($lang['Removal_cancel']) . '</button>'; }
		$html .= '<input type="hidden" name="sid" value="' . phpbb_prune_html($userdata['session_id']) . '" /></p></form>';
	}
	return $html;
}
function phpbb_prune_notify($result)
{
	global $board_config, $userdata, $phpbb_root_path, $phpEx;
	if (!is_array($result) || $result['email'] === '') { return true; }
	// Never use persisted language text as a path without validating its shape.
	$language = preg_match('/^[a-z][a-z0-9_]*$/D', $result['language']) ? $result['language'] : '';
	if ($language !== '' && !is_file($phpbb_root_path . 'language/lang_' . $language . '/email/delete_users.tpl')) { $language = ''; }
	try
	{
		$emailer = new emailer($board_config['smtp_delivery'], true);
		$emailer->from($board_config['board_email']); $emailer->replyto($board_config['board_email']);
		$emailer->email_address($result['email']); $emailer->use_template('delete_users', $language);
		$emailer->assign_vars(array('U_REGISTER'=>phpbb_board_url('profile.' . $phpEx . '?mode=register'),'USER'=>$userdata['username'],'USERNAME'=>$result['username'],'SITENAME'=>$board_config['sitename'],'BOARD_EMAIL'=>$board_config['board_email']));
		$sent = $emailer->send(); $emailer->reset(); return $sent !== false;
	}
	catch (Exception $error) { return false; }
	catch (Error $error) { return false; }
}
