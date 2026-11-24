<?php
function seed_knowledgebase($db)
{
	$db->query("UPDATE phpbb_kb_config SET config_value='71' WHERE config_name='mod_group'");
	$db->query("UPDATE phpbb_kb_config SET config_value='1' WHERE config_name='allow_rating'");
	$db->query("INSERT INTO phpbb_kb_categories (category_id,category_name,category_details,parent,cat_order) VALUES (8,'Wissensarchiv','Historische Artikel',1,3)");
	$db->query("INSERT INTO phpbb_kb_types (id,type) VALUES (2,'Anleitung')");
	$db->query("INSERT INTO phpbb_kb_articles (article_id,article_category_id,article_title,article_description,article_date,article_author_id,username,
		bbcode_uid,article_body,article_type,approved,topic_id,views,article_rating,article_totalvotes) VALUES
		(201,8,'Öffentliche Anleitung','Beschreibung &amp; Grüße','100201',1,'','testuid','Einstieg[toc]\n[b:testuid]Grüße[/b:testuid] Wissensprobe[page]Fortsetzung[toc]\nZweite Wissensseite',2,1,1,17,16.5000,2),
		(202,8,'Ungeprüfte Anleitung','','100202',2,'','','Wissensprobe nur intern',2,0,0,0,0,0),
		(203,8,'Überarbeitete Anleitung','','100203',87,'','','Noch in Prüfung',2,2,0,1,0,0),
		(204,8,'Geschütztes Diskussionsthema','','100204',0,'EditorFixture','','0',1,1,7,1,0,0)");
	$db->query("INSERT INTO phpbb_kb_votes (votes_ip,votes_userid,votes_file) VALUES ('c0000203',1,201),('192.0.2.5',2,201)");
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
}
