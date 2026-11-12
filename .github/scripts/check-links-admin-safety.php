<?php

$root = dirname(dirname(__DIR__));
$admin = $root . '/phpBB2/admin/admin_links.php';
$body = (string) file_get_contents($admin);
$errors = array();

$required = array(
	"in_array(\$mode, array('add', 'view', 'edit', 'delete', 'update'), true)",
	"in_array(\$action, array('add', 'modify', 'delete'), true)",
	'phpbb_admin_require_post_session();',
	'phpbb_admin_session_field()',
	'phpbb_normalize_external_url($value)',
	'phpbb_link_admin_mutate($db, $_POST, $userdata)',
	"'includes/functions_link_admin.'",
	"phpbb_link_text_input(admin_links_request_scalar('search_keywords', ''), 100)",
	"'U_LINK_EDIT' => append_sid",
	"'U_LINK_DELETE' => append_sid"
);

define('IN_PHPBB', true);
require $root . '/phpBB2/includes/functions_link_admin.php';
foreach(array(1,'41',16777215)as$value){if(phpbb_link_admin_id($value)!==(int)$value){$errors[]='Exact valid Link ID changed';}}
foreach(array(0,-1,true,1.5,'01','1e0','1foo','16777216',array(1),null)as$value){$refused=false;try{phpbb_link_admin_id($value);}catch(PhpbbLinkException$error){$refused=true;}if(!$refused){$errors[]='Invalid Link ID accepted';}}
$store=file_get_contents($root.'/phpBB2/includes/functions_link_admin.php');
foreach(array('s.session_admin=1','HEX(s.session_id)=HEX(',"' LOCK IN SHARE MODE'",'jr_admin_authorization_routes()', 'jr_admin_route_matches_file($routes[$hash],$file)',"array('admin_links.'",'phpbb_link_submission_input($post)')as$marker){if(strpos($store,$marker)===false){$errors[]='Missing Link ACP provider boundary: '.$marker;}}

foreach ($required as $marker)
{
	if (strpos($body, $marker) === false)
	{
		$errors[] = 'Missing link administration safety marker: ' . $marker;
	}
}

$forbidden = array(
	"admin_links.\$phpEx?mode=update",
	"'&sid=' . \$userdata['session_id']",
	"str_replace(\"'\", \"''\", \$link_title)",
	"WHERE link_id = '\$link_id'",
	"preg_match('/[\\\\\\x00-\\x20\\x7f]/', \$value)"
);

foreach ($forbidden as $marker)
{
	if (strpos($body, $marker) !== false)
	{
		$errors[] = 'Legacy link administration path remains: ' . $marker;
	}
}

foreach (array('fisubsilversh') as $style)
{
	$edit = (string) file_get_contents($root . '/phpBB2/templates/' . $style . '/admin/admin_links_edit_body.tpl');
	$list = (string) file_get_contents($root . '/phpBB2/templates/' . $style . '/admin/admin_links_body.tpl');
	if (strpos($edit, '{S_HIDDEN_FIELDS}') === false)
	{
		$errors[] = $style . ' link edit form is missing hidden POST fields.';
	}
	if (strpos($list, '{linkrow.U_LINK_EDIT}') === false || strpos($list, '{linkrow.U_LINK_DELETE}') === false)
	{
		$errors[] = $style . ' link list still assembles administrative URLs in the template.';
	}
}

if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

echo "Link administration safety checks passed.\n";
