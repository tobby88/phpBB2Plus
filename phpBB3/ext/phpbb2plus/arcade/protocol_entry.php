<?php
// Original game binaries use these filenames at the board root.
// Initialise the same native session as app.php before dispatching the extension service.
if (!defined('PHPBB2PLUS_ARCADE_ENDPOINT')) { http_response_code(404); return; }
define('IN_PHPBB', true);
$phpbb_root_path = defined('PHPBB_ROOT_PATH') ? PHPBB_ROOT_PATH : './';
$phpEx = 'php';
require $phpbb_root_path . 'common.php';
$user->session_begin();
$auth->acl($user->data);
$user->setup('app');
if (!$phpbb_container->get('ext.manager')->is_enabled('phpbb2plus/arcade')) { http_response_code(404); return; }
$phpbb_container->get('phpbb2plus.arcade.protocol')->handle(PHPBB2PLUS_ARCADE_ENDPOINT)->send();
