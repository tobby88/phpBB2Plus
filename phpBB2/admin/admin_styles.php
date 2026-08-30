<?php
/***************************************************************************
 *                              admin_styles.php
 *                            -------------------
 *   Compatibility endpoint for phpBB2's retired built-in style manager.
 ***************************************************************************/

if (!defined('IN_PHPBB'))
{
	define('IN_PHPBB', true);
}

// Register one compatibility entry so the ACP module scanner recognizes this
// endpoint. eXtreme Styles replaces it with its own menu during discovery.
if (!empty($setmodules))
{
	$file = basename(__FILE__);
	$module['Styles']['Manage'] = $file;
	return;
}

$phpbb_root_path = './../';
$no_page_header = true;
require($phpbb_root_path . 'extension.inc');
require('./pagestart.' . $phpEx);

// Preserve old bookmarks without retaining the obsolete manager, including
// its executable theme_info.cfg import and direct GET mutation paths.
redirect(append_sid("xs_frameset.$phpEx?action=menu", true));

?>
