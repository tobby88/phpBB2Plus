<?php
// Called only by the isolated legacy_database.php fixture.
function seed_forum_access($db)
{
	$db->query("INSERT INTO phpbb_groups (group_id,group_type,group_name,group_description,group_moderator,group_single_user) VALUES
		(77,2,'Designated leader','',89,0),(78,2,'Waiting leader','',87,0),(79,2,'Approved leader','',1,0),(80,2,'Former leader','',888888,0)");
	$db->query('INSERT INTO phpbb_user_group (group_id,user_id,user_pending) VALUES
		(77,1,1),(77,1,0),(77,1,0),(77,87,1),(77,87,1),(77,-1,0),(77,888888,0),
		(78,87,1),(79,1,1),(79,1,0),(80,888888,0)');
	// Old ID 1 is remapped to max(source IDs)+1 on this board. A retired grant can have that same number.
	$reuse = 1 + (int) $db->query('SELECT MAX(user_id) FROM phpbb_users')->fetch_row()[0];
	$db->query("INSERT INTO phpbb_forums (forum_id,forum_name,forum_desc,cat_id,forum_order,auth_view,auth_read,auth_post,auth_reply,auth_edit,auth_delete)
		VALUES (15,'Contributors','',1,35,0,0,2,2,2,2)");
	$db->query("INSERT INTO phpbb_groups (group_id,group_type,group_name,group_description,group_single_user) VALUES
		(81,1,'Personal moderation','',1),(82,1,'Personal editing','',1),(83,1,'Former assignment','',1)");
	$db->query("INSERT INTO phpbb_user_group (group_id,user_id,user_pending) VALUES
		(81,87,1),(81,89,1),(81,89,0),(82,87,0),(82,1,0),(83,888888,0),(83,$reuse,0),(83,-1,0)");
	$db->query('INSERT INTO phpbb_auth_access (group_id,forum_id,auth_edit,auth_reply,auth_mod)
		VALUES (81,15,0,0,1),(82,15,1,0,0),(83,15,0,1,1)');
	$db->query('UPDATE phpbb_auth_access SET auth_delete=1 WHERE forum_id=15 AND group_id=82');
	$db->query("INSERT INTO phpbb_forums (forum_id,forum_name,forum_desc,cat_id,forum_order,auth_view,auth_read) VALUES
		(8,'Listed reading room','',1,32,0,5),(9,'Private reading room','',1,33,2,0),(10,'Two reading circles','',1,34,2,2)");
	$db->query("INSERT INTO phpbb_groups (group_id,group_type,group_name,group_description,group_single_user)
		VALUES (76,1,'Personal reading permission','',1)");
	$db->query('INSERT INTO phpbb_user_group (group_id,user_id,user_pending) VALUES (76,89,0)');
	$db->query('INSERT INTO phpbb_auth_access (group_id,forum_id,auth_view,auth_read) VALUES
		(71,9,1,0),(77,9,1,0),(71,10,1,0),(74,10,1,0),(73,10,0,1),(76,10,0,1)');
	foreach (array(8,9,10) as $forum)
	{
		$topic = 480+$forum;
		$db->query("INSERT INTO phpbb_topics (topic_id,forum_id,topic_title,topic_poster,topic_time,topic_first_post_id,topic_last_post_id)
			VALUES ($topic,$forum,'Reading notes $forum',2,1000,$topic,$topic)");
		$db->query("INSERT INTO phpbb_posts (post_id,topic_id,forum_id,poster_id,poster_ip,post_time) VALUES ($topic,$topic,$forum,2,'c0000203',1000)");
		$db->query("INSERT INTO phpbb_posts_text (post_id,post_subject,post_text) VALUES ($topic,'Reading notes $forum','Reading circle fixture $forum')");
	}
	$db->query("UPDATE phpbb_topics SET topic_desc='Neue Sitzordnung' WHERE topic_id=490");
	$db->query("INSERT INTO phpbb_topics (topic_id,forum_id,topic_title,topic_desc,topic_poster,topic_time,topic_first_post_id,topic_last_post_id,topic_moved_id,topic_status)
		VALUES (511,1,'Verschobener Lesekreis','Frühere Sitzordnung',2,1000,490,490,490,2)");
	$db->query("UPDATE phpbb_posts_text SET post_text=CONCAT(post_text,' Kreisnotizprobe') WHERE post_id IN (32,490)");
}

function seed_native_content($db)
{
	$db->query("INSERT INTO phpbb_posts_text (post_id,post_subject,bbcode_uid,post_text) VALUES
		(143001,'Unzugeordnet 📷','orphanuid','Zeile eins\n[b:orphanuid]Größe[/b:orphanuid]'),
		(143002,NULL,'',''),(143003,'0','',NULL)");
	$db->query("INSERT INTO phpbb_privmsgs_text (privmsgs_text_id,privmsgs_text) VALUES (143001,'Private text without envelope')");
	$db->query("UPDATE phpbb_config SET config_value='0' WHERE config_name='announcement_duration'");
	$db->query("UPDATE phpbb_config SET config_value='1' WHERE config_name='announcement_prune_strategy'");
	foreach (array(701=>array(2,3),702=>array(3,0),703=>array(2,-1),704=>array(2,80000),705=>array(2,1),706=>array(1,9)) as $id=>$schedule)
	{
		list($type,$days) = $schedule;
		$start = $id === 702 ? time() : 1000000000;
		$db->query("INSERT INTO phpbb_topics (topic_id,forum_id,topic_title,topic_type,topic_announce_duration,topic_poster,topic_time,topic_first_post_id,topic_last_post_id)
			VALUES ($id,7,'Duration fixture $id',$type,$days,2,$start,$id,$id)");
		$db->query("INSERT INTO phpbb_posts (post_id,topic_id,forum_id,poster_id,poster_ip,post_time) VALUES ($id,$id,7,2,'c0000203',$start)");
		$db->query("INSERT INTO phpbb_posts_text (post_id,post_subject,post_text) VALUES ($id,'Duration fixture $id','Ein Aushang zur Probe')");
	}
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

function seed_missing_pm_accounts($db, $write_journal)
{
	$missing = (int) $db->query('SELECT MAX(user_id)+1 FROM phpbb_users')->fetch_row()[0];
	// These are source fixtures for distinct inbox, sent and saved copies.
	$rows = array(116 => array(0, 2, $missing), 117 => array(3, $missing, 2), 118 => array(2, 2, $missing),
		119 => array(4, $missing, 2), 120 => array(1, $missing, $missing), 121 => array(4, -1, 2), 122 => array(3, 2, -1),
		123 => array(1, $missing, 2), 124 => array(1, 2, $missing), 125 => array(5, $missing, 2), 126 => array(5, 2, $missing));
	$rows[109] = array(6, 2, 1); // Internal, unfinished sent-box copy.
	$rows[129] = array(7, 2, 1); // Custom source folder with no native equivalent.
	if ($write_journal) { $rows[127] = array(1, 2, 1); $rows[128] = array(3, 2, 1); }
	foreach ($rows as $id => list($type, $sender, $recipient))
	{
		$db->query("INSERT INTO phpbb_privmsgs (privmsgs_id,privmsgs_type,privmsgs_subject,privmsgs_from_userid,
			privmsgs_to_userid,privmsgs_date,privmsgs_ip) VALUES ($id,$type,'Mailbox fixture $id',$sender,$recipient,1000010000,'c0000205')");
		$db->query("INSERT INTO phpbb_privmsgs_text (privmsgs_text_id,privmsgs_text,privmsgs_bbcode_uid)
			VALUES ($id,'Fixture body $id','privtest')");
	}
	if ($write_journal)
	{
		$db->query("UPDATE phpbb_privmsgs SET privmsgs_write_payload=IF(privmsgs_id=127,'{}','') WHERE privmsgs_id IN (127,128)");
	}
	else
	{
		$db->query('ALTER TABLE phpbb_privmsgs DROP COLUMN privmsgs_write_payload');
	}
}

function seed_missing_post_authors($db)
{
	$missing = (int) $db->query('SELECT MAX(user_id)+1 FROM phpbb_users')->fetch_row()[0];
	$db->query("UPDATE phpbb_posts SET poster_id=$missing,post_author_removed=0 WHERE post_id=32");
	$db->query("UPDATE phpbb_topics SET topic_poster=$missing WHERE topic_id=1");
}

function verify_native_content($db, $recipient)
{
	foreach (array('privmsgs', 'privmsgs_to') as $table)
	{
		check_fixture(!$db->query("SELECT 1 FROM p3_$table WHERE msg_id IN (109,127,128)")->fetch_row(), 'An unfinished source message became available in phpBB');
	}
	check_fixture($db->query('SELECT 1 FROM p3_privmsgs WHERE msg_id=129')->fetch_row() &&
		!$db->query('SELECT 1 FROM p3_privmsgs_to WHERE msg_id=129')->fetch_row(), 'An unknown source folder lost its body or gained a mailbox');
	$detached = $db->query('SELECT a.poster_id,a.is_orphan,a.in_message,a.post_msg_id,a.topic_id,q.attach_id FROM p3_plus_attach_refs r
		JOIN p3_attachments a ON a.attach_id=r.attach_id LEFT JOIN p3_plus_attach_payers q ON q.attach_id=a.attach_id
		WHERE (r.source_id=50 AND r.in_message=0 AND r.parent_id=143001) OR (r.source_id=63 AND r.in_message=1 AND r.parent_id=109)')->fetch_all();
	check_fixture(count($detached) === 2, 'A file attached to missing content was not preserved');
	foreach ($detached as $copy)
	{
		check_fixture(array_map('intval', array_slice($copy, 0, 5)) === array(0,1,0,0,0) && $copy[5] === null,
			'A file without a native parent became owned, attached or chargeable');
	}
	$orphans = $db->query('SELECT source_id,source_json FROM p3_plus_orphan_posts ORDER BY source_id')->fetch_all(MYSQLI_ASSOC);
	check_fixture(array_map('intval', array_column($orphans, 'source_id')) === array(143001, 143002, 143003),
		'Only forum texts with no source header should be held for recovery');
	$text = array_map(static function ($row) { return json_decode($row['source_json'], true, 512, JSON_THROW_ON_ERROR); }, $orphans);
	check_fixture($text[0] === array('post_subject' => 'Unzugeordnet 📷', 'bbcode_uid' => 'orphanuid', 'post_text' => "Zeile eins\n[b:orphanuid]Größe[/b:orphanuid]") &&
		$text[1]['post_subject'] === null && $text[1]['post_text'] === '' && $text[2]['post_subject'] === '0' && $text[2]['post_text'] === null,
		'Orphaned post text, markup or nullable fields changed during import');
	foreach (array('posts' => 'post_id', 'plus_bylines' => 'post_id', 'privmsgs' => 'msg_id', 'privmsgs_to' => 'msg_id') as $table => $key)
	{
		check_fixture(!$db->query("SELECT 1 FROM p3_$table WHERE $key BETWEEN 143001 AND 143003")->fetch_row(),
			'Unassigned text acquired a native post, byline or mailbox');
	}
	$author = $db->query('SELECT p.poster_id,p.post_username,b.author_name,b.author_key FROM p3_posts p
		JOIN p3_plus_bylines b ON b.post_id=p.post_id WHERE p.post_id=32')->fetch_assoc();
	check_fixture($author && (int) $author['poster_id'] === 1 && in_array($author['post_username'], array('Ehemaliger Nutzer', 'Former member'), true)
		&& $author['author_name'] === 'RetiredFixture' && strlen($author['author_key']) === 64,
		'A post with a missing source author lost its retained name or became owned by a different account');
	$topic = $db->query('SELECT t.topic_poster,p.poster_id FROM p3_topics t JOIN p3_posts p ON p.post_id=t.topic_first_post_id
		WHERE t.topic_id=1')->fetch_row();
	check_fixture($topic && (int) $topic[0] === 2 && (int) $topic[1] === 2, 'A stale topic creator did not resolve to the surviving first-post author');
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

	$rows = $db->query('SELECT msg_id,user_id,folder_id,pm_new,pm_unread FROM p3_privmsgs_to WHERE msg_id BETWEEN 110 AND 115 ORDER BY msg_id,user_id');
	$boxes = array();
	while ($row = $rows->fetch_assoc()) { $boxes[(int) $row['user_id']][(int) $row['msg_id']] = array_map('intval', array_slice($row, 2)); }
	check_fixture(count($boxes[$recipient]) === 4 && count($boxes[2]) === 4, 'Incorrect PM ownership');
	$owners = $db->query('SELECT msg_id,user_id,author_id FROM p3_privmsgs_to WHERE msg_id BETWEEN 116 AND 126 ORDER BY msg_id,user_id')->fetch_all();
	check_fixture(array_map(static function ($row) { return array_map('intval', $row); }, $owners)
		=== array(array(117,2,1), array(118,2,2)), 'A missing source account acquired a destination mailbox or displaced its valid correspondent');
	$addresses = $db->query('SELECT msg_id,author_id,to_address FROM p3_privmsgs WHERE msg_id BETWEEN 116 AND 126 ORDER BY msg_id')->fetch_all();
	$expected_addresses = array(116 => array(2,''), 117 => array(1,'u_2'), 118 => array(2,''), 119 => array(1,'u_2'),
		120 => array(1,''), 121 => array(1,'u_2'), 122 => array(2,''), 123 => array(1,'u_2'), 124 => array(2,''),
		125 => array(1,'u_2'), 126 => array(2,''));
	check_fixture(count($addresses) === count($expected_addresses), 'A message body was discarded with its missing mailbox');
	foreach ($addresses as list($id, $author, $address))
	{
		check_fixture(array((int) $author, $address) === $expected_addresses[$id], 'An old private-message participant was attributed to another account');
	}
	$expected = array(110 => array(0,0,0), 111 => array(-3,1,1), 115 => array(0,0,1));
	foreach ($expected as $id => $flags)
	{
		check_fixture(array_values($boxes[$recipient][$id]) === $flags, 'Incorrect recipient PM state for ' . $id);
	}
	check_fixture($boxes[2][111]['folder_id'] === -2 && $boxes[2][112]['folder_id'] === -1 && $boxes[2][115]['folder_id'] === -2,
		'Sent or outbox PM lost');
	foreach (array($recipient => 113, 2 => 114) as $owner => $id)
	{
		$folder = $db->query("SELECT folder_id,pm_count,plus_legacy_savebox FROM p3_privmsgs_folder WHERE user_id=$owner")->fetch_assoc();
		$expected_count = $owner === 2 ? 2 : 1;
		check_fixture((int) $folder['folder_id'] === $boxes[$owner][$id]['folder_id'] && (int) $folder['pm_count'] === $expected_count &&
			(int) $folder['plus_legacy_savebox'] === 1, 'Saved folder ownership, converted identity or count');
	}
	check_fixture((int) $db->query('SELECT COUNT(*) FROM p3_privmsgs WHERE msg_id BETWEEN 110 AND 115 AND plus_legacy_pm=1')->fetch_row()[0] === 6,
		'The six converted messages lost their original bookmark identities');
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
