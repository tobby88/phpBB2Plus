<?php
/** 
*
* @package attachment_mod
* @version $Id: pm_attachments.php,v 1.2 2005/11/06 18:35:43 acydburn Exp $
* @copyright (c) 2002 Meik Sievertsen
* @license http://opensource.org/licenses/gpl-license.php GNU Public License 
*
*/

/**
*/
if ( !defined('IN_PHPBB') )
{
	die('Hacking attempt');
	exit;
}

/**
* @package attachment_mod
* Class for Private Messaging
*/
class attach_pm extends attach_parent
{
	var $compose_database = null;

	function handle_attachments($mode)
	{
		global $db, $privmsg_id;
		require_once dirname(__DIR__) . '/includes/functions_pm_compose_attachments.php';
		$original_db = $db;
		$lock = attach_require_mutation_lock($original_db);
		try
		{
			$this->compose_database = new PhpbbPmComposeDatabase($lock->connection, $mode, $privmsg_id);
			$db = $this->compose_database;
			$this->validate_compose_attachments();
			return parent::handle_attachments($mode);
		}
		finally { $db = $original_db; $this->compose_database = null; $lock->release(); }
	}

	function validate_compose_attachments()
	{
		$db = $this->compose_database;
		if (!$db || count($this->attachment_list) !== count($this->attachment_id_list)) { phpbb_acl_error('PM_journal_changed'); }
		foreach ($this->attachment_list as $i=>$filename)
		{
			if (!isset($this->attachment_id_list[$i]) || !is_string($filename)) { phpbb_acl_error('PM_journal_changed'); }
			$id = $this->attachment_id_list[$i];
			if ($id === 0 || $id === '0')
			{
				phpbb_pm_staged_attachment_name($filename, $db->actor);
				if (attach_pm_stage_is_claimed($db, $filename)) { phpbb_acl_error('PM_write_pending'); }
				attach_require_unpublished_file($db, $filename);
			}
			else
			{
				$ids = attach_delete_id_array(array($id));
				if (!$ids || !$db->message_id || !phpbb_acl_rows($db, 'SELECT a.attach_id FROM ' . ATTACHMENTS_TABLE . ' a,' . ATTACHMENTS_DESC_TABLE
					. ' d WHERE a.attach_id = ' . $ids[0] . ' AND d.attach_id = a.attach_id AND a.privmsgs_id = ' . $db->message_id
					. ' AND a.post_id = 0 AND a.user_id_1 = ' . $db->actor . " AND HEX(d.physical_filename) = HEX('" . $db->sql_escape($filename) . "')")) { phpbb_acl_error('Not_Authorised'); }
			}
		}
		// Validate every selected action before uploading or changing any entry.
		foreach (array('update_attachment','del_attachment','del_thumbnail') as $action)
		{
			if (!isset($_POST[$action])) { continue; }
			if (!is_array($_POST[$action]) || !$_POST[$action] || ($action === 'update_attachment' && count($_POST[$action]) !== 1)) { phpbb_acl_error('PM_journal_changed'); }
			foreach ($_POST[$action] as $key=>$value)
			{
				if (!is_scalar($value)) { phpbb_acl_error('PM_journal_changed'); }
				if ($action === 'update_attachment')
				{
					$ids=attach_delete_id_array(array($key));
					if (!$ids || !$db->message_id || !in_array((string)$ids[0],array_map('strval',$this->attachment_id_list),true)) { phpbb_acl_error('Not_Authorised'); }
				}
				elseif (!is_string($key) || !in_array($key,$this->attachment_list,true)) { phpbb_acl_error('Not_Authorised'); }
			}
		}
	}

	function prepare_physical_filename()
	{
		if (!$this->compose_database) { phpbb_acl_error('Not_Authorised'); }
		$this->compose_database->require_write();
		$this->attach_filename = 'pm_' . $this->compose_database->actor . '_' . bin2hex(phpbb_random_bytes(16)) . '.' . strtolower($this->extension);
		phpbb_pm_staged_attachment_name($this->attach_filename, $this->compose_database->actor);
	}

	function replace_stored_attachment($attachment_id, $old, $metadata)
	{
		require_once dirname(__DIR__) . '/includes/functions_pm_attachment_edit.php';
		return phpbb_pm_edit_attachment($this->compose_database,$attachment_id,$metadata,true);
	}

	function remove_stored_thumbnail($attachment_id)
	{
		require_once dirname(__DIR__) . '/includes/functions_pm_attachment_edit.php';
		return phpbb_pm_edit_attachment($this->compose_database,$attachment_id,array('thumbnail'=>0));
	}

