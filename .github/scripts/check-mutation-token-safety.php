<?php

function mutation_token_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Mutation token safety test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$files = array(
	'absence notification' => file_get_contents($root . '/phpBB2/absence_notify_popup.php'),
	'Album comment deletion' => file_get_contents($root . '/phpBB2/album_comment_delete.php'),
	'Album picture deletion' => file_get_contents($root . '/phpBB2/album_delete.php'),
	'Arcade moderation' => file_get_contents($root . '/phpBB2/arcade_modcp.php'),
	'user pruning' => file_get_contents($root . '/phpBB2/delete_users.php'),
	'administration bootstrap' => file_get_contents($root . '/phpBB2/admin/pagestart.php'),
);
$page_header = file_get_contents($root . '/phpBB2/includes/page_header.php');
$overall_header = file_get_contents($root . '/phpBB2/templates/fisubsilversh/overall_header.tpl');

foreach ($files as $label => $source)
{
	mutation_token_assert(strpos($source, "is_scalar(\$_POST['sid'])") !== false || strpos($source, "is_scalar(\$_GET['sid'])") !== false, $label . ' must reject array session tokens');
}

mutation_token_assert(strpos($files['absence notification'], "REQUEST_METHOD']) !== 'POST'") !== false, 'absence changes must require POST');
mutation_token_assert(strpos($files['Album comment deletion'], "REQUEST_METHOD']) !== 'POST'") !== false, 'Album comment deletion must require POST');
mutation_token_assert(strpos($page_header, "isset(\$_POST['marknow'])") !== false && strpos($page_header, "HTTP_GET_VARS['marknow']") === false, 'CrackerTracker message acknowledgements must be POST-only');
mutation_token_assert(strpos($page_header, "phpbb_session_action_token('ctracker-message'") !== false, 'CrackerTracker message acknowledgements need scoped action tokens');
mutation_token_assert(strpos($page_header, '!hash_equals($expected_mark_token, $mark_token)') !== false, 'CrackerTracker message tokens must use constant-time verification');
mutation_token_assert(strpos($page_header, "sid=' . urlencode(\$userdata['session_id'])") === false, 'CrackerTracker notices must not expose raw session IDs in action URLs');
mutation_token_assert(strpos($overall_header, '<form method="post" action="{ctracker_message.switch_mark_action.S_MARK_ACTION}"') !== false, 'CrackerTracker notice actions must render as POST forms');
mutation_token_assert(strpos($overall_header, 'name="ct_token"') !== false && strpos($overall_header, 'U_MARK_MESSAGE') === false, 'CrackerTracker notice forms must submit the scoped token without legacy GET links');

echo "Mutation token safety tests passed.\n";
