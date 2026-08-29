<?php
/*
  paFileDB 3.0
  ©2001/2002 PHP Arena
  Written by Todd
  todd@phparena.net
  http://www.phparena.net
  Keep all copyright links on the script visible
  Please read the license included with this script for more information.
*/

class pafiledb_download extends pafiledb_public
{
	function main($action = false)
	{
		global $_REQUEST, $lang, $db, $pafiledb_user, $pafiledb_config, $board_config, $phpEx, $userdata;
		global $phpbb_root_path, $_SERVER, $pafiledb_functions;

		$file_id = 0;
		if (isset($_REQUEST['file_id']) && is_scalar($_REQUEST['file_id']))
		{
			$file_id = intval($_REQUEST['file_id']);
		}
		else if ($action != '' && is_scalar($action))
		{
			$file_id_array = explode('=', (string) $action, 2);
			$file_id = isset($file_id_array[1]) ? intval($file_id_array[1]) : 0;
		}
		if ($file_id <= 0)
		{
			message_die(GENERAL_MESSAGE, $lang['File_not_exist']);
		}

		$mirror_id = (isset($_REQUEST['mirror_id']) && is_scalar($_REQUEST['mirror_id'])) ? intval($_REQUEST['mirror_id']) : false;

		$sql = 'SELECT *
			FROM ' . PA_FILES_TABLE . " AS f
			WHERE f.file_id = $file_id";

		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, 'Couldnt select download', '', __LINE__, __FILE__, $sql);
		}

		//=========================================================================
		// Id doesn't match with any file in the database another nice error message
		//=========================================================================

		if(!$file_data = $db->sql_fetchrow($result))
		{
			message_die(GENERAL_MESSAGE, $lang['File_not_exist']);
		}

		$db->sql_freeresult($result);

		$can_view_unapproved = $userdata['user_level'] == ADMIN ||
			!empty($this->auth[$file_data['file_catid']]['auth_mod']) ||
			($userdata['session_logged_in'] && intval($file_data['user_id']) === intval($userdata['user_id']));
		if (empty($file_data['file_approved']) && !$can_view_unapproved)
		{
			message_die(GENERAL_MESSAGE, $lang['File_not_exist']);
		}

		//=========================================================================
		// Check if the user is authorized to download the file
		//=========================================================================

		if( (!$this->auth[$file_data['file_catid']]['auth_download']) )
		{
			if ( !$userdata['session_logged_in'] )
			{
				redirect(append_sid("login.$phpEx?redirect=dload.$phpEx?action=download&file_id=$file_id", true));
			}

			$message = sprintf($lang['Sorry_auth_download'], $this->auth[$file_data['file_catid']]['auth_download_type']);
			message_die(GENERAL_MESSAGE, $message);
		}

		//=========================================================================
		// Check for hot links
		// Borrowed from Smartor Album mod, thanks Smartor
		//=========================================================================


		$url_referer = trim((string) getenv('HTTP_REFERER'));
		if ($url_referer == '')
		{
			$url_referer = isset($_SERVER['HTTP_REFERER']) && is_scalar($_SERVER['HTTP_REFERER']) ? trim((string) $_SERVER['HTTP_REFERER']) : '';
		}

		if( ($pafiledb_config['hotlink_prevent']) and (!empty($url_referer)) )
		{
			$referer_host = strtolower((string) parse_url($url_referer, PHP_URL_HOST));

			$good_referers = array();

			if ($pafiledb_config['hotlink_allowed'] != '')
			{
				$good_referers = explode(',', $pafiledb_config['hotlink_allowed']);
			}

			$good_referers[] = $board_config['server_name'];

			$errored = TRUE;

			for ($i = 0; $i < count($good_referers); $i++)
			{
				$allowed_host = strtolower(trim((string) $good_referers[$i]));
				if (strpos($allowed_host, '://') !== false)
				{
					$allowed_host = strtolower((string) parse_url($allowed_host, PHP_URL_HOST));
				}
				$allowed_host = preg_replace('/:\\d+$/', '', $allowed_host);

				if ($allowed_host !== '' && ($referer_host === $allowed_host || substr($referer_host, -strlen('.' . $allowed_host)) === '.' . $allowed_host))
				{
					$errored = FALSE;
				}
			}

			if ($errored)
			{
				message_die(GENERAL_MESSAGE, $lang['Directly_linked']);
			}
		}


		$sql = 'SELECT *
			FROM ' . PA_MIRRORS_TABLE . " AS f
			WHERE f.file_id = $file_id
			ORDER BY mirror_id";

		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, 'Couldnt select download', '', __LINE__, __FILE__, $sql);
		}

		$mirrors_data = array();
		while($row = $db->sql_fetchrow($result))
		{
			$mirrors_data[$row['mirror_id']] = $row;
		}

		$db->sql_freeresult($result);

		if(!empty($mirrors_data) && !$mirror_id)
		{
			global $pafiledb_template;
			global $template, $db, $theme, $gen_simple_header, $starttime;

			$this->generate_category_nav($file_data['file_catid']);

			$pafiledb_template->assign_vars(array(
				'L_INDEX' => sprintf($lang['Forum_Index'], $board_config['sitename']),
				'L_MIRRORS' => $lang['Mirrors'],
				'L_MIRROR_LOCATION' => $lang['Mirror_location'],
				'L_DOWNLOAD' => $lang['Download_file'],

				'U_INDEX' => append_sid('index.'.$phpEx),
				'U_DOWNLOAD_HOME' => append_sid('dload.'.$phpEx),

				'FILE_NAME' => pafiledb_html($file_data['file_name']),
				'DOWNLOAD' => $pafiledb_config['settings_dbname'])
			);

			$pafiledb_template->assign_block_vars('mirror_row', array(
				'U_DOWNLOAD' => append_sid('dload.php?action=download&file_id=' . $file_id . '&mirror_id=-1'),
				'MIRROR_LOCATION' => pafiledb_html($board_config['sitename']))
			);

			foreach($mirrors_data as $mir_id => $mirror_data)
			{
				$pafiledb_template->assign_block_vars('mirror_row', array(
					'U_DOWNLOAD' => append_sid('dload.php?action=download&file_id=' . $file_id . '&mirror_id=' . $mir_id),
					'MIRROR_LOCATION' => pafiledb_html($mirror_data['mirror_location']))
				);
			}

			include_once($phpbb_root_path . 'includes/page_header.'.$phpEx);
			$this->display($lang['Download'], 'pa_mirrors_body.tpl');
			include_once($phpbb_root_path . 'includes/page_tail.'.$phpEx);
		}
		elseif((!empty($mirrors_data) && $mirror_id == -1) || (empty($mirrors_data)))
		{
			$real_filename = $file_data['real_name'];
			$physical_filename = $file_data['unique_name'];
			$upload_dir = (!empty($file_data['upload_dir'])) ? $file_data['upload_dir'] : $pafiledb_config['upload_dir'];
			$file_url = $file_data['file_dlurl'];
		}
		elseif($mirror_id > 0 && !empty($mirrors_data[$mirror_id]))
		{
			$real_filename = $mirrors_data[$mirror_id]['real_name'];
			$physical_filename = $mirrors_data[$mirror_id]['unique_name'];
			$upload_dir = (!empty($mirrors_data[$mirror_id]['upload_dir'])) ? $mirrors_data[$mirror_id]['upload_dir'] : $pafiledb_config['upload_dir'];
			$file_url = $mirrors_data[$mirror_id]['file_dlurl'];
		}
		else
		{
			message_die(GENERAL_MESSAGE, 'Mirror doesn\'t exist');
		}


		//=========================================================================
		// Update download counter and the last downloaded date
		//=========================================================================

		$current_time = time();
		$sql = 'UPDATE ' . PA_FILES_TABLE . "
			SET file_dls = file_dls + 1, file_last = $current_time
			WHERE file_id = $file_id";

		if ( !($db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, 'Couldnt Update Files table', '', __LINE__, __FILE__, $sql);
		}

		//=========================================================================
		// Update downloader Info for the given file
		//=========================================================================

		$pafiledb_user->update_downloader_info($file_id);

		if (!empty($file_url))
		{
			$file_url = pafiledb_normalize_remote_url($file_url);
			if ($file_url === false)
			{
				message_die(GENERAL_MESSAGE, 'The stored remote download URL is invalid.');
			}
			pa_redirect($file_url);
		}
		else
		{

			//=========================================================================
			// now send the file to the user so he can enjoy it :D
			//=========================================================================
			send_file_to_browser($real_filename, 'application/force-download', $physical_filename, $phpbb_root_path . $upload_dir);
		}
	}
}

