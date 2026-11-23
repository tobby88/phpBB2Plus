<?php
// Execute the database read portion of the actual detail and comment controllers
// in the same canonical fixture as the album views.
function adr_picture_context($program, $pic_id)
{
	global $db, $lang, $board_config;
	eval($program);
	return $thispic;
}

function adr_smiley_rows($program)
{
	global $db;
	$max_smilies = 20;
	eval($program);
	$result = $db->sql_query($sql);
	if (!$result)
	{
		$error = $db->sql_error();
		throw new RuntimeException($error['code'].' '.$error['message']);
	}
	try { return $db->sql_fetchrowset($result); }
	finally { $db->sql_freeresult($result); }
}

function adr_comment_rows($program, $pic_id, $sort_order, $start, $comments_per_page)
{
	$limit_sql = $start . ',' . $comments_per_page;
	eval($program);
	return apt_rows($sql);
}

$adr_picture_reads = $adr_smiley_reads = array();
foreach (array('album_showpage.php', 'album_comment_edit.php', 'album_comment_delete.php') as $file)
{
	$source = file_get_contents($phpbb_root_path.$file);
	$start = strpos($source, '// Get this pic info and current category info');
	$end = strpos($source, '// Check the permissions', $start);
	apt_check($start !== false && $end > $start, 'Complete actual detail controller read: '.$file);
	$adr_picture_reads[$file] = substr($source, $start, $end - $start);
	if ($file !== 'album_comment_delete.php')
	{
		$start = strpos($source, '$max_smilies = 20;');
		$start = strpos($source, '$sql = ', $start);
		$end = strpos($source, ';', $start);
		apt_check($start !== false && $end > $start, 'Actual inline smiley query: '.$file);
		$adr_smiley_reads[$file] = substr($source, $start, $end - $start + 1);
	}
}

apt_sql('DELETE FROM fixture_album_rate');
apt_sql('DELETE FROM fixture_album_comment');
apt_sql('DELETE FROM fixture_album');
apt_sql('DELETE FROM fixture_album_cat');
apt_insert('album_cat', array('cat_id'=>100, 'cat_title'=>'Details', 'cat_desc'=>'', 'cat_order'=>1,
	'cat_user_id'=>8, 'cat_view_level'=>ALBUM_GUEST, 'cat_approval'=>ALBUM_USER));
apt_picture(501, 100, 1, 8);
apt_picture(502, 100, 1, 99);
apt_picture(503, 101, 1, 8);
foreach (array(2, 8, 0) as $point)
{
	apt_insert('album_rate', array('rate_pic_id'=>501, 'rate_user_id'=>7, 'rate_user_ip'=>'00000000', 'rate_point'=>$point));
}
foreach (array(501, 501, 502) as $id)
{
	apt_insert('album_comment', array('comment_pic_id'=>$id, 'comment_cat_id'=>100, 'comment_user_id'=>7,
		'comment_user_ip'=>'00000000', 'comment_time'=>100));
}

