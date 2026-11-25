<?php
// Exercise only a named synthetic account on a disposable local phpBB installation.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 2 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
require_once './includes/acp/auth.php';
require_once './includes/functions_module.php';
require_once dirname(__DIR__, 2) . '/phpBB3/install/convertors/plus_junior_admin.php';
if (plus_jr_source_present($db, 'missing_fixture_')) { throw new RuntimeException('An absent Junior Admin source was treated as installed'); }
plus_jr_finish();
plus_jr_finish();
$writer = new auth_admin();
$subject = function () use ($db)
{
	$result = $db->sql_query('SELECT * FROM ' . USERS_TABLE . " WHERE user_id=91 AND username='JuniorFixture'");
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	if (!$row) { throw new RuntimeException('Missing Junior Admin fixture'); }
	return $row + array('is_registered' => true, 'is_bot' => false);
};
$account = $subject();
$auth->acl($account);
$stock = new \phpbb\auth\auth();
$stock->acl($account);
$review = method_exists(\phpbb2plus\knowledgebase\service\articles::class, 'reviewer');
if (!$auth->has_delegated_acp() || $auth->acl_get('a_') || $stock->acl_get('a_') ||
	$auth->acl_get('a_words') || $stock->acl_get('a_words') || $auth->acl_get('a_user') || (bool) $auth->acl_get('a_plus_kb_articles') !== $review)
{ throw new RuntimeException('An archived Junior Admin grant affected native public staff permissions'); }
if ($review)
{
	$user->data = $account;
	$kb = $phpbb_container->get('phpbb2plus.knowledgebase.articles');
	if (!$kb->reviewer() || $kb->moderator()) { throw new RuntimeException('Delegated article review must not imply editorial rights'); }
}
define('ADMIN_START', true);
if (!$auth->acl_get('a_') || !$auth->acl_get('a_words') || !$auth->acl_get('a_plus_kb_types') || $auth->acl_get('a_icons'))
{ throw new RuntimeException('Junior Admin entrance or the scoped module grant changed'); }
define('IN_ADMIN', true);
if ($auth->acl_get('a_')) { throw new RuntimeException('The ACP entrance changed native administrator status inside the panel'); }
try
{
	$writer->acl_set('user', 0, 91, array('a_words' => ACL_NEVER));
	$account = $subject();
	$auth->acl($account);
	if ($auth->acl_get('a_words') || !$auth->acl_get('a_plus_kb_types')) { throw new RuntimeException('An individual native Never did not revoke just its matching delegation'); }
	$writer->acl_set('user', 0, 91, array('a_plus_kb_types' => ACL_NEVER, 'a_plus_kb_articles' => ACL_NEVER));
	plus_jr_finish();
	$account = $subject();
	$auth->acl($account);
	if ($auth->has_delegated_acp()) { throw new RuntimeException('An account with no effective delegation could enter the ACP'); }
	$writer->acl_set('user', 0, 91, array('a_ranks' => ACL_YES, 'a_words' => ACL_NO));
	$account = $subject();
	$auth->acl($account);
	if (!$auth->native_module('acl_a_ranks') || !$auth->native_module('') || $auth->native_module('acl_a_words') || !$auth->acl_get('a_words'))
	{ throw new RuntimeException('Scoped assignments changed independently held native ACP rights'); }
	$account['user_perm_from'] = 2;
	$auth->acl($account);
	if ($auth->has_delegated_acp()) { throw new RuntimeException('A permission trace inherited the real account’s Junior Admin assignments'); }
}
finally
{
	$writer->acl_set('user', 0, 91, array('a_words' => ACL_NO, 'a_plus_kb_types' => ACL_NO, 'a_plus_kb_articles' => ACL_NO, 'a_ranks' => ACL_NO));
}

// The source fixture's ordinary Archive Club has no administrative assignments.
$membership = array('group_id' => 73, 'user_id' => 91, 'group_leader' => 0, 'user_pending' => 1);
$db->sql_query('INSERT INTO ' . USER_GROUP_TABLE . ' ' . $db->sql_build_array('INSERT', $membership));
try
{
	$writer->acl_set('group', 0, 73, array('a_words' => ACL_NEVER));
	$account = $subject();
	$auth->acl($account);
	if (!$auth->acl_get('a_words')) { throw new RuntimeException('A pending group membership changed Junior Admin access'); }
	$db->sql_query('UPDATE ' . USER_GROUP_TABLE . ' SET user_pending=0 WHERE group_id=73 AND user_id=91');
	$account = $subject();
	$auth->acl($account);
	if ($auth->acl_get('a_words') || !$auth->acl_get('a_plus_kb_types'))
	{ throw new RuntimeException('An active group denial did not revoke the corresponding delegation'); }
}
finally
{
	$db->sql_query('DELETE FROM ' . USER_GROUP_TABLE . ' WHERE group_id=73 AND user_id=91');
	$writer->acl_set('group', 0, 73, array('a_words' => ACL_NO));
}
