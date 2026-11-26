<?php
use phpbb2plus\community\service\legacy_login;

require_once __DIR__ . '/ext/phpbb2plus/community/service/legacy_login.php';
header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: no-referrer');
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, array('GET', 'HEAD', 'POST'), true)) { header('Allow: GET, HEAD, POST'); http_response_code(405); return; }

// Remove the old session, form fields and credentials before the native session
// code sees the URL. A 303 discards an obsolete password submission's body.
$fields = $method === 'POST' ? $_POST + $_GET : $_GET;
$logout = isset($fields['logout']) || ($fields['go'] ?? '') === 'logout';
$target = legacy_login::target($fields['redirect'] ?? $fields['back'] ?? '', $fields, $_SERVER['SCRIPT_NAME']);
$canonical = array('go' => $logout ? 'logout' : 'login', 'back' => $target);
if ($method === 'POST' || $_GET !== $canonical || ($_SERVER['QUERY_STRING'] ?? '') !== http_build_query($canonical, '', '&', PHP_QUERY_RFC3986))
{
	header('Location: ./login.php?' . http_build_query($canonical, '', '&', PHP_QUERY_RFC3986), true, $method === 'POST' ? 303 : 302);
	return;
}

define('IN_PHPBB', true);
define('IN_LOGIN', true);
$phpbb_root_path = './';
$phpEx = 'php';
require __DIR__ . '/common.php';
$user->session_begin(false);
$auth->acl($user->data);
$user->setup('ucp');
if (!$logout && !$user->data['is_registered'])
{
	redirect(append_sid('./ucp.php', http_build_query(array('mode' => 'login', 'redirect' => $target), '', '&', PHP_QUERY_RFC3986), false));
}
if (!$logout || !$user->data['is_registered']) { redirect('./' . $target); }

// Only the confirmed form carries the currently authenticated phpBB3 session.
$language->add_lang('legacy_login', 'phpbb2plus/community');
$template->assign_vars(array('U_PLUS_LOGOUT_ACTION' => './ucp.php?mode=logout', 'U_PLUS_LOGOUT_CANCEL' => './' . $target,
	'PLUS_LOGOUT_SID' => $user->session_id));
page_header($language->lang('LOGOUT'));
header('Cache-Control: private, no-store, max-age=0');
$template->set_filenames(array('body' => '@phpbb2plus_community/legacy_logout.html'));
page_footer();
