<?php
// Exercise real ACL assignments to three synthetic actors in the disposable target only.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 2 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
define('IN_PHPBB',true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
require_once './includes/acp/auth.php';
$writer = new auth_admin();
$result = $db->sql_query('SELECT username FROM ' . USERS_TABLE . ' WHERE user_id=87');
$actor = $db->sql_fetchfield('username');
$db->sql_freeresult($result);
if ($actor !== 'DepartingFixture') { throw new RuntimeException('Missing synthetic Arcade moderator'); }
$writer->acl_set('user',0,87,array('m_plus_arcade'=>ACL_YES));
$arcade = $phpbb_container->get('phpbb2plus.arcade.catalog');
$gallery = $phpbb_container->get('phpbb2plus.gallery.access');
$kb = $phpbb_container->get('phpbb2plus.knowledgebase.articles');
foreach (array('ModernMember'=>array(true,0,true),'HistoricMember'=>array(false,50,true),'DepartingFixture'=>array(true,50,false)) as $name=>$expected)
{
	$result = $db->sql_query('SELECT * FROM ' . USERS_TABLE . " WHERE username='$name'");
	$account = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	if (!$account) { throw new RuntimeException('Missing isolated actor ' . $name); }
	$user->data = $account + array('is_registered'=>true,'is_bot'=>false);
	$auth->acl($user->data);
	if (array((bool) $arcade->staff(),$gallery->watermark_strength(),(bool) $kb->moderator()) !== $expected)
	{
		throw new RuntimeException('Explicit Arcade, album and Knowledge Base roles diverged for ' . $name);
	}
}
$desk = new \phpbb2plus\knowledgebase\acp\articles_info();
$access = $desk->module()['modes']['manage']['auth'];
require_once './includes/functions_module.php';
if (p_master::module_auth($access, 0)) { throw new RuntimeException('An unrelated Arcade moderator can access the Knowledge Base desk'); }
foreach (array(ACL_YES, ACL_NO) as $grant)
{
	$writer->acl_set('user', 0, 87, array('a_plus_kb_articles' => $grant));
	$auth->acl_clear_prefetch(87);
	$result = $db->sql_query('SELECT * FROM ' . USERS_TABLE . ' WHERE user_id=87');
	$user->data = $db->sql_fetchrow($result) + array('is_registered' => true, 'is_bot' => false);
	$db->sql_freeresult($result);
	$auth->acl($user->data);
	if ((bool) p_master::module_auth($access, 0) !== ($grant === ACL_YES) || (bool) $kb->moderator() !== ($grant === ACL_YES) || $auth->acl_get('a_plus_kb'))
	{ throw new RuntimeException('Granting or removing article administration changed the wrong capabilities'); }
}
