<?php

define('IN_PHPBB', true);

$phpbb_root_path = './';
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.' . $phpEx);

$userdata = session_pagestart($user_ip, PAGE_INDEX);
init_userprefs($userdata);

function kontakt_claim_rate_limit($cache_dir, $client_key, $interval)
{
	$cache_dir = rtrim((string) $cache_dir, '/\\') . '/';
	if (!is_dir($cache_dir))
	{
		return false;
	}

	$now = time();
	if ($handle = @opendir($cache_dir))
	{
		while (($entry = readdir($handle)) !== false)
		{
			if (preg_match('/^contact-[a-f0-9]{64}\.rate$/', $entry)
				&& is_file($cache_dir . $entry) && @filemtime($cache_dir . $entry) < $now - 2592000)
			{
				@unlink($cache_dir . $entry);
			}
		}
		closedir($handle);
	}

	$rate_file = $cache_dir . 'contact-' . hash('sha256', (string) $client_key) . '.rate';
	$rate_handle = @fopen($rate_file, 'c+');
	if (!$rate_handle || !@flock($rate_handle, LOCK_EX))
	{
		if ($rate_handle)
		{
			@fclose($rate_handle);
		}
		return false;
	}

	$stored = @stream_get_contents($rate_handle);
	$previous = is_string($stored) ? trim($stored) : '';
	$allowed = is_string($stored) && (!ctype_digit($previous) || intval($previous) <= $now - max(1, intval($interval)));
	if ($allowed)
	{
		$stamp = (string) $now;
		$allowed = @rewind($rate_handle) && @ftruncate($rate_handle, 0)
			&& @fwrite($rate_handle, $stamp) === strlen($stamp) && @fflush($rate_handle)
			&& @rewind($rate_handle) && @stream_get_contents($rate_handle) === $stamp;
		if ($allowed) { @chmod($rate_file, 0660); }
	}
	@flock($rate_handle, LOCK_UN);
	@fclose($rate_handle);
	return $allowed;
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
