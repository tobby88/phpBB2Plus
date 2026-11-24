<?php
// Run inside the already disposable attachment-list database and its strict driver.
function auth($type, $forum, $user)
{
	return array('auth_view' => true, 'auth_read' => $user['can_read'], 'auth_attachments' => true);
}

function aal_rule_dialog($forum_id, $member = true, $admin = false, $can_read = true, $disabled = false, $allow_pm_attachments = true)
{
	global $db, $board_config, $lang, $phpbb_root_path, $aal_rule_query, $aal_rule_body;
	$privmsg = !$forum_id;
	$userdata = array('user_level' => $admin ? ADMIN : USER, 'session_logged_in' => $member,
		'user_allow_pm' => true, 'can_read' => $can_read);
	$attach_config = array('disable_mod' => $disabled, 'allow_pm_attach' => $allow_pm_attachments,
		'max_filesize' => 1536, 'max_filesize_pm' => 9437184);
	$template = (new ReflectionClass('Template'))->newInstanceWithoutConstructor();
	$template->vars = &$template->_tpldata['.'][0];
	$template->load_config($phpbb_root_path . 'templates/fisubsilversh', false);
	ob_start();
	try
	{
		eval($aal_rule_query);
		eval($aal_rule_body);
		return ob_get_contents();
	}
	finally { ob_end_clean(); }
}

require_once $phpbb_root_path . 'includes/template.php';
$rule_source = str_replace("\r\n", "\n", file_get_contents($phpbb_root_path . 'attach_rules.php'));
$a = strpos($rule_source, '$admin ='); $b = strpos($rule_source, '$gen_simple_header');
$c = strpos($rule_source, '$template->assign_vars(', $b); $d = strpos($rule_source, "include(\$phpbb_root_path . 'includes/page_tail.", $c);
aal_check($a !== false && $b > $a && $c > $b && $d > $c, 'Actual complete rule-dialog bindings');
$aal_rule_query = substr($rule_source, $a, $b - $a);
$aal_rule_body = substr($rule_source, $c, $d - $c);
$ddl = file_get_contents($phpbb_root_path . 'install/schemas/mysql_schema.sql');
foreach (array('extension_groups', 'extensions') as $name)
{
	aal_check(preg_match('/^CREATE TABLE phpbb_' . $name . '\s*\([\s\S]*?;/m', $ddl, $table)
		&& $db->sql_query(str_replace('phpbb_' . $name, 'fixture_' . $name, $table[0])), 'Canonical extension table');
}
aal_check($db->sql_query("INSERT INTO fixture_extension_groups (group_id,group_name,allow_group,max_filesize,forum_permissions)
	VALUES (1,'A & B',1,0,''),(2,'Local',1,2097152,'#7'),(3,'Empty',1,0,''),
	(4,'Disabled',0,0,''),(5,'Unsupported',1,0,'')"), 'Fixture extension groups');
aal_check($db->sql_query("INSERT INTO fixture_extensions (group_id,extension)
	VALUES (1,'txt'),(1,'pdf'),(2,'png'),(4,'gif'),(5,'')"), 'Fixture file types');
$theme['template_name'] = 'fisubsilversh';
$board_config = array('privmsg_disable' => 0, 'xs_use_cache' => 0, 'xs_auto_compile' => 0, 'xs_auto_recompile' => 0);
foreach (array('english', 'german') as $locale)
{
	$board_config['default_lang'] = $locale;
	require $phpbb_root_path . 'language/lang_' . $locale . '/lang_main.php';
	require $phpbb_root_path . 'language/lang_' . $locale . '/lang_main_attach.php';
	$pm = aal_rule_dialog(0); $local = aal_rule_dialog(7); $other = aal_rule_dialog(8);
	aal_check($pm === $local && strpos($pm, '1.5 ' . $lang['KB']) !== false && strpos($pm, '2 ' . $lang['MB']) !== false,
		'PMs use the upload limit per file, including the extension-group override');
	aal_check(strpos($other, 'A &amp; B') !== false && strpos($other, 'Local') === false
		&& strpos($other, 'pdf') < strpos($other, 'txt') && strpos($other, 'Disabled') === false
		&& strpos($other, 'Unsupported') === false && strpos($other, 'Empty') === false, 'Visible, escaped and sorted populated groups');
	$admin_html = aal_rule_dialog(8, true, true);
	aal_check(substr_count($admin_html, $lang['Unlimited']) === 2 && strpos($admin_html, 'Local') !== false, 'Administrator upload rules');
	foreach (array(array(0,false), array(7,true,false,false), array(7,true,false,true,true), array(0,true,true,true,false,false)) as $request)
	{
		try { call_user_func_array('aal_rule_dialog', $request); throw new RuntimeException('Rules should have been unavailable'); }
		catch (AttachmentAdminListExit $e) { aal_check($e->getMessage() === $lang['Not_Authorised'], 'Localized unavailable dialog'); }
	}
}
aal_check($db->sql_query('UPDATE fixture_extension_groups SET allow_group=0'), 'Disable fixture groups');
aal_check(strpos(aal_rule_dialog(7), $lang['Note_user_empty_group_permissions']) !== false, 'Empty dialog explains the result');
