<?php
function seed_knowledgebase($db)
{
	$db->query("UPDATE phpbb_kb_config SET config_value='71' WHERE config_name='mod_group'");
	$db->query("UPDATE phpbb_kb_config SET config_value='1' WHERE config_name='allow_rating'");
	$db->query("INSERT INTO phpbb_kb_categories (category_id,category_name,category_details,parent,cat_order) VALUES (8,'Wissensarchiv','Historische Artikel',1,3)");
	$db->query("INSERT INTO phpbb_kb_types (id,type) VALUES (2,'Anleitung')");
	$db->query("INSERT INTO phpbb_kb_types (id,type) VALUES (9,'Sterne 🌠 &amp;amp; Zeichen')");
	$db->query("INSERT INTO phpbb_kb_categories (category_id,category_name,category_details,parent,cat_order)
		VALUES (9,'Pflanzen 🌿','Gärten &amp;amp; Parks 🌼',8,10),(10,'Lesemappe','',1,20),
		(11,'Fundstücke','',10,10),(12,'Archivregister','',1,30),(14,'Briefe der Klasse','',11,10)");
	$db->query("INSERT INTO phpbb_kb_articles (article_id,article_category_id,article_title,article_description,article_date,article_author_id,username,
		bbcode_uid,article_body,article_type,approved,topic_id,views,article_rating,article_totalvotes) VALUES
		(201,8,'Öffentliche Anleitung','Beschreibung &amp; Grüße','100201',1,'','testuid','Einstieg[toc]\n[b:testuid]Grüße[/b:testuid] Wissensprobe[page]Fortsetzung[toc]\nZweite Wissensseite',2,1,1,17,16.5000,2),
		(202,8,'Ungeprüfte Anleitung','','100202',2,'','','Wissensprobe nur intern',2,0,0,0,0,0),
		(203,8,'Überarbeitete Anleitung','','100203',87,'','','Noch in Prüfung',2,2,0,1,0,0),
		(204,8,'Geschütztes Diskussionsthema','','100204',0,'EditorFixture','','0',1,1,7,1,8.1000,1),
		(211,10,'Lesemappenbrief','','100211',2,'','','Rückblick',1,1,1,0,0,0),
		(212,11,'Ein Fundstück','','100212',2,'','','Fundstück',1,1,0,0,0,0),
		(213,10,'Interner Entwurf','','100213',2,'','','Entwurf',1,0,0,0,0,0),
		(214,14,'Brief der Klasse','','100214',2,'','','Archivbrief',1,1,0,0,0,0)");
	$db->query("INSERT INTO phpbb_kb_votes (votes_ip,votes_userid,votes_file) VALUES ('c0000203',1,201),('192.0.2.5',2,201)");
	$db->query("INSERT INTO phpbb_kb_votes (votes_ip,votes_userid,votes_file) VALUES ('c0000204',2,211)");
	$db->query("INSERT INTO phpbb_kb_articles (article_id,article_category_id,article_title,article_description,article_date,article_author_id,
		username,bbcode_uid,article_body,article_type,approved,topic_id,views,article_rating,article_totalvotes)
		VALUES (210,8,'Verknüpfter Artikel','','100210',2,'','','Verknüpfung',1,1,9,0,0,0)");
	$db->query("INSERT INTO phpbb_topics (topic_id,forum_id,topic_title,topic_poster,topic_time,topic_first_post_id,topic_last_post_id)
		VALUES (9,1,'[ KB ] Verknüpfter Artikel',2,100210,72,72)");
	$db->query("INSERT INTO phpbb_posts (post_id,topic_id,forum_id,poster_id,poster_ip,post_time) VALUES (72,9,1,2,'7f000001',100210)");
	$db->query("INSERT INTO phpbb_posts_text (post_id,post_subject,bbcode_uid,post_text) VALUES (72,'[ KB ] Verknüpfter Artikel','testuid',
		'[b:testuid]Kategorie:[/b:testuid] Wissensarchiv\n[b:testuid][url=http://www.myserver.tld/phpBB2/kb.php?mode=article&amp;k=210]Artikel lesen[/url][/b:testuid]')");
}

function verify_knowledgebase($db, $member)
{
	$a = $db->query('SELECT * FROM p3_plus_kb_articles WHERE article_id=201')->fetch_assoc();
	check_fixture($a && (int) $a['author_id'] === $member && $a['description'] === 'Beschreibung & Grüße' && (int) $a['topic_id'] === 1 &&
		$a['historical_points'] === '16.5000' && $a['historical_votes'] === '2' && (int) $a['views'] === 17 && strpos($a['editor_text'],'[b]Grüße[/b]') !== false,
		'Knowledge Base article metadata, editable BBCode or exact historical score changed');
	$pages = $db->query('SELECT * FROM p3_plus_kb_pages WHERE article_id=201 ORDER BY page_number')->fetch_all(MYSQLI_ASSOC);
	check_fixture(count($pages) === 2 && $pages[0]['heading'] === 'Einstieg' && $pages[1]['heading'] === 'Fortsetzung' &&
		strpos($pages[0]['content'], '<B>') !== false && strpos($pages[1]['content'], 'Zweite Wissensseite') !== false, 'Knowledge Base page boundaries or formatter changed');
	$v = $db->query('SELECT user_ip,points FROM p3_plus_kb_votes WHERE user_id=' . (int) $member . ' AND article_id=201')->fetch_assoc();
	check_fixture($v && $v['user_ip'] === '192.0.2.3' && (int) $v['points'] === 0, 'Lost historical Knowledge Base voting identity');
	$owned = $db->query('SELECT a.intro_post,p.plus_kb_article,p.plus_kb_digest,a.discussion_hash FROM p3_plus_kb_articles a
		JOIN p3_posts p ON p.post_id=a.intro_post WHERE a.article_id=210 AND a.managed_topic=1')->fetch_assoc();
	check_fixture($owned && (int) $owned['intro_post'] === 72 && (int) $owned['plus_kb_article'] === 210 &&
		$owned['plus_kb_digest'] === $owned['discussion_hash'] && !(int) $a['managed_topic'], 'A generated intro or an ordinary linked topic was misclassified');
}

function verify_knowledgebase_category_removal($db)
{
	foreach (array('articles','pages','votes','mail') as $table)
	{
		$total = $db->query("SELECT COUNT(*) FROM p3_plus_kb_$table WHERE article_id IN (211,212,213)")->fetch_row()[0];
		check_fixture(!(int) $total, 'Removing category contents left orphan rows in ' . $table);
	}
	$parent = $db->query('SELECT c.parent_id FROM p3_plus_kb_articles a JOIN p3_plus_kb_cats c ON a.cat_id=c.cat_id WHERE a.article_id=214')->fetch_row();
	check_fixture($parent && (int) $parent[0] === 1, 'Deleting a parent removed or orphaned a descendant article');
}
