<?php

$root = dirname(dirname(__DIR__));
$admin = (string) file_get_contents($root . '/phpBB2/admin/admin_jr_admin.php');
$errors = array();

require_once $root . '/phpBB2/includes/functions_jr_admin.php';
$lang = array(
	'General' => 'General', 'Styles' => 'Styles', 'Attachments' => 'Attachments',
	'Users' => 'Users', 'Arcade' => 'Arcade', 'Future' => 'Future', 'Banner' => 'Banner',
	'Styles_Management' => 'Style management', 'Manage' => 'Manage', 'Configuration' => 'Configuration',
	'Extension_control' => 'Extension control', 'Profile_fields_add' => 'Add profile field',
	'General_Plusconfig' => 'phpBB2 Plus Configuration'
);
$navigation_fixture = array(
	'Arcade' => array('Manage' => array('file_hash' => 'arcade')),
	'Plus' => array('Configuration' => array('file_hash' => 'plus-config')),
	'Extreme_Styles' => array('Styles_Management' => array('file_hash' => 'xs')),
	'General' => array(
		'Configuration' => array('file_hash' => 'core-config'),
		'Manage' => array('file_hash' => 'general')
	),
	'Systeminfo' => array('PHPInfo' => array('file_hash' => 'phpinfo')),
	'Users' => array('Add_new' => array('file_hash' => 'add-user')),
	'Custom_Profile' => array('Add_new' => array('file_hash' => 'profile-field')),
	'Styles' => array('Banner' => array('file_hash' => 'banner')),
	'Extensions' => array('Extension_control' => array('file_hash' => 'extensions')),
	'Attachments' => array('Manage' => array('file_hash' => 'attachments')),
	'Future' => array('Manage' => array('file_hash' => 'future'))
);
$prepared_navigation = jr_admin_prepare_navigation_modules($navigation_fixture);
$resolved_module_directory = jr_admin_module_directory();
if ($resolved_module_directory === false || basename(rtrim($resolved_module_directory, '/\\')) !== 'admin')
{
	$errors[] = 'AdminCP module discovery is not anchored to the installation root.';
}
$expected_categories = array('General', 'Users', 'Styles', 'Attachments', 'Arcade', 'Future');
if (array_keys($prepared_navigation) !== $expected_categories)
{
	$errors[] = 'AdminCP categories are not grouped in the expected task-oriented order.';
}
if (isset($prepared_navigation['Extreme_Styles']) || isset($prepared_navigation['Extensions']) ||
	isset($prepared_navigation['Custom_Profile']) || isset($prepared_navigation['Systeminfo']) ||
	isset($prepared_navigation['Plus']))
{
	$errors[] = 'Related AdminCP categories remain unnecessarily split.';
}
if (!isset($prepared_navigation['Users']['Custom_Profile__Add_new']['file_hash']) ||
	$prepared_navigation['Users']['Custom_Profile__Add_new']['file_hash'] !== 'profile-field' ||
	$prepared_navigation['Users']['Custom_Profile__Add_new']['navigation_name'] !== 'Profile_fields_add')
{
	$errors[] = 'Profile-field navigation was not merged into Users with an unambiguous label.';
}
if (!isset($prepared_navigation['General']['Plus__Configuration']['file_hash']) ||
	$prepared_navigation['General']['Plus__Configuration']['file_hash'] !== 'plus-config' ||
	$prepared_navigation['General']['Plus__Configuration']['navigation_name'] !== 'General_Plusconfig')
{
	$errors[] = 'Plus configuration was not merged into General with an unambiguous label.';
}
if (!isset($prepared_navigation['Styles']['Styles_Management']['file_hash']) || $prepared_navigation['Styles']['Styles_Management']['file_hash'] !== 'xs' ||
	!isset($prepared_navigation['Attachments']['Extension_control']['file_hash']) || $prepared_navigation['Attachments']['Extension_control']['file_hash'] !== 'extensions')
{
	$errors[] = 'AdminCP category grouping changed an original module identity.';
}
if (array_keys($prepared_navigation['Styles']) !== array('Banner', 'Styles_Management'))
{
	$errors[] = 'AdminCP modules are not sorted by their visible navigation label.';
}

foreach (array(
	'phpbb_admin_require_post_session();',
	'phpbb_admin_session_field()',
	'$allowed_module_hashes',
	"preg_match('/^[0-9]+_' . UPDATE_MODULE_PREFIX . '([a-f0-9]{32})$/D'",
	'$db->sql_escape($user_update_list)',
	'$db->sql_escape($admin_notes)',
	'$db->sql_escape($user_search)',
	'$allowed_sort_items',
	'jr_admin_safe_color',
	"'user_jr_admin' => ''",
	'$letter_list = array();'
) as $marker)
{
	if (strpos($admin, $marker) === false)
	{
		$errors[] = 'Missing Junior Admin safety marker: ' . $marker;
	}
}

foreach (array(
	'$params = array(',
	'$update_find_pattern',
	'print_r($_POST)',
	'print_r($_GET)',
	'" AND username LIKE (\'".$_POST[\'user_search\']',
	'admin_notes = \'$admin_notes\'',
	'<input type="hidden" name="sid"'
) as $marker)
{
	if (strpos($admin, $marker) !== false)
	{
		$errors[] = 'Legacy Junior Admin path remains: ' . $marker;
	}
}

foreach (array('fisubsilversh') as $style)
{
	$template = (string) file_get_contents($root . '/phpBB2/templates/' . $style . '/admin/jr_admin_user_permissions.tpl');
	if (strpos($template, '{S_HIDDEN_FIELDS}') === false)
	{
		$errors[] = $style . ' Junior Admin permissions form does not render the POST token';
	}
}

if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

echo "Junior Admin safety checks passed.\n";
