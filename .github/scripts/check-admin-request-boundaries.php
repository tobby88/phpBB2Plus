<?php

function admin_boundary_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Administration request boundary test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__)) . '/phpBB2/admin/';
$expectations = array(
	'admin_email_list.php' => "is_scalar(\$_GET['start'])",
	'admin_acronyms.php' => "is_scalar(\$_POST['id'])",
	'admin_account.php' => "is_scalar(\$_POST['delete'])",
	'admin_forumauth.php' => "is_scalar(\$_GET['adv'])",
	'admin_pa_catauth.php' => "is_scalar(\$_GET['cat_id'])",
	'admin_mass_email.php' => 'is_scalar($_POST[POST_GROUPS_URL])',
	'admin_users_list.php' => "is_scalar(\$_POST['group_id'])",
	'admin_statistics.php' => "is_scalar(\$_POST['return_limit_set'])",
	'admin_ranks.php' => "is_scalar(\$_POST['id'])",
	'admin_words.php' => "is_scalar(\$_POST['id'])",
);

foreach ($expectations as $file => $needle)
{
	$source = file_get_contents($root . $file);
	admin_boundary_assert(strpos($source, $needle) !== false, $file . ' must reject nested request values');
}

echo "Administration request boundary tests passed.\n";
