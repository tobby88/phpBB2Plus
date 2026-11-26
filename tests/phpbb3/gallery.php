<?php
function seed_gallery($db)
{
	$db->query("UPDATE phpbb_album_config SET config_value='16' WHERE config_name='thumbnail_size'");
	$db->query("UPDATE phpbb_album_config SET config_value='3' WHERE config_name='user_pics_limit'");
	$db->query("UPDATE phpbb_album_config SET config_value='.club.example.invalid, https://www.gallery.example.invalid/show' WHERE config_name='hotlink_allowed'");
	$db->query("UPDATE phpbb_album_sp_config SET config_value='1' WHERE config_name='use_watermark'");
	// The album home uses the Supercharged gallery settings, independently of category pagination.
	$db->query("UPDATE phpbb_album_sp_config SET config_value='3' WHERE config_name='img_cols'");
	$db->query("UPDATE phpbb_album_sp_config SET config_value='30' WHERE config_name='midthumb_width'");
	$db->query("UPDATE phpbb_album_sp_config SET config_value='18' WHERE config_name='midthumb_height'");
	$db->query("UPDATE phpbb_album_config SET config_value='3' WHERE config_name='personal_gallery_view'");
	$db->query("UPDATE phpbb_album_config SET config_value='71,72' WHERE config_name='personal_gallery_private'");
	$db->query("INSERT INTO phpbb_groups (group_id,group_type,group_name,group_description,group_moderator,group_single_user)
		VALUES (71,2,'Photo Club','',2,0),(72,1,'Single HistoricMember','',0,1)");
	$db->query('INSERT INTO phpbb_user_group (group_id,user_id,user_pending) VALUES (71,1,0),(71,87,1),(72,1,0)');
	$db->query("UPDATE phpbb_album_cat SET cat_approval=2 WHERE cat_id=1");
	$db->query("INSERT INTO phpbb_album_cat (cat_id,cat_parent,cat_title,cat_desc,cat_order,cat_view_level,cat_view_groups,cat_moderator_groups)
		VALUES (8,1,'Clubfotografie','Gruppenalbum',2,3,'71','72'),(9,8,'Unteralbum','',1,-1,'','')");
	// Real stored personal categories can have different group lists and converted individual grants.
	$db->query("INSERT INTO phpbb_album_cat (cat_id,cat_parent,cat_title,cat_desc,cat_user_id,cat_view_level,cat_view_groups,cat_moderator_groups)
		VALUES (81,0,'Stored personal rules','',2,3,'71','72'),(82,81,'Different personal rules','',2,3,'74','')");
	$db->query("INSERT INTO phpbb_album (pic_id,pic_filename,pic_title,pic_desc,pic_user_id,pic_username,pic_time,pic_cat_id,pic_view_count,pic_lock,pic_approval)
		VALUES (100,'fixture.png','Öffentliche Fotoprobe','Beschreibung &amp; Grüße',2,'',100100,1,14,0,1),
		(101,'fixture.png','Clubfotoprobe','',1,'',100101,8,2,0,1),(102,'fixture.png','Freigabeprobe','',1,'',100102,1,0,0,0),
		(103,'fixture.png','Persönliche Fotoprobe','',1,'',100103,0,2,0,1),(104,'fixture.png','Verschachtelte Fotoprobe','',2,'',100104,9,4,1,1),
		(105,'fixture.png','Foto zum Kontowechsel','',87,'',100105,1,0,0,1),
		(106,'fixture.png','Historisches Gastfoto','',-1,'Gast',100001,0,0,0,1)");
	$db->query("UPDATE phpbb_album SET pic_thumbnail='fixture-small.png' WHERE pic_id=100");
	$db->query("UPDATE phpbb_album SET pic_thumbnail='missing-small.png' WHERE pic_id=101");
	$db->query("INSERT INTO phpbb_album_comment (comment_id,comment_pic_id,comment_cat_id,comment_user_id,comment_username,comment_user_ip,
		comment_time,comment_text,comment_edit_time,comment_edit_count,comment_edit_user_id)
		VALUES (95,100,1,-1,'EditorFixture','c0000203',100112,'Historischer Bildkommentar',100114,1,1),
		(96,105,1,87,'','c0000209',100115,'Kommentar zum Kontowechsel',100116,1,87)");
	$db->query("INSERT INTO phpbb_album_rate (rate_pic_id,rate_user_id,rate_user_ip,rate_point,rate_hon_point) VALUES (100,1,'c0000204',8,-1),(100,2,'c0000205',6,3)");
}

function verify_gallery($db, $member_id)
{
	$pic = $db->query('SELECT * FROM p3_plus_album_pics WHERE pic_id=100')->fetch_assoc();
	check_fixture($pic && $pic['description'] === 'Beschreibung & Grüße' && (int) $pic['width'] === 40
		&& (int) $pic['height'] === 24 && (int) $pic['views'] === 14, 'Album metadata or original dimensions changed');
	$personal = $db->query('SELECT c.* FROM p3_plus_album_pics p JOIN p3_plus_album_cats c ON p.cat_id=c.cat_id WHERE p.pic_id=103')->fetch_assoc();
	check_fixture($personal && (int) $personal['owner_id'] === $member_id && (int) $personal['view_level'] === 3,
		'Personal gallery root or its view policy lost');
	$grants = $db->query("SELECT subject_type,subject_id FROM p3_plus_album_grants WHERE cat_id=8 AND capability='view'")->fetch_assoc();
	check_fixture($grants && $grants['subject_type'] === 'g' && (int) $grants['subject_id'] === 71, 'Gallery group grant not migrated');
	$single = $db->query("SELECT subject_type,subject_id FROM p3_plus_album_grants WHERE cat_id=8 AND capability='moderator'")->fetch_assoc();
	check_fixture($single && $single['subject_type'] === 'u' && (int) $single['subject_id'] === $member_id, 'Single-user gallery group must become a personal grant');
	$row = $db->query('SELECT author_removed,editor_id,edit_count FROM p3_plus_album_comments WHERE comment_id=95')->fetch_assoc();
	$rating = $db->query('SELECT COUNT(*),SUM(points),SUM(honour_points) FROM p3_plus_album_ratings WHERE pic_id=100')->fetch_row();
	check_fixture($row && (int) $row['author_removed'] === 1 && (int) $row['editor_id'] === $member_id && (int) $row['edit_count'] === 1
		&& (int) $rating[0] === 2 && (int) $rating[1] === 14 && (int) $rating[2] === 2, 'Picture discussion or ratings changed');
}
