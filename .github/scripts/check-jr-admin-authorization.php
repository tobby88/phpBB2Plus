<?php
// Real permission functions and installed declarations, isolated user grants.
define('IN_PHPBB', true); define('ADMIN', 1); define('JR_ADMIN_TABLE', 'fixture_jr_admin');
$root = dirname(dirname(__DIR__));
$phpEx = 'php'; $lang = array();
require $root . '/phpBB2/includes/functions_jr_admin.php';
function jr_auth_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
class JrAuthDatabase
{
	var $grants = '';
	function sql_query($sql) { jr_auth_check(strpos($sql,'SELECT * FROM fixture_jr_admin')===0 && strpos($sql,'user_id = 7')!==false,'Only fixture identity queried'); return true; }
	function sql_fetchrow($result) { return array('user_jr_admin'=>$this->grants); }
}
$db = new JrAuthDatabase();
$userdata = array('user_id'=>7,'user_level'=>0,'session_id'=>'fixture-session','session_logged_in'=>true);
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try
{
	$routes = jr_admin_authorization_routes();
	jr_auth_check(is_array($routes) && count($routes)>100,'All installed static and localized XS declarations read');
	jr_auth_check(!function_exists('admin_user_post_string'),'Reading declarations never executes admin_users functions');
	foreach (glob($root.'/phpBB2/admin/admin_*.php') as $file)
	{
		$source=file_get_contents($file); $entries=jr_admin_file_registrations($source,basename($file));
		jr_auth_check(is_array($entries),'Supported declaration grammar: '.basename($file));
		foreach($entries as $entry) { jr_auth_check(isset($routes[md5($entry[0].$entry[1].$entry[2])]),'Every declared module identity is available'); }
	}
	$manage=md5('UsersManageadmin_users.php'); $board=md5('GeneralConfigurationadmin_board.php');
	$db->grants=$manage; $_POST=array(); $_GET=array('module'=>$manage);
	jr_auth_check(jr_admin_secure('admin_users.php'),'Assigned module opens its own controller');
	jr_auth_check(!jr_admin_secure('admin_board.php') && !jr_admin_secure('admin_jr_admin.php'),'Valid module token cannot authorize another controller');
	jr_auth_check(jr_admin_secure('/admin/admin_users.php?redirect=/admin/admin_board.php'),'Query-path text cannot replace the executing endpoint');
	foreach(array($board,'',array($manage),str_repeat('a',32)) as $bad) { $_GET=array('module'=>$bad); jr_auth_check(!jr_admin_secure('admin_users.php'),'Mismatched or malformed module token refused'); }
	$_GET=array('sid'=>'fixture-session');
	jr_auth_check(jr_admin_secure('admin_users.php') && !jr_admin_secure('admin_board.php'),'SID navigation still checks file-specific grant');
	$_GET=array('sid'=>'wrong'); jr_auth_check(!jr_admin_secure('admin_users.php'),'Bad navigation SID refused');
	$_GET=array(); $_POST=array('submit'=>'1');
	jr_auth_check(jr_admin_secure('admin_users.php') && !jr_admin_secure('admin_board.php'),'POST module authorization is bound to current file; pagestart separately checks POST/SID');
	$_POST=array(); $_GET=array();
	jr_auth_check(jr_admin_secure('index.php') && !jr_admin_secure('indexXphp') && !jr_admin_secure('index.php.evil'),'Only exact ACP index is exempt from module checks');
	$db->grants=''; jr_auth_check(!jr_admin_secure('index.php'),'Users without any module grant cannot open ACP index');
	$db->grants=md5('ArcadeTournamentsadmin_arcade_tournaments.php'); $_GET=array('module'=>$db->grants);
	jr_auth_check(jr_admin_secure('admin_arcade_tournaments.php') && !jr_admin_secure('admin_arcade.php'),'Cross-file declaration targets the registered destination, not the declaring file');
	$db->grants=md5('ArcadeEditadmin_arcade_games.php?mode=edit_games'); $_GET=array('sid'=>'fixture-session');
	jr_auth_check(jr_admin_secure('admin_arcade_scores.php') && !jr_admin_secure('admin_pa_ug_auth.php'),'Documented Arcade helper retains parent module scope only');
	$db->grants=md5('DownloadPermissionsadmin_pa_catauth.php');
	jr_auth_check(jr_admin_secure('admin_pa_ug_auth.php'),'Download permission subpage retains parent scope');
	$db->grants=md5('ArcadeView../activity.php'); $_GET=array('module'=>$db->grants);
	jr_auth_check(!jr_admin_secure('admin_arcade_games.php'),'Public navigation link is not an ACP capability');
	$db->grants=md5('Extreme_StylesStyles_Managementxs_frameset.php?action=menu'); $_GET=array('module'=>$db->grants);
	jr_auth_check(jr_admin_secure('xs_frameset.php') && !jr_admin_secure('admin_users.php'),'XS management grant remains confined to styles');
	foreach(glob($root.'/phpBB2/admin/xs_*.php') as $helper) {
		if(strpos(file_get_contents($helper), "require('./pagestart.'")!==false) { jr_auth_check(jr_admin_secure(basename($helper)),'XS child controller inherits styles scope: '.basename($helper)); }
	}
	$userdata['user_level']=ADMIN; $_GET=array();
	jr_auth_check(jr_admin_secure('admin_board.php'),'Full administrator behavior retained');
	$fixture='<?php // $module["Fake"]["Comment"]="admin_fake.php";' . "\n" . '$module["Test"]["Name"]=$filename."?mode=test";';
	jr_auth_check(jr_admin_file_registrations($fixture,'admin_fixture.php')===array(array('Test','Name','admin_fixture.php?mode=test')),'Comments do not register grants');
	jr_auth_check(jr_admin_file_registrations('<?php $module["Test"]["Name"]=dangerous_call();','admin_fixture.php')===false,'Unsupported dynamic declarations fail closed without execution');
	jr_auth_check(jr_admin_file_registrations('<?php $module["Test"]["Name"]="admin_fixture.$phpEx";','admin_fixture.php')===array(array('Test','Name','admin_fixture.php')),'Quoted extension interpolation matches legacy identity');
	jr_auth_check(!jr_admin_route_matches_file('../admin_users.php','admin_users.php'),'Traversal route cannot match ACP target');
	$bootstrap=file_get_contents($root.'/phpBB2/admin/pagestart.php');
	jr_auth_check(strpos($bootstrap,"jr_admin_secure(isset(\$_SERVER['SCRIPT_NAME'])")!==false && strpos($bootstrap,"jr_admin_secure(basename(\$HTTP_SERVER_VARS['REQUEST_URI']))")===false,'Authorization uses executing script, not attacker-controlled URI/query text');
	echo "Junior Admin endpoint-bound authorization checks passed.\n";
}
finally { restore_error_handler(); }
