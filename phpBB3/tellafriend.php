<?php
// Old suggestions only open the corresponding native form. Previously submitted messages require a fresh form.
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '',array('GET','HEAD'),true)) { header('Allow: GET, HEAD'); http_response_code(405); exit; }
define('IN_PHPBB',true);
$phpbb_root_path='./';
$phpEx='php';
require $phpbb_root_path . 'common.' . $phpEx;
if (!$phpbb_container->get('ext.manager')->is_enabled('phpbb2plus/community')) { http_response_code(404); exit; }
$id=$phpbb_container->get('phpbb2plus.community.recommendations')->legacy_topic($request->variable('link','',true,\phpbb\request\request_interface::GET));
header('Cache-Control: private, no-store');
header('Location: ' . ($id ? './memberlist.php?mode=email&t=' . $id : './app.php/recommend'),true,302);
