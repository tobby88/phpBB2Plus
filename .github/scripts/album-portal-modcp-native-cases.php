<?php
// Run the real display reads inside the canonical Album fixture.
function apk_section($file, $from, $to)
{
	$source = file_get_contents($GLOBALS['phpbb_root_path'].$file);
	$start = strpos($source, $from);
	$end = $start === false ? false : strpos($source, $to, $start + strlen($from));
	apt_check($end !== false && $end > $start, 'Actual read and rendering block: '.$file);
	return substr($source, $start, $end - $start);
}

function apk_render($program, $CFG)
{
	global $db, $userdata, $album_config, $board_config, $lang, $phpEx;
	$album_config['cols_per_page'] = 2;
	$album_config['fullpic_popup'] = 0;
	$album_config['rate'] = $album_config['comment'] = 1;
	$album_user_id = 0;
	$template = new AlbumPageTemplate();
	$cat_id = 100;
	$thiscat = array('cat_approval'=>ALBUM_ADMIN);
	$sort_method = 'pic_time';
	$sort_order = 'DESC';
	$start = 0;
	eval($program);
	return array('template'=>$template, 'total'=>isset($total_pics) ? (int) $total_pics : null,
		'category'=>$thiscat);
}

$upload_program = apk_section('album_upload.php', '// Get the current Category Info', '//--- Album Category Hierarchy : end');
$mod_program = apk_section('album_modcp.php', '// Use the same approval filter', '// Start output of page (ModCP)');
$portal_program = apk_section('portal.php', "if ( \$CFG['pics_number'] > 0 )", '// End add  - Photo Album Block');

apt_sql('DELETE FROM fixture_album_comment');
apt_sql('DELETE FROM fixture_album_rate');
apt_sql('DELETE FROM fixture_album');
apt_sql('DELETE FROM fixture_album_cat');
foreach (array(
	100=>array(0,ALBUM_GUEST,ALBUM_ADMIN),
	101=>array(8,ALBUM_GUEST,ALBUM_USER),
	102=>array(0,ALBUM_PRIVATE,ALBUM_ADMIN),
	103=>array(0,ALBUM_GUEST,ALBUM_USER),
) as $id => $category)
{
	apt_insert('album_cat', array('cat_id'=>$id, 'cat_title'=>'Category '.$id, 'cat_desc'=>'', 'cat_order'=>$id,
		'cat_user_id'=>$category[0], 'cat_view_level'=>$category[1], 'cat_approval'=>$category[2], 'cat_view_groups'=>'9'));
}
apt_picture(510, 100, 1, 8);
apt_picture(511, 100, 0, 8);
apt_picture(512, 101, 0, 8);
apt_picture(513, 102, 1, 8);
apt_picture(514, 103, 1, 8);
foreach (array(2, 8, 0) as $point)
{
	apt_insert('album_rate', array('rate_pic_id'=>510, 'rate_user_id'=>7, 'rate_user_ip'=>'00000000', 'rate_point'=>$point));
}
foreach (array(1, 2) as $time)
{
	apt_insert('album_comment', array('comment_pic_id'=>510, 'comment_cat_id'=>100, 'comment_user_id'=>7,
		'comment_user_ip'=>'00000000', 'comment_time'=>$time));
}

$board_config['topics_per_page'] = 2;
$apk_mode_result = $db->sql_query('SELECT @@SESSION.sql_mode AS mode');
$apk_mode = $db->sql_fetchrow($apk_mode_result);
$db->sql_freeresult($apk_mode_result);
$apk_cases = 0;
try
{
	foreach (array('STRICT_ALL_TABLES', 'STRICT_ALL_TABLES,ONLY_FULL_GROUP_BY,ANSI_QUOTES,NO_BACKSLASH_ESCAPES') as $mode)
	{
		apt_check($db->sql_query("SET SESSION sql_mode='".$mode."'") !== false, 'Upload, moderation and portal SQL mode');
		$upload = apk_render($upload_program, array());
		apt_check((int) $upload['category']['cat_id'] === 100 && (int) $upload['category']['count'] === 2,
			'Actual upload category keeps the total picture count for quotas');
		$apk_cases++;

		foreach (array(USER, ADMIN) as $role)
		{
			apt_actor($role === ADMIN ? 9 : 7, $role);
			$mod = apk_render($mod_program, array());
			$rows = $mod['template']->rows['picrow'];
			apt_check(array_map('strval', array_column($rows, 'PIC_ID')) === ($role === ADMIN ? array('511','510') : array('510')) &&
				$mod['total'] === count($rows), 'Moderation page count reflects its actual approval filter');
			$row = $rows[count($rows) - 1];
			apt_check((int) $row['COMMENTS'] === 2 && (float) $row['RATING'] === 5.0,
				'Actual moderator row does not multiply comments by ratings');
			$apk_cases++;
		}

		apt_actor(-1);
		foreach (array(
			array('0', '0', '', array('514','510')),
			array('1', '0', '', array('514','512','510')),
			array('1', '0', '100,102', array('510')),
			array('1', '1', '100,102', array('510')),
			array('1', '1', '', array('510','512','514')),
			array('1', '0', '102', array()),
		) as $scope)
		{
			$portal = apk_render($portal_program, array('pics_number'=>3, 'pics_all'=>$scope[0], 'pics_sort'=>$scope[1],
				'cat_id'=>$scope[2], 'pics_thumbsize'=>100));
			$rows = isset($portal['template']->rows['recent_pics.recent_detail']) ? $portal['template']->rows['recent_pics.recent_detail'] : array();
			$ids = array();
			foreach ($rows as $row)
			{
				$ids[] = substr($row['U_PIC'], strlen('album_showpage.php?pic_id='));
				if (end($ids) === '510')
				{
					apt_check($row['COMMENTS'] === $lang['Comments'].': 2<br />' && $row['RATING'] === $lang['Rating'].': 5<br />',
						'Portal rendering preserves exact photo comment and rating totals');
				}
			}
			if ($scope[1] === '1') { sort($ids, SORT_STRING); }
			apt_check($ids === $scope[3], 'Actual portal grid respects category subset, permissions, approval and sort');
			$apk_cases++;
		}
	}
	echo 'Album portal, upload and moderation native: '.$apk_cases." cases PASS; strict storage, approval, accurate totals and actual portal grids.\n";
}
finally
{
	$db->sql_query("SET SESSION sql_mode='".$db->sql_escape($apk_mode['mode'])."'");
}
