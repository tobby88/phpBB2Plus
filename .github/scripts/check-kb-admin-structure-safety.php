<?php

function kb_structure_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Knowledge Base structure safety test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$categories = file_get_contents($root . '/phpBB2/admin/admin_kb_cat.php');
$types = file_get_contents($root . '/phpBB2/admin/admin_kb_types.php');
$templates = array(
	$root . '/phpBB2/templates/subSilver/admin/kb_cat_admin_body.tpl',
	$root . '/phpBB2/templates/fisubsilversh/admin/kb_cat_admin_body.tpl',
	$root . '/phpBB2/templates/subSilver/admin/kb_type_body.tpl',
	$root . '/phpBB2/templates/fisubsilversh/admin/kb_type_body.tpl'
);

kb_structure_assert(strpos($categories, 'function kb_admin_category_order_form') !== false, 'category ordering must use POST forms');
kb_structure_assert(strpos($categories, 'function kb_admin_category_parent_valid') !== false, 'category parent cycles must be rejected');
kb_structure_assert(strpos($categories, "in_array(\$mode, array('up', 'down'), true)") !== false, 'category ordering must require a POST token');
kb_structure_assert(strpos($categories, '$cat_name_sql = $db->sql_escape($cat_name);') !== false, 'category names must be escaped at the SQL boundary');
kb_structure_assert(strpos($categories, 'SET parent = $old_parent WHERE parent = $old_category') !== false, 'category deletion must not orphan children');
kb_structure_assert(strpos($categories, 'KB_MATCH_TABLE') !== false && strpos($categories, 'KB_VOTES_TABLE') !== false, 'bulk article deletion must remove dependent search and vote rows');
kb_structure_assert(strpos($categories, '?mode=up&amp;cat=') === false && strpos($categories, '?mode=down&amp;cat=') === false, 'category ordering must not be a GET mutation');

kb_structure_assert(substr_count($types, 'phpbb_admin_require_post_session();') >= 3, 'type create, edit and delete must require POST tokens');
kb_structure_assert(strpos($types, '$type_name_sql = $db->sql_escape($type_name);') !== false, 'type names must be escaped at the SQL boundary');
kb_structure_assert(strpos($types, 'SET article_type = $new_type') !== false, 'deleted type references must be reassigned, including to no type');
kb_structure_assert(strpos($types, '$typelist .=') !== false && strpos($types, '$s>') === false, 'type choices must not use an undefined selection variable');

foreach ($templates as $template)
{
	$body = file_get_contents($template);
	kb_structure_assert(strpos($body, '{S_HIDDEN_FIELDS}') !== false, basename($template) . ' must render the POST token');
}

echo "Knowledge Base structure safety tests passed.\n";
