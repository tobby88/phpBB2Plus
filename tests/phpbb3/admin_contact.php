<?php
// This fixture changes two settings only inside an isolated phpBB3 test database.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$store = $phpbb_container->get('config_text');
$key = 'test_plus_contact_form';
switch ($argv[2])
{
	case 'open':
		require_once __DIR__ . '/../../phpBB3/install/convertors/plus_native.php';
		$sender = 'founder@example.invalid';
		$contact = 'helpdesk@example.invalid';
		$board = 'old-board@example.invalid';
		$chosen = 'chosen@example.invalid';
		if ($config['board_contact'] !== $contact || $config['board_email'] !== $sender ||
			plus_contact_recipient($sender, $sender, $sender, 'webmaster@yourdomain', $board) !== $board ||
			plus_contact_recipient($chosen, $sender, $sender, $contact, $board) !== $chosen ||
			plus_contact_recipient($chosen, $chosen, $sender, $contact, $board) !== $chosen ||
			plus_contact_recipient($contact, $sender, $sender, $contact, $board) !== $contact ||
			plus_contact_recipient($sender, $sender, $sender, '', 'youraddress@yourdomain.com') !== $sender ||
			plus_contact_recipient('contact@yourdomain.tld', $sender, $sender, '', '') !== $sender ||
			plus_contact_recipient('', '', '', 'webmaster@yourdomain', '') !== '')
		{
			throw new RuntimeException('The native contact recipient or the independent envelope sender changed');
		}
		if ($store->get($key) !== null) { throw new RuntimeException('Contact form check already in progress'); }
		$store->set($key, json_encode(array($config['email_enable'], $config['contact_admin_form_enable'])));
		$config->set('email_enable', 1);
		$config->set('contact_admin_form_enable', 1);
		break;
	case 'contact_off':
		$config->set('contact_admin_form_enable', 0);
		break;
	case 'mail_off':
		$config->set('contact_admin_form_enable', 1);
		$config->set('email_enable', 0);
		break;
	case 'restore':
		$saved = json_decode((string) $store->get($key), true);
		if (!is_array($saved) || count($saved) !== 2) { throw new RuntimeException('Missing contact form settings'); }
		$config->set('email_enable', $saved[0]);
		$config->set('contact_admin_form_enable', $saved[1]);
		$store->delete($key);
		break;
	default: exit(2);
}
