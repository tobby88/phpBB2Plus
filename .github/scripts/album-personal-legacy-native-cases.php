<?php
// Reuse the route fixture's loopback schema. Run the actual legacy reader and
// its XS template, substituting only session/bootstrap and the outer page shell.
require_once $phpbb_root_path.'includes/template.php';
$apl_program = substr($apr_source, $apr_begin);
$apl_program = preg_replace("~^\\h*include\\(\\\$phpbb_root_path \\. 'includes/page_(?:header|tail)\\.'\\.\\\$phpEx\\);\\h*$~m", '', $apl_program, -1, $apl_shells);
apt_check($apl_shells === 2, 'Only the outer legacy page header and tail are replaced');
$apl_program = preg_replace('/\?>\s*$/', '', $apl_program, 1);

function apl_view($get, $post = array())
{
	global $db, $userdata, $album_config, $album_sp_config, $lang, $phpEx, $images, $board_config;
	global $apl_program, $phpbb_root_path;
	$_GET = $get;
	$_POST = $post;
	$template = (new ReflectionClass('Template'))->newInstanceWithoutConstructor();
	$template->vars =& $template->_tpldata['.'][0];
	$template->load_config($phpbb_root_path.'templates/fisubsilversh', false);
	ob_start();
	try
	{
		eval($apl_program);
		$html = ob_get_contents();
	}
	finally
	{
		ob_end_clean();
	}
	preg_match_all('/href="album_showpage\.php\?pic_id=([0-9]+)"/', $html, $matches);
	return array('rows' => $picrow, 'total' => (int) $total_pics, 'html' => $html, 'ids' => $matches[1]);
}

function apl_snapshot()
{
	$rows = apr_snapshot();
	$rows['ratings'] = apt_rows('SELECT * FROM fixture_album_rate ORDER BY rate_pic_id, rate_point');
	$rows['comments'] = apt_rows('SELECT * FROM fixture_album_comment ORDER BY comment_id');
	return $rows;
}

