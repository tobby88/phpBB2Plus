<?php

function statistics_runtime_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Statistics runtime safety test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$module = file_get_contents($root . '/phpBB2/stat_modules/top_smilies/module.php');

statistics_runtime_assert(strpos($module, 'count($sort_array) < 2') !== false, 'empty and single-row rankings must not access negative offsets');
statistics_runtime_assert(strpos($module, '$smile_url_sql = $db->sql_escape($smile_url)') !== false, 'stored smile image names must be escaped at the SQL boundary');
statistics_runtime_assert(strpos($module, '$smile_code_sql = $db->sql_escape($smile_code)') !== false, 'stored smile codes must be escaped at the SQL boundary');
statistics_runtime_assert(strpos($module, 'SELECT COUNT(*) AS matching_posts') !== false, 'per-post mode must count in SQL instead of transferring every post');
statistics_runtime_assert(strpos($module, "htmlspecialchars((string) \$all_smilies[\$i]['code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')") !== false, 'stored smile codes must be escaped for HTML');
statistics_runtime_assert(strpos($module, "rawurlencode(\$all_smilies[\$i]['smile_url'])") !== false, 'smile asset names must be encoded as path segments');

echo "Statistics runtime safety tests passed.\n";
