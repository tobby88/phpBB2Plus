<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_IP_LOOKUP_NATIVE')!=='1'){echo "IP lookup checks require an explicitly enabled disposable database.\n";return;}
require __DIR__.'/ip-lookup-native-fixture.php';
try{
	ipn_open();ip_language('english');
	foreach(array('english','german')as$locale){ip_language($locale);foreach(array(USER,MOD,ADMIN)as$level){
		ipn_seed($level);$before=ipn_snapshot();$users=$level===ADMIN?array(-1=>1,7=>1,8=>3):array(-1=>1,7=>1,8=>1);
		foreach(array('shoutbox_max.php','modcp.php')as$route){ip_check(ipn_query($route)===$users,'Actual controller cross-forum totals retain only currently moderated forums');$ip_cases++;}
		$ips=$level===ADMIN?array('c0000209'=>1,'c0000258'=>1,'c0000263'=>1):array('c0000209'=>1);ip_check(ipn_query('modcp.php','ips')===$ips,'Other-IP enumeration obeys same forum scope');$ip_cases++;
		ip_check(ipn_snapshot()===$before,'Every lookup is read-only');
		if($level===USER){ipn_denied(function()use($db){phpbb_ip_lookup_actor($db,true);});}else{ip_check((int)phpbb_ip_lookup_actor($db,true)['user_level']===$level,'Fresh global staff role authorizes shoutbox IP view');$ip_cases++;}
	}}
	foreach(array("DELETE FROM fixture_sessions","UPDATE fixture_sessions SET session_logged_in=0","UPDATE fixture_sessions SET session_user_id=8","UPDATE fixture_sessions SET session_id=LOWER(session_id)","UPDATE fixture_users SET user_active=0 WHERE user_id=7","DELETE FROM fixture_users WHERE user_id=7")as$change){ipn_seed();ipn_change($change);ipn_denied(function()use($db){phpbb_ip_lookup_actor($db,true);});ipn_denied(function(){ipn_query('modcp.php');});}
	foreach(array(false,null,0,-1,'7junk',array(7))as$bad){ipn_seed();$userdata['user_id']=$bad;ipn_denied(function()use($db){phpbb_ip_lookup_forum_filter($db,3);});}
	foreach(array('',null,array('MixedCase-ip-session'),'wrong')as$bad){ipn_seed();$userdata['session_id']=$bad;ipn_denied(function()use($db){phpbb_ip_lookup_actor($db,true);});}
	foreach(array('DELETE FROM fixture_user_group','UPDATE fixture_user_group SET user_pending=1','UPDATE fixture_auth_access SET auth_mod=0','UPDATE fixture_forums SET auth_view=5 WHERE forum_id=3','UPDATE fixture_forums SET auth_read=5 WHERE forum_id=3')as$change){ipn_seed();ipn_change($change);ipn_denied(function(){ipn_query('modcp.php');});ip_check(ipn_query('shoutbox_max.php')===array(),'Global shoutbox staff cannot inspect unmoderated forum correlation');$ip_cases++;}
	ipn_seed(ADMIN);ipn_change('UPDATE fixture_users SET user_level=0 WHERE user_id=7');ip_check(ipn_query('modcp.php')===array(-1=>1,7=>1,8=>1),'Cached ADMIN role cannot widen current forum moderation scope');$ip_cases++;ipn_denied(function()use($db){phpbb_ip_lookup_actor($db,true);});
	foreach(array('SELECT user_id, user_level','SELECT session_id','SELECT a.forum_id')as$failure){ipn_seed();$db->fail=$failure;ipn_denied(function()use($db){phpbb_ip_lookup_forum_filter($db,3);});$db->fail='';}
	foreach(array('modcp.php','shoutbox_max.php')as$route){ipn_seed();ipn_change("INSERT INTO fixture_posts(post_id,poster_id,forum_id,poster_ip) VALUES(9,8,9,'c00002aa')");$queries=ip_controller_queries($route);$source=$queries['users'];ip_check(strpos($source,'sql_escape')!==false,'Actual source escapes persisted IP query value');$ip_cases++;}
	echo 'IP lookup native: ',$ip_cases,' cases, ',$ip_checks," assertions PASS; both actual controller SQL paths, canonical MariaDB schema, current sessions/roles/ACLs, scoped totals and read-only rejection.\n";
}finally{ipn_close();restore_error_handler();}
