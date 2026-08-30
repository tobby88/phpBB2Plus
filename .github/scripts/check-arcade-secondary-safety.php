<?php

function arcade_secondary_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Arcade secondary safety test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$rate = file_get_contents($root . '/phpBB2/arcade_rate.php');
$comment = file_get_contents($root . '/phpBB2/arcade_comment.php');
$tournament = file_get_contents($root . '/phpBB2/arcade_tournament.php');

arcade_secondary_assert(strpos($rate, "!is_scalar(\$HTTP_POST_VARS['sid'])") !== false, 'ratings must reject nested tokens');
arcade_secondary_assert(substr_count($comment, "include(\$phpbb_root_path . 'includes/page_tail.'.\$phpEx);\n\texit;") >= 2, 'comment confirmation and edit pages must stop after their page tail');
arcade_secondary_assert(strpos($comment, 'min(1000000, intval(phpbb_request_scalar') !== false, 'comment offsets must be scalar and bounded');
arcade_secondary_assert(strpos($tournament, 'count($normalized_join_tours) >= 100') !== false, 'tournament join batches must be bounded');
arcade_secondary_assert(strpos($tournament, "is_scalar(\$HTTP_POST_VARS['tour_name'])") !== false, 'tournament text fields must be scalar');
arcade_secondary_assert(strpos($tournament, "is_scalar(\$HTTP_GET_VARS['tour_token'])") !== false, 'tournament play tokens must be scalar');

echo "Arcade secondary safety tests passed.\n";