	function delete_stored_attachment($message_id, $attachment_id)
	{
		if (!$this->compose_database || (int)$message_id !== $this->compose_database->message_id || !$message_id) { phpbb_acl_error('Not_Authorised'); }
		$this->compose_database->require_write();
		$ids = attach_delete_id_array(array($attachment_id));
		if (!$ids) { phpbb_acl_error('PM_journal_changed'); }
		return attach_delete_selected($this->compose_database, array((int)$message_id), $ids, PAGE_PRIVMSGS, 0, false, false);
	}

	function delete_temporary_attachment($filename, $thumbnail = false)
	{
		if (!$this->compose_database) { phpbb_acl_error('Not_Authorised'); }
		$this->compose_database->require_write();
		phpbb_pm_staged_attachment_name($filename, $this->compose_database->actor);
		if (attach_pm_stage_is_claimed($this->compose_database, $filename)) { phpbb_acl_error('PM_write_pending'); }
		if (!is_string($filename) || attach_ftp_listing_entry($filename, '0') === false
			|| phpbb_acl_rows($this->compose_database, 'SELECT attach_id FROM ' . ATTACHMENTS_DESC_TABLE
				. " WHERE physical_filename = '" . $this->compose_database->sql_escape($filename) . "' LIMIT 1")) { phpbb_acl_error('PM_journal_changed'); }
		// The original may have just been removed before its thumbnail. Verify
		// absence idempotently instead of requiring the original to still exist.
		if (!attach_delete_file($filename, $thumbnail)) { phpbb_acl_error('Attachment_delete_incomplete'); }
		return true;
	}

	function move_uploaded_attachment($mode, $file)
	{
		if (!$this->compose_database) { phpbb_acl_error('Not_Authorised'); }
		$this->compose_database->require_write();
		$result = parent::move_uploaded_attachment($mode, $file);
		$this->compose_database->authority();
		return $result;
	}

	/**
	* Constructor
	*/
	function __construct()
	{
		$this->attach_pm();
	}

	function attach_pm()
	{
		global $_POST;

		$this->attach_parent();
		$this->page = PAGE_PRIVMSGS;
	}

	/**
	* Preview Attachments in PM's
	*/
	function preview_attachments()
	{
		global $attach_config, $userdata;

		if (!intval($attach_config['allow_pm_attach']))
		{
			return false;
		}
	
		display_attachments_preview($this->attachment_list, $this->attachment_filesize_list, $this->attachment_filename_list, $this->attachment_comment_list, $this->attachment_extension_list, $this->attachment_thumbnail_list);
	}

	/**
	* Insert an Attachment into a private message
	*/
	function insert_attachment_pm($a_privmsgs_id)
	{
		global $db, $mode, $attach_config, $privmsg_sent_id, $userdata, $to_userdata, $_POST;

		$a_privmsgs_id = (int) $a_privmsgs_id;

		// Insert Attachment ?
		if (!$a_privmsgs_id)
		{
			$a_privmsgs_id = (int) $privmsg_sent_id;
		}
		
		if ($a_privmsgs_id && ($mode == 'post' || $mode == 'reply' || $mode == 'edit') && intval($attach_config['allow_pm_attach']))
		{
			$this->do_insert_attachment('attach_list', 'pm', $a_privmsgs_id);
			$this->do_insert_attachment('last_attachment', 'pm', $a_privmsgs_id);

		}
	}

