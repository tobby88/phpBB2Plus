<?php

function admin_core_action_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "ACP core action safety test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$mail = file_get_contents($root . '/phpBB2/admin/admin_mass_email.php');
$mail_template = file_get_contents($root . '/phpBB2/templates/fisubsilversh/admin/user_email_body.tpl');
$prune = file_get_contents($root . '/phpBB2/admin/admin_forum_prune.php');
$prune_select = file_get_contents($root . '/phpBB2/templates/fisubsilversh/admin/forum_prune_select_body.tpl');
$prune_form = file_get_contents($root . '/phpBB2/templates/fisubsilversh/admin/forum_prune_body.tpl');

admin_core_action_assert(strpos($mail, "if ( isset(\$_POST['submit']) )\n{\n\tphpbb_admin_require_post_session();") !== false, 'mass mail delivery must require the administrator form token');
admin_core_action_assert(strpos($mail, "is_scalar(\$_POST['subject'])") !== false && strpos($mail, "is_scalar(\$_POST['message'])") !== false, 'mass mail fields must reject nested input');
admin_core_action_assert(strpos($mail, "str_replace(array(\"\\r\", \"\\n\"), ' ', \$subject)") !== false, 'mass mail subjects must not contain injected header lines');
admin_core_action_assert(strpos($mail, "'MESSAGE' => phpbb_admin_html(\$message)") !== false && strpos($mail, "'SUBJECT' => phpbb_admin_html(\$subject)") !== false, 'mass mail form redisplay must be escaped');
admin_core_action_assert(strpos($mail_template, '{S_FORM_TOKEN}') !== false, 'mass mail form must submit its session token');

admin_core_action_assert(strpos($prune, "if( isset(\$_POST['doprune']) )\n{\n\tphpbb_admin_require_post_session();") !== false, 'forum pruning must require the administrator form token');
admin_core_action_assert(strpos($prune, "preg_match('/^(?:Root|[cf][0-9]+)$/D', \$fid)") !== false, 'forum pruning must validate hierarchy identifiers');
admin_core_action_assert(substr_count($prune, "'S_FORM_TOKEN' => phpbb_admin_session_field()") === 2, 'both forum-prune forms need session tokens');
admin_core_action_assert(strpos($prune_select, '{S_FORM_TOKEN}') !== false && strpos($prune_form, '{S_FORM_TOKEN}') !== false, 'forum-prune templates must render their session tokens');

echo "ACP core action safety tests passed.\n";

?>