$apl_original = array($album_sp_config, isset($theme) ? $theme : null);
$theme = array('template_name' => 'fisubsilversh');
$album_sp_config['rate_type'] = 1;
$board_config['xs_use_cache'] = $board_config['xs_auto_compile'] = $board_config['xs_auto_recompile'] = 0;
$album_config['rate'] = $album_config['comment'] = 1;
$album_config['fullpic_popup'] = 0;
$album_config['sort_method'] = 'pic_time';
$album_config['sort_order'] = 'DESC';
$album_config['rows_per_page'] = 2;
$album_config['cols_per_page'] = 1;
$album_config['personal_gallery'] = ALBUM_USER;
$album_config['personal_gallery_view'] = ALBUM_GUEST;
apt_sql("UPDATE fixture_users SET username='Gallery &amp; Owner' WHERE user_id=8");
apt_picture(610, 0, 1, 8);
apt_picture(611, 0, 1, 8);
apt_picture(612, 0, 1, 8);
apt_picture(613, 0, 1, 9);
apt_picture(614, 0, 1, ANONYMOUS);
foreach (array(610 => array(2, 8, 0), 611 => array(7), 612 => array(0)) as $id => $points)
{
	foreach ($points as $point)
	{
		apt_insert('album_rate', array('rate_pic_id' => $id, 'rate_user_id' => 7, 'rate_user_ip' => '00000000', 'rate_point' => $point));
	}
}
foreach (array(10 => 610, 12 => 610, 11 => 611) as $comment => $id)
{
	apt_insert('album_comment', array('comment_id' => $comment, 'comment_pic_id' => $id, 'comment_cat_id' => 0,
		'comment_user_id' => 7, 'comment_user_ip' => '00000000', 'comment_time' => 100));
}
$apl_before = apl_snapshot();
$apl_views = 0;
$apl_original_mode = $db->sql_fetchrow($db->sql_query('SELECT @@SESSION.sql_mode AS mode'));
try
{
	foreach (array('STRICT_ALL_TABLES', 'STRICT_ALL_TABLES,ONLY_FULL_GROUP_BY,ANSI_QUOTES,NO_BACKSLASH_ESCAPES') as $mode)
	{
		apt_check($db->sql_query("SET SESSION sql_mode='".$mode."'") !== false, 'Legacy reader fixture SQL mode');
		foreach (array('english', 'german') as $locale)
		{
			$lang = array();
			require $phpbb_root_path.'language/lang_'.$locale.'/lang_main.php';
			require $phpbb_root_path.'language/lang_'.$locale.'/lang_main_album.php';
			$board_config['default_lang'] = $locale;
			apt_actor(-1);
			foreach (array(
				array('rating', 'DESC', array('611', '610')),
				array('comments', 'DESC', array('610', '611')),
				array('new_comment', 'ASC', array('612', '611')),
				array('pic_title', 'ASC', array('610', '611')),
				array('pic_view_count', 'ASC', array('610', '611')),
			) as $sort)
			{
				$view = apl_view(array('user_id' => '8', 'sort_method' => $sort[0], 'sort_order' => $sort[1]));
				apt_check($view['ids'] === $sort[2] && $view['total'] === 3, 'Legacy view renders only the selected owner and requested sort');
				apt_check(strpos($view['html'], 'Gallery &amp; Owner') !== false &&
					strpos($view['html'], 'Grüße &amp; &lt;'.$sort[2][0].'&gt;') !== false &&
					strpos($view['html'], 'album_thumbnail.php?pic_id='.$sort[2][0]) !== false &&
					strpos($view['html'], 'sort_method='.$sort[0].'&amp;sort_order='.$sort[1].'&amp;start=2') !== false,
					'Actual template renders escaped text, thumbnail and matching pagination');
				apt_check(strpos($view['html'], 'album_edit.php') === false, 'Public gallery render has no owner actions');
				if ($sort[0] === 'rating')
				{
					$row = $view['rows'][1];
					apt_check((float) $row['rating'] === 5.0 && (int) $row['comments'] === 2 && (int) $row['new_comment'] === 12 &&
						strpos($view['html'], $lang['Rating'].': 5<br />') !== false &&
						strpos($view['html'], $lang['Comments'].': 2<br />') !== false,
						'Distinct comments and nonzero rating average render correctly');
				}
				$apl_views++;
			}
			$view = apl_view(array('user_id' => '8'), array('start' => '2', 'sort_method' => 'rating', 'sort_order' => 'DESC'));
			apt_check($view['ids'] === array('612') && $view['rows'][0]['rating'] === null &&
				(int) $view['rows'][0]['comments'] === 0 && $view['rows'][0]['new_comment'] === null,
				'Page two retains unrated and uncommented pictures');
			$apl_views++;

			$view = apl_view(array('user_id' => '8', 'start' => '2'));
			apt_check($view['ids'] === array('610') && $view['total'] === 3,
				'Equal upload times keep the same page order through the picture ID');
			$apl_views++;

			apt_actor(8);
			$view = apl_view(array(), array('sort_method' => 'rating', 'sort_order' => 'DESC'));
			apt_check($view['ids'] === array('611', '610') &&
				strpos($view['html'], 'album_edit.php?pic_id=610') !== false &&
				strpos($view['html'], 'album_upload.php?cat_id=0') !== false,
				'Owner retains the historical gallery actions');
			$apl_views++;

			apt_actor();
			$view = apl_view(array('user_id' => (string) ANONYMOUS));
			apt_check($view['ids'] === array('614') && $view['total'] === 1 &&
				strpos($view['html'], 'album_edit.php') === false, 'Historical anonymous gallery stays separate');
			$apl_views++;
		}
	}
	apt_check(apl_snapshot() === $apl_before, 'Actual legacy renders preserve fixture rows');
	echo 'Album legacy personal reader native: '.$apl_views." rendered views PASS; strict MariaDB/MySQL, ratings, comments, ownership, pagination and EN/DE.\n";
}
finally
{
	$album_sp_config = $apl_original[0];
	$theme = $apl_original[1];
	$db->sql_query("SET SESSION sql_mode='".$db->sql_escape($apl_original_mode['mode'])."'");
}
