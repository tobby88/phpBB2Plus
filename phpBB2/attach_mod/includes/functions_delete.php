<?php
/** 
*
* @package attachment_mod
* @version $Id: functions_delete.php,v 1.1 2005/11/05 12:23:33 acydburn Exp $
* @copyright (c) 2002 Meik Sievertsen
* @license http://opensource.org/licenses/gpl-license.php GNU Public License 
*
*/

/**
* All Attachment Functions processing the Deletion Process
*/

/**
* Delete Attachment(s) from post(s) (intern)
*/
function attach_delete_id_array($value)
{
	// Destructive selections must be validated in full, never truncated or
	// partially accepted. Only the caller may interpret integer 0 as a sentinel.
	if (is_int($value) || is_string($value))
	{
		if (is_string($value) && trim($value) === '') { return array(); }
		$value = explode(',', (string) $value);
	}
	if (!is_array($value)) { return false; }
	$ids = array();
	$maximum = (string) PHP_INT_MAX;
	foreach ($value as $part)
	{
		if (!is_int($part) && !is_string($part)) { return false; }
		$part = trim((string) $part);
		if (!preg_match('/^[0-9]+$/D', $part)) { return false; }
		$part = ltrim($part, '0');
		if ($part === '' || strlen($part) > strlen($maximum) ||
			(strlen($part) === strlen($maximum) && strcmp($part, $maximum) > 0))
		{
			return false;
		}
		$id = (int) $part;
		$ids[$id] = $id;
	}
	return array_values($ids);
}

// Deletion is idempotent only when a complete inventory confirms that the
// regular file is already absent. A failed/unavailable listing is not proof.
function attach_delete_file($filename, $mode = false)
{
	if (attach_ftp_listing_entry($filename, '0') === false ||
		in_array(strtolower($filename), array('index.php', '.htaccess', '.htpasswd'), true))
	{
		return false;
	}
	try
	{
		if (unlink_attach($filename, $mode, true)) { return true; }
		$files = attach_storage_file_entries($mode, true);
		if ($files === false) { return false; }
		$name = $mode == MODE_THUMBNAIL ? 't_' . $filename : $filename;
		foreach ($files as $entry) { if ($entry['name'] === $name) { return false; } }
		return true;
	}
	catch (Exception $exception) { return false; }
	catch (Error $exception) { return false; }
}

