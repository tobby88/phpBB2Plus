<?php
/***************************************************************************
 *                            post_comment.php
 *                            -------------------
 *   begin                : Wednesday, Jan 1, 2003
 *   copyright            : (C) 2002 Illuminati Gaming Network
 *   email                : whofarted75@yahoo.com
 *
 *   $Id: post_comment.php,v 0.0.6   Exp $
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

class pafiledb_post_comment extends pafiledb_public
{
	function main($action = false)
	{
		global $pafiledb_template, $lang, $board_config, $phpEx, $pafiledb_config, $db, $images, $userdata;
		global $phpbb_root_path, $pafiledb_functions;
		require_once $phpbb_root_path . 'pafiledb/includes/functions_comment_storage.' . $phpEx;

		$file_id = isset($_REQUEST['file_id']) ? $_REQUEST['file_id'] : null;
		$cid = isset($_REQUEST['cid']) ? $_REQUEST['cid'] : 0;
		$delete = isset($_REQUEST['delete']) && is_string($_REQUEST['delete']) && $_REQUEST['delete'] === 'do';
		$submit = isset($_POST['submit']);
		$preview = isset($_POST['preview']);
		$raw_subject = isset($_POST['subject']) ? (is_string($_POST['subject']) ? phpbb_request_raw_value($_POST['subject']) : null) : '';
		$raw_message = isset($_POST['message']) ? (is_string($_POST['message']) ? phpbb_request_raw_value($_POST['message']) : null) : '';

		// A cached page permission is only for the form. The owned writer checks
		// the current session, account, category, file and exact comment target.
		if (($delete && isset($_POST['confirm_delete'])) || (!$delete && $submit))
		{
			try
			{
				$saved = pafiledb_comment_write($db, $delete ? 'delete' : 'post', $file_id, $cid, $raw_subject, $raw_message);
			}
			catch (PaFileDbCommentFailure $error)
			{
				message_die(GENERAL_MESSAGE, pafiledb_comment_error_message($error));
				return;
			}
			$message = $lang[$delete ? 'Comment_deleted' : 'Comment_posted'] . '<br /><br />' .
				sprintf($lang['Click_return'], '<a href="' . append_sid('dload.php?action=file&file_id=' . $saved['file_id']) . '">', '</a>');
			message_die(GENERAL_MESSAGE, $message);
			return;
		}

		try { $file_id = pafiledb_mutation_id($file_id); }
		catch (PaFileDbMutationFailure $error) { message_die(GENERAL_MESSAGE, $lang['File_not_exist']); return; }
		$sql = 'SELECT f.file_name, f.file_catid, c.cat_allow_comments FROM ' . PA_FILES_TABLE . ' f
			INNER JOIN ' . PA_CATEGORY_TABLE . ' c ON c.cat_id=f.file_catid
			WHERE f.file_id=' . $file_id . ' AND f.file_approved=1';
		if (!($result = $db->sql_query($sql))) { message_die(GENERAL_ERROR, 'Could not select download'); return; }
		$file_data = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);
		if (!$file_data) { message_die(GENERAL_MESSAGE, $lang['File_not_exist']); return; }
		$auth = isset($this->auth[$file_data['file_catid']]) ? $this->auth[$file_data['file_catid']] : array();
		foreach (array('auth_view', 'auth_read', 'auth_view_file') as $field)
		{
			if (empty($auth[$field])) { message_die(GENERAL_MESSAGE, $lang['Not_Authorised']); return; }
		}
		if ($delete)
		{
			try { $cid = pafiledb_mutation_id($cid); }
			catch (PaFileDbMutationFailure $error) { message_die(GENERAL_MESSAGE, $lang['File_not_exist']); return; }
			$result = $db->sql_query('SELECT poster_id FROM ' . PA_COMMENTS_TABLE . ' WHERE comments_id=' . $cid . ' AND file_id=' . $file_id);
			if (!$result) { message_die(GENERAL_ERROR, 'Could not select comment'); return; }
			$comment = $db->sql_fetchrow($result);
			$db->sql_freeresult($result);
			if (!$comment) { message_die(GENERAL_MESSAGE, $lang['File_not_exist']); return; }
			if (empty($userdata['session_logged_in']) || (empty($auth['auth_mod']) &&
				(empty($auth['auth_delete_comment']) || (int)$comment['poster_id'] !== (int)$userdata['user_id'])))
			{ message_die(GENERAL_MESSAGE, $lang['Not_Authorised']); return; }
			$confirm_action = append_sid('dload.php?action=post_comment&file_id=' . $file_id . '&cid=' . $cid . '&delete=do');
			$message = $lang['Comment_delete_confirm'] . '<br /><br /><form method="post" action="' .
				htmlspecialchars($confirm_action, ENT_QUOTES, 'UTF-8') . '"><input type="hidden" name="sid" value="' .
				htmlspecialchars($userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" /><input type="submit" name="confirm_delete" value="' .
				htmlspecialchars($lang['Yes'], ENT_QUOTES, 'UTF-8') . '" />&nbsp;&nbsp;<a href="' .
				append_sid('dload.php?action=file&file_id=' . $file_id) . '">' . $lang['No'] . '</a></form>';
			message_die(GENERAL_MESSAGE, $message);
			return;
		}
		if ((string)$file_data['cat_allow_comments'] !== '1' || empty($auth['auth_post_comment']))
		{
			if (empty($userdata['session_logged_in']))
			{ redirect(append_sid('login.' . $phpEx . '?redirect=dload.' . $phpEx . '?action=post_comment&file_id=' . $file_id, true)); return; }
			message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
			return;
		}
		if (!is_string($raw_subject) || !is_string($raw_message) ||
			preg_match('//u', $raw_subject) !== 1 || preg_match('//u', $raw_message) !== 1)
		{ message_die(GENERAL_MESSAGE, $lang['PA_comment_invalid']); return; }

		$pafiledb_functions->pa_generate_smilies('inline', PAGE_POSTING);
		$html_status = $userdata['user_allowhtml'] && $pafiledb_config['allow_html'] ? $lang['HTML_is_ON'] : $lang['HTML_is_OFF'];
		$bbcode_status = $userdata['user_allowbbcode'] && $pafiledb_config['allow_bbcode'] ? $lang['BBCode_is_ON'] : $lang['BBCode_is_OFF'];
		$smilies_status = $userdata['user_allowsmile'] && $pafiledb_config['allow_smilies'] ? $lang['Smilies_are_ON'] : $lang['Smilies_are_OFF'];
		$links_status = $pafiledb_config['allow_comment_links'] ? $lang['Links_are_ON'] : $lang['Links_are_OFF'];
		$images_status = $pafiledb_config['allow_comment_images'] ? $lang['Images_are_ON'] : $lang['Images_are_OFF'];
		$hidden_form_fields = '<input type="hidden" name="action" value="post_comment" />
			<input type="hidden" name="file_id" value="' . $file_id . '" />
			<input type="hidden" name="comment" value="post" />
			<input type="hidden" name="sid" value="' . htmlspecialchars($userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" />';
		$this->generate_category_nav($file_data['file_catid']);
		$pafiledb_template->assign_vars(array(
			'HTML_STATUS' => $html_status,
			'BBCODE_STATUS' => sprintf($bbcode_status, '<a href="' . append_sid("faq.$phpEx?mode=bbcode") . '" target="_phpbbcode">', '</a>'),
			'SMILIES_STATUS' => $smilies_status,
			'LINKS_STATUS' => $links_status,
			'IMAGES_STATUS' => $images_status,
			'FILE_NAME' => pafiledb_html($file_data['file_name']),
			'SUBJECT' => htmlspecialchars($raw_subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
			'COMMENT' => htmlspecialchars($raw_message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
			'DOWNLOAD' => $pafiledb_config['settings_dbname'],
			'MESSAGE_LENGTH' => $pafiledb_config['max_comment_chars'],

			'L_COMMENT_ADD' => $lang['Comment_add'],
			'L_COMMENT' => $lang['Message_body'],
			'L_COMMENT_TITLE' => $lang['Subject'],
			'L_OPTIONS' => $lang['Options'],
			'L_COMMENT_EXPLAIN' => sprintf($lang['Comment_explain'], $pafiledb_config['max_comment_chars']),
			'L_PREVIEW' => $lang['Preview'],
			'L_SUBMIT' => $lang['Submit'],
			'L_DOWNLOAD'=> $lang['Download'],
			'L_INDEX' => sprintf($lang['Forum_Index'], $board_config['sitename']),
		   'L_CHECK_MSG_LENGTH' => $lang['Check_message_length'],
		   'L_MSG_LENGTH_1' => $lang['Msg_length_1'],
		   'L_MSG_LENGTH_2' => $lang['Msg_length_2'],
		   'L_MSG_LENGTH_3' => $lang['Msg_length_3'],
		   'L_MSG_LENGTH_4' => $lang['Msg_length_4'],
		   'L_MSG_LENGTH_5' => $lang['Msg_length_5'],
		   'L_MSG_LENGTH_6' => $lang['Msg_length_6'],

			'L_BBCODE_B_HELP' => $lang['bbcode_b_help'],
			'L_BBCODE_I_HELP' => $lang['bbcode_i_help'],
			'L_BBCODE_U_HELP' => $lang['bbcode_u_help'],
			'L_BBCODE_Q_HELP' => $lang['bbcode_q_help'],
			'L_BBCODE_C_HELP' => $lang['bbcode_c_help'],
			'L_BBCODE_L_HELP' => $lang['bbcode_l_help'],
			'L_BBCODE_O_HELP' => $lang['bbcode_o_help'],
			'L_BBCODE_P_HELP' => $lang['bbcode_p_help'],
			'L_BBCODE_W_HELP' => $lang['bbcode_w_help'],
			'L_BBCODE_A_HELP' => $lang['bbcode_a_help'],
			'L_BBCODE_S_HELP' => $lang['bbcode_s_help'],
			'L_BBCODE_F_HELP' => $lang['bbcode_f_help'],
			'L_EMPTY_MESSAGE' => $lang['Empty_message'],

			'L_FONT_COLOR' => $lang['Font_color'],
			'L_COLOR_DEFAULT' => $lang['color_default'],
			'L_COLOR_DARK_RED' => $lang['color_dark_red'],
			'L_COLOR_RED' => $lang['color_red'],
			'L_COLOR_ORANGE' => $lang['color_orange'],
			'L_COLOR_BROWN' => $lang['color_brown'],
			'L_COLOR_YELLOW' => $lang['color_yellow'],
			'L_COLOR_GREEN' => $lang['color_green'],
			'L_COLOR_OLIVE' => $lang['color_olive'],
			'L_COLOR_CYAN' => $lang['color_cyan'],
			'L_COLOR_BLUE' => $lang['color_blue'],
			'L_COLOR_DARK_BLUE' => $lang['color_dark_blue'],
			'L_COLOR_INDIGO' => $lang['color_indigo'],
			'L_COLOR_VIOLET' => $lang['color_violet'],
			'L_COLOR_WHITE' => $lang['color_white'],
			'L_COLOR_BLACK' => $lang['color_black'],

			'L_FONT_SIZE' => $lang['Font_size'],
			'L_FONT_TINY' => $lang['font_tiny'],
			'L_FONT_SMALL' => $lang['font_small'],
			'L_FONT_NORMAL' => $lang['font_normal'],
			'L_FONT_LARGE' => $lang['font_large'],
			'L_FONT_HUGE' => $lang['font_huge'],

			'L_BBCODE_CLOSE_TAGS' => $lang['Close_Tags'],
			'L_STYLES_TIP' => $lang['Styles_tip'],

			'U_INDEX' => append_sid('index.'.$phpEx),
			'U_DOWNLOAD_HOME' => append_sid('dload.'.$phpEx),
			'U_FILE_NAME' => append_sid('dload.php?action=file&file_id=' . $file_id),

			'S_POST_ACTION' => append_sid('dload.'.$phpEx),
			'S_HIDDEN_FORM_FIELDS' => $hidden_form_fields)
		);

		if ($preview)
		{
			try
			{
				$settings = $pafiledb_config;
				$settings['_html_tags'] = $board_config['allow_html_tags'];
				$prepared = pafiledb_comment_prepare($raw_subject, $raw_message, $settings, $userdata, pafiledb_comment_capacities($db));
			}
			catch (PaFileDbCommentTextFailure $error)
			{
				message_die(GENERAL_MESSAGE, pafiledb_comment_error_message($error));
				return;
			}
			$orig_word = $replacement_word = array();
			obtain_word_list($orig_word, $replacement_word);
			$pafiledb_template->assign_vars(array(
				'PREVIEW' => true,
				'PRE_COMMENT' => pafiledb_comment_render($prepared['text'], $prepared['uid'], $settings, !empty($userdata['user_allowsmile']), $orig_word, $replacement_word)
			));
		}
		$this->display($lang['Download'], 'pa_comment_posting.tpl');
	}
}
