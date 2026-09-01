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
$statistics = file_get_contents($root . '/phpBB2/statistics.php');
$admin = file_get_contents($root . '/phpBB2/admin/admin_statistics.php');

statistics_runtime_assert(strpos($module, 'count($sort_array) < 2') !== false, 'empty and single-row rankings must not access negative offsets');
statistics_runtime_assert(strpos($module, '$smile_url_sql = $db->sql_escape($smile_url)') !== false, 'stored smile image names must be escaped at the SQL boundary');
statistics_runtime_assert(strpos($module, '$smile_code_sql = $db->sql_escape($smile_code)') !== false, 'stored smile codes must be escaped at the SQL boundary');
statistics_runtime_assert(strpos($module, 'SELECT COUNT(*) AS matching_posts') !== false, 'per-post mode must count in SQL instead of transferring every post');
statistics_runtime_assert(strpos($module, "htmlspecialchars((string) \$all_smilies[\$i]['code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')") !== false, 'stored smile codes must be escaped for HTML');
statistics_runtime_assert(strpos($module, "rawurlencode(\$all_smilies[\$i]['smile_url'])") !== false, 'smile asset names must be encoded as path segments');
statistics_runtime_assert(strpos($statistics, "max(1, min(100, intval(\$__stats_config['return_limit'])))") !== false, 'public result limits must be bounded');
statistics_runtime_assert(strpos($admin, 'max(1, min(100, intval(') !== false, 'the ACP must persist only bounded result limits');

$escaped_modules = array(
	'most_used_languages' => "htmlspecialchars((string) \$lang_data[\$i]['user_lang']",
	'most_viewed_topics' => "htmlspecialchars((string) \$topic_data[\$i]['topic_title']",
	'most_active_topics' => "htmlspecialchars((string) \$topic_data[\$i]['topic_title']",
	'top_posters' => "htmlspecialchars((string) \$user_data[\$i]['username']",
	'site_hist_month_top_posters' => "htmlspecialchars((string) \$user_data[\$i]['username']",
	'site_hist_week_top_posters' => "htmlspecialchars((string) \$user_data[\$i]['username']",
	'top_attachments' => '$filename_html = htmlspecialchars($filename',
	'admin_statistics' => '$newest_user = htmlspecialchars(',
);
foreach ($escaped_modules as $directory => $marker)
{
	$source = file_get_contents($root . '/phpBB2/stat_modules/' . $directory . '/module.php');
	statistics_runtime_assert(strpos($source, $marker) !== false, $directory . ' must escape stored labels');
}

echo "Statistics runtime safety tests passed.\n";
