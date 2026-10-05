<?php
// Early request/ID boundaries need no simulated database. Native transaction,
// authority, rollback and role evidence is in check-userlist-native.php.
define('IN_PHPBB', true);
$root = dirname(dirname(__DIR__)) . '/phpBB2/';
require $root . 'includes/php_compat.php';
require $root . 'includes/functions_userlist_storage.php';
$lang = array(); $checks = 0;
function ul_check($ok, $message) { $GLOBALS['checks']++; if (!$ok) { throw new RuntimeException($message); } }
function ul_denied($callback, $key) {
    $denied = false; try { call_user_func($callback); } catch (PhpbbUserlistException $e) { $denied = $e->getMessage() === $key; }
    ul_check($denied, 'Invalid original input refused before opening a writer');
}
class UserlistNoDatabase {
    function sql_dedicated_connection() { throw new RuntimeException('Malformed form must never open a database'); }
}
$db = new UserlistNoDatabase();
foreach (array(1,'1','0001','16777215',16777215) as $id) { ul_check(phpbb_userlist_id($id) === (int)$id, 'Valid bounded decimal ID'); }
foreach (array(0,-1,true,false,null,'','1e0','1junk','16777216',array(1),new stdClass()) as $id) {
    ul_denied(function() use ($id) { phpbb_userlist_id($id); }, 'Admin_userlist_invalid_selection');
}
$userdata = array('user_id'=>1,'session_id'=>'fixture-admin','session_admin'=>1,'session_logged_in'=>1);
$_SERVER['REQUEST_METHOD']='POST'; $_POST=array('sid'=>'fixture-admin');
foreach (array(array(),array(array(2)),array('2junk'),array(true),'2',array_fill(0,1001,2)) as $ids) {
    ul_denied(function() use ($db,$ids) { phpbb_userlist_apply($db,'ban',$ids); }, 'Admin_userlist_invalid_selection');
}
foreach (array('',null,true,array('ban'),'delete') as $action) {
    ul_denied(function() use ($db,$action) { phpbb_userlist_apply($db,$action,array(2)); }, 'Admin_userlist_invalid_selection');
}
foreach (array(null,0,true,array(3),'3junk','1e0') as $group) {
    ul_denied(function() use ($db,$group) { phpbb_userlist_apply($db,'group',array(2),$group); }, 'Admin_userlist_invalid_selection');
}
foreach (array('get','missing','nested','wrong','cached-nested','cached-missing') as $case) {
    $_SERVER['REQUEST_METHOD']=$case==='get'?'GET':'POST'; $_POST=array('sid'=>'fixture-admin'); $userdata['session_id']='fixture-admin';
    if ($case==='missing') { $_POST=array(); }
    if ($case==='nested') { $_POST['sid']=array('fixture-admin'); }
    if ($case==='wrong') { $_POST['sid']='wrong'; }
    if ($case==='cached-nested') { $userdata['session_id']=array('fixture-admin'); }
    if ($case==='cached-missing') { unset($userdata['session_id']); }
    ul_denied(function() use ($db) { phpbb_userlist_apply($db,'deactivate',array(2)); }, 'Session_invalid');
}
echo $checks . " early userlist request/ID checks passed; publication uses native InnoDB tests.\n";
