<?php
function seed_custom_profiles($db)
{
	$db->query("ALTER TABLE phpbb_users ADD kurzer_text MEDIUMTEXT, ADD story_text MEDIUMTEXT, ADD single_choice TEXT,
		ADD many_choices TEXT, ADD team_note TEXT, ADD clothing TEXT");
	$db->query("INSERT INTO phpbb_profile_fields(field_id,field_column,field_name,field_description,field_type,text_field_default)
		VALUES(51,NULL,'Kurzer Text','Persönlicher Text',0,'Vorgabe'), (55,'team_note','Interne Notiz','',0,''),
		(56,'clothing','Trikotnummer','',0,'')");
	$db->query("UPDATE phpbb_profile_fields SET users_can_view=0 WHERE field_id=55");
	$db->query("UPDATE phpbb_profile_fields SET view_in_profile=0,view_in_topic=1 WHERE field_id=56");
	$db->query("INSERT INTO phpbb_profile_fields(field_id,field_column,field_name,field_description,field_type,text_area_default,text_area_maxlen)
		VALUES(52,'story_text','Erinnerungen','Mehrzeiliger Text',1,REPEAT('Frühling ',45),4000)");
	$db->query("INSERT INTO phpbb_profile_fields(field_id,field_column,field_name,field_type,radio_button_default,radio_button_values)
		VALUES(53,'single_choice','Sitzreihe',2,'0','0,Grün &amp; Blau')");
	$db->query("INSERT INTO phpbb_profile_fields(field_id,field_column,field_name,field_type,checkbox_default,checkbox_values,is_required)
		VALUES(54,'many_choices','Interessenliste',3,'Musik','Lesen,Musik,Bild &amp; Ton',1)");
	$db->query("UPDATE phpbb_users SET kurzer_text='Groß &amp; Klein',story_text=CONCAT('Erste Zeile',CHAR(10),'Letzte Zeile'),
		single_choice='0',many_choices='Musik,Bild &amp; Ton',team_note='Vertrauliche interne Notiz',clothing='Nur im Thema 42' WHERE user_id=87");
	$db->query("UPDATE phpbb_users SET many_choices='Musik',single_choice='0' WHERE user_id>0 AND many_choices IS NULL");
}

function verify_custom_profiles($db)
{
	$row = $db->query("SELECT pf_plus_f51,pf_plus_f52,pf_plus_f53,pf_plus_f54,pf_plus_f55 FROM p3_profile_fields_data WHERE user_id=87")->fetch_assoc();
	check_fixture($row && $row['pf_plus_f51'] === 'Groß &amp; Klein' && $row['pf_plus_f52'] === "Erste Zeile\nLetzte Zeile" &&
		(int) $row['pf_plus_f53'] === 2 && $row['pf_plus_f54'] === '2,3' && $row['pf_plus_f55'] === 'Vertrauliche interne Notiz',
		'Custom text, multi-line text, zero-valued choice, multiple choices or private profile field changed');
	$row = $db->query("SELECT l.lang_default_value, f.field_default_value FROM p3_profile_fields f JOIN p3_profile_lang l ON f.field_id=l.field_id WHERE f.plus_source_id=52 LIMIT 1")->fetch_assoc();
	check_fixture($row && $row['lang_default_value'] === str_repeat('Frühling ',45) && $row['field_default_value'] === $row['lang_default_value'],
		'An overlong native custom profile default was truncated');
}
