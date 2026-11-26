<?php
// Additional records for the disposable Plus source and its converted target.
function seed_poll_conversion($db)
{
	$db->query("INSERT INTO phpbb_topics (topic_id,forum_id,topic_title,topic_poster,topic_time,topic_first_post_id,topic_last_post_id,topic_status,topic_vote)
		VALUES (603,12,'Locked ballot',2,1000000000,603,603,1,1)");
	$db->query("INSERT INTO phpbb_posts (post_id,topic_id,forum_id,poster_id,poster_ip,post_time) VALUES (603,603,12,2,'c0000203',1000000000)");
	$db->query("INSERT INTO phpbb_posts_text (post_id,post_subject,post_text) VALUES (603,'Locked ballot','Moderator ballot fixture')");
	$db->query("INSERT INTO phpbb_vote_desc (vote_id,topic_id,vote_text,vote_start,vote_length) VALUES (603,603,'Locked ballot question',1000000000,0)");
	$db->query("INSERT INTO phpbb_vote_results VALUES (603,1,'First',1),(603,2,'Second',0)");
	$db->query('UPDATE phpbb_topics SET topic_vote=0 WHERE topic_id=602');
	$db->query('UPDATE phpbb_vote_results SET vote_option_id=IF(vote_option_id=1,127,250) WHERE vote_id=602');
	$db->query("INSERT INTO phpbb_vote_voters VALUES (21,-1,'c0000205'),(21,1,'c0000204')");
	$missing = (int) $db->query('SELECT MAX(user_id)+1 FROM phpbb_users')->fetch_row()[0];
	$db->query("UPDATE phpbb_vote_voters SET vote_user_id=$missing WHERE vote_id=601");
	$db->query("INSERT INTO phpbb_vote_desc (vote_id,topic_id,vote_text,vote_start,vote_length) VALUES
		(14401,701,'Doppelte Antwort 📷',10000,0),(14402,702,'Erste Frage',10000,0),(14403,702,'Zweite Frage',10001,0),
		(14404,703,'Ohne Antworten',10000,0),(14405,704,'Nummer null',10000,0),
		(14406,705,REPEAT('📷',245),0,0),(14407,706,'Zu viele Optionen',10000,0),(14408,14480,'Fehlendes Thema',10000,0)");
	$db->query("INSERT INTO phpbb_vote_results VALUES (14401,1,'Erste',7),(14401,1,'Zweite',19),(14402,2,'Eins',5),
		(14403,2,'Zwei',9),(14405,0,'Null',4),(14406,200,'Grüße &amp; 📷',20000001),(14406,255,'Herbst',0),
		(14408,1,'Verwaist',9),(14490,1,'Ohne Umfrage',3)");
	$choices = array();
	for ($id = 1; $id <= 127; $id++) { $choices[] = "(14407,$id,'Option $id',$id)"; }
	$db->query('INSERT INTO phpbb_vote_results VALUES ' . implode(',', $choices));
	$db->query("INSERT INTO phpbb_vote_voters VALUES (14401,2,'c0000203'),(14402,-1,'c0000203'),(14403,2,'c0000204'),(14490,2,'c0000203')");
	$db->query("INSERT INTO phpbb_vote_voters VALUES (602,87,'c0000257'),(14405,87,'c0000257')");
	// Historical erasure takes precedence even if a second ballot claimed the same topic.
	$db->query("INSERT INTO phpbb_vote_desc (vote_id,topic_id,vote_text,vote_start,vote_length)
		VALUES (14409,805100,'Previously erased question',0,0)");
	$db->query("INSERT INTO phpbb_vote_results VALUES (14409,2,'Previously erased choice',1)");
	$db->query("INSERT INTO phpbb_vote_voters VALUES (14409,87,'c0000257')");
}

function verify_poll_conversion($db)
{
	$rows = $db->query('SELECT source_id,reason FROM p3_plus_poll_review ORDER BY source_id')->fetch_all();
	$expected = array(14401=>'duplicate-option-id',14402=>'multiple-polls',14403=>'multiple-polls',14404=>'no-options',
		14405=>'invalid-option-id',14407=>'native-option-limit',14408=>'missing-topic');
	check_fixture(array_column($rows, 1, 0) === $expected, 'Historical polls were assigned to the wrong review category');
	check_fixture(!$db->query('SELECT 1 FROM p3_topics WHERE topic_id IN (701,702,703,704,706) AND poll_start<>0')->fetch_row() &&
		(int) $db->query('SELECT COUNT(*) FROM p3_topics WHERE topic_id IN (701,702,703,704,706)')->fetch_row()[0] === 5,
		'Holding a source ballot changed or duplicated its topic');
	foreach (array('poll_options','poll_votes') as $table)
	{
		check_fixture(!$db->query("SELECT 1 FROM p3_$table WHERE topic_id IN (701,702,703,704,706,14480)")->fetch_row(),
			'An ambiguous or missing topic received a native choice or receipt');
	}
	$options = $db->query('SELECT poll_option_id,plus_source_option,poll_option_total FROM p3_poll_options WHERE topic_id=705 ORDER BY poll_option_id')->fetch_all();
	check_fixture(array_map(static function ($row) { return array_map('intval', $row); }, $options) === array(array(1,200,20000001),array(2,255,0)),
		'Sparse source IDs or large vote totals changed in native storage');
	$header = $db->query('SELECT poll_title,poll_start,poll_length,plus_poll_epoch FROM p3_topics WHERE topic_id=705')->fetch_row();
	check_fixture(html_entity_decode($header[0], ENT_QUOTES, 'UTF-8') === str_repeat('📷',245) && array_map('intval', array_slice($header,1)) === array(1,0,1),
		'An unlimited epoch poll lost its full question or became closed');
	check_fixture((int) $db->query('SELECT COUNT(*) FROM p3_plus_poll_choices WHERE source_id=14407')->fetch_row()[0] === 127 &&
		(int) $db->query('SELECT COUNT(*) FROM p3_plus_poll_receipts')->fetch_row()[0] === 4 &&
		!$db->query('SELECT 1 FROM p3_plus_poll_receipts WHERE source_id NOT IN (14401,14402,14403,14405)')->fetch_row() &&
		!$db->query('SELECT 1 FROM p3_plus_poll_choices WHERE source_id IN (14409,805100)')->fetch_row(),
		'Raw choices or historical participation became detached from their unresolved source polls');
	check_fixture($db->query("SELECT 1 FROM p3_poll_votes WHERE topic_id=602 AND vote_user_id=87 AND vote_user_ip='192.0.2.87'")->fetch_row() &&
		$db->query("SELECT 1 FROM p3_plus_poll_receipts WHERE source_id=14405 AND user_id=87 AND vote_ip='192.0.2.87'")->fetch_row(),
		'An existing vote lost its living owner during conversion');
	$topic = $db->query('SELECT poll_title,poll_start FROM p3_topics WHERE topic_id=602')->fetch_row();
	check_fixture($topic && $topic[0] === 'Ballot 602' && (int) $topic[1] === 1000000000, 'A stale source topic flag suppressed a valid poll');
}
