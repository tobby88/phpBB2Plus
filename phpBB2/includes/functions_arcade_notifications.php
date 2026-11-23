<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once __DIR__ . '/functions_owned_storage.php';
require_once __DIR__ . '/functions_optional_pm.php';

class PhpbbArcadeNoticeMailbox extends PhpbbOwnedStore
{
	use PhpbbOptionalPm;

	protected function storage_tables()
	{
		return array(CONFIG_TABLE, iNA, USERS_TABLE, PRIVMSGS_TABLE, PRIVMSGS_TEXT_TABLE);
	}

	public function deliver($sender, $recipient, $subject, $message, $ip)
	{
		if (!is_int($sender) || ($sender <= 0 && $sender !== -1) || !is_int($recipient) || $recipient <= 0 || $sender === $recipient) {
			return false;
		}
		$settings = array();
		foreach ($this->rows('SELECT config_name,config_value FROM ' . CONFIG_TABLE .
			" WHERE config_name IN ('privmsg_disable','max_inbox_privmsgs','allow_bbcode','allow_smilies') LOCK IN SHARE MODE") as $row) {
			$settings[$row['config_name']] = $row['config_value'];
		}
		$enabled = $this->rows('SELECT config_value FROM ' . iNA . " WHERE config_name='games_use_pms' LOCK IN SHARE MODE");
		if (count($settings) !== 4 || count($enabled) !== 1) { $this->storage_fail(); }
		if ($enabled[0]['config_value'] !== '1' || $settings['privmsg_disable'] !== '0') { return false; }

		$users = $this->rows('SELECT user_id,username,user_active,user_allow_pm,user_blocktime,games_block_pm,user_notify_pm,user_email,user_lang' .
			' FROM ' . USERS_TABLE . ' WHERE user_id IN (' . $sender . ',' . $recipient . ') ORDER BY user_id FOR UPDATE');
		$by_id = array();
		foreach ($users as $row) { $by_id[(int) $row['user_id']] = $row; }
		if (!isset($by_id[$recipient]) || empty($by_id[$recipient]['user_active']) || !empty($by_id[$recipient]['games_block_pm'])) {
			return false;
		}
		if ($sender > 0 && (!isset($by_id[$sender]) || empty($by_id[$sender]['user_active']) ||
			empty($by_id[$sender]['user_allow_pm']) || (int) $by_id[$sender]['user_blocktime'] >= time())) {
			return false;
		}

		$uid = $settings['allow_bbcode'] === '1' ? make_bbcode_uid() : '';
		$smilies = $settings['allow_smilies'] === '1';
		$text = phpbb_stored_text($message);
		if ($uid !== '') { $text = bbencode_first_pass($text, $uid); }
		if (!$this->insert_optional_pm($sender, $recipient, $settings['max_inbox_privmsgs'], $subject, $text, $ip, $uid, $smilies)) {
			return false;
		}
		$this->commit();
		return $by_id[$recipient];
	}
}
