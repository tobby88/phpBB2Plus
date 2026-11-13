<?php

define('IN_PHPBB', true);

$phpbb_root_path = './';
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.' . $phpEx);

$userdata = session_pagestart($user_ip, PAGE_INDEX);
init_userprefs($userdata);
require_once $phpbb_root_path . 'ctracker/classes/class_contact_rate_storage.php';

function kontakt_claim_rate_limit($cache_dir, $client_key, $interval)
{
	return ctracker_rate_limit_contact($cache_dir, $client_key, $interval);
}

$page_title = $lang['Kontakt'];
include($phpbb_root_path . 'includes/page_header.' . $phpEx);

$false = '';
$true = '';
$sid = isset($_POST['sid']) && is_string($_POST['sid']) ? $_POST['sid'] : '';
$honeypot = isset($_POST['website']) && is_string($_POST['website']) ? trim($_POST['website']) : '';
$name = isset($_POST['name']) && is_string($_POST['name']) ? trim(stripslashes($_POST['name'])) : '';
$mail = isset($_POST['mail']) && is_string($_POST['mail']) ? trim(stripslashes($_POST['mail'])) : '';
$subject = isset($_POST['betreff']) && is_string($_POST['betreff']) ? trim(stripslashes($_POST['betreff'])) : '';
$body = isset($_POST['textfeld']) && is_string($_POST['textfeld']) ? trim(stripslashes($_POST['textfeld'])) : '';

$name = trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $name));
$subject = trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $subject));
$mail = preg_replace('/[\r\n\x00]+/', '', $mail);

$email_to = isset($plus_config['contact_email']) ? trim((string) $plus_config['contact_email']) : '';
if (!filter_var($email_to, FILTER_VALIDATE_EMAIL))
{
	$email_to = trim((string) $board_config['board_email']);
}

$valid = isset($_SERVER['REQUEST_METHOD']) && strtoupper((string) $_SERVER['REQUEST_METHOD']) === 'POST'
	&& $sid !== ''
	&& hash_equals((string) $userdata['session_id'], $sid)
	&& $honeypot === ''
	&& (!isset($_POST['website']) || is_string($_POST['website']))
	&& $name !== '' && strlen($name) <= 100
	&& filter_var($mail, FILTER_VALIDATE_EMAIL)
	&& $subject !== '' && strlen($subject) <= 150
	&& $body !== '' && strlen($body) <= 10000
	&& filter_var($email_to, FILTER_VALIDATE_EMAIL);
$rate_limited = false;

if ($valid)
{
	$rate_interval = max(60, intval($board_config['flood_interval']));
	$rate_limited = !kontakt_claim_rate_limit($phpbb_root_path . 'cache', $user_ip, $rate_interval);
	$valid = !$rate_limited;
}

if ($valid)
{
	include($phpbb_root_path . 'includes/emailer.' . $phpEx);
	$emailer = new emailer($board_config['smtp_delivery']);
	$emailer->email_address($email_to);
	$emailer->replyto($mail);
	$emailer->set_subject($subject);
	// Insert user content once. It is literal text, not a template or headers.
	$emailer->msg = "Subject: {CONTACT_SUBJECT}\nCharset: UTF-8\n\nName: {CONTACT_NAME}\nEmail: {CONTACT_EMAIL}\nIP: {CONTACT_IP}\n\n{CONTACT_BODY}";
	$emailer->assign_vars(array('CONTACT_SUBJECT' => $subject, 'CONTACT_NAME' => $name,
		'CONTACT_EMAIL' => $mail, 'CONTACT_IP' => decode_ip($user_ip), 'CONTACT_BODY' => $body));
	$emailer->send();
	$emailer->reset();
	$true = $lang['kontakt9'];
}
else
{
	$false = $rate_limited ? $lang['Flood_email_limit'] : $lang['kontakt8'];
}

$template->assign_vars(array(
	'false' => htmlspecialchars($false, ENT_QUOTES, 'UTF-8'),
	'true' => htmlspecialchars($true, ENT_QUOTES, 'UTF-8')
));

$template->set_filenames(array('body' => 'kontakt_post.tpl'));
$template->pparse('body');

include($phpbb_root_path . 'includes/page_tail.' . $phpEx);

?>
