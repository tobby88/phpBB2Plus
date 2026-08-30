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

foreach ($files as $label => $source)
{
	mutation_token_assert(strpos($source, "is_scalar(\$_POST['sid'])") !== false || strpos($source, "is_scalar(\$_GET['sid'])") !== false, $label . ' must reject array session tokens');
}

mutation_token_assert(strpos($files['absence notification'], "REQUEST_METHOD']) !== 'POST'") !== false, 'absence changes must require POST');
mutation_token_assert(strpos($files['Album comment deletion'], "REQUEST_METHOD']) !== 'POST'") !== false, 'Album comment deletion must require POST');

echo "Mutation token safety tests passed.\n";
