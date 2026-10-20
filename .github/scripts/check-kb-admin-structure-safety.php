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
	$root . '/phpBB2/templates/fisubsilversh/admin/kb_cat_admin_body.tpl',
	$root . '/phpBB2/templates/fisubsilversh/admin/kb_type_body.tpl'
);

$category_storage = file_get_contents($root . '/phpBB2/includes/functions_kb_category_storage.php');
kb_structure_assert(strpos($categories, 'function kb_admin_category_order_form') !== false, 'category ordering must use POST forms');
kb_structure_assert(strpos($categories, "in_array(\$mode, array('up', 'down'), true)") !== false, 'category ordering requires a POST token');
kb_structure_assert(strpos($categories, 'kb_change_category($db, $mode,') !== false, 'every mutation goes through the owning API');
kb_structure_assert(preg_match('/\\b(?:INSERT INTO|UPDATE)\\s+["\\\']?\\s*\\.\\s*KB_|DELETE FROM ["\\\']?\\s*\\.\\s*KB_/', $categories) !== 1, 'controller retains no old autocommit mutation SQL');
kb_structure_assert(strpos($category_storage, 'kb_counter_rebuild_plan($proposed, array())') !== false, 'proposed complete hierarchy rejects cycles before edit writes');
kb_structure_assert(strpos($category_storage, 'array_search($category_id, $siblings, true)') !== false, 'ordering locates actual siblings rather than fixed offsets');
kb_structure_assert(strpos($category_storage, '$storage->sql_escape($name)') !== false && strpos($category_storage, 'function kb_category_text(') !== false, 'plain UTF8 category names are bounded and SQL-escaped only at writes');
kb_structure_assert(strpos($category_storage, 'SET parent=') !== false && strpos($category_storage, 'WHERE parent=') !== false, 'direct child categories survive removal');
kb_structure_assert(strpos($category_storage, 'kb_delete_article_owned($storage);') !== false && strpos($category_storage, 'kb_delete_article($') === false, 'whole purge reuses SQL-only worker without independently committing articles');
$deletion = file_get_contents($root . '/phpBB2/includes/functions_kb_delete_storage.php');
kb_structure_assert(strpos($deletion, 'kb_remove_article_words($article_id);') !== false && strpos($deletion, "'DELETE FROM ' . KB_VOTES_TABLE") !== false, 'shared deletion removes article search/vote dependencies');
kb_structure_assert(strpos($deletion, "\$storage->settings['del_topic']") === false && strpos($deletion, "\$this->settings['del_topic'] === '1'") !== false, 'owned cleanup reloads current discussion deletion policy');
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/phpBB2', FilesystemIterator::SKIP_DOTS)) as $file)
{
	if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
		kb_structure_assert(preg_match('/\b(?:kb_delete_discussion_topic|email_kb_admin)\s*\(/', file_get_contents($file->getPathname())) !== 1, 'retired helpers must have neither definitions nor dangling callers: ' . $file->getFilename());
	}
}
kb_structure_assert(strpos($category_storage, 'kb_clear_search_cache(); $storage->commit();') !== false, 'category changes invalidate cached searches before the owned commit');
kb_structure_assert(strpos($categories, '?mode=up&amp;cat=') === false && strpos($categories, '?mode=down&amp;cat=') === false, 'category ordering must not be a GET mutation');

kb_structure_assert(substr_count($types, 'phpbb_admin_require_post_session();') >= 3, 'type create, edit and delete must require POST tokens');
kb_structure_assert(strpos($types, '$type_name_sql = $db->sql_escape($type_name);') !== false, 'type names must be escaped at the SQL boundary');
kb_structure_assert(strpos($types, 'function kb_admin_type_text') !== false, 'type names must respect schema limits');
kb_structure_assert(strpos($types, 'SET article_type = $new_type') !== false, 'deleted type references must be reassigned, including to no type');
kb_structure_assert(strpos($types, '$typelist .=') !== false && strpos($types, '$s>') === false, 'type choices must not use an undefined selection variable');

foreach ($templates as $template)
{
	$body = file_get_contents($template);
	kb_structure_assert(strpos($body, '{S_HIDDEN_FIELDS}') !== false, basename($template) . ' must render the POST token');
}

$category_edit_template = file_get_contents($root . '/phpBB2/templates/fisubsilversh/admin/kb_cat_edit_body.tpl');
kb_structure_assert(strpos($category_edit_template, 'name="number_articles"') === false, 'derived category article counters must not be editable');
kb_structure_assert(strpos($category_edit_template, '</otpion>') === false, 'category parent options must use valid markup');

echo "Knowledge Base structure safety tests passed.\n";
