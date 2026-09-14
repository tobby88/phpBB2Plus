<?php
// Storage-only unit adapters deliberately model an authorized root session.
// Revocation and delegated routes must be exercised by the native authority test.
define('USERS_TABLE', 'fixture_acl_users'); define('SESSIONS_TABLE', 'fixture_acl_sessions');
define('JR_ADMIN_TABLE', 'fixture_acl_jr'); define('ADMIN', 1);
define('CTRACKER_CONFIG', 'fixture_ct_config');
if (!defined('GENERAL_ERROR')) { define('GENERAL_ERROR', 1); }
$phpEx = 'php';
$userdata = array('user_id'=>1,'session_id'=>'fixture-admin','session_logged_in'=>1,'session_admin'=>1);
$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST['sid'] = $userdata['session_id'];
class CtFixtureAuthorityResult { var $rows; function __construct($rows) { $this->rows = $rows; } }
function ct_fixture_authority_query($sql)
{
 if (strpos($sql,' LOCK IN SHARE MODE') !== false && preg_match('/^SELECT (session_id|user_id) FROM fixture_acl_/', $sql))
 { return new CtFixtureAuthorityResult(array(array('user_id'=>1,'session_id'=>'fixture-admin'))); }
 if (strpos($sql,'SELECT user_id, user_level, user_active FROM fixture_acl_users') === 0)
 { return new CtFixtureAuthorityResult(array(array('user_id'=>1,'user_level'=>1,'user_active'=>1))); }
 if (strpos($sql,'SELECT 1 AS allowed WHERE ') === 0)
 { return new CtFixtureAuthorityResult(array(array('allowed'=>1))); }
 return false;
}
