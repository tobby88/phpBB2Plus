<?php

$root = dirname(dirname(__DIR__));
$attachments = (string) file_get_contents($root . '/phpBB2/admin/admin_attachments.php');
$extensions = (string) file_get_contents($root . '/phpBB2/admin/admin_extensions.php');
$control = (string) file_get_contents($root . '/phpBB2/admin/admin_attach_cp.php');
$errors = array();

foreach (array($attachments, $extensions, $control) as $body)
{
	foreach (array('phpbb_admin_require_post_session();', 'phpbb_admin_session_field()') as $marker)
	{
		if (strpos($body, $marker) === false)
		{
			$errors[] = 'Attachment administration is missing ' . $marker;
		}
	}
}

foreach (array('$sync_confirm', "if (\$mode == 'sync' && !\$sync_confirm)", "if (\$mode == 'sync' && \$sync_confirm)") as $marker)
{
	if (strpos($attachments, $marker) === false)
	{
		$errors[] = 'Attachment synchronization is missing ' . $marker;
	}
}
if (strpos($attachments, "if (\$mode == 'sync')") !== false)
{
	$errors[] = 'Attachment synchronization still mutates directly from GET';
}

foreach (array('$add_forum || $delete_forum', "array('extensions', 'groups', 'forbidden')") as $marker)
{
	if (strpos($extensions, $marker) === false)
	{
		$errors[] = 'Extension administration is missing ' . $marker;
	}
}

foreach (array('$normalized_delete_ids', 'phpbb_admin_html($attachments[$i][\'comment\'])') as $marker)
{
	if (strpos($control, $marker) === false)
	{
		$errors[] = 'Attachment control panel is missing ' . $marker;
	}
}

foreach (array('subSilver', 'fisubsilversh') as $style)
{
	foreach (array('attach_extension_groups.tpl', 'extension_groups_permissions.tpl', 'attach_cp_attachments.tpl') as $template_name)
	{
		$template = (string) file_get_contents($root . '/phpBB2/templates/' . $style . '/admin/' . $template_name);
		if (strpos($template, '{S_HIDDEN_FIELDS}') === false)
		{
			$errors[] = $style . '/' . $template_name . ' does not render the session token';
		}
	}
}

if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

echo "Attachment administration safety checks passed.\n";