	// Export the values already processed by handle_attachments. The durable
	// writer consumes this plan on its own connection; this method performs no
	// SQL writes, file operations or lock acquisition of its own.
	function prepared_write_attachments()
	{
		global $attach_config;
		require_once dirname(__DIR__) . '/includes/functions_pm_write_attachments.php';
		$items = array();
		foreach ($this->attachment_list as $i=>$name)
		{
			if (!isset($this->attachment_id_list[$i],$this->attachment_comment_list[$i])) { phpbb_acl_error('PM_journal_changed'); }
			$id = $this->attachment_id_list[$i];
			// The legacy parser uses string "0" for a just-uploaded file and
			// database drivers return stored IDs as decimal strings.
			if ($id === 0 || $id === '0') { $id = 0; }
			else
			{
				$ids = attach_delete_id_array(array($id));
				if (!$ids) { phpbb_acl_error('PM_journal_changed'); }
				$id = $ids[0];
			}
			$entry = array('id'=>$id,'comment'=>$this->attachment_comment_list[$i]);
			if (!$id)
			{
				$entry['physical_filename'] = $name;
				foreach (array('real_filename'=>'attachment_filename_list','extension'=>'attachment_extension_list','mimetype'=>'attachment_mimetype_list',
					'filesize'=>'attachment_filesize_list','filetime'=>'attachment_filetime_list','thumbnail'=>'attachment_thumbnail_list') as $key=>$property)
				{
					if (!isset($this->{$property}[$i])) { phpbb_acl_error('PM_journal_changed'); }
					$entry[$key] = $this->{$property}[$i];
				}
			}
			$items[] = $entry;
		}
		if ($this->post_attach && !isset($_POST['update_attachment']))
		{
			$items[] = array('id'=>0,'physical_filename'=>$this->attach_filename,'real_filename'=>$this->filename,'comment'=>$this->file_comment,
				'extension'=>$this->extension,'mimetype'=>$this->type,'filesize'=>$this->filesize,'filetime'=>$this->filetime,'thumbnail'=>$this->thumbnail);
		}
		if ($items && (empty($attach_config['allow_pm_attach']) || !empty($attach_config['disable_mod']))) { phpbb_acl_error('Not_Authorised'); }
		return phpbb_pm_write_attachment_plan($items);
	}

	/**
	* Duplicate Attachment for sent PM
	*/
	function duplicate_attachment_pm($switch_attachment, $original_privmsg_id, $new_privmsg_id)
	{
		global $db, $privmsg, $folder;
		if (($privmsg['privmsgs_type'] != PRIVMSGS_NEW_MAIL && $privmsg['privmsgs_type'] != PRIVMSGS_UNREAD_MAIL) || $folder != 'inbox' || intval($switch_attachment) != 1) { return; }
		if ((int) $original_privmsg_id <= 0 || (int) $new_privmsg_id <= 0 || (int) $original_privmsg_id === (int) $new_privmsg_id) { return; }
		$lock = attach_require_mutation_lock($db);
		try { $this->duplicate_attachment_pm_locked($lock->connection, $switch_attachment, $original_privmsg_id, $new_privmsg_id); }
		finally { $lock->release(); }
	}

	function duplicate_attachment_pm_locked($db, $switch_attachment, $original_privmsg_id, $new_privmsg_id)
	{
		global $privmsg, $folder;
		if (!attach_message_exists($db, 'pm', $original_privmsg_id) || !attach_message_exists($db, 'pm', $new_privmsg_id)) { return; }

		if (($privmsg['privmsgs_type'] == PRIVMSGS_NEW_MAIL || $privmsg['privmsgs_type'] == PRIVMSGS_UNREAD_MAIL) && $folder == 'inbox' && intval($switch_attachment) == 1)
		{
			$sql = 'SELECT a.*
				FROM ' . ATTACHMENTS_TABLE . ' a, ' . ATTACHMENTS_DESC_TABLE . ' d
				WHERE a.attach_id = d.attach_id AND a.privmsgs_id = ' . (int) $original_privmsg_id;

			if (!($result = $db->sql_query($sql)))
			{
				message_die(GENERAL_ERROR, 'Couldn\'t query Attachment Table', '', __LINE__, __FILE__, $sql);
			}
			$rows = $db->sql_fetchrowset($result);
			$num_rows = $db->sql_numrows($result);
			$db->sql_freeresult($result);

			if ($num_rows > 0)
			{
				for ($i = 0; $i < $num_rows; $i++)
				{
					$sql_ary = array(
						'attach_id'		=> (int) $rows[$i]['attach_id'],
						'post_id'		=> (int) $rows[$i]['post_id'],
						'privmsgs_id'	=> (int) $new_privmsg_id,
						'user_id_1'		=> (int) $rows[$i]['user_id_1'],
						'user_id_2'		=> (int) $rows[$i]['user_id_2'],
					);

					$sql = 'INSERT INTO ' . ATTACHMENTS_TABLE . ' ' . attach_mod_sql_build_array('INSERT', $sql_ary); 

					if (!($result = $db->sql_query($sql)))
					{
						message_die(GENERAL_ERROR, 'Couldn\'t store Attachment for sent Private Message', '', __LINE__, __FILE__, $sql);
					}
				}

				$sql = 'UPDATE ' . PRIVMSGS_TABLE . '
					SET privmsgs_attachment = 1
					WHERE privmsgs_id = ' . (int) $new_privmsg_id;

				if (!($db->sql_query($sql)))
				{
					message_die(GENERAL_ERROR, 'Unable to update Private Message Table.', '', __LINE__, __FILE__, $sql);
				}
			}
		}
	}

