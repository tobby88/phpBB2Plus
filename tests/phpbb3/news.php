<?php
function seed_news($db)
{
	$db->query("UPDATE phpbb_config SET config_value='templates/fisubsilversh/images/news/topicnews.gif' WHERE config_name='news_rss_image'");
	$db->query("UPDATE phpbb_config SET config_value='Forum &amp; News' WHERE config_name='news_rss_image_desc'");
	$db->query("INSERT INTO phpbb_news VALUES (74,'Private desk','announcements.gif')");
	$db->query("UPDATE phpbb_topics SET news_id=1 WHERE topic_id=1");
	$db->query("UPDATE phpbb_topics SET news_id=74 WHERE topic_id=7");
	$db->query("UPDATE phpbb_topics SET news_id=999 WHERE topic_id=601");
	$db->query("INSERT INTO phpbb_topics (topic_id,forum_id,topic_title,topic_poster,topic_time,topic_first_post_id,topic_last_post_id,news_id)
		VALUES (695,1,'Withdrawn news fixture',2,1084742470,695,695,1)");
	$db->query("INSERT INTO phpbb_posts (post_id,topic_id,forum_id,poster_id,poster_ip,post_time,post_deleted,post_deleted_time,post_deleted_by)
		VALUES (695,695,1,2,'c0000203',1084742470,1,1084742490,2)");
	$db->query("INSERT INTO phpbb_posts_text (post_id,post_subject,post_text) VALUES (695,'Withdrawn news fixture','Withdrawn article body')");
}
function verify_news($db)
{
	$rows = $db->query('SELECT category_id,name,image,active FROM p3_plus_news_cats ORDER BY category_id')->fetch_all(MYSQLI_ASSOC);
	$cats = array_column($rows,null,'category_id');
	check_fixture(count($cats) === 4 && $cats[2]['name'] === 'Announcements' && $cats[74]['image'] === $cats[2]['image'] &&
		preg_match('/^[0-9a-f]{64}\.gif$/D',$cats[2]['image']) && (int) $cats[999]['active'] === 0,
		'Missing original news category, verified image or retired category ID');
	$topics = $db->query('SELECT topic_id,plus_news_cat FROM p3_topics WHERE topic_id IN (1,2,7,601) ORDER BY topic_id')->fetch_all();
	check_fixture(array_map('intval',array_column($topics,1)) === array(1,2,74,999),'Native topics lost their news classifications');
	$unused = $db->query("SELECT asset FROM p3_plus_news_images WHERE label='Unused fixture.gif'")->fetch_row();
	check_fixture($unused && preg_match('/^[0-9a-f]{64}\.gif$/D',$unused[0]),'An unassigned news image disappeared from the ACP');
	$forum = $db->query('SELECT forum_last_post_id,forum_topics_approved,forum_topics_unapproved,forum_topics_softdeleted,
		forum_posts_approved,forum_posts_unapproved,forum_posts_softdeleted FROM p3_forums WHERE forum_id=1')->fetch_row();
	$topics = $db->query('SELECT SUM(topic_visibility=1), SUM(topic_visibility IN (0,3)), SUM(topic_visibility=2)
		FROM p3_topics WHERE forum_id=1')->fetch_row();
	$posts = $db->query('SELECT SUM(post_visibility=1), SUM(post_visibility IN (0,3)), SUM(post_visibility=2)
		FROM p3_posts WHERE forum_id=1')->fetch_row();
	check_fixture(array_map('intval', $forum) === array_map('intval', array_merge(array(72), $topics, $posts)),
		'The converted forum kept stale counts, a withdrawn news post or the private destination of a move');
}
