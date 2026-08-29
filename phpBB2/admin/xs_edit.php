<?php

if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }
$phpbb_root_path = './../';
require($phpbb_root_path . 'extension.inc');
require('./pagestart.' . $phpEx);

message_die(GENERAL_MESSAGE, isset($lang['xs_edit_templates_disabled']) ? $lang['xs_edit_templates_disabled'] : 'The web-based template editor has been disabled. Manage template files through the source repository and deployment process.');

?>