	/**
	* Delete Attachments out of selected Private Message(s)
	*/
	function delete_all_pm_attachments($mark_list)
	{
		global $confirm, $delete_all;

		if (sizeof($mark_list))
		{
			$delete_sql_id = '';
			for ($i = 0; $i < sizeof($mark_list); $i++)
			{
				$delete_sql_id .= (($delete_sql_id != '') ? ', ' : '') . intval($mark_list[$i]);
			}

			if ($delete_all && $confirm)
			{
				delete_attachment($delete_sql_id, 0, PAGE_PRIVMSGS);
			}
		}
	}

	/**
	* Display the Attach Limit Box (move it to displaying.php ?)
	*/ 
	function display_attach_box_limits()
	{
		global $folder, $attach_config, $board_config, $template, $lang, $userdata, $db;

		if (!$attach_config['allow_pm_attach'] && $userdata['user_level'] != ADMIN)
		{
			return;
		}

		$this->get_quota_limits($userdata);

		$pm_filesize_limit = (!$attach_config['pm_filesize_limit']) ? $attach_config['attachment_quota'] : $attach_config['pm_filesize_limit'];

		$pm_filesize_total = get_total_attach_pm_filesize('to_user', (int) $userdata['user_id']);

		$attach_limit_pct = ( $pm_filesize_limit > 0 ) ? round(( $pm_filesize_total / $pm_filesize_limit ) * 100) : 0;
		$attach_limit_img_length = ( $pm_filesize_limit > 0 ) ? round(( $pm_filesize_total / $pm_filesize_limit ) * $board_config['privmsg_graphic_length']) : 0;
		if ($attach_limit_pct > 100)
		{
			$attach_limit_img_length = $board_config['privmsg_graphic_length'];
		}
		$attach_limit_remain = ( $pm_filesize_limit > 0 ) ? $pm_filesize_limit - $pm_filesize_total : 100;

		$l_box_size_status = sprintf($lang['Attachbox_limit'], $attach_limit_pct);

		$template->assign_vars(array(
			'ATTACHBOX_LIMIT_IMG_WIDTH'	=> $attach_limit_img_length, 
			'ATTACHBOX_LIMIT_PERCENT'	=> $attach_limit_pct, 

			'ATTACH_BOX_SIZE_STATUS'	=> $l_box_size_status)
		);
	}
	
	/**
	* For Private Messaging
	*/
	function privmsgs_attachment_mod($mode)
	{
		global $attach_config, $template, $lang, $userdata, $_POST, $phpbb_root_path, $phpEx, $db;
		global $confirm, $delete, $delete_all, $post_id, $privmsgs_id, $privmsg_id, $submit, $refresh, $mark_list, $folder;

		if ($folder != 'outbox')
		{
			$this->display_attach_box_limits();
		}
		// Mailbox delete/save are handled by the current owner-scoped controller.
		// Only compose requests may process staged uploads or attachment edits.
		if (!in_array($mode, array('post','reply','quote','edit'), true)) { return; }

		if (!intval($attach_config['allow_pm_attach']))
		{
			return;
		}

		if (!$refresh)
		{
			$add_attachment_box = (!empty($_POST['add_attachment_box'])) ? TRUE : FALSE;
			$posted_attachments_box = (!empty($_POST['posted_attachments_box'])) ? TRUE : FALSE;

			$refresh = $add_attachment_box || $posted_attachments_box;
		}

		$source_id = phpbb_pm_compose_attachment_source($db, $mode, $privmsg_id);
		$original_id = $privmsg_id;
		$original_post_id = $post_id;
		try
		{
			$privmsg_id = $source_id;
			$post_id = $source_id;
			$result = $this->handle_attachments($mode === 'quote' ? 'reply' : $mode);
		}
		finally { $privmsg_id = $original_id; $post_id = $original_post_id; }

		if ($result === false)
		{
			return;
		}


		if ($submit || $refresh || $mode != '')
		{
			$this->display_attachment_bodies();
		}
	}
}

/**
* Entry Point
*/
function execute_privmsgs_attachment_handling($mode)
{
	global $attachment_mod;

	$attachment_mod['pm'] = new attach_pm();
	
	if ($mode != 'read')
	{
		$attachment_mod['pm']->privmsgs_attachment_mod($mode);
	}
}

?>
