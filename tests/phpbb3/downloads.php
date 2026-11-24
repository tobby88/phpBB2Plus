<?php
function seed_downloads($db)
{
	$db->query("INSERT INTO phpbb_pa_cat (cat_id,cat_name,cat_desc,cat_parent,parents_data,cat_order,cat_allow_file,auth_view,auth_read,auth_view_file)
		VALUES (21,'Öffentliche Dateien','',0,'',5,1,0,0,0),(22,'Clubordner','',0,'',6,0,2,2,0),
		(23,'Clubdateien','',22,'',1,1,0,0,0),(24,'Einzelfreigabe','',0,'',7,1,2,2,2)");
	$db->query("INSERT INTO phpbb_pa_auth (group_id,cat_id,auth_view,auth_read,auth_view_file) VALUES (71,22,1,1,0),(72,24,1,1,1)");
	$db->query("INSERT INTO phpbb_pa_license (license_id,license_name,license_text) VALUES (31,'Beispiellizenz','Nur für den fiktiven Test 🎮.')");
	$db->query("INSERT INTO phpbb_pa_files
		(file_id,user_id,file_name,file_catid,file_dir,real_name,unique_name,file_desc,file_longdesc,file_creator,file_version,file_ssurl,file_license,file_time,file_approved,file_dls,file_dlurl)
		VALUES (50,1,'Zeichen &amp; Farben',21,'pafiledb/uploads/','Farben grüßen.txt','library-50.txt','Fixture download','Mehrzeilige Beschreibung','Fiktive Autorin','1.5','pafiledb/images/screenshots/vorschau.png',31,20000,1,8,''),
		(51,2,'Clubbibliothek',23,'pafiledb/uploads/','club.txt','club-51.txt','Fixture download','','','','',0,20001,1,0,''),
		(52,1,'Ungeprüfter Entwurf',21,'','draft.txt','draft-52.txt','','','','','',0,20002,0,0,''),
		(53,1,'Kein Original',21,'','missing.txt','missing-53.txt','','','','','',0,20003,1,0,''),
		(54,1,'Privatfreigabe',24,'','external.txt','','Fixture download','','','','',0,20004,1,1,'https://example.invalid/fixture.txt')");
	$db->query("INSERT INTO phpbb_pa_mirrors (mirror_id,file_id,unique_name,file_dir,file_dlurl,mirror_location)
		VALUES (8,50,'mirror-50.txt','pafiledb/uploads/','','Lokale Kopie'),(9,51,'','','https://example.invalid/club.txt','Beispielserver')");
	$db->query("INSERT INTO phpbb_pa_custom (custom_id,custom_name,custom_description,data,field_order,field_type,regex)
		VALUES (81,'Farbauswahl','','a:2:{i:0;s:4:\"Blau\";i:1;s:3:\"Rot\";}',1,4,'')");
	$db->query("INSERT INTO phpbb_pa_customdata VALUES (50,81,'a:2:{i:0;s:4:\"Blau\";i:1;s:3:\"Rot\";}')");
	$db->query("INSERT INTO phpbb_pa_comments (comments_id,file_id,comments_text,comments_title,comments_time,comment_bbcode_uid,poster_id)
		VALUES (91,50,'Ein [b:fixture]Beispiel[/b:fixture] 🎨','Testkommentar',20005,'fixture',1)");
	$db->query("INSERT INTO phpbb_pa_votes (user_id,votes_ip,votes_file,rate_point) VALUES (1,'7f000001',50,8),(2,'7f000002',50,10)");
	$db->query("INSERT INTO phpbb_pa_download_info VALUES (50,1,'7f000001','Linux','Firefox','1')");
	$db->query("INSERT INTO phpbb_pa_file_journal (operation_id,item_index,operation_kind,actor_id,file_scope,file_dir,filename,file_bytes,file_sha256,binding_hash,item_state,created_at,completed_at)
		VALUES (REPEAT('1',32),1,'cleanup',1,'download','pafiledb/uploads','old-fixture.txt',14,REPEAT('1',64),REPEAT('2',64),2,19999,20000)");
}

function verify_downloads($db, $member)
{
	$f = $db->query('SELECT * FROM p3_plus_dl_files WHERE file_id=50')->fetch_assoc();
	$mirror = $db->query('SELECT * FROM p3_plus_dl_mirrors WHERE mirror_id=8')->fetch_assoc();
	$conditions = $db->query('SELECT terms FROM p3_plus_dl_licenses WHERE license_id=31')->fetch_row()[0];
	check_fixture($f && (int) $f['owner_id'] === $member && $f['asset_status'] === 'ready' && $f['preview_type'] === 'image/png'
		&& $mirror && $mirror['asset_status'] === 'ready' && $mirror['storage_name'] !== $f['storage_name'] &&
		strpos(html_entity_decode($conditions, ENT_QUOTES, 'UTF-8'),'🎮') !== false, 'Missing paFileDB media, account or licence text');
	foreach (array('files'=>5,'mirrors'=>2,'grants'=>2,'comments'=>1,'votes'=>2,'visitors'=>1,'fields'=>1,'field_values'=>1,'licenses'=>1,'import_journal'=>1) as $table=>$count)
	{
		check_fixture((int) $db->query("SELECT COUNT(*) FROM p3_plus_dl_$table")->fetch_row()[0] === $count, 'Incomplete download conversion for ' . $table);
	}
}
