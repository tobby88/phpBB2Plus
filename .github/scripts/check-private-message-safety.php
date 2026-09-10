<?php

function private_message_test_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Private message test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$privmsg = file_get_contents($root . '/phpBB2/privmsg.php');

private_message_test_assert(strpos($privmsg, 'function privmsg_post_session_is_valid') !== false, 'PM writes must share one session guard');
private_message_test_assert(strpos($privmsg, "\$request_method === 'POST'") !== false, 'the PM session guard must require POST');
private_message_test_assert(substr_count($privmsg, 'privmsg_post_session_is_valid(') >= 4, 'delete, save and send/edit writes must use the shared guard');
private_message_test_assert(strpos($privmsg, "AND privmsgs_type IN (' . PRIVMSGS_NEW_MAIL") !== false, 'edit authorization must be limited to undelivered messages');
private_message_test_assert(strpos($privmsg, '$to_username_sql = $db->sql_escape(stripslashes($to_username))') !== false, 'recipient lookup must use database-driver escaping');
private_message_test_assert(strpos($privmsg, 'phpbb_pm_write_message($pm_write_nonce,') !== false, 'SEND/EDIT must use the durable owning writer');
private_message_test_assert(strpos($privmsg, 'INSERT INTO ' . '" . PRIVMSGS_TABLE') === false, 'Controller must not bypass durable header publication');
$writer = file_get_contents($root . '/phpBB2/includes/functions_pm_write.php');
private_message_test_assert(strpos($writer, "\$body = \$db->sql_escape(\$content['text'])") !== false, 'PM bodies use owning database-driver escaping');
private_message_test_assert(strpos($writer, "\$db->sql_escape(\$content[\$field])") !== false, 'PM header strings use owning database-driver escaping');
private_message_test_assert(strpos($writer, "\$key = phpbb_pm_write_guard(\$db, \$row)") !== false, 'Body and header publication share the accepted intent guard');
private_message_test_assert(strpos($privmsg, 'duplicate_attachment_pm(') === false, 'controller must not duplicate attachments outside the durable read worker');
$read_position = strpos($privmsg, 'try { phpbb_pm_read_message(');
$fetch_position = strpos($privmsg, 'SELECT u.username AS username_1');
private_message_test_assert($read_position !== false && $fetch_position > $read_position, 'displayed body must be fetched after the durable read transition');
private_message_test_assert(strpos($privmsg, 'phpbb_pm_resume_reads(') !== false, 'mailbox requests must resume accepted read intents');

echo "Private message safety tests passed.\n";
