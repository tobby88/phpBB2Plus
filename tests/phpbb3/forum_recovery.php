<?php
// Called only for disposable source and destination schemas.
function seed_forum_recovery($db)
{
	$db->query("INSERT INTO phpbb_categories (cat_id,cat_title,cat_order,cat_main_type,cat_main) VALUES
		(71,'Loop A',1,'c',72),(72,'Loop B',2,'c',71),(73,'Detached category',3,'f',60001)");
	$db->query("INSERT INTO phpbb_forums (forum_id,forum_name,cat_id,main_type,auth_view,auth_read) VALUES
		(51,'Recovered public room',800,'c',0,0),(52,'Private child fixture',51,'f',5,5),(53,'Private loop fixture',72,'c',5,5)");
	// Forum 54 also happens to be the native converter's provisional category number.
	$db->query("INSERT INTO phpbb_topics (topic_id,forum_id,topic_title,topic_poster,topic_time,topic_first_post_id,topic_last_post_id,topic_type,topic_status,topic_moved_id) VALUES
		(14701,54,'Missing forum fixture',2,10001,14711,14711,0,0,0),
		(14702,60002,'Former global fixture',2,10002,14712,14712,3,0,0),
		(14703,7,'Existing private parent',2,10003,14713,14713,0,0,0),
		(14704,14,'Moved fixture',2,10004,0,0,0,2,603),
		(14705,14,'Held shadow fixture',2,10005,0,0,0,2,14702)");
	$db->query("INSERT INTO phpbb_posts (post_id,topic_id,forum_id,poster_id,post_time,post_deleted) VALUES
		(14711,14701,14,2,10001,0),(14712,14702,60002,2,10002,0),(14713,14703,14,2,10003,0),
		(14720,14880,14,2,10004,0),(14721,14880,7,-1,10005,0),(14722,14704,14,2,10006,0),
		(14723,14881,14,2,10007,1),(14724,0,14,2,10008,0)");
	$db->query("INSERT INTO phpbb_posts_text (post_id,post_subject,post_text) VALUES
		(14711,'Missing forum fixture','Separate destination'),(14712,'Former global fixture','Held global content'),
		(14713,'Existing private parent','Misrouted private message body'),(14720,'Grüße im Archiv','Wiederfundprobe'),
		(14721,'Second reply','Second recovered body'),(14722,'0','Original shadow post'),(14723,'Erased private title','Erased recovery fixture'),
		(14724,'','No historical topic number')");
	$db->query('INSERT INTO phpbb_attachments (attach_id,post_id,privmsgs_id,user_id_1,user_id_2) VALUES (50,14721,0,-1,0)');
	$db->query('ALTER TABLE phpbb_topics AUTO_INCREMENT=806000');
	$db->query('INSERT INTO phpbb_topics_watch (topic_id,user_id) VALUES (806000,2),(806002,2)');
}

function verify_forum_recovery($db, $damaged)
{
	$rows = $db->query('SELECT * FROM p3_forums WHERE plus_recovery_role=1')->fetch_all(MYSQLI_ASSOC);
	if (!$damaged)
	{
		check_fixture(!$rows && !$db->query('SELECT 1 FROM p3_forums WHERE plus_recovery_role=2')->fetch_row(),
			'An intact board received a recovery forum or category');
		return;
	}
	check_fixture(count($rows) === 1 && $rows[0]['forum_name'] === 'Wiederhergestellte Foren' && (int) $rows[0]['forum_id'] > 65535,
		'Unexpected recovery category ' . json_encode(array_column($rows, 'forum_name', 'forum_id')));
	$root = (int) $rows[0]['forum_id'];
	$layout = $db->query("SELECT c.forum_name,p.forum_name,c.plus_recovery_reason,c.plus_recovery_parent
		FROM p3_forums c JOIN p3_forums p ON p.forum_id=c.parent_id AND c.left_id>p.left_id AND c.right_id<p.right_id
		WHERE c.forum_id IN (51,52,53) OR c.plus_legacy_category IN (71,72,73) ORDER BY c.forum_name")->fetch_all();
	check_fixture($layout === array(
		array('Detached category','Wiederhergestellte Foren','missing-parent','f60001'),
		array('Loop A','Wiederhergestellte Foren','cycle','c72'), array('Loop B','Loop A','',''),
		array('Private child fixture','Recovered public room','',''), array('Private loop fixture','Loop B','',''),
		array('Recovered public room','Wiederhergestellte Foren','missing-parent','c800')),
		'The tree repair lost a valid edge, its bounds or the original damaged relationship');
	check_fixture((int) $db->query("SELECT COUNT(*) FROM p3_forums WHERE parent_id=$root AND plus_recovery_reason<>''")->fetch_row()[0] === 3,
		'Each damaged branch needs exactly one recovered root');
	verify_recovered_topics($db, $root);
}

function verify_recovered_topics($db, $root)
{
	$forums = $db->query('SELECT * FROM p3_forums WHERE plus_recovery_role=2')->fetch_all(MYSQLI_ASSOC);
	check_fixture(count($forums) === 1 && (int) $forums[0]['parent_id'] === $root && (int) $forums[0]['forum_status'] === 1
		&& $forums[0]['forum_name'] === 'Wiederhergestellte Themen', 'Missing the locked forum for recovered contents');
	$forum = (int) $forums[0]['forum_id'];
	$allowed = $db->query("SELECT DISTINCT g.group_name FROM p3_acl_groups a JOIN p3_groups g ON g.group_id=a.group_id
		WHERE a.forum_id=$forum AND a.auth_setting=1")->fetch_all();
	check_fixture(array_column($allowed, 0) === array('ADMINISTRATORS') && !$db->query("SELECT 1 FROM p3_acl_users WHERE forum_id=$forum")->fetch_row(),
		'Unexpected access to unassigned posts');
	$topics = $db->query("SELECT * FROM p3_topics WHERE forum_id=$forum ORDER BY topic_id")->fetch_all(MYSQLI_ASSOC);
	check_fixture(count($topics) === 6, 'Incorrect count of surviving or generated recovered topics: ' . count($topics));
	$by_id = array_column($topics, null, 'topic_id');
	foreach (array(14701=>54, 14702=>60002) as $id=>$previous_forum)
	{
		check_fixture(isset($by_id[$id]) && $by_id[$id]['plus_recovery_reason'] === 'missing-forum' && (int) $by_id[$id]['plus_recovery_from'] === $previous_forum,
			'An existing topic lost its number or its original forum');
	}
	check_fixture((int) $by_id[14702]['topic_type'] === 2 && (int) $by_id[14702]['plus_recovery_type'] === 3,
		'A recovered announcement is still global or lost its original scope');
	$first = array(0=>14724, 14704=>14722, 14880=>14720, 14881=>14723);
	$titles = array(0=>'Wiederhergestelltes Thema',14704=>'0',14880=>'Grüße im Archiv',14881=>'');
	foreach ($topics as $row)
	{
		if ($row['plus_recovery_reason'] === 'missing-forum') { continue; }
		$id = (int) $row['topic_id'];
		$old = (int) $row['plus_recovery_from'];
		check_fixture(isset($first[$old]) && $id > 806000 && $id !== 806002 && $row['topic_title'] === $titles[$old]
			&& (int) $row['topic_first_post_id'] === $first[$old] && (int) $row['topic_status'] === 1
			&& $row['plus_recovery_reason'] === ($old === 14704 ? 'moved-topic' : 'missing-topic'),
			'Wrong recovered topic number, opening post or title for ' . $old . ' at ' . $id);
		unset($first[$old]);
		check_fixture((int) $row['topic_visibility'] === ($old === 14881 ? 2 : 1) && (int) $row['plus_legacy_removed'] === (int) ($old === 14881),
			'A historical erasure changed state during recovery');
	}
	check_fixture(!$first, 'A lost topic was merged into another source topic');
	$posts = $db->query('SELECT p.post_id,p.forum_id,p.plus_recovery_forum,p.plus_recovery_topic,p.post_postcount,t.plus_recovery_from
		FROM p3_posts p JOIN p3_topics t ON t.topic_id=p.topic_id AND t.forum_id=p.forum_id WHERE p.post_id BETWEEN 14711 AND 14724 ORDER BY p.post_id')->fetch_all();
	$expected = array(array(14711,$forum,14,-1,0,54),array(14712,$forum,60002,-1,0,60002),array(14713,7,14,-1,1,0),
		array(14720,$forum,14,14880,0,14880),array(14721,$forum,7,14880,0,14880),array(14722,$forum,14,14704,0,14704),
		array(14723,$forum,14,14881,0,14881),array(14724,$forum,14,0,0,0));
	check_fixture(array_map(static function ($row) { return array_map('intval', $row); }, $posts) === $expected,
		'Posts did not follow the canonical forum, lost topic or no-count policy');
	check_fixture($db->query("SELECT 1 FROM p3_attachments a JOIN p3_posts p ON p.post_id=a.post_msg_id AND p.topic_id=a.topic_id
		WHERE p.post_id=14721 AND p.forum_id=$forum AND a.in_message=0 AND a.is_orphan=0")->fetch_row(), 'A recovered attachment lost its actual topic');
	check_fixture(!$db->query("SELECT 1 FROM p3_posts WHERE post_id=14723 AND (post_subject<>'' OR post_text LIKE '%Erased recovery fixture%')")->fetch_row(),
		'An erased post regained source contents');
}
