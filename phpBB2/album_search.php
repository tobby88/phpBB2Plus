<?php
/***************************************************************************
 *                            album_search.php
 *                            -------------------
 *   started            : Saturday, January 18, 2004
 *   copyright          : © Volodymyr (CLowN) Skoryk
 *   email              : blaatimmy72@yahoo.com
 *	 version            : 1.5
 *
 ***************************************************************************/

/***************************************************************************
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************/

 /***************************************************************************
 *
 *   Change Log:
 *
 *		1.5.0
 *			-fixed bug in searching personal galleries
 *
 *		1.4.0
 *			-made search of personal galleries possible
 *
 *		1.3.0
 *			-totally rewrote search.php and templet file to use phpbbs
 *			 template system
 *			-fixed bug in mysql query line
 *			-implemented use of $_GET and $_POST
 *
 *		1.2.0
 *			-fixed session problem,and php opening tag before comments bug
 *
 *		1.1.0
 *			-fixed bug were username and picture name were rewerced in the
 *			 template
 *
 *		1.0.0
 *			-initial release
 *
 ***************************************************************************/

//+-+-------------------------------------------------------+-+-+-+-+-+-+-+-+

define('IN_PHPBB', true);
$phpbb_root_path = './';
$album_root_path = $phpbb_root_path . 'album_mod/';
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.'.$phpEx);

// Start session management
//
	$userdata = session_pagestart($user_ip, PAGE_ALBUM);
	init_userprefs($userdata);
