<?php
/*
  paFileDB 3.1
  ©2001/2002 PHP Arena
  Written by Todd
  todd@phparena.net
  http://www.phparena.net
  Keep all copyright links on the script visible
  Please read the license included with this script for more information.
  This script was programmed by Andrew Langland <andy@razza.org>
*/
if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }

if( !empty($setmodules) )
{
	$file = basename(__FILE__);
	$module['Download']['Fchecker'] = $file;
	return;
}

$phpbb_root_path = "./../";

require($phpbb_root_path . 'extension.inc');

require('./pagestart.' . $phpEx);

include($phpbb_root_path . 'language/lang_' . $board_config['default_lang'] . '/lang_admin_pafiledb.' . $phpEx);

include($phpbb_root_path . 'pafiledb/pafiledb_common.'.$phpEx);

$upload_dir = isset($pafiledb_config['upload_dir']) ? trim((string) $pafiledb_config['upload_dir'], '/\\') . '/' : 'pafiledb/uploads/';
$screenshot_dir = isset($pafiledb_config['screenshots_dir']) ? trim((string) $pafiledb_config['screenshots_dir'], '/\\') . '/' : 'pafiledb/images/screenshots/';
$this_dir = $phpbb_root_path . $upload_dir;
$screenshot_path = $phpbb_root_path . $screenshot_dir;
$html_path = get_formated_url() . '/' . $upload_dir;
$html_screenshot_path = get_formated_url() . '/' . $screenshot_dir;

$safety = 0;
if( isset($_GET['safety']) || isset($_POST['safety']) )
{
	$safety_value = (isset($_POST['safety']) && is_scalar($_POST['safety'])) ? $_POST['safety'] : ((isset($_GET['safety']) && is_scalar($_GET['safety'])) ? $_GET['safety'] : 0);
	$safety = (intval($safety_value) === 1) ? 1 : 0;
}

$template->set_filenames(array(
    	'admin' => 'admin/pa_admin_file_checker.tpl')
);

$template->assign_vars(array(
	'L_FILE_CHECKER' => $lang['File_checker'],
	'L_FCHECKER_EXPLAIN' => $lang['File_checker_explain'])
);

