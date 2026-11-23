<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

class PhpbbOptionalPmFailure extends RuntimeException {}

// The caller owns the mailbox writer lock and transaction. A notification
// fits in spare capacity or is skipped. It never displaces existing mail.
trait PhpbbOptionalPm
{
	public function insert_optional_pm($sender, $recipient, $limit, $subject, $text, $ip, $uid = '', $smilies = false)
	{
		if ((!is_string($limit) && !is_int($limit)) || !preg_match('/^[0-9]{1,10}$/D', (string) $limit) ||
			!is_int($sender) || ($sender <= 0 && $sender !== -1) || !is_int($recipient) || $recipient <= 0 ||
			!is_string($subject) || $subject === '' || preg_match('//u', $subject) !== 1 ||
			preg_match_all('/./us', $subject) > 255 || !is_string($text) || $text === '' ||
			strlen($text) > 65535 || preg_match('//u', $text) !== 1 ||
			!is_string($ip) || !preg_match('/^[0-9a-f]{8}$/iD', $ip) ||
			!is_string($uid) || !preg_match('/^(?:[0-9a-z]{1,10})?$/iD', $uid)) {
			throw new PhpbbOptionalPmFailure('Invalid automatic message');
		}

		$destination = 'privmsgs_to_userid=' . $recipient;
		$counts = $this->rows('SELECT COUNT(*) AS items FROM ' . PRIVMSGS_TABLE . ' WHERE ' . $destination .
			' AND privmsgs_type IN (' . PRIVMSGS_NEW_MAIL . ',' . PRIVMSGS_READ_MAIL . ',' . PRIVMSGS_UNREAD_MAIL . ') FOR UPDATE');
		if (count($counts) !== 1) { throw new PhpbbOptionalPmFailure('Inbox count unavailable'); }
		if ((int) $counts[0]['items'] >= max(1, (int) $limit)) { return false; }

		$now = time();
		$bbcode = $uid === '' ? 0 : 1;
		$smilies = $smilies ? 1 : 0;
		$this->query('INSERT INTO ' . PRIVMSGS_TABLE .
			' (privmsgs_type,privmsgs_subject,privmsgs_from_userid,privmsgs_to_userid,privmsgs_date,privmsgs_ip,privmsgs_enable_html,privmsgs_enable_bbcode,privmsgs_enable_smilies,privmsgs_attach_sig) VALUES (' .
			PRIVMSGS_NEW_MAIL . ",CONVERT(X'" . bin2hex($subject) . "' USING utf8mb4)," . $sender . ',' . $recipient . ',' .
			$now . ",'" . $ip . "',0," . $bbcode . ',' . $smilies . ',0)');
		$message = $this->connection->sql_nextid();
		if (!preg_match('/^[1-9][0-9]{0,9}$/D', (string) $message)) { throw new PhpbbOptionalPmFailure('Missing message ID'); }
		$this->query('INSERT INTO ' . PRIVMSGS_TEXT_TABLE . ' (privmsgs_text_id,privmsgs_bbcode_uid,privmsgs_text) VALUES (' .
			$message . ",'" . $uid . "',CONVERT(X'" . bin2hex($text) . "' USING utf8mb4))");

		$visible = ' FROM ' . PRIVMSGS_TABLE . ' WHERE ' . $destination . ' AND privmsgs_write_payload IS NULL AND privmsgs_type=';
		$new = '(SELECT COUNT(*)' . $visible . PRIVMSGS_NEW_MAIL . ')';
		$unread = '(SELECT COUNT(*)' . $visible . PRIVMSGS_UNREAD_MAIL . ')';
		$this->query('UPDATE ' . USERS_TABLE . ' SET user_new_privmsg=' . $new . ',user_unread_privmsg=' . $unread .
			',user_last_privmsg=CASE WHEN user_last_privmsg IS NULL OR user_last_privmsg<' . $now . ' THEN ' . $now .
			' ELSE user_last_privmsg END WHERE user_id=' . $recipient);

		$rows = $this->rows('SELECT p.privmsgs_from_userid,p.privmsgs_to_userid,p.privmsgs_type,p.privmsgs_subject,p.privmsgs_date,p.privmsgs_ip,' .
			'p.privmsgs_enable_html,p.privmsgs_enable_bbcode,p.privmsgs_enable_smilies,p.privmsgs_attach_sig,p.privmsgs_write_payload,t.privmsgs_bbcode_uid,t.privmsgs_text' .
			' FROM ' . PRIVMSGS_TABLE . ' p JOIN ' . PRIVMSGS_TEXT_TABLE . ' t ON t.privmsgs_text_id=p.privmsgs_id' .
			' WHERE p.privmsgs_id=' . $message . ' LOCK IN SHARE MODE');
		if (count($rows) !== 1) { throw new PhpbbOptionalPmFailure('Incomplete automatic message'); }
		$row = $rows[0];
		if ((int) $row['privmsgs_from_userid'] !== $sender || (int) $row['privmsgs_to_userid'] !== $recipient ||
			(int) $row['privmsgs_type'] !== (int) PRIVMSGS_NEW_MAIL || $row['privmsgs_subject'] !== $subject ||
			(int) $row['privmsgs_date'] !== $now || $row['privmsgs_ip'] !== $ip || $row['privmsgs_text'] !== $text ||
			$row['privmsgs_bbcode_uid'] !== $uid || $row['privmsgs_write_payload'] !== null ||
			(int) $row['privmsgs_enable_html'] !== 0 || (int) $row['privmsgs_enable_bbcode'] !== $bbcode ||
			(int) $row['privmsgs_enable_smilies'] !== $smilies || (int) $row['privmsgs_attach_sig'] !== 0) {
			throw new PhpbbOptionalPmFailure('Stored message differs');
		}
		$counters = $this->rows('SELECT u.user_new_privmsg,u.user_unread_privmsg,u.user_last_privmsg,' . $new . ' AS expected_new,' .
			$unread . ' AS expected_unread FROM ' . USERS_TABLE . ' u WHERE u.user_id=' . $recipient . ' LOCK IN SHARE MODE');
		if (count($counters) !== 1 || (int) $counters[0]['user_new_privmsg'] !== (int) $counters[0]['expected_new'] ||
			(int) $counters[0]['user_unread_privmsg'] !== (int) $counters[0]['expected_unread'] ||
			(int) $counters[0]['user_last_privmsg'] < $now) {
			throw new PhpbbOptionalPmFailure('Stored inbox counts differ');
		}
		return true;
	}
}