apt_check(preg_match('/^CREATE TABLE phpbb_smilies\s*\([\s\S]*?;/m', $ddl, $match) === 1, 'Canonical smiley table');
apt_sql(str_replace('phpbb_smilies', 'fixture_smilies', $match[0]));
foreach (array(1=>'same.png', 2=>'same.png', 3=>'unique.png', 4=>'SAME.png') as $id => $image)
{
	apt_insert('smilies', array('smilies_id'=>$id, 'code'=>'code'.$id, 'smile_url'=>$image, 'emoticon'=>'label'.$id));
}
for ($id = 5; $id <= 25; $id++)
{
	apt_insert('smilies', array('smilies_id'=>$id, 'code'=>'code'.$id, 'smile_url'=>'image'.$id.'.png', 'emoticon'=>'label'.$id));
}
$board_config['posts_per_page'] = 10;
$mode_result = $db->sql_query('SELECT @@SESSION.sql_mode AS mode');
$old_mode = $db->sql_fetchrow($mode_result);
$db->sql_freeresult($mode_result);
$adr_failures = array();
$adr_cases = 0;
try
{
	foreach (array('STRICT_ALL_TABLES', 'STRICT_ALL_TABLES,ONLY_FULL_GROUP_BY,ANSI_QUOTES,NO_BACKSLASH_ESCAPES') as $mode)
	{
		apt_check($db->sql_query("SET SESSION sql_mode='".$mode."'") !== false, 'Detail controller SQL mode');
		foreach ($adr_picture_reads as $file => $program)
		{
			try { $picture = adr_picture_context($program, 501); }
			catch (AlbumPageExit $error)
			{
				$details = $db->sql_error();
				$adr_failures[] = $file.': '.$details['code'].' '.$error->getMessage();
				continue;
			}
			apt_check((int) $picture['pic_id'] === 501 && (int) $picture['cat_id'] === 100 &&
				(int) $picture['user_id'] === 8 && (int) $picture['comments_count'] === 2,
				'Real detail controller carries exact picture, category, author and count: '.$file);
			apt_check((float) $picture['rating'] === 5.0, 'Detail and comment form rating excludes zero placeholders');
			$picture = adr_picture_context($program, 502);
			apt_check((int) $picture['pic_user_id'] === 99 && $picture['user_id'] === null &&
				(int) $picture['comments_count'] === 1 && $picture['rating'] === null,
				'Missing account leaves its picture readable with no rating');
			foreach (array(503, 504) as $missing)
			{
				$refused = false;
				try { adr_picture_context($program, $missing); }
				catch (AlbumPageExit $error)
				{
					$refused = strpos($error->getMessage(), $lang['Pic_not_exist']) === 0;
				}
				apt_check($refused, 'Missing or orphan picture keeps the controlled not-found response');
			}
			$adr_cases++;
		}
		foreach ($adr_smiley_reads as $file => $program)
		{
			try { $smilies = adr_smiley_rows($program); }
			catch (RuntimeException $error) { $adr_failures[] = $file.': inline smiley query failed in '.$mode.' ('.$error->getMessage().')'; continue; }
			apt_check(count($smilies) === 20 && $smilies[0]['code'] === 'code1' && $smilies[0]['emoticon'] === 'label1' &&
				$smilies[1]['code'] === 'code3' && $smilies[19]['code'] === 'code22',
				'Inline picker retains one row per image and uses its first complete entry: '.$file);
			$adr_cases++;
		}
	}
	apt_check(!$adr_failures, implode("\n", $adr_failures));

	$controller = file_get_contents($phpbb_root_path . 'album_showpage.php');
	$begin = strpos($controller, '$sql = "SELECT c.*,');
	$end = strpos($controller, '";', $begin);
	apt_check($begin !== false && $end > $begin, 'Actual sorted comment-page SELECT');
	$read_page = substr($controller, $begin, $end + 2 - $begin);
	$markup = file_get_contents($phpbb_root_path . 'templates/fisubsilversh/album_showpage_body.tpl');
	apt_check(strpos($markup, 'id="comment-{commentrow.ID}"') !== false && strpos($markup, 'href="{commentrow.U_MINI_POST}"') !== false,
		'Rendered mini-post links reference a real comment anchor');
	$comment_ids = array('5301', '5303', '5306', '5310', '3500000001');
	foreach ($comment_ids as $id)
	{
		apt_insert('album_comment', array('comment_id'=>$id, 'comment_pic_id'=>507, 'comment_cat_id'=>100,
			'comment_user_id'=>$id === '5310' ? 20150 : 7, 'comment_username'=>'Stored name',
			'comment_user_ip'=>'00000000', 'comment_time'=>100, 'comment_edit_count'=>1, 'comment_edit_user_id'=>8));
	}
	apt_insert('album_comment', array('comment_id'=>5304, 'comment_pic_id'=>508, 'comment_cat_id'=>100,
		'comment_user_id'=>7, 'comment_user_ip'=>'00000000', 'comment_time'=>100));
	$editor = apt_rows('SELECT username FROM fixture_users WHERE user_id=8');
	foreach (array('ASC', 'DESC') as $direction)
	{
		$ordered = $direction === 'ASC' ? $comment_ids : array_reverse($comment_ids);
		foreach ($ordered as $position => $id)
		{
			foreach (array(1, 2, 5, 8) as $page_size)
			{
				$start = album_comment_page_start($db, 507, $id, $direction, 27, 5, $page_size);
				$rows = adr_comment_rows($read_page, 507, $direction, $start, $page_size);
				$row = $rows[$position % $page_size];
				apt_check($start === (int) floor($position / $page_size) * $page_size &&
					(string) $row['comment_id'] === $id && $row['editor_name'] === $editor[0]['username'] &&
					(int) $row['user_id'] === ($id === '5310' ? ANONYMOUS : 7),
					'Exact comment, editor and author on the actual page for both sort orders and different page sizes');
				$adr_cases++;
			}
		}
	}
	foreach (array('', 'sid=' . str_repeat('b', 32)) as $SID)
	{
		foreach (array('ASC', 'DESC') as $direction)
		{
			foreach (array(false, true) as $raw)
			{
				$link = album_comment_link('3500000001', $direction, $raw);
				$parts = parse_url($raw ? $link : html_entity_decode($link, ENT_QUOTES, 'UTF-8'));
				parse_str($parts['query'], $values);
				apt_check($parts['path'] === 'album_showpage.php' && $parts['fragment'] === 'comment-3500000001' &&
					$values['comment_id'] === '3500000001' && !isset($values['pic_id']) &&
					(isset($values['sort_order']) ? $values['sort_order'] : 'ASC') === $direction &&
					(isset($values['sid']) ? 'sid=' . $values['sid'] : '') === $SID &&
					(!$raw || strpos($link, '&amp;') === false),
					'Direct comment URL retains its order, session and final fragment in HTML and redirects');
				$adr_cases++;
			}
		}
	}
	$SID = '';
	apt_check(album_comment_page_start($db, 507, null, 'ASC', 99, 5, 2) === 4 &&
		album_comment_page_start($db, 507, null, 'DESC', -3, 5, 2) === 0 &&
		album_comment_page_start($db, 509, null, 'ASC', 10, 0, 0) === 0,
		'Shortened and empty comment lists and zero-sized legacy settings retain a valid page');
	echo 'Album detail reads native: '.$adr_cases." cases PASS; actual strict photo/comment lookups and deterministic inline smilies.\n";
}
finally
{
	$db->sql_query("SET SESSION sql_mode='".$db->sql_escape($old_mode['mode'])."'");
}
