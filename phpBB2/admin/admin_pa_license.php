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

if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }

if( !empty($setmodules) )
{
	$file = basename(__FILE__);
// MX mod
    $module['Download']['License_title'] = $file;
//    $module['Download']['Alicense'] = $file."?license=add";
//    $module['Download']['Elicense'] = $file."?license=edit";
//    $module['Download']['Dlicense'] = $file."?license=delete";
	return;
}

$phpbb_root_path = "./../";

require($phpbb_root_path . 'extension.inc');

require('./pagestart.' . $phpEx);

include($phpbb_root_path . 'pafiledb/pafiledb_common.'.$phpEx);

$row = '';
$template->assign_var('S_FORM_TOKEN', phpbb_admin_session_field());

function pa_license_form_scalar($form, $key)
{
	return (is_array($form) && isset($form[$key]) && is_scalar($form[$key])) ? trim(stripslashes((string) $form[$key])) : '';
}

$license_action = '';
if (isset($_POST['license']) && is_scalar($_POST['license']))
{
	$license_action = (string) $_POST['license'];
}
elseif (isset($_GET['license']) && is_scalar($_GET['license']))
{
	$license_action = (string) $_GET['license'];
}

if (in_array($license_action, array('add', 'edit', 'delete'), true))
{
	$license = $license_action;

	switch($license)
	{
		case 'add':
		{
			$template->set_filenames(array(
				'admin' => 'admin/pa_admin_license_add.tpl')
			);

			$add = (isset($_POST['add']) && is_scalar($_POST['add'])) ? (string) $_POST['add'] : '';

			if ($add == 'do')
			{
				phpbb_admin_require_post_session();
				$form = (isset($_POST['form']) && is_array($_POST['form'])) ? $_POST['form'] : array();
				$name = pa_license_form_scalar($form, 'name');
				$text = pa_license_form_scalar($form, 'text');
				if ($name === '')
				{
					message_die(GENERAL_ERROR, 'A license name is required.');
				}
				$sql = "INSERT INTO " . PA_LICENSE_TABLE . " (license_name, license_text)
					VALUES ('" . $db->sql_escape($name) . "', '" . $db->sql_escape($text) . "')";

				if ( !($db->sql_query($sql)) )
				{
					message_die(GENERAL_ERROR, 'Couldnt Query info', '', __LINE__, __FILE__, $sql);
				}

				$message = $lang['Licenseadded'] . '<br /><br />' . sprintf($lang['Click_return'], '<a href="' . append_sid("admin_pa_license.$phpEx") . '">', '</a>') . '<br /><br />' . sprintf($lang['Click_return_admin_index'], '<a href="' . append_sid("index.$phpEx?pane=right") . '">', '</a>');

				message_die(GENERAL_MESSAGE, $message);
			}

			if (empty($add))
			{
				$template->assign_vars(array(
					'S_ADD_LIC_ACTION' => append_sid("admin_pa_license.$phpEx"),
					'L_ALICENSETITLE' => $lang['Alicensetitle'],
					'L_LICENSEEXPLAIN' => $lang['Licenseexplain'],
					'L_LNAME' => $lang['Lname'],
					'L_LTEXT' => $lang['Ltext'])
				);
			}

			$template->pparse('admin');

			break;
		}

		case 'edit':
		{
			$template->set_filenames(array(
				'admin' => 'admin/pa_admin_license_edit.tpl')
			);

			$edit = (isset($_POST['edit']) && is_scalar($_POST['edit'])) ? (string) $_POST['edit'] : '';

			if ($edit == 'do')
			{
				phpbb_admin_require_post_session();
				$form = (isset($_POST['form']) && is_array($_POST['form'])) ? $_POST['form'] : array();
				$id = (isset($_POST['id']) && is_scalar($_POST['id'])) ? intval($_POST['id']) : 0;
				$name = pa_license_form_scalar($form, 'name');
				$text = pa_license_form_scalar($form, 'text');
				if ($id <= 0 || $name === '')
				{
					message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
				}
				$sql = "UPDATE " . PA_LICENSE_TABLE . " SET license_name = '" . $db->sql_escape($name) .
					"', license_text = '" . $db->sql_escape($text) . "' WHERE license_id = " . $id;

				if ( !($db->sql_query($sql)) )
				{
					message_die(GENERAL_ERROR, 'Couldnt Query info', '', __LINE__, __FILE__, $sql);
				}

				$message = $lang['Licenseedited'] . '<br /><br />' . sprintf($lang['Click_return'], '<a href="' . append_sid("admin_pa_license.$phpEx") . '">', '</a>') . '<br /><br />' . sprintf($lang['Click_return_admin_index'], '<a href="' . append_sid("index.$phpEx?pane=right") . '">', '</a>');

				message_die(GENERAL_MESSAGE, $message);
			}

			if ($edit == 'form')
			{
				$select = (isset($_POST['select']) && is_scalar($_POST['select'])) ? intval($_POST['select']) : 0;
				if ($select <= 0)
				{
					message_die(GENERAL_ERROR, 'Invalid paFileDB license.');
				}

				$sql = "SELECT * FROM " . PA_LICENSE_TABLE . " WHERE license_id = " . $select;

				if ( !($result = $db->sql_query($sql)) )
				{
					message_die(GENERAL_ERROR, 'Couldnt Query info', '', __LINE__, __FILE__, $sql);
				}

				$license = $db->sql_fetchrow($result);
				$db->sql_freeresult($result);
				if (!$license)
				{
					message_die(GENERAL_ERROR, 'Invalid paFileDB license.');
				}

				$text = str_replace("<br>", "\n", $license['license_text']);

				$template->assign_block_vars("license_form", array());

				$template->assign_vars(array(
					'S_EDIT_LIC_ACTION' => append_sid("admin_pa_license.$phpEx"),
					'L_ELICENSETITLE' => $lang['Elicensetitle'],
					'L_LICENSEEXPLAIN' => $lang['Licenseexplain'],
					'L_LNAME' => $lang['Lname'],
					'LICENSE_NAME' => phpbb_admin_html($license['license_name']),
					'TEXT' => phpbb_admin_html($text),
					'SELECT' => $select,
					'L_LTEXT' => $lang['Ltext'])
				);
			}

			if (empty($edit))
			{
				$sql = "SELECT * FROM " . PA_LICENSE_TABLE;

				if ( !($result = $db->sql_query($sql)) )
				{
					message_die(GENERAL_ERROR, 'Couldnt Query info', '', __LINE__, __FILE__, $sql);
				}

				while ($license = $db->sql_fetchrow($result))
				{
					$row .= '<tr><td width="3%" class="row1" align="center" valign="middle"><input type="radio" name="select" value="' . (int) $license['license_id'] . '"></td><td width="97%" class="row1">' . phpbb_admin_html($license['license_name']) . '</td></tr>';
				}

				$template->assign_block_vars("license", array());

				$template->assign_vars(array(
					'S_EDIT_LIC_ACTION' => append_sid("admin_pa_license.$phpEx"),
					'L_ELICENSETITLE' => $lang['Elicensetitle'],
					'L_LICENSEEXPLAIN' => $lang['Licenseexplain'],
					'ROW' => $row)
				);
			}

			$template->pparse('admin');

			break;
		}

		case 'delete':
		{
			$template->set_filenames(array(
				'admin' => 'admin/pa_admin_license_delete.tpl')
			);

			$delete = (isset($_POST['delete']) && is_scalar($_POST['delete'])) ? (string) $_POST['delete'] : '';

			if ($delete == 'do')
			{
				phpbb_admin_require_post_session();
				$select = (isset($_POST['select']) && is_array($_POST['select'])) ? $_POST['select'] : array();

				if (empty($select))
				{
					$message = $lang['lderror'] . '<br /><br />' . sprintf($lang['Click_return'], '<a href="' . append_sid("admin_pa_license.$phpEx?license=delete") . '">', '</a>');

					message_die(GENERAL_MESSAGE, $message);
				}
				else
				{
					$license_ids = array();
					foreach ($select as $key => $value)
					{
						$key = intval($key);
						if ($key > 0) { $license_ids[$key] = $key; }
					}
					foreach ($license_ids as $key)
					{
						$sql = "DELETE FROM " . PA_LICENSE_TABLE . " WHERE license_id = " . $key;

						if ( !($db->sql_query($sql)) )
						{
							message_die(GENERAL_ERROR, 'Couldnt Query info', '', __LINE__, __FILE__, $sql);
						}

						$sql = "UPDATE " . PA_FILES_TABLE . " SET file_license = 0 WHERE file_license = " . $key;

						if ( !($db->sql_query($sql)) )
						{
							message_die(GENERAL_ERROR, 'Couldnt Query info', '', __LINE__, __FILE__, $sql);
						}
					}

					$message = $lang['Ldeleted'] . '<br /><br />' . sprintf($lang['Click_return'], '<a href="' . append_sid("admin_pa_license.$phpEx") . '">', '</a>') . '<br /><br />' . sprintf($lang['Click_return_admin_index'], '<a href="' . append_sid("index.$phpEx?pane=right") . '">', '</a>');

					message_die(GENERAL_MESSAGE, $message);
				}
			}

			if (empty($delete))
			{
				$sql = "SELECT * FROM " . PA_LICENSE_TABLE;

				if ( !($result = $db->sql_query($sql)) )
				{
					message_die(GENERAL_ERROR, 'Couldnt Query info', '', __LINE__, __FILE__, $sql);
				}

				while ($license = $db->sql_fetchrow($result))
				{
					$row .= '<tr><td width="3%" class="row1" align="center" valign="middle"><input type="checkbox" name="select[' . (int) $license['license_id'] . ']" value="yes"></td><td width="97%" class="row1">' . phpbb_admin_html($license['license_name']) . '</td></tr>';
				}

				$template->assign_vars(array(
					'S_DELETE_LIC_ACTION' => append_sid("admin_pa_license.$phpEx"),
					'L_DLICENSETITLE' => $lang['Dlicensetitle'],
					'L_LICENSEEXPLAIN' => $lang['Licenseexplain'],
					'ROW' => $row)
				);

			}

			$template->pparse('admin');

			break;
		}
	}
}
// MX Addon
else
{
		// main
			$template->set_filenames(array(
				'admin' => 'admin/pa_admin_license.tpl')
			);

				$sql = "SELECT * FROM " . PA_LICENSE_TABLE;

				if ( !($result = $db->sql_query($sql)) )
				{
					message_die(GENERAL_ERROR, 'Couldnt Query info', '', __LINE__, __FILE__, $sql);
				}

				while ($license = $db->sql_fetchrow($result))
				{
					$row .= '<tr><td width="80%" class="row1" align="center">' . phpbb_admin_html($license['license_name']) . '</td></tr>';
				}

				$template->assign_vars(array(
					'S_DELETE_LIC_ACTION' => append_sid("admin_pa_license.$phpEx"),
					'L_LICENSETITLE' => $lang['License_title'],
					'L_ALICENSETITLE' => $lang['Alicensetitle'],
					'L_ELICENSETITLE' => $lang['Elicensetitle'],
					'L_DLICENSETITLE' => $lang['Dlicensetitle'],
					'L_LICENSEEXPLAIN' => $lang['Licenseexplain'],
					'ROW' => $row)
				);
			$template->pparse('admin');
}

include('./page_footer_admin.'.$phpEx);
?>
