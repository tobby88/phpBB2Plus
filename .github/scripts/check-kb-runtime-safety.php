<?php

function kb_runtime_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Knowledge Base runtime test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$functions = file_get_contents($root . '/phpBB2/includes/functions_kb.php');
$admin = file_get_contents($root . '/phpBB2/admin/admin_kb_art.php');

kb_runtime_assert(strpos($functions, "\$article_link = '<a") !== false, 'article link must not overwrite the database row');
kb_runtime_assert(strpos($functions, "\$postrow[\$i]['link_rating']") === false, 'KB ratings must not read an unrelated post row');
kb_runtime_assert(substr_count($functions, '$article_rating / $article_totalvotes') === 2, 'both KB list variants must use article rating fields');
kb_runtime_assert(strpos($admin, "isset(\$_POST['a']) ? (int) \$_POST['a']") !== false, 'admin article ID must be normalized');

echo "Knowledge Base runtime safety tests passed.\n";
