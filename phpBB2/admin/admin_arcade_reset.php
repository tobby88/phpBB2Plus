<?php
/*************************************************************************** 
 *                          admin_arcade_reset.php 
 *                            ------------------- 
 *   begin                : 08/01/04
 *   copyright            : (C) 2005 Minesh Mistry & Ebaby
 *   website 1            : Support: www.phpbb-amod.co.uk
 *   website 2            : Demo and live site: www.gamelounge.co.uk
 *   version              : 1.0.1
 *   history              : Original by version Ebaby, Made in ACP panel By Minesh
 * 
 *   note: removing the original copyright is illegal even you have modified 
 *         the code.  Just append yours if you have modified it. 
 ***************************************************************************/ 

/*************************************************************************** 
 * 
 *   This program is free software; you can redistribute it and/or modify 
 *   it under the terms of the GNU General Public License as published by 
 *   the Free Software Foundation; either version 2 of the License, or 
 *   (at your option) any later version. 
 * 
 ***************************************************************************/
 
if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }
$phpbb_root_path = './../';

if( !empty($setmodules) )
{
	return;
}

require($phpbb_root_path . 'extension.inc');
require('pagestart.' . $phpEx);

$mode = (isset($HTTP_POST_VARS['mode']) && is_scalar($HTTP_POST_VARS['mode'])) ? (string) $HTTP_POST_VARS['mode'] : '';
$reset_queries = array(
	'reset_played' => "UPDATE " . $table_prefix . "ina_games SET played = 0",
	'reset_last_player' => "UPDATE " . $table_prefix . "ina_cat SET last_player = 0",
	'reset_last_time' => "UPDATE " . $table_prefix . "ina_cat SET last_time = 0",
);

if (isset($reset_queries[$mode]))
{
	phpbb_admin_require_post_session();

	if (!$db->sql_query($reset_queries[$mode]))
	{
		message_die(GENERAL_ERROR, 'Unable to reset the selected Arcade statistic.');
	}

	message_die(GENERAL_MESSAGE, 'The selected Arcade statistic was reset successfully.<br /><br /><a href="' . append_sid('admin_arcade_reset.' . $phpEx) . '">Return to Arcade resets</a>');
}

$form_action = append_sid('admin_arcade_reset.' . $phpEx);
$session_field = '<input type="hidden" name="sid" value="' . htmlspecialchars((string) $userdata['session_id']) . '" />';

echo '
  <table width="99%" cellpadding="4" cellspacing="1" border="0" align="center" class="bodyline">
    <tr>
      <th width="20%" colspan="2" class="thHead">Reset Arcade Plays </th>
    </tr>
    <tr>
      <td colspan="2" class="row1"><div align="center"><span class="gensmall">Reset only the statistic you intentionally want to clear.</span><br />
      </div></td>
    </tr>
    <tr>
      <td width="50%" class="row2"><div align="right">Reset Played Number </div></td>
      <td width="50%" class="row3"><form method="post" action="' . $form_action . '">' . $session_field . '<input type="hidden" name="mode" value="reset_played" /><input type="submit" class="mainoption" value="Go!" onclick="return confirm(\'Reset the played counter for every Arcade game?\');" /></form></td>
    </tr>
    <tr>
      <td width="50%" class="row2"><div align="right">Reset Last Player</div></td>
      <td width="50%" class="row3"><form method="post" action="' . $form_action . '">' . $session_field . '<input type="hidden" name="mode" value="reset_last_player" /><input type="submit" class="mainoption" value="Go!" onclick="return confirm(\'Reset the last-player statistic for every Arcade category?\');" /></form></td>
    </tr>
    <tr>
      <td width="50%" class="row2"><div align="right">Reset Last Time</div></td>
      <td width="50%" class="row3"><form method="post" action="' . $form_action . '">' . $session_field . '<input type="hidden" name="mode" value="reset_last_time" /><input type="submit" class="mainoption" value="Go!" onclick="return confirm(\'Reset the last-played time for every Arcade category?\');" /></form></td>
    </tr>
    <tr>
      <td colspan="2" align="center" class="catBottom">&nbsp;</td>
    </tr>
  </table>
  <table width="100%"  border="0" cellspacing="0" cellpadding="0">
  <tr>
    <td><div align="center"><span class=gensmall>Original version By Ebaby | Made in ACP panel By Minesh </SPAN></div></td>
  </tr>
</table>
';

// Generate footer
include('page_footer_admin.' . $phpEx);

?>