if ($safety == 1)
{
	$saved = 0;

	$template->assign_block_vars("check", array());

	$template->assign_vars(array(
		'L_FILE_CHECKER_SP1' => $lang['Checker_sp1'])
	);

	$sql = "SELECT file_id, file_dlurl, unique_name, file_dir FROM " . PA_FILES_TABLE;

	if ( !($overall_result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, 'Couldnt Query info', '', __LINE__, __FILE__, $sql);
	}

	while ($temp = $db->sql_fetchrow($overall_result))
	{
		$temp_dlurl = $temp['file_dlurl'];
		$temp_name = basename(str_replace('\\', '/', (string) $temp['unique_name']));
		if ($temp_name === '' || $temp_name !== $temp['unique_name'] || trim(str_replace('\\', '/', (string) $temp['file_dir']), '/') . '/' !== trim(str_replace('\\', '/', $upload_dir), '/') . '/')
		{
			continue;
		}

		if (!is_file($this_dir . $temp_name))
		{
/*			$sql = "DELETE FROM " . PA_FILES_TABLE . " WHERE file_dlurl = '" . $temp_dlurl . "'";
			if ( !($db->sql_query($sql)) )
			{
				message_die(GENERAL_ERROR, 'Couldnt Query info', '', __LINE__, __FILE__, $sql);
			}*/
			$template->assign_block_vars("check.check_step1", array(
				'DEL_DURL' => phpbb_admin_html($temp_dlurl))
			);
		}
	}

	$template->assign_vars(array(
		'L_FILE_CHECKER_SP2' => $lang['Checker_sp2'])
	);
	$sql = "SELECT file_id, file_ssurl FROM " . PA_FILES_TABLE;

	if ( !($overall_result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, 'Couldnt Query info', '', __LINE__, __FILE__, $sql);
	}
	while ($temp = $db->sql_fetchrow($overall_result))
	{
		$temp_ssurl = $temp['file_ssurl'];
		$temp_file_id = $temp['file_id'];
		if (substr($temp_ssurl, 0, strlen($html_screenshot_path)) !== $html_screenshot_path)
		{
			continue;
		}
		$temp_ssname = substr($temp_ssurl, strlen($html_screenshot_path));
		if ($temp_ssname === '' || basename(str_replace('\\', '/', $temp_ssname)) !== $temp_ssname)
		{
			continue;
		}

		if (!is_file($screenshot_path . $temp_ssname))
		{
			/*$sql = "UPDATE " . PA_FILES_TABLE . " SET file_ssurl='' WHERE file_id = '" . $temp_file_id . "'";

			if ( !($db->sql_query($sql)) )
			{
				message_die(GENERAL_ERROR, 'Couldnt Query info', '', __LINE__, __FILE__, $sql);
			}*/

			$template->assign_block_vars("check.check_step2", array(
				'DEL_SSURL' => $temp_file_id)
			);
		}
	}

	$template->assign_vars(array(
		'L_FILE_CHECKER_SP3' => $lang['Checker_sp3'])
	);

	$scan_directories = array(
		array('path' => $this_dir, 'type' => 'upload'),
		array('path' => $screenshot_path, 'type' => 'screenshot')
	);
	foreach ($scan_directories as $scan_directory)
	{
		$files = @opendir($scan_directory['path']);
		if (!$files)
		{
			continue;
		}
		while (($temp = readdir($files)) !== false)
		{
			if ($temp === '.' || $temp === '..' || basename($temp) !== $temp || !is_file($scan_directory['path'] . $temp))
			{
				continue;
			}

			$temp_sql = $db->sql_escape($temp);
			if ($scan_directory['type'] === 'upload')
			{
				$sql = 'SELECT file_id FROM ' . PA_FILES_TABLE . " WHERE unique_name = '$temp_sql' UNION SELECT mirror_id FROM " . PA_MIRRORS_TABLE . " WHERE unique_name = '$temp_sql'";
			}
			else
			{
				$url_sql = $db->sql_escape($html_screenshot_path . $temp);
				$sql = 'SELECT file_id FROM ' . PA_FILES_TABLE . " WHERE file_ssurl = '$url_sql'";
			}
			if (!($result = $db->sql_query($sql)))
			{
				message_die(GENERAL_ERROR, 'Couldnt Query info', '', __LINE__, __FILE__, $sql);
			}
			$numhits = $db->sql_numrows($result);
			$db->sql_freeresult($result);

			if (!$numhits)
			{
				$saved += max(0, intval(@filesize($scan_directory['path'] . $temp)));
				$template->assign_block_vars("check.check_step3", array(
					'DEL_FILE' => phpbb_admin_html($temp))
				);
			}
		}
		closedir($files);
	}

	if($saved == 0)
	{
		$saved = "N/A";
	}
	elseif($saved >= 1073741824)
	{
		$saved = round($saved / 1073741824 * 100) / 100 . " Giga Byte";
	}
	elseif($saved >= 1048576)
	{
		$saved = round($saved / 1048576 * 100) / 100 . " Mega Byte";
	}
	elseif($saved >= 1024)
	{
		$saved = round($saved / 1024 * 100) / 100 . " Kilo Byte";
	}
	else
	{
		$saved = $saved . " Bytes";
	}

	$template->assign_vars(array(
		'L_FILE_CHECKER_SAVED' => $lang['Checker_saved'],
		'SAVED' => $saved)
	);
	$template->pparse('admin');
}
else
{
	$template->assign_block_vars("perform", array());

	$lang['File_saftey'] = str_replace("{html_path}", phpbb_admin_html($html_path), $lang['File_saftey']);

	$template->assign_vars(array(
		'L_FILE_CHECKER' => $lang['File_checker'],
  		'L_FILE_PERFORM' => $lang['File_checker_perform'],
		'L_FILE_SAFTEY' => $lang['File_saftey'])
	);

    $template->pparse('admin');
}

include('./page_footer_admin.'.$phpEx);

?>
