<?php
function seed_profile_media($db)
{
	$db->query("INSERT INTO phpbb_flags (flag_id,flag_name,flag_image) VALUES (250,'Nordlicht','nordlicht.png')");
	$db->query("UPDATE phpbb_users SET user_from_flag='nordlicht.png' WHERE user_id=2");
	$db->query("UPDATE phpbb_users SET user_from_flag='Abendrot.png' WHERE user_id=1");
	$db->query("UPDATE phpbb_config SET config_value='1' WHERE config_name IN ('allow_avatar_upload','allow_avatar_local','allow_avatar_remote')");
	$db->query("UPDATE phpbb_users SET user_avatar_type=1,user_avatar='historic.png' WHERE user_id=1");
	$db->query("UPDATE phpbb_users SET user_avatar_type=3,user_avatar='Grüne Gruppe/Blauer Stern.png',user_rank=77 WHERE user_id=2");
	$db->query("UPDATE phpbb_users SET user_avatar_type=2,user_avatar='https://example.invalid/avatar.jpg' WHERE user_id=87");
	$db->query("INSERT INTO phpbb_users (user_id,username,user_avatar_type,user_avatar,user_email,user_lang)
		VALUES (86,'MissingPictureFixture',1,'old.jpg','missing-picture@example.invalid','english')");
	$db->query("INSERT INTO phpbb_users (user_id,username,user_avatar_type,user_avatar,user_allowavatar,user_email,user_lang)
		VALUES (85,'DisabledPictureFixture',1,'historic.png',0,'disabled-picture@example.invalid','english')");
	$db->query("INSERT INTO phpbb_ranks (rank_id,rank_title,rank_min,rank_special,rank_image)
		VALUES (77,'Sprachabzeichen',-1,1,'abzeichen.png')");
	$db->query("INSERT INTO phpbb_smilies (smilies_id,code,smile_url,emoticon)
		VALUES (83,':pf:','zeichen.png','Fixture mark'),(84,':pf2:','zeichen.png','Other fixture code'),(85,':lost:','lost.png','Missing image')");
	$db->query("UPDATE phpbb_posts_text SET post_text=CONCAT(post_text,' :pf:') WHERE post_id=1");
}

function verify_profile_media($db, $member)
{
	$users = $db->query("SELECT user_id,user_avatar,user_avatar_type,user_avatar_width,user_avatar_height FROM p3_users WHERE user_id IN (2,86,87,$member)")->fetch_all(MYSQLI_ASSOC);
	$users = array_column($users, null, 'user_id');
	check_fixture($users[$member]['user_avatar_type'] === 'avatar.driver.upload' && preg_match('/^' . $member . '_[0-9]+\.png$/D',$users[$member]['user_avatar'])
		&& (int) $users[$member]['user_avatar_width'] === 18 && (int) $users[$member]['user_avatar_height'] === 16, 'Incorrect ownership or dimensions for the uploaded avatar');
	check_fixture($users[2]['user_avatar_type'] === 'avatar.driver.local' && strpos($users[2]['user_avatar'],'Blauer%20Stern.png') !== false
		&& (int) $users[2]['user_avatar_width'] === 20, 'Gallery avatar was not migrated to the native driver');
	check_fixture($users[87]['user_avatar_type'] === 'avatar.driver.remote' && $users[87]['user_avatar'] === 'https://example.invalid/avatar.jpg', 'Lost remote avatar reference');
	check_fixture($users[86]['user_avatar'] === '' && $users[86]['user_avatar_type'] === '', 'Missing source picture should not become a broken avatar');
	$disabled = $db->query("SELECT u.user_avatar, i.reason FROM p3_users u JOIN p3_plus_media_issues i ON i.source_id=u.user_id AND i.asset_kind='avatar' WHERE u.user_id=85")->fetch_assoc();
	check_fixture($disabled && $disabled['user_avatar'] === '' && $disabled['reason'] === 'disabled_in_source', 'The conversion published a withdrawn avatar');
	$issues = $db->query("SELECT * FROM p3_plus_media_issues WHERE (asset_kind='avatar' AND source_id=86) OR (asset_kind='smiley' AND source_id=85) ORDER BY source_id")->fetch_all(MYSQLI_ASSOC);
	check_fixture(count($issues) === 2 && json_decode($issues[0]['reference_json'], true)['code'] === ':lost:' &&
		json_decode($issues[1]['reference_json'],true)['name'] === 'old.jpg', 'Unresolved profile media cannot be reconciled against the source');
	$smiles = $db->query("SELECT smiley_url,smiley_width,smiley_height FROM p3_smilies WHERE smiley_id IN (83,84,85)")->fetch_all(MYSQLI_ASSOC);
	check_fixture(count($smiles) === 2 && $smiles[0] === $smiles[1] && (int) $smiles[0]['smiley_width'] === 10 && (int) $smiles[0]['smiley_height'] === 8, 'Duplicate smiley codes or missing smiley file were not reconciled');
	$post = $db->query('SELECT post_text FROM p3_posts WHERE post_id=1')->fetch_row();
	check_fixture($post && strpos($post[0], '<E>:pf:</E>') !== false, 'The converter stored an imported smiley as plain text');
	return array('member'=>$member,'uploaded'=>$users[$member]['user_avatar'],'gallery'=>$users[2]['user_avatar'],'smiley'=>$smiles[0]['smiley_url']);
}
