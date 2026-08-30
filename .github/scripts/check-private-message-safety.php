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
private_message_test_assert(strpos($privmsg, '$subject_sql = $db->sql_escape(stripslashes($privmsg_subject))') !== false, 'PM subjects must use database-driver escaping');
private_message_test_assert(strpos($privmsg, '$message_sql = $db->sql_escape(stripslashes($privmsg_message))') !== false, 'PM bodies must use database-driver escaping');
private_message_test_assert(strpos($privmsg, 'AND EXISTS (') !== false, 'edited message text must remain bound to an undelivered message header');
private_message_test_assert(substr_count($privmsg, 'duplicate_attachment_pm(') === 1, 'sent-copy attachments must be duplicated exactly once');
$copy_text_position = strpos($privmsg, '$copy_text = $db->sql_escape');
$attachment_copy_position = strpos($privmsg, 'duplicate_attachment_pm(');
private_message_test_assert($copy_text_position !== false && $attachment_copy_position !== false && $copy_text_position < $attachment_copy_position, 'attachments must be duplicated only after the sent copy exists');

echo "Private message safety tests passed.\n";
