<?php
function apt_hotornot_draw()
{
	global $db, $userdata, $album_config, $album_data, $album_sp_config;
	$before = array($db, $userdata, $album_config, $album_data, $album_sp_config);
	try { return album_hotornot_picture($db); }
	finally { apt_check(array($db, $userdata, $album_config, $album_data, $album_sp_config) === $before, 'Drawing a picture preserves the page context'); }
}

apt_actor(-1);
$album_data = array();
apt_picture(101, 1, 0);
apt_picture(103, 3);
apt_picture(104, 4);
apt_picture('4294967295', 7, 0);
apt_sql('UPDATE fixture_album_cat SET cat_rate_level=' . ALBUM_GUEST . ' WHERE cat_id=7');
$choice = apt_hotornot_draw();
apt_check((string)$choice['picture']['pic_id'] === '4294967295' && $choice['can_rate'] && !$choice['already_rated'], 'Guest draws the only visible picture, including a legacy pending flag where approval is disabled');
apt_sql("UPDATE fixture_album_sp_config SET config_value='0' WHERE config_name='hon_rate_users'");
$choice = apt_hotornot_draw();
apt_check((string)$choice['picture']['pic_id'] === '4294967295' && !$choice['can_rate'] && !$choice['already_rated'], 'Guest can still view a picture when guest voting is disabled');
apt_sql("UPDATE fixture_album_sp_config SET config_value='1, 3;4' WHERE config_name='hon_rate_where'");
apt_check(apt_hotornot_draw() === null, 'No visible picture in configured categories returns an empty draw');

apt_actor();
apt_sql("UPDATE fixture_album_sp_config SET config_value='4' WHERE config_name='hon_rate_where'");
$choice = apt_hotornot_draw();
apt_check((int)$choice['picture']['pic_id'] === 104 && (int)$choice['picture']['cat_user_id'] === 7, 'Personal gallery owner can draw their permitted picture');
apt_sql("UPDATE fixture_album_sp_config SET config_value='3' WHERE config_name='hon_rate_where'");
apt_check(apt_hotornot_draw() === null, 'A restricted category with no current membership is empty');
apt_insert('user_group', array('group_id'=>9, 'user_id'=>7, 'user_pending'=>0));
$choice = apt_hotornot_draw();
apt_check((int)$choice['picture']['pic_id'] === 103, 'Current category members can draw the same group picture');
apt_sql('DELETE FROM fixture_user_group WHERE group_id=9 AND user_id=7');

apt_sql("UPDATE fixture_album_sp_config SET config_value='7' WHERE config_name='hon_rate_where'");
apt_sql("UPDATE fixture_album_sp_config SET config_value='0' WHERE config_name='hon_rate_times'");
apt_insert('album_rate', array('rate_pic_id'=>'4294967295', 'rate_user_id'=>7, 'rate_user_ip'=>'00000000', 'rate_point'=>3, 'rate_hon_point'=>0));
$choice = apt_hotornot_draw();
apt_check($choice['can_rate'] && $choice['picture']['rating'] === null, 'Separate rating channel permits a first vote after a regular rating');
apt_sql("UPDATE fixture_album_sp_config SET config_value='0' WHERE config_name='hon_rate_sep'");
$choice = apt_hotornot_draw();
apt_check(!$choice['can_rate'] && $choice['already_rated'] && (float)$choice['picture']['rating'] === 3.0, 'Shared rating channel displays the existing vote and its average');
apt_sql("UPDATE fixture_album_sp_config SET config_value='1' WHERE config_name='hon_rate_times'");
apt_sql('UPDATE fixture_album SET pic_lock=1 WHERE pic_id=4294967295');
$choice = apt_hotornot_draw();
apt_check(!$choice['can_rate'] && !$choice['already_rated'], 'A locked picture remains visible without voting controls or a misleading duplicate-vote label');
apt_sql("UPDATE fixture_album_config SET config_value='0' WHERE config_name IN ('rate','rate_scale')");
$choice = apt_hotornot_draw();
apt_check((string)$choice['picture']['pic_id'] === '4294967295' && !$choice['rating_enabled'] && !$choice['can_rate'], 'A disabled rating module with no scale keeps its pictures readable');
$cases += 10;

apt_sql('DELETE FROM fixture_album_rate');
apt_sql('DELETE FROM fixture_album');
apt_sql('UPDATE fixture_album_cat SET cat_rate_level=0 WHERE cat_id=7');
apt_sql("UPDATE fixture_album_config SET config_value='1' WHERE config_name='rate'");
apt_sql("UPDATE fixture_album_config SET config_value='10' WHERE config_name='rate_scale'");
apt_sql("UPDATE fixture_album_sp_config SET config_value='1' WHERE config_name IN ('hon_rate_sep','hon_rate_users')");
apt_sql("UPDATE fixture_album_sp_config SET config_value='' WHERE config_name='hon_rate_where'");