//=========================================================================
// this function Borrowed from Acyd Burn attachment mod, (thanks Acyd for this great mod)
//=========================================================================

function send_file_to_browser($real_filename, $mimetype, $physical_filename, $upload_dir)
{
	global $_SERVER, $HTTP_USER_AGENT, $HTTP_SERVER_VARS, $lang, $db, $pafiledb_functions;

	$physical_filename = basename((string) $physical_filename);
	if ($upload_dir == '')
	{
		$filename = $physical_filename;
	}
	else
	{
		$filename = rtrim($upload_dir, '/\\') . '/' . $physical_filename;
	}

	$gotit = FALSE;


	if (@!file_exists(@$pafiledb_functions->pafiledb_realpath($filename)))
	{
		message_die(GENERAL_ERROR, $lang['Error_no_download']);
	}
	else
	{
		$gotit = TRUE;
		$size = @filesize($filename);
	}




	//
	// Determine the Browser the User is using, because of some nasty incompatibilities.
	// Most of the methods used in this function are from phpMyAdmin. :)
	//
	$user_agent = (!empty($_SERVER['HTTP_USER_AGENT'])) ? $_SERVER['HTTP_USER_AGENT'] : ((!empty($HTTP_SERVER_VARS['HTTP_USER_AGENT'])) ? $HTTP_SERVER_VARS['HTTP_USER_AGENT'] : '');

	if (ereg('Opera(/| )([0-9].[0-9]{1,2})', $user_agent, $log_version))
	{
		$browser_version = $log_version[2];
		$browser_agent = 'opera';
	}
	else if (ereg('MSIE ([0-9].[0-9]{1,2})', $user_agent, $log_version))
	{
		$browser_version = $log_version[1];
		$browser_agent = 'ie';
	}
	else if (ereg('OmniWeb/([0-9].[0-9]{1,2})', $user_agent, $log_version))
	{
		$browser_version = $log_version[1];
		$browser_agent = 'omniweb';
    }
	else if (ereg('(Konqueror/)(.*)(;)', $user_agent, $log_version))
	{
		$browser_version = $log_version[2];
		$browser_agent = 'konqueror';
    }
	else if (ereg('Mozilla/([0-9].[0-9]{1,2})', $user_agent, $log_version) && ereg('Safari/([0-9]*)', $user_agent, $log_version2))
	{
		$browser_version = $log_version[1] . '.' . $log_version2[1];
		$browser_agent = 'safari';
    }
	else if (ereg('Mozilla/([0-9].[0-9]{1,2})', $user_agent, $log_version))
	{
		$browser_version = $log_version[1];
		$browser_agent = 'mozilla';
    }
	else
	{
		$browser_version = 0;
		$browser_agent = 'other';
    }

	$mimetype = ($browser_agent == 'ie' || $browser_agent == 'opera') ? 'application/octetstream' : 'application/octet-stream';
	$real_filename = html_entity_decode(basename((string) $real_filename), ENT_QUOTES, 'UTF-8');
	$real_filename = trim(str_replace(array("\r", "\n", '"', '\\'), '_', $real_filename));
	if ($real_filename === '')
	{
		$real_filename = 'download';
	}

	@ob_end_clean();
	@ini_set('zlib.output_compression', 'Off');
	header('Pragma: public');
	header('Content-Transfer-Encoding: none');

	//
	// Send out the Headers
	//
	header('Content-Type: ' . $mimetype);
	header('Content-Disposition: attachment; filename="' . $real_filename . '"');

	//
	// Now send the File Contents to the Browser
	//
	if ($gotit)
	{
		if ($size)
		{
			header("Content-length: $size");
		}

		$result = @readfile($filename);

		if (!$result)
		{
			return true;
		}
	}
	else
	{
		return false;
	}


	@flush();
	exit();
}

function pa_redirect($file_url)
{
	global $cache, $db;
	$file_url = trim((string) $file_url);
	if ($file_url === '' || preg_match('/[\r\n\x00]/', $file_url))
	{
		message_die(GENERAL_MESSAGE, 'The download URL is invalid.');
	}
	if (isset($db))
	{
		$db->sql_close();
	}

	if(isset($cache))
	{
		$cache->unload();
	}

	// Redirect via an HTML form for PITA webservers
	if (@preg_match('/Microsoft|WebSTAR|Xitami/', getenv('SERVER_SOFTWARE')))
	{
		header('Refresh: 0; URL=' . $file_url);
		$safe_url = htmlspecialchars($file_url, ENT_QUOTES, 'UTF-8');
		echo '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN"><html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><meta http-equiv="refresh" content="0; url=' . $safe_url . '"><title>Redirect</title></head><body><div align="center">If your browser does not support meta redirection please click <a href="' . $safe_url . '">HERE</a> to be redirected</div></body></html>';
		exit;
	}

	// Behave as per HTTP/1.1 spec for others
	Header("Location: $file_url");
	exit();
}
?>
