<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(),array(
	'ACL_A_PLUS_WL_CREATE'=>'Can add pending or published websites in the ACP',
	'ACL_A_PLUS_WL_EDIT'=>'Can edit website directory entries',
	'ACL_A_PLUS_WL_DELETE'=>'Can permanently delete individual directory websites',
	'ACL_A_PLUS_WL_CATEGORIES'=>'Can manage directory categories, including moving or deleting their entries',
	'ACL_A_PLUS_WL_SETTINGS'=>'Can change website directory settings',
	'ACL_A_PLUS_WL_REVIEW'=>'Can review and publish website directory entries',
	'ACL_A_PLUS_WL_PUBLISH'=>'Can publish own website submissions immediately and bypass the submission lock',
));
