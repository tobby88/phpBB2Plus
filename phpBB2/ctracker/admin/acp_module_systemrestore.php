<?php
/**  
* <b>acp_module_systemrestore.php</b><br>
* The ACP Module for the System Restore Feature
* 
* @author Christian Knerr (cback)
* @package ctracker
* @version 5.0.0
* @since 26.07.2006 - 13:29:09
* @copyright (c) 2006 www.cback.de
* 
* @license http://opensource.org/licenses/gpl-license.php GNU Public License 
*/

// Constant check
if ( !defined('IN_PHPBB') || !defined('CTRACKER_ACP') )
{
	die('Hacking attempt!');
}

/*
 * Template File definition
 */
$template->set_filenames(array(
	'ct_body' => 'ctracker/acp/acp_systemrestore.tpl')
);

$mode = phpbb_admin_post_string('mode');
if ( $mode == 'backup')
{
	phpbb_admin_require_post_session();
	$backup_system = new ct_adminfunctions();
	$backup_system->recover_configuration();
	unset($backup_system);
	
	// Send the user the OK message
	$template->assign_block_vars('infobox', array(
				'COLOR'				=> 'DBFFCF',
				'L_MESSAGE_TEXT'	=> $lang['ctracker_rec_succ'])
		);
}
else if ( $mode == 'restore' )
{
	phpbb_admin_require_post_session();
	$backup_system = new ct_adminfunctions();
	$backup_system->restore_configuration();
	unset($backup_system);	
	
	// Send the User the OK message
	$template->assign_block_vars('infobox', array(
				'COLOR'				=> 'DBFFCF',
				'L_MESSAGE_TEXT'	=> $lang['ctracker_rec_succ'])
		);
}

/*
 * Load backup status
 */
$save_status = '';
$saved_now   = false;
$backup = array();
$sql = 'SELECT * FROM ' . CTRACKER_BACKUP . ' WHERE config_name = \'ct_last_backup\'';
if ( !$result = $db->sql_query($sql) )
{
	$save_status = $lang['ctracker_rec_never_saved'];
}	
else
{
	while ( $row = $db->sql_fetchrow($result) )
	{
		$backup[$row['config_name']] = $row['config_value'];
	}
	if (isset($backup['ct_last_backup']) && intval($backup['ct_last_backup']) > 0)
	{
		$saved_now = true;
		$save_status = sprintf($lang['ctracker_rec_last_saved'], date($board_config['default_dateformat'], intval($backup['ct_last_backup'])));
	}
	else
	{
		$save_status = $lang['ctracker_rec_never_saved'];
	}
}

if ($saved_now)
{
	$template->assign_block_vars('restore_available', array());
}
else
{
	$template->assign_block_vars('restore_unavailable', array());
}


/*
 * Send some vars to the template
 */
$template->assign_vars(array(
		'IMG_RECOVERY'		=> $phpbb_root_path . $images['ctracker_recovery'],
		'L_HEADLINE'		=> $lang['ctracker_rec_head'],
		'L_SUBHEADLINE'		=> $lang['ctracker_rec_subhead'],
		'L_BACKUP'			=> $lang['ctracker_rec_backup'],
		'L_RESTORE'			=> ($saved_now)? $lang['ctracker_rec_restore'] : $lang['ctracker_rec_pab'],
		'L_SAVE_STATUS'		=> $save_status,
		
		'S_FORM_ACTION'		=> append_sid('admin_cracker_tracker.' . $phpEx . '?modu=10'),
		'S_FORM_TOKEN'		=> phpbb_admin_session_field())
  );
  

// Generate the page
$template->pparse('ct_body');


?>