//
// End session management

	include($album_root_path . 'album_common.'.$phpEx);

	$page_title = $lang['Album_Search'];
	include($phpbb_root_path . 'includes/page_header.'.$phpEx);

	$template->set_filenames(array(
		'body' => 'album_search_body.tpl')
	);
	//+-+-------------------------------------------------------+-+-+-+-+-+-+-+-+

	$search_value = isset($_POST['search']) ? $_POST['search'] : (isset($_GET['search']) ? $_GET['search'] : '');
	$mode_value = isset($_POST['mode']) ? $_POST['mode'] : (isset($_GET['mode']) ? $_GET['mode'] : '');
	$search = is_scalar($search_value) ? trim((string) $search_value) : '';
	$mode = is_scalar($mode_value) ? strtolower((string) $mode_value) : '';
	$search_columns = array(
		'user' => 'p.pic_username',
		'name' => 'p.pic_title',
		'desc' => 'p.pic_desc',
	);

	$template->assign_vars(array(
		'L_SEARCH_FOR' => $lang['Search_for'],
		'L_USERNAME' => $lang['Username'],
		'L_NAME' => $lang['Name'],
		'L_DESCRIPTION' => $lang['Description'],
		'L_THAT_CONTAINS' => $lang['That_contains'],
		'L_SUBMIT' => $lang['Submit'],
		'L_RESET' => $lang['Reset'],
	));

	if ($search !== '')
	{
		$template->assign_block_vars('switch_search_results', array());

		if (!isset($search_columns[$mode]) || strlen($search) > 100 || strpos($search, "\0") !== false)
		{
			message_die(GENERAL_ERROR, $lang['Album_Search_Invalid']);
		}
		$where = $search_columns[$mode];
		$search_sql = $db->sql_escape($search);
		$author_visibility_sql = $mode==='user' ? phpbb_removed_author_metadata_sql('p.pic_user_id','p.pic_username') : '';

		// Limit visible results, not raw matches. Once a category/gallery is
		// refused, exclude that whole context and restart the bounded result set.
		// The final batch is ordered and authorized together, even when new
		// pictures arrive between queries. Never cache a positive permission.
		$denied_categories = array();
		$denied_personal = array();
		do
		{
			$denied_before = count($denied_categories) + count($denied_personal);
			$exclude_sql = '';
			if ($denied_categories) { $exclude_sql .= ' AND p.pic_cat_id NOT IN (' . implode(',', array_keys($denied_categories)) . ')'; }
			if ($denied_personal) { $exclude_sql .= ' AND NOT (p.pic_cat_id = ' . PERSONAL_GALLERY . ' AND p.pic_user_id IN (' . implode(',', array_keys($denied_personal)) . '))'; }
			$matches = array();
			$in = array();

			// Descriptions may be very large. Search them in SQL without loading
			// their bytes for every candidate; only render/permission fields travel.
			$sql = "SELECT p.pic_id,p.pic_cat_id,p.pic_user_id,p.pic_username,p.pic_title,p.pic_time,
				c.cat_id,c.cat_user_id,c.cat_title,c.cat_parent,c.cat_approval,
				c.cat_view_level,c.cat_upload_level,c.cat_rate_level,c.cat_comment_level,c.cat_edit_level,c.cat_delete_level,
				c.cat_view_groups,c.cat_upload_groups,c.cat_rate_groups,c.cat_comment_groups,c.cat_edit_groups,c.cat_delete_groups,c.cat_moderator_groups
				FROM " . ALBUM_TABLE . " AS p
				LEFT JOIN " . ALBUM_CAT_TABLE . " AS c ON c.cat_id = p.pic_cat_id
				WHERE p.pic_approval = 1
					AND " . $where . " LIKE '%" . $search_sql . "%'
					" . $author_visibility_sql . "
					AND (c.cat_id IS NOT NULL OR (p.pic_cat_id = " . PERSONAL_GALLERY . " AND p.pic_user_id <> 0
						AND EXISTS (SELECT 1 FROM " . USERS_TABLE . " album_search_owner WHERE album_search_owner.user_id = p.pic_user_id)))
					" . $exclude_sql . "
				ORDER BY p.pic_time DESC,p.pic_id DESC
				LIMIT 500";

			if (!($result = $db->sql_query($sql)))
			{
				message_die(GENERAL_ERROR, "Couldn't obtain Album search results", "", __LINE__, __FILE__, $sql);
			}
			try
			{
				while ($row = $db->sql_fetchrow($result))
				{
					$pic_id = (string) $row['pic_id'];
					if (preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', $pic_id) !== 1 || (strlen($pic_id) === 10 && strcmp($pic_id, '4294967295') > 0))
					{
						message_die(GENERAL_ERROR, "Couldn't obtain Album search results");
					}
					$cat_id = intval($row['pic_cat_id']);
					$album_user_id = ($cat_id === PERSONAL_GALLERY) ? intval($row['pic_user_id']) : intval($row['cat_user_id']);
					if (($cat_id === PERSONAL_GALLERY && isset($denied_personal[$album_user_id])) ||
						($cat_id !== PERSONAL_GALLERY && isset($denied_categories[$cat_id])) || isset($in[$pic_id])) { continue; }
					if ($cat_id !== PERSONAL_GALLERY && (empty($row['cat_id']) || $album_user_id < ALBUM_PUBLIC_GALLERY))
					{
						$denied_categories[$cat_id] = true;
						continue;
					}

					$album_user_access = ($cat_id === PERSONAL_GALLERY)
						? album_permissions($album_user_id, PERSONAL_GALLERY, ALBUM_AUTH_VIEW)
						: album_permissions($album_user_id, $cat_id, ALBUM_AUTH_VIEW, $row);
					if (!album_check_permission($album_user_access, ALBUM_AUTH_VIEW))
					{
						if ($cat_id === PERSONAL_GALLERY) { $denied_personal[$album_user_id] = true; }
						else { $denied_categories[$cat_id] = true; }
						continue;
					}
					$in[$pic_id] = true;
					$matches[] = $row;
				}
			}
			finally { $db->sql_freeresult($result); }
		}
		while (count($denied_categories) + count($denied_personal) > $denied_before);

		$numres = count($matches);
		if ($numres === 0) { message_die(GENERAL_MESSAGE, $lang['No_search_match']); }
		foreach ($matches as $row)
		{
			$pic_id = (string) $row['pic_id'];
			$cat_id = intval($row['pic_cat_id']);
			$album_user_id = ($cat_id === PERSONAL_GALLERY) ? intval($row['pic_user_id']) : intval($row['cat_user_id']);
			$user_cat_root_id = album_get_personal_root_id($album_user_id);
			$is_personal = ($cat_id === PERSONAL_GALLERY || $album_user_id !== ALBUM_PUBLIC_GALLERY);

			$template->assign_block_vars('switch_search_results.search_results', array(
				'L_USERNAME' => phpbb_author_name((int)$row['pic_user_id'],$row['pic_username']),
				'U_PROFILE' => append_sid('profile.php?mode=viewprofile&u=' . intval($row['pic_user_id'])),

				'L_CAT' => $is_personal ? $lang['Users_Personal_Galleries'] : htmlspecialchars((string) $row['cat_title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
				'U_CAT' => ($cat_id === PERSONAL_GALLERY || $cat_id == $user_cat_root_id)
					? append_sid(album_append_uid($album_user_id, 'album.php'))
					: append_sid(album_append_uid($album_user_id, 'album_cat.php?cat_id=' . $cat_id)),

				'L_PIC' => htmlspecialchars((string) $row['pic_title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
				'U_PIC' => append_sid('album_showpage.php?pic_id=' . $pic_id),

				'L_TIME' => create_date($board_config['default_dateformat'], $row['pic_time'], $board_config['board_timezone'])
			));
		}

		$template->assign_vars(array(
			'L_NRESULTS' => $numres,
			'L_SEARCH_RESULTS' => sprintf($numres == 1 ? $lang['Found_search_match'] : $lang['Found_search_matches'], $numres),
			'L_TCATEGORY' => $lang['Category'],
			'L_TTITLE' => $lang['Name'],
			'L_TSUBMITER' => $lang['Author'],
			'L_TSUBMITED' => $lang['Posted']
		));
	}
	else
	{
		$template->assign_block_vars('switch_search', array());
	}


//+-+-------------------------------------------------------+-+-+-+-+-+-+-+-+

	$template->pparse('body');
	include($phpbb_root_path . 'includes/page_tail.'.$phpEx);

// +-------------------------------------------------------------+
// |  Powered by Photo Album 2.x.x (c) 2002-2003 Smartor         |
// |  with Volodymyr (CLowN) Skoryk's Service Pack 1 © 2003-2004 |
// +-------------------------------------------------------------+

?>
