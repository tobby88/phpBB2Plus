<?php
// Fixture-only reads and writes to schemas created by database.php.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[2])) { exit(2); }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli('127.0.0.1', getenv('PLUS_TEST_DB_USER') ?: 'root', getenv('PLUS_TEST_DB_PASSWORD'),
	$argv[2], (int) (getenv('PLUS_TEST_DB_PORT') ?: 3306));
$db->set_charset('utf8mb4');

function check_fixture($ok, $message)
{
	if (!$ok) { throw new RuntimeException($message); }
}
require __DIR__ . '/native_data.php';
require __DIR__ . '/news.php';
require __DIR__ . '/profile_media.php';
require __DIR__ . '/custom_profiles.php';
require __DIR__ . '/gallery.php';
require __DIR__ . '/knowledgebase.php';
require __DIR__ . '/arcade.php';
require __DIR__ . '/downloads.php';
require __DIR__ . '/banners.php';

if ($argv[1] === 'seed')
{
	$schemas = dirname(__DIR__, 2) . '/phpBB2/install/schemas/';
	// The historical setup SQL relies on its installer's session defaults.
	$db->query("SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'");
	foreach (array('mysql_schema.sql', 'mysql_basic.sql') as $file)
	{
		$db->multi_query(file_get_contents($schemas . $file));
		do {
			if ($result = $db->store_result()) { $result->free(); }
		} while ($db->more_results() && $db->next_result());
	}
	$statement = $db->prepare("UPDATE phpbb_users SET username='ModernMember', user_password=?,
		user_lang='german', user_email='modern@example.invalid' WHERE user_id=2");
	$modern = password_hash(addslashes(getenv('PLUS_TEST_MODERN_PASSWORD')), PASSWORD_BCRYPT);
	$statement->bind_param('s', $modern);
	$statement->execute();
	$db->query("UPDATE phpbb_users SET user_birthday=0,user_reg_ip='2001:db8::7',
		user_from='Fixture Town',user_signal='Example.42',user_threema='ABCD0123',user_tg='examplefixture',
		user_fb='https://www.facebook.com/example.fixture',user_twr='examplefixture' WHERE user_id=2");
	$statement = $db->prepare("INSERT INTO phpbb_users (user_id,username,user_password,user_lang,user_email)
		VALUES (1,'HistoricMember',?,'english','historic@example.invalid')");
	$historic = md5(addslashes(getenv('PLUS_TEST_HISTORIC_PASSWORD')));
	$statement->bind_param('s', $historic);
	$statement->execute();
	$db->query("UPDATE phpbb_config SET config_value='german' WHERE config_name='default_lang'");
	$db->query("INSERT INTO phpbb_posts (post_id,topic_id,forum_id,poster_id,poster_ip,post_username,
		post_time,post_edit_time,post_edit_count,post_edit_user_id,post_author_removed,post_deleted,post_deleted_time,post_deleted_by)
		VALUES (31,1,1,-1,'7f000001','AliasFixture',10000,10100,1,1,1,1,10200,2)");
	$db->query("INSERT INTO phpbb_posts_text (post_id,post_subject,post_text) VALUES (31,'Test','0')");
	$db->query("INSERT INTO phpbb_posts (post_id,topic_id,forum_id,poster_id,poster_ip,post_username,post_time,post_author_removed)
		VALUES (32,1,1,-1,'7f000001','RetiredFixture',1000010000,1)");
	$db->query("INSERT INTO phpbb_posts_text (post_id,post_subject,post_text) VALUES (32,'Retained post','Former member fixture')");
	$db->query("UPDATE phpbb_posts_text SET post_text='Grüße [b:testuid]Forum[/b:testuid] 😀', bbcode_uid='testuid' WHERE post_id=1");
	$db->query("INSERT INTO phpbb_bookmarks VALUES (1,1),(1,1),(999,1),(1,-1)");
	$db->query("INSERT INTO phpbb_categories (cat_id,cat_title,cat_order,cat_main,cat_main_type)
		VALUES(11,'Nested category',2,1,'f')");
	$db->query("INSERT INTO phpbb_forums (forum_id,forum_name,forum_desc,forum_order,cat_id,main_type,auth_view,auth_read)
		VALUES (7,'Restricted forum fixture','',20,1,'f',5,5),(11,'Native link','',2,11,'c',0,0)");
	$db->query("UPDATE phpbb_forums SET forum_link='https://example.invalid/archive',forum_link_hit_count=1,forum_link_hit=8 WHERE forum_id=11");
	$db->query("INSERT INTO phpbb_topics (topic_id,forum_id,topic_title,topic_poster,topic_time,topic_first_post_id,topic_last_post_id)
		VALUES (7,7,'Restricted topic fixture',2,1234567891,40,40)");
	$db->query("INSERT INTO phpbb_posts (post_id,topic_id,forum_id,poster_id,poster_ip,post_time)
		VALUES (40,7,7,2,'7f000001',1234567891)");
	$db->query("INSERT INTO phpbb_posts_text (post_id,post_subject,post_text) VALUES (40,'Restricted topic fixture','Private fixture text')");
	$db->query("INSERT INTO phpbb_posts (post_id,topic_id,forum_id,poster_id,poster_ip,post_username,post_time,post_author_removed) VALUES
		(44,7,7,-1,'c0000201','RetiredFixture',1234567910,1),(45,1,1,-1,'c0000202','RetiredFixGuest',1000010001,0)");
	$db->query("INSERT INTO phpbb_posts_text (post_id,post_subject,post_text) VALUES
		(44,'Private retained byline','A former member in a restricted forum'),(45,'Named guest message','A genuine guest contribution')");
	$db->query("UPDATE phpbb_topics SET topic_last_post_id=44,topic_replies=1 WHERE topic_id=7");
	$db->query("INSERT INTO phpbb_shout (shout_id,shout_username,shout_user_id,shout_session_time,shout_text,shout_active,shout_author_removed)
		VALUES (5,'FormerShouter',-1,1000,'Grüße aus dem Archiv',0,1),(7,'Member',1,1100,'Masked shout fixture',2,0)");
	seed_native_content($db);
	seed_news($db);
	$db->query("INSERT INTO phpbb_users (user_id,username,user_password,user_lang,user_email,user_posts)
		VALUES (87,'DepartingFixture','" . $db->real_escape_string($modern) . "','german','departing@example.invalid',1)");
	$db->query("INSERT INTO phpbb_removed_author_names (author_user_id,author_name_hash,author_name,recorded_at)
		VALUES (88,SHA2('EditorFixture',256),'EditorFixture',10000),(2,SHA2('ModernMember',256),'ModernMember',10000)");
	$db->query("UPDATE phpbb_posts SET post_edit_user_id=88,post_edit_username='EditorFixture',post_edit_count=2,post_edit_time=1234500000 WHERE post_id=32");
	$db->query("UPDATE phpbb_posts_text SET post_text=CONCAT(post_text,' [quote=\"EditorFixture\":testuid]Zitatprobe[/quote:testuid] [quote=\"GuestQuoter\":testuid]Gastzitat[/quote:testuid]') WHERE post_id=1");
	$db->query("INSERT INTO phpbb_posts (post_id,topic_id,forum_id,poster_id,poster_ip,post_time)
		VALUES (42,1,1,87,'7f000001',1234501000)");
	$db->query("INSERT INTO phpbb_posts_text (post_id,post_subject,post_text) VALUES (42,'Account fixture','Account removal fixture')");
	seed_gallery($db);
	$catalogue = unserialize($db->query('SELECT config_value FROM phpbb_post_icon_config WHERE config_id=1')->fetch_row()[0],array('allowed_classes'=>false));
	$catalogue['icons'][] = array('ind'=>90,'img'=>'images/icon/icon3.gif','alt'=>'icon_idea','auth'=>1);
	$catalogue['icons'][] = array('ind'=>91,'img'=>'images/icon/withdrawn.gif','alt'=>'Withdrawn','auth'=>0);
	$catalogue['special']['POST_CALENDAR']['icon']=22;
	$update_icons = $db->prepare('UPDATE phpbb_post_icon_config SET config_value=? WHERE config_id=1');
	$serialized_icons = serialize($catalogue);
	$update_icons->bind_param('s',$serialized_icons);
	$update_icons->execute();
	$db->query('UPDATE phpbb_topics SET topic_icon=0 WHERE topic_id=1');
	$db->query('UPDATE phpbb_topics SET topic_icon=3 WHERE topic_id=2');
	$db->query('UPDATE phpbb_posts SET post_icon=3 WHERE post_id=1');
	$db->query("INSERT INTO phpbb_color_groups (group_id,group_name,group_color,hidden,order_num)
		VALUES (6,'Redaktion &amp; Gäste','#5a769B',0,8),(7,'Hidden palette','navy',1,-2),(8,'Invalid colour','not-a-colour',0,9)");
	$db->query("INSERT INTO phpbb_groups (group_id,group_type,group_name,group_description,group_single_user,group_color_group)
		VALUES (73,2,'Archive Club','',0,6)");
	$db->query("UPDATE phpbb_groups SET group_color_group=7 WHERE group_id=71");
	$db->query("UPDATE phpbb_groups SET group_color_group=1 WHERE group_id=72");
	$db->query("INSERT INTO phpbb_user_group (group_id,user_id,user_pending) VALUES (73,1,0)");
	$db->query("UPDATE phpbb_users SET user_color_group=99 WHERE user_id=87");
	seed_knowledgebase($db);
	seed_arcade($db);
	seed_downloads($db);
	seed_banners($db);
	seed_profile_media($db);
	seed_custom_profiles($db);
	$db->query("UPDATE phpbb_portal SET portal_value='<center>Grüße aus dem <b>Archiv</b><br><a href=\"ftopic1.html\">Forum</a><img src=\"templates/fisubsilversh/images/news/topicnews.gif\" alt=\"Briefkopf\"></center>' WHERE portal_name='welcome_text'");
	$db->query("UPDATE phpbb_portal SET portal_value='14' WHERE portal_name='exceptional_forums'");
	$db->query("UPDATE phpbb_portal SET portal_value='1,7' WHERE portal_name='poll_forum'");
	$db->query("UPDATE phpbb_portal SET portal_value='2' WHERE portal_name='number_recent_topics'");
	$db->query("UPDATE phpbb_portal SET portal_value='3' WHERE portal_name='number_recent_files'");
	$db->query("UPDATE phpbb_portal SET portal_value='6' WHERE portal_name='pics_number'");
	$db->query("UPDATE phpbb_portal SET portal_value='137' WHERE portal_name='pics_thumbsize'");
	$db->query("UPDATE phpbb_plus SET config_value='4' WHERE config_name='show_shoutbox'");
	$db->query("UPDATE phpbb_users SET user_allow_viewonline=0,user_lastlogon=1084742471 WHERE user_id=87");
	$db->query("UPDATE phpbb_topics SET topic_calendar_time=1206917100,topic_calendar_duration=91799 WHERE topic_id=1");
	$db->query("UPDATE phpbb_topics SET topic_calendar_time=1206964800,topic_calendar_duration=0 WHERE topic_id=7");
	$db->query("UPDATE phpbb_users SET user_birthday=DATEDIFF('1980-02-29','1970-01-01'),user_calendar_forum=0 WHERE user_id=1");
	$db->query("UPDATE phpbb_forums SET auth_cal=2 WHERE forum_id=1");
	$db->query("UPDATE phpbb_forums SET auth_cal=5 WHERE forum_id=7");
	$db->query("INSERT INTO phpbb_auth_access (group_id,forum_id,auth_cal) VALUES (71,1,1),(72,1,1) ON DUPLICATE KEY UPDATE auth_cal=1");
	$db->query('UPDATE phpbb_forums SET auth_news=2 WHERE forum_id=1');
	$db->query('UPDATE phpbb_auth_access SET auth_news=1 WHERE forum_id=1 AND group_id=71');
	$db->query("INSERT INTO phpbb_attachments_desc (attach_id,physical_filename,real_filename,download_count,extension,mimetype,filesize,filetime,thumbnail)
		VALUES (50,'public.gif','Frühling.gif',14,'gif','image/gif',0,10000000,1),
		(63,'letter.png','Brief.png',3,'png','image/png',0,10000001,0),(80,'unlinked.gif','Entwurf.gif',0,'gif','image/gif',0,10000002,0)");
	$db->query("INSERT INTO phpbb_attachments (attach_id,post_id,privmsgs_id,user_id_1,user_id_2)
		VALUES (50,32,0,-1,0),(50,32,0,-1,0),(50,32,0,2,0),(50,40,0,2,0),(63,0,111,2,1),(63,0,112,2,1)");
	$db->query("INSERT INTO phpbb_quota_limits (quota_limit_id,quota_desc,quota_limit) VALUES
		(9,'Klein & fein',50),(10,'Medium fixture',100),(11,'Group fixture',300),(12,'Full range',18446744073709551615)");
	$db->query("INSERT INTO phpbb_attach_quota (user_id,group_id,quota_type,quota_limit_id) VALUES
		(1,0,1,9),(0,71,1,11),(0,73,1,10),(0,71,2,10),(0,73,2,11),(0,72,1,12),(999,0,2,12)");
	$db->query("UPDATE phpbb_attachments_config SET config_value='1' WHERE config_name='default_upload_quota'");
	$db->query('UPDATE phpbb_forums SET auth_attachments=1 WHERE forum_id=1');
	$db->query('UPDATE phpbb_forums SET auth_attachments=2,auth_download=1 WHERE forum_id=14');
	$db->query('INSERT INTO phpbb_auth_access (group_id,forum_id,auth_attachments) VALUES (71,14,1) ON DUPLICATE KEY UPDATE auth_attachments=1');
	$db->query("UPDATE phpbb_attachments_config SET config_value='9' WHERE config_name='default_pm_quota'");
	$db->query("INSERT INTO phpbb_groups (group_id,group_type,group_name,group_description,group_single_user) VALUES (74,2,'PM quota circle','',0)");
	$db->query("INSERT INTO phpbb_user_group (group_id,user_id,user_pending) VALUES (74,87,0)");
}
elseif ($argv[1] === 'installed-flags')
{
	$stats = $db->query("SELECT COUNT(*) AS choices, SUM(image='') AS missing FROM p3_plus_flags")->fetch_assoc();
	check_fixture((int) $stats['choices'] === 192 && (int) $stats['missing'] === 0, 'Fresh board has an incomplete bundled flag catalogue');
}
elseif ($argv[1] === 'profile-media')
{
	$id = (int) $db->query("SELECT user_id FROM p3_users WHERE username='HistoricMember'")->fetch_row()[0];
	echo json_encode(verify_profile_media($db, $id));
}
elseif ($argv[1] === 'post-icons')
{
	$rows = $db->query('SELECT plus_icon_id,icons_id,icons_url,icons_width,icons_height,icons_alt,display_on_posting FROM p3_icons WHERE plus_icon_id>0')->fetch_all(MYSQLI_ASSOC);
	echo json_encode(array_column($rows,null,'plus_icon_id'));
}
elseif (in_array($argv[1],array('colours-disable','colours-enable'),true))
{
	$enabled = $argv[1] === 'colours-enable';
	$row = $db->query("SELECT u.user_colour,u.plus_colour_id,u.plus_colour_applied,g.group_colour,t.topic_first_poster_colour
		FROM p3_users u JOIN p3_groups g ON g.group_id=u.group_id JOIN p3_topics t ON t.topic_id=601 WHERE u.user_id=2")->fetch_assoc();
	$wanted = $enabled ? 'FF0000' : $row['group_colour'];
	check_fixture((int) $row['plus_colour_id'] === 1 && (bool) $row['plus_colour_applied'] === $enabled
		&& $row['user_colour'] === $wanted && $row['topic_first_poster_colour'] === $wanted,'Toggling the extension lost a selection or left a stale colour');
}
elseif ($argv[1] === 'banner-clicks')
{
	$row = $db->query("SELECT clicks,views,(SELECT COUNT(*) FROM p3_plus_banner_hits WHERE banner_id=5) AS total
		FROM p3_plus_banners WHERE banner_id=5")->fetch_assoc();
	check_fixture($row && $row['clicks'] === '18446744073709551614.00' && (int) $row['total'] === 3,
		'The first click and its detail row should each be recorded exactly once');
	check_fixture($row['views'] === '18446744073709551615.00' && (int) $db->query('SELECT views FROM p3_plus_banners WHERE banner_id=21')->fetch_row()[0] === 0,
		'Banner views overflowed the original counter or counted a placement outside its schedule');
}
elseif (in_array($argv[1], array('converted', 'hashes-unchanged', 'signed-in'), true))
{
	$users = $db->query("SELECT user_id,username,user_password,user_lang FROM p3_users
		WHERE username IN ('ModernMember','HistoricMember') ORDER BY username")->fetch_all(MYSQLI_ASSOC);
	check_fixture(count($users) === 2, 'Lost migrated accounts');
	foreach ($users as $user)
	{
		$is_bridge = strpos($user['user_password'], '$PLUS2$') === 0;
		check_fixture($is_bridge === ($argv[1] !== 'signed-in'), 'Unexpected credential upgrade state for ' . $user['username']);
	}
	check_fixture((int) $users[0]['user_id'] !== 1 && $users[0]['user_lang'] === 'en' && $users[1]['user_lang'] === 'de', 'User ID or locale not converted');
	if ($argv[1] !== 'converted') { return; }
	$historic_id = (int) $users[0]['user_id'];
	$icon = $db->query('SELECT i.plus_icon_id,i.icons_width,i.icons_height FROM p3_posts p JOIN p3_icons i ON i.icons_id=p.icon_id WHERE p.post_id=1')->fetch_row();
	$topic_icon = $db->query('SELECT i.plus_icon_id FROM p3_topics t JOIN p3_icons i ON i.icons_id=t.icon_id WHERE t.topic_id=2')->fetch_row();
	$unavailable = $db->query("SELECT source_id FROM p3_plus_media_issues WHERE asset_kind='post-icon'")->fetch_row();
	check_fixture($icon && (int) $icon[0]===3 && (int) $icon[1]>0 && (int) $icon[2]>0 && (int) $topic_icon[0]===3 &&
		(int) $unavailable[0]===91,'Converted post/topic selectors, image dimensions or missing artwork report were lost');
	$colours = $db->query("SELECT user_id,user_colour,plus_colour_applied,plus_colour_id FROM p3_users WHERE user_id IN (2,87,$historic_id)")->fetch_all(MYSQLI_ASSOC);
	$by_user = array_column($colours,null,'user_id');
	check_fixture($by_user[2]['user_colour']==='FF0000' && $by_user[$historic_id]['user_colour']==='000080'
		&& (int) $by_user[87]['plus_colour_id']===0 && (int) $by_user[87]['plus_colour_applied']===0,
		'Personal, ordered membership or pending colour was converted incorrectly');
	$topic_colour = $db->query('SELECT topic_first_poster_colour,topic_last_poster_colour FROM p3_topics WHERE topic_id=601')->fetch_row();
	$forum_colour = $db->query('SELECT forum_last_poster_colour FROM p3_forums WHERE forum_id=14')->fetch_row();
	check_fixture($topic_colour===array('FF0000','FF0000') && $forum_colour[0]==='FF0000','Native topic and forum author colours were not refreshed');
	$origin = $db->query("SELECT user_id,plus_legacy_id FROM p3_users WHERE user_id IN (1,2,$historic_id) ORDER BY user_id")->fetch_all(MYSQLI_ASSOC);
	check_fixture(array_map('intval', array_column($origin, 'plus_legacy_id')) === array(0,2,1), 'Wrong original profile IDs after conversion');
	verify_native_content($db, (int) $users[0]['user_id']);
	verify_news($db);
	verify_custom_profiles($db);
	verify_gallery($db, (int) $users[0]['user_id']);
	verify_knowledgebase($db, (int) $users[0]['user_id']);
	verify_arcade($db, (int) $users[0]['user_id']);
	verify_downloads($db, (int) $users[0]['user_id']);
	verify_banners($db, (int) $users[0]['user_id']);
	$byline = $db->query('SELECT * FROM p3_posts WHERE post_id=31')->fetch_assoc();
	check_fixture($byline && (int) $byline['post_visibility'] === 2 && (int) $byline['post_delete_time'] === 10200
		&& (int) $byline['post_delete_user'] === 2 && (int) $byline['post_edit_user'] === (int) $users[0]['user_id']
		&& $byline['post_text'] === '<t>0</t>' && $byline['post_username'] === 'Ehemaliger Nutzer', 'Lost deletion, editor, byline or zero post');
	$bookmarks = $db->query('SELECT topic_id,user_id FROM p3_bookmarks')->fetch_all(MYSQLI_ASSOC);
	check_fixture(count($bookmarks) === 1 && (int) $bookmarks[0]['topic_id'] === 1
		&& (int) $bookmarks[0]['user_id'] === (int) $users[0]['user_id'], 'Bookmarks not remapped');
	$extension = $db->query("SELECT ext_active FROM p3_ext WHERE ext_name='phpbb2plus/community'")->fetch_row();
	check_fixture($extension && (int) $extension[0] === 1, 'The Plus extension must be enabled');
	$text = $db->query('SELECT post_text FROM p3_posts WHERE post_id=1')->fetch_row()[0];
	check_fixture(strpos($text, 'Grüße') !== false && strpos($text, '<B>') !== false
		&& strpos(html_entity_decode($text, ENT_QUOTES, 'UTF-8'), '😀') !== false, 'Lost Unicode text or BBCode');
	$alias = $db->query('SELECT author_name FROM p3_plus_bylines WHERE post_id=32')->fetch_row();
	check_fixture($alias && $alias[0] === 'RetiredFixture', 'Missing retained byline');
	$nested = $db->query("SELECT c.parent_id, c.left_id AS cat_left, f.left_id, f.right_id, c.right_id AS cat_right,
		f.forum_type, f.forum_link FROM p3_forums c JOIN p3_forums f ON f.parent_id=c.forum_id
		WHERE c.forum_name='Nested category' AND f.forum_id=11")->fetch_assoc();
	check_fixture($nested && (int) $nested['parent_id'] === 1 && $nested['cat_left'] < $nested['left_id']
		&& $nested['cat_right'] > $nested['right_id'] && (int) $nested['forum_type'] === 2
		&& $nested['forum_link'] === 'https://example.invalid/archive', 'Lost nested category, linked forum or tree bounds');
	$shout = $db->query('SELECT author_id,hidden_by FROM p3_plus_shouts WHERE shout_id=7')->fetch_assoc();
	check_fixture($shout && (int) $shout['author_id'] === (int) $users[0]['user_id']
		&& (int) $shout['hidden_by'] === 2, 'Shout author or moderation state was not converted');
	$profile = $db->query('SELECT u.user_ip,u.user_birthday,p.* FROM p3_users u JOIN p3_profile_fields_data p ON p.user_id=u.user_id WHERE u.user_id=2')->fetch_assoc();
	check_fixture($profile && $profile['user_birthday'] === ' 1- 1-1970' && $profile['user_ip'] === '2001:db8::7'
		&& $profile['pf_phpbb_facebook'] === 'https://www.facebook.com/example.fixture' && $profile['pf_plus_signal'] === 'Example.42'
		&& $profile['pf_plus_threema'] === 'ABCD0123', 'Missing native birthday, registration IP or contact field');
}
elseif ($argv[1] === 'played')
{
	$row = $db->query("SELECT g.played,c.total_played,c.last_user_id,p.owner_id,p.last_played,
		(SELECT COUNT(*) FROM p3_plus_arc_launches WHERE game_id=60) AS launches
		FROM p3_plus_arc_games g JOIN p3_plus_arc_categories c ON c.cat_id=g.cat_id
		JOIN p3_plus_arc_players p ON p.owner_id=c.last_user_id JOIN p3_users u ON u.user_id=p.owner_id
		WHERE g.game_id=60 AND u.username='HistoricMember'")->fetch_assoc();
	check_fixture($row && (int) $row['played'] === 1 && (int) $row['total_played'] === 1 &&
		(int) $row['launches'] === 1 && $row['last_played'] === 'puzzle', 'Repeating the start form changed the play history');
}
elseif ($argv[1] === 'scored')
{
	$member = (int) $db->query("SELECT user_id FROM p3_users WHERE username='HistoricMember'")->fetch_row()[0];
	foreach (array('scores','all_scores') as $board)
	{
		$rows = $db->query("SELECT score_units,player_id FROM p3_plus_arc_$board WHERE game_name='puzzle' AND owner_id=$member")->fetch_all(MYSQLI_ASSOC);
		check_fixture(count($rows) === 1 && $rows[0]['score_units'] === '1457500' && (int) $rows[0]['player_id'] === 1, 'Wrong score or duplicated migrated identity');
	}
	$winner = $db->query("SELECT winner_id,all_winner_id FROM p3_plus_arc_games WHERE game_id=60")->fetch_assoc();
	check_fixture((int) $winner['winner_id'] === $member && (int) $winner['all_winner_id'] === 2, 'Current and all-time winner pointers diverged');
	$count = $db->query("SELECT COUNT(*) FROM p3_plus_arc_launches WHERE finished_at>0 AND game_id=60")->fetch_row()[0];
	check_fixture((int) $count === 4, 'A duplicate callback counted an extra completed play');
	$wallet = $db->query("SELECT balance_text FROM p3_plus_arc_wallets WHERE owner_id=$member AND currency_field='user_points'")->fetch_row()[0];
	$poor = $db->query("SELECT balance_text FROM p3_plus_arc_wallets WHERE owner_id=2 AND currency_field='user_points'")->fetch_row()[0];
	$paid = $db->query("SELECT played FROM p3_plus_arc_games WHERE game_id=62")->fetch_row()[0];
	check_fixture($wallet === '9007199254741092.87345678' && $poor === '3' && (int) $paid === 1, 'A reward, charge or rejected play changed the wrong balance');
}
elseif ($argv[1] === 'downloaded')
{
	$counts = $db->query('SELECT downloads,last_download,(SELECT COUNT(*) FROM p3_plus_dl_visitors WHERE file_id=50) AS visitors FROM p3_plus_dl_files WHERE file_id=50')->fetch_assoc();
	check_fixture((int) $counts['downloads'] === 10 && (int) $counts['visitors'] === 1 && $counts['last_download']>20000, 'Licence, original or mirror accounting double-counted a visitor');
	$votes = $db->query('SELECT COUNT(*),SUM(points) FROM p3_plus_dl_votes WHERE file_id=50')->fetch_row();
	check_fixture((int) $votes[0] === 3 && (int) $votes[1] === 24, 'A repeated vote changed the paFileDB rating totals');
}
elseif ($argv[1] === 'attachments')
{
	$rows = $db->query('SELECT r.source_id,r.parent_id,r.attach_id,r.in_message,a.filesize,a.physical_filename,a.is_orphan,a.download_count
		FROM p3_plus_attach_refs r JOIN p3_attachments a ON a.attach_id=r.attach_id ORDER BY r.source_id,r.attach_id')->fetch_all(MYSQLI_ASSOC);
	echo json_encode($rows), "\n";
}
else { exit(2); }
