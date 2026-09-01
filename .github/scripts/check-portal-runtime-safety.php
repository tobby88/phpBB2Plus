<?php

function portal_runtime_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Portal runtime safety test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$portal = file_get_contents($root . '/phpBB2/portal.php');

portal_runtime_assert($portal !== false, 'portal source must be readable');
portal_runtime_assert(strpos($portal, '$portal_numeric_limits = array(') !== false, 'database-configured result limits must be normalized');
portal_runtime_assert(strpos($portal, "'number_recent_topics' => 100") !== false, 'recent topic batches must be capped');
portal_runtime_assert(strpos($portal, "'number_recent_files' => 100") !== false, 'recent download batches must be capped');
portal_runtime_assert(strpos($portal, "'pics_number' => 100") !== false, 'picture batches must be capped');
portal_runtime_assert(strpos($portal, "'number_top_posters' => 100") !== false, 'top-poster batches must be capped');
portal_runtime_assert(strpos($portal, "phpbb_sql_id_list(isset(\$CFG['exceptional_forums'])") !== false, 'exceptional forum IDs must be normalized');
portal_runtime_assert(strpos($portal, "phpbb_sql_id_list(isset(\$CFG['cat_id'])") !== false, 'picture category IDs must be normalized');
portal_runtime_assert(strpos($portal, '$allowed_cat_sql = phpbb_sql_id_list($allowed_cat);') !== false, 'authorized picture category IDs must be normalized');
portal_runtime_assert(strpos($portal, "phpbb_sql_id_list(isset(\$CFG['poll_forum'])") !== false, 'poll forum IDs must be normalized');
portal_runtime_assert(strpos($portal, "\$except_forum_id = '\\'start\\''") === false, 'the non-numeric exceptional-forum sentinel must stay removed');

echo "Portal runtime safety checks passed.\n";