function delete_attachment($post_id_array = 0, $attach_id_array = 0, $page = 0, $user_id = 0)
{
	global $db, $lang;

	$discover_posts = ($post_id_array === 0);
	$discover_attachments = ($attach_id_array === 0);
	$post_id_array = $discover_posts ? array() : attach_delete_id_array($post_id_array);
	$attach_id_array = $discover_attachments ? array() : attach_delete_id_array($attach_id_array);
	$user_ids = ($user_id === 0) ? array() : attach_delete_id_array($user_id);
	if ($post_id_array === false || $attach_id_array === false || $user_ids === false ||
		($user_id !== 0 && count($user_ids) !== 1))
	{
		message_die(GENERAL_ERROR, $lang['Error_deleted_attachments']);
	}
	$user_id = $user_ids ? $user_ids[0] : 0;

	// No implicit "all attachments" operation, including in the PM context.
	if (($discover_posts && $discover_attachments) ||
		(!$discover_posts && !$post_id_array) || (!$discover_attachments && !$attach_id_array))
	{
		return;
	}

	if ($discover_posts)
	{
		$post_id_array = array();

		// Get the post_ids to fill the array
		if ($page == PAGE_PRIVMSGS)
		{
			$p_id = 'privmsgs_id';
		}
		else
		{
			$p_id = 'post_id';
		}

		$sql = "SELECT $p_id 
			FROM " . ATTACHMENTS_TABLE . '
				WHERE attach_id IN (' . implode(', ', $attach_id_array) . ") AND $p_id > 0
			GROUP BY $p_id";

		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, 'Could not select ids', '', __LINE__, __FILE__, $sql);
		}

		$num_post_list = $db->sql_numrows($result);

		if ($num_post_list == 0)
		{
			$db->sql_freeresult($result);
			return;
		}

		while ($row = $db->sql_fetchrow($result))
		{
			$post_id_array[] = intval($row[$p_id]);
		}
		$db->sql_freeresult($result);
	}
		
	if (!sizeof($post_id_array))
	{
		return;
	}

	// First of all, determine the post id and attach_id
	if ($discover_attachments)
	{
		$attach_id_array = array();

		// Get the attach_ids to fill the array
		if ($page == PAGE_PRIVMSGS)
		{
			$whereclause = 'WHERE privmsgs_id IN (' . implode(', ', $post_id_array) . ')';
		}
		else
		{
			$whereclause = 'WHERE post_id IN (' . implode(', ', $post_id_array) . ')';
		}
			
		$sql = 'SELECT attach_id 
			FROM ' . ATTACHMENTS_TABLE . " $whereclause 
			GROUP BY attach_id";

		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, 'Could not select Attachment Ids', '', __LINE__, __FILE__, $sql);
		}

		$num_attach_list = $db->sql_numrows($result);

		if ($num_attach_list == 0)
		{
			$db->sql_freeresult($result);
			return;
		}

		while ($row = $db->sql_fetchrow($result))
		{
			$attach_id_array[] = (int) $row['attach_id'];
		}
		$db->sql_freeresult($result);
	}
	
	if (!sizeof($attach_id_array))
	{
		return;
	}

	if ($page == PAGE_PRIVMSGS)
	{
		$sql_id = 'privmsgs_id';
		if ($user_id)
		{
			$post_id_array_2 = array();

			$sql = 'SELECT privmsgs_id, privmsgs_type, privmsgs_to_userid, privmsgs_from_userid
				FROM ' . PRIVMSGS_TABLE . '
				WHERE privmsgs_id IN (' . implode(', ', $post_id_array) . ')';
			if ( !($result = $db->sql_query($sql)) )
			{
				message_die(GENERAL_ERROR, 'Couldn\'t get Privmsgs Type', '', __LINE__, __FILE__, $sql);
			}

			while ($row = $db->sql_fetchrow($result))
			{
				$privmsgs_type = $row['privmsgs_type'];
								
				if ($privmsgs_type == PRIVMSGS_READ_MAIL || $privmsgs_type == PRIVMSGS_NEW_MAIL || $privmsgs_type == PRIVMSGS_UNREAD_MAIL)
				{
					if ($row['privmsgs_to_userid'] == $user_id)
					{
						$post_id_array_2[] = $row['privmsgs_id'];
					}
				}
				else if ($privmsgs_type == PRIVMSGS_SENT_MAIL)
				{
					if ($row['privmsgs_from_userid'] == $user_id)
					{
						$post_id_array_2[] = $row['privmsgs_id'];
					}
				}
				else if ($privmsgs_type == PRIVMSGS_SAVED_OUT_MAIL)
				{
					if ($row['privmsgs_from_userid'] == $user_id)
					{
						$post_id_array_2[] = $row['privmsgs_id'];
					}
				}
				else if ($privmsgs_type == PRIVMSGS_SAVED_IN_MAIL)
				{
					if ($row['privmsgs_to_userid'] == $user_id)
					{
						$post_id_array_2[] = $row['privmsgs_id'];
					}
				}
			}
			$db->sql_freeresult($result);
			$post_id_array = $post_id_array_2;
		}
	}
	else
	{
		$sql_id = 'post_id';
	}

	$delete_incomplete = false;
	if (sizeof($post_id_array) && sizeof($attach_id_array))
	{
		// Only attachments currently linked to this selection are deletion
		// candidates. An unrelated/orphan ID must not trigger physical cleanup.
		$sql = 'SELECT attach_id FROM ' . ATTACHMENTS_TABLE . '
			WHERE attach_id IN (' . implode(', ', $attach_id_array) . ")
				AND $sql_id IN (" . implode(', ', $post_id_array) . ')
			GROUP BY attach_id';
		if (!($result = $db->sql_query($sql)))
		{
			message_die(GENERAL_ERROR, $lang['Error_deleted_attachments'], '', __LINE__, __FILE__, $sql);
		}
		$attach_id_array = array();
		while ($row = $db->sql_fetchrow($result))
		{
			$attach_id_array[] = (int) $row['attach_id'];
		}
		$db->sql_freeresult($result);
		if (!$attach_id_array) { return; }

		$sql = 'DELETE FROM ' . ATTACHMENTS_TABLE . ' 
			WHERE attach_id IN (' . implode(', ', $attach_id_array) . ") 
				AND $sql_id IN (" . implode(', ', $post_id_array) . ')';

		if ( !($db->sql_query($sql)) )   
		{
			message_die(GENERAL_ERROR, $lang['Error_deleted_attachments'], '', __LINE__, __FILE__, $sql);   
		} 
	
		for ($i = 0; $i < sizeof($attach_id_array); $i++)
		{
			$sql = 'SELECT attach_id 
				FROM ' . ATTACHMENTS_TABLE . ' 
					WHERE attach_id = ' . (int) $attach_id_array[$i];
			
			if ( !($result = $db->sql_query($sql)) )
			{
				message_die(GENERAL_ERROR, 'Could not select Attachment Ids', '', __LINE__, __FILE__, $sql);
			}
			
			$num_rows = $db->sql_numrows($result);
			$db->sql_freeresult($result);

			if ($num_rows == 0)
			{
				$sql = 'SELECT attach_id, physical_filename, thumbnail
					FROM ' . ATTACHMENTS_DESC_TABLE . '
					WHERE attach_id = ' . (int) $attach_id_array[$i];
	
				if ( !($result = $db->sql_query($sql)) )
				{
					message_die(GENERAL_ERROR, 'Couldn\'t query attach description table', '', __LINE__, __FILE__, $sql);
				}
				
				$num_rows = $db->sql_numrows($result);

				if ($num_rows != 0)
				{
					$num_attach = $num_rows;
					$attachments = $db->sql_fetchrowset($result);
					$db->sql_freeresult($result);

					// delete attachments
					for ($j = 0; $j < $num_attach; $j++)
					{
						// Keep the main file and description if thumbnail removal
						// fails. Persist successful thumbnail cleanup before trying
						// the main file, so a later recovery knows what remains.
						if (intval($attachments[$j]['thumbnail']) == 1)
						{
							if (!attach_delete_file($attachments[$j]['physical_filename'], MODE_THUMBNAIL))
							{
								$delete_incomplete = true;
								continue;
							}
							$sql = 'UPDATE ' . ATTACHMENTS_DESC_TABLE . ' SET thumbnail = 0 WHERE attach_id = ' . (int) $attachments[$j]['attach_id'];
							if (!$db->sql_query($sql))
							{
								message_die(GENERAL_ERROR, $lang['Error_deleted_attachments'], '', __LINE__, __FILE__, $sql);
							}
						}
						if (!attach_delete_file($attachments[$j]['physical_filename']))
						{
							$delete_incomplete = true;
							continue;
						}
					
						$sql = 'DELETE FROM ' . ATTACHMENTS_DESC_TABLE . '
							WHERE attach_id = ' . (int) $attachments[$j]['attach_id'];

						if ( !($db->sql_query($sql)) )
						{
							message_die(GENERAL_ERROR, $lang['Error_deleted_attachments'], '', __LINE__, __FILE__, $sql);
						}
					}
				}
				else
				{
					$db->sql_freeresult($result);
				}
			}
		}
	}

	// Now Sync the Topic/PM
	if ($page == PAGE_PRIVMSGS)
	{
		for ($i = 0; $i < sizeof($post_id_array); $i++)
		{
			$sql = 'SELECT attach_id 
				FROM ' . ATTACHMENTS_TABLE . ' 
				WHERE privmsgs_id = ' . (int) $post_id_array[$i];

			if ( !($result = $db->sql_query($sql)) )
			{
				message_die(GENERAL_ERROR, 'Couldn\'t query Attachments Table', '', __LINE__, __FILE__, $sql);
			}
			
			$num_rows = $db->sql_numrows($result);
			$db->sql_freeresult($result);

			if ($num_rows == 0)
			{
				$sql = 'UPDATE ' . PRIVMSGS_TABLE . ' SET privmsgs_attachment = 0 
					WHERE privmsgs_id = ' . $post_id_array[$i];

				if ( !($result = $db->sql_query($sql)) )
				{
					message_die(GENERAL_ERROR, 'Couldn\'t update Private Message Attachment Switch', '', __LINE__, __FILE__, $sql);
				}
			}
		}
	}
	else
	{
		if (sizeof($post_id_array))
		{
			$sql = 'SELECT topic_id 
				FROM ' . POSTS_TABLE . ' 
				WHERE post_id IN (' . implode(', ', $post_id_array) . ') 
				GROUP BY topic_id';
		
			if ( !($result = $db->sql_query($sql)) )
			{
				message_die(GENERAL_ERROR, 'Couldn\'t select Topic ID', '', __LINE__, __FILE__, $sql);
			}
	
			while ($row = $db->sql_fetchrow($result))
			{
				attachment_sync_topic($row['topic_id']);
			}
			$db->sql_freeresult($result);
		}
	}
	// Link removal has already happened: synchronize its flags, then report
	// incomplete file cleanup instead of displaying an unconditional success.
	if ($delete_incomplete) { message_die(GENERAL_ERROR, $lang['Attachment_delete_incomplete']); }
}

?>
