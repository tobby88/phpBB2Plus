<?php
// Called only by the isolated legacy_database.php fixture.
function seed_native_content($db)
{
	$db->query("INSERT INTO phpbb_topics_watch (topic_id,user_id,notify_status)
		VALUES (1,1,0),(1,1,1),(2,1,0),(999,1,0),(1,-1,0),(1,99,0)");
	$db->query("UPDATE phpbb_topics SET topic_vote=1 WHERE topic_id=1");
	$db->query("INSERT INTO phpbb_vote_desc (vote_id,topic_id,vote_text,vote_start,vote_length)
		VALUES (21,1,'Welche Jahreszeit?',1000000000,0)");
	$db->query("INSERT INTO phpbb_vote_results (vote_id,vote_option_id,vote_option_text,vote_result)
		VALUES (21,1,'Frühling',2),(21,2,'Herbst',1)");
	$db->query("INSERT INTO phpbb_vote_voters (vote_id,vote_user_id,vote_user_ip) VALUES (21,1,'c0000204'),(21,88,'c0000205')");
	$db->query("INSERT INTO phpbb_forums (forum_id,forum_name,forum_desc,cat_id,forum_order,auth_view,auth_read,auth_vote,auth_pollcreate)
		VALUES (14,'Ballots open to guests','',1,28,0,0,0,0)");
	foreach (array(601,602) as $id)
	{
		$db->query("INSERT INTO phpbb_topics (topic_id,forum_id,topic_title,topic_poster,topic_time,topic_first_post_id,topic_last_post_id,topic_vote)
			VALUES ($id,14,'Ballot $id',2,1000000000,$id,$id,1)");
		$db->query("INSERT INTO phpbb_posts (post_id,topic_id,forum_id,poster_id,poster_ip,post_time) VALUES ($id,$id,14,2,'c0000203',1000000000)");
		$db->query("INSERT INTO phpbb_posts_text (post_id,post_subject,post_text) VALUES ($id,'Ballot $id','A public poll')");
		$db->query("INSERT INTO phpbb_vote_desc (vote_id,topic_id,vote_text,vote_start,vote_length) VALUES ($id,$id,'Ballot $id',1000000000,0)");
		$db->query("INSERT INTO phpbb_vote_results (vote_id,vote_option_id,vote_option_text,vote_result) VALUES ($id,1,'Rot',1),($id,2,'Blau',0)");
	}
	$db->query("INSERT INTO phpbb_vote_voters (vote_id,vote_user_id,vote_user_ip) VALUES (601,-1,'7f000001')");
	$db->query("UPDATE phpbb_posts_text SET post_text=CONCAT(post_text, ' Archivsuchprobe') WHERE post_id IN (32,40)");
	$db->query('UPDATE phpbb_users SET user_new_privmsg=9, user_unread_privmsg=12 WHERE user_id=1');
	$pm = $db->prepare('INSERT INTO phpbb_privmsgs (privmsgs_id,privmsgs_type,privmsgs_subject,privmsgs_from_userid,
		privmsgs_to_userid,privmsgs_date,privmsgs_ip) VALUES (?,?,?,2,1,1000010000,?)');
	$text = $db->prepare('INSERT INTO phpbb_privmsgs_text (privmsgs_text_id,privmsgs_text,privmsgs_bbcode_uid) VALUES (?,?,?)');
	$ip = 'c0000205';
	$uid = 'privtest';
	for ($type = 0; $type <= 5; $type++)
	{
		$id = 110 + $type;
		$subject = 'Postfachprobe ' . $id;
		$body = 'Vertraulich ' . $id . ' [b:privtest]Grüße[/b:privtest]';
		$pm->bind_param('iiss', $id, $type, $subject, $ip);
		$pm->execute();
		$text->bind_param('iss', $id, $body, $uid);
		$text->execute();
	}
}

function verify_native_content($db, $recipient)
{
	$watches = $db->query("SELECT topic_id,notify_status FROM p3_topics_watch WHERE user_id=$recipient ORDER BY topic_id")->fetch_all();
	check_fixture(count($watches) === 2 && (int) $watches[0][0] === 1 && (int) $watches[0][1] === 1
		&& (int) $watches[1][0] === 2 && (int) $watches[1][1] === 0, 'Duplicate, orphan or incorrect topic subscription');
	check_fixture((int) $db->query('SELECT COUNT(*) FROM p3_topics_watch')->fetch_row()[0] === 2, 'Subscription owner missing');

	$poll = $db->query('SELECT poll_title,poll_start FROM p3_topics WHERE topic_id=1')->fetch_assoc();
	$options = $db->query('SELECT poll_option_text,poll_option_total FROM p3_poll_options WHERE topic_id=1 ORDER BY poll_option_id')->fetch_all();
	$voter = $db->query('SELECT poll_option_id,vote_user_id,vote_user_ip FROM p3_poll_votes WHERE topic_id=1')->fetch_assoc();
	check_fixture((int) $db->query('SELECT COUNT(*) FROM p3_poll_votes WHERE topic_id=1')->fetch_row()[0] === 1,
		'A retired account receipt was attached to a different native member');
	check_fixture($poll['poll_title'] === 'Welche Jahreszeit?' && (int) $poll['poll_start'] === 1000000000
		&& count($options) === 2 && $options[0][0] === 'Frühling' && (int) $options[0][1] === 2
		&& $options[1][0] === 'Herbst' && (int) $options[1][1] === 1
		&& (int) $voter['vote_user_id'] === $recipient && (int) $voter['poll_option_id'] === 127
		&& $voter['vote_user_ip'] === '192.0.2.4', 'Poll totals, converted voter or IP did not survive');

	$rows = $db->query('SELECT msg_id,user_id,folder_id,pm_new,pm_unread FROM p3_privmsgs_to ORDER BY msg_id,user_id');
	$boxes = array();
	while ($row = $rows->fetch_assoc()) { $boxes[(int) $row['user_id']][(int) $row['msg_id']] = array_map('intval', array_slice($row, 2)); }
	check_fixture(count($boxes[$recipient]) === 4 && count($boxes[2]) === 4, 'Incorrect PM ownership');
	$expected = array(110 => array(0,0,0), 111 => array(-3,1,1), 115 => array(0,0,1));
	foreach ($expected as $id => $flags)
	{
		check_fixture(array_values($boxes[$recipient][$id]) === $flags, 'Incorrect recipient PM state for ' . $id);
	}
	check_fixture($boxes[2][111]['folder_id'] === -2 && $boxes[2][112]['folder_id'] === -1 && $boxes[2][115]['folder_id'] === -2,
		'Sent or outbox PM lost');
	foreach (array($recipient => 113, 2 => 114) as $owner => $id)
	{
		$folder = $db->query("SELECT folder_id,pm_count FROM p3_privmsgs_folder WHERE user_id=$owner")->fetch_assoc();
		check_fixture((int) $folder['folder_id'] === $boxes[$owner][$id]['folder_id'] && (int) $folder['pm_count'] === 1, 'Saved folder ownership or count');
	}
	$counts = $db->query("SELECT user_new_privmsg,user_unread_privmsg FROM p3_users WHERE user_id=$recipient")->fetch_row();
	check_fixture((int) $counts[0] === 1 && (int) $counts[1] === 2, 'Incoming PM counts stale');
	$body = $db->query("SELECT message_text,to_address FROM p3_privmsgs WHERE msg_id=111")->fetch_assoc();
	check_fixture(strpos($body['message_text'], '<B>') !== false && strpos($body['message_text'], 'Grüße') !== false
		&& $body['to_address'] === 'u_' . $recipient,
		'PM recipient reference or BBCode lost');
	$hits = $db->query("SELECT m.post_id FROM p3_search_wordmatch m JOIN p3_search_wordlist w ON w.word_id=m.word_id
		WHERE w.word_text='archivsuchprobe' ORDER BY m.post_id")->fetch_all();
	check_fixture(count($hits) === 2 && (int) $hits[0][0] === 32 && (int) $hits[1][0] === 40, 'Native post search index not built during conversion');
}
