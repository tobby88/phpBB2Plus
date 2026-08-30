<?php

$root = dirname(dirname(__DIR__));
$files = array(
	'acronyms' => (string) file_get_contents($root . '/phpBB2/admin/admin_acronyms.php'),
	'words' => (string) file_get_contents($root . '/phpBB2/admin/admin_words.php'),
	'ranks' => (string) file_get_contents($root . '/phpBB2/admin/admin_ranks.php'),
	'smilies' => (string) file_get_contents($root . '/phpBB2/admin/admin_smilies.php')
);
$bbcode = (string) file_get_contents($root . '/phpBB2/includes/bbcode.php');
$errors = array();

foreach ($files as $name => $body)
{
	foreach (array('phpbb_admin_require_post_session();', 'phpbb_admin_session_field()') as $marker)
	{
		if (strpos($body, $marker) === false)
		{
			$errors[] = 'Missing ' . $name . ' safety marker: ' . $marker;
		}
	}
	foreach (array('$HTTP_POST_VARS', '$HTTP_GET_VARS', '<input type="hidden" name="sid"') as $marker)
	{
		if (strpos($body, $marker) !== false)
		{
			$errors[] = 'Legacy ' . $name . ' request path remains: ' . $marker;
		}
	}
}

$required = array(
	'acronyms' => array("in_array(\$mode, array('', 'add', 'edit', 'save', 'delete'), true)", '$db->sql_escape($acronym)', '$confirmed && isset($_POST[\'id\'])'),
	'words' => array("in_array(\$mode, array('add', 'edit', 'save', 'delete'), true)", '$db->sql_escape($word)', '$confirm && isset($_POST[\'id\'])'),
	'ranks' => array("in_array(\$mode, array('add', 'edit', 'save', 'delete'), true)", '$db->sql_escape($rank_title)', '$confirm && isset($_POST[\'id\'])'),
	'smilies' => array("in_array(\$mode, array('delete', 'edit', 'save', 'savenew'), true)", '$confirm ? admin_smiley_request_int($_POST, \'id\')', '$db->sql_escape($smile_code)')
);
foreach ($required as $name => $markers)
{
	foreach ($markers as $marker)
	{
		if (strpos($files[$name], $marker) === false)
		{
			$errors[] = 'Missing ' . $name . ' safety marker: ' . $marker;
		}
	}
}

foreach (array('html_entity_decode((string) $acronyms[$i][\'acronym\']', 'htmlspecialchars($description_text', "str_replace(array('\\\\', '$')") as $marker)
{
	if (strpos($bbcode, $marker) === false)
	{
		$errors[] = 'Missing safe acronym rendering marker: ' . $marker;
	}
}

if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

echo "Content administration safety checks passed.\n";
