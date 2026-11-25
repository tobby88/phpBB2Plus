<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array('ACL_A_PLUS_GALLERY' => 'Can administer the photo album'));
$lang['ACL_U_PLUS_ALBUM_PERSONAL'] = 'Can create a personal album when creation is restricted to selected groups';
$lang['ACL_A_PLUS_ALBUM_CATEGORIES'] = 'Can manage the shared photo categories and their access levels';
$lang['ACL_A_PLUS_ALBUM_AUTH'] = 'Can manage group permissions for shared photo categories';
$lang['ACL_A_PLUS_ALBUM_PERSONAL_AUTH'] = 'Can manage group creation and access permissions for personal albums';
$lang['ACL_A_PLUS_ALBUM_CACHE'] = 'Can clear generated album previews';
