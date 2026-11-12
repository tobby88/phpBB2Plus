<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_IP_LOOKUP_NATIVE')!=='1'){throw new RuntimeException('Explicit owned native fixture required');}
require __DIR__.'/ip-lookup-fixture.php';require $ip_app.'db/mysqli.php';
$ipn_db=$ipn_control=null;$ipn_created=false;$ipn_schema='codex_ip_lookup_'.bin2hex(phpbb_random_bytes(8));
$ipn_tables=array('users','sessions','forums','user_group','auth_access','posts');
class IpLookupNativeDatabase extends sql_db
{
	var $setup=false;
	var $fail='';
	function sql_query($sql='',$transaction=false){if(!$this->setup&&!preg_match('/^SELECT\b/i',ltrim($sql))){throw new RuntimeException('IP lookup must remain read-only');}if($this->fail!==''&&strpos(ltrim($sql),$this->fail)===0){return false;}return parent::sql_query($sql,$transaction);}
}
function ipn_sql($sql){$r=$GLOBALS['db']->sql_query($sql);if(!$r){throw new RuntimeException('Own IP lookup fixture query failed');}return$r;}
function ipn_open()
{
	global $ipn_control,$ipn_schema,$ipn_created,$db,$ipn_tables,$ip_app;
	$port=getenv('PHPBB_IP_LOOKUP_PORT')?:'3306';ip_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Explicit loopback fixture port');$host='127.0.0.1:'.$port;$password=getenv('PHPBB_IP_LOOKUP_PASSWORD')?:'';
	$ipn_control=new sql_db($host,'root',$password,'',false);ip_check((bool)$ipn_control->db_connect_id,'Owned local fixture server');
	ip_check(preg_match('/^codex_ip_lookup_[a-f0-9]{16}$/D',$ipn_schema)&&$ipn_control->sql_query('CREATE DATABASE '.$ipn_schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Exclusive owned schema');$ipn_created=true;
	$db=new IpLookupNativeDatabase($host,'root',$password,$ipn_schema,false);ip_check((bool)$db->db_connect_id,'Owned schema connection');$db->setup=true;
	$canonical=file_get_contents($ip_app.'install/schemas/mysql_schema.sql');foreach($ipn_tables as$name){ip_check(preg_match('/CREATE TABLE phpbb_'.preg_quote($name,'/').' \(.*?\) ENGINE=InnoDB[^;]*;/s',$canonical,$m)===1,'Canonical production schema');ipn_sql(str_replace('phpbb_'.$name,'fixture_'.$name,$m[0]));}$db->setup=false;
}
function ipn_seed($level=MOD)
{
	global $db,$userdata,$ipn_tables,$tree;
	$db->setup=true;foreach($ipn_tables as$name){ipn_sql('DELETE FROM fixture_'.$name);}
	ipn_sql("INSERT INTO fixture_users(user_id,username,user_password,user_level) VALUES(7,'Fixture moderator','',".(int)$level."),(8,'Other member','',0),(-1,'Anonymous','',0)");
	ipn_sql("INSERT INTO fixture_sessions(session_id,session_user_id,session_logged_in) VALUES('MixedCase-ip-session',7,1)");
	ipn_sql("INSERT INTO fixture_forums(forum_id,cat_id,forum_name,auth_view,auth_read) VALUES(3,1,'Moderated forum',0,0),(8,1,'Other forum',0,0),(9,1,'Private forum',5,5)");
	ipn_sql("INSERT INTO fixture_user_group(group_id,user_id,user_pending) VALUES(20,7,0)");ipn_sql('INSERT INTO fixture_auth_access(group_id,forum_id,auth_mod) VALUES(20,3,1)');
	ipn_sql("INSERT INTO fixture_posts(post_id,poster_id,forum_id,poster_ip) VALUES(1,7,3,'c0000209'),(2,8,3,'c0000209'),(3,8,8,'c0000209'),(4,8,9,'c0000209'),(5,7,9,'c0000263'),(6,7,8,'c0000258'),(7,-1,3,'c0000209')");
	$userdata=array('user_id'=>7,'user_level'=>ADMIN,'session_logged_in'=>true,'session_id'=>'MixedCase-ip-session');
	// Deliberately stale all-forum grants must never replace owning-connection ACLs.
	$tree=array('data'=>array(array('forum_id'=>9,'auth_mod'=>1,'auth_view'=>0,'auth_read'=>0)),'keys'=>array('f9'=>0),'type'=>array('f'));
	$db->setup=false;
}
function ipn_change($sql){$GLOBALS['db']->setup=true;try{ipn_sql($sql);}finally{$GLOBALS['db']->setup=false;}}
function ipn_query($route,$kind='users')
{
	global $db;$queries=ip_controller_queries($route);$forum_id=3;$poster_id=7;$shout_identifyer=$post_row=array('shout_ip'=>'c0000209','poster_ip'=>'c0000209');
	eval($queries[$kind]);$r=ipn_sql($sql);$rows=$db->sql_fetchrowset($r);$db->sql_freeresult($r);$out=array();foreach($rows as$row){$key=$kind==='users'?(string)$row['user_id']:$row['poster_ip'];$out[$key]=(int)$row['postings'];}ksort($out);return$out;
}
function ipn_snapshot(){global $db,$ipn_tables;$all=array();foreach($ipn_tables as$name){$r=ipn_sql('SELECT * FROM fixture_'.$name);$rows=$db->sql_fetchrowset($r);$db->sql_freeresult($r);$encoded=array_map('json_encode',$rows);sort($encoded);$all[$name]=$encoded;}return$all;}
function ipn_post($post_id,$forum_id=3)
{
	global $db,$lang;
	$source=file_get_contents($GLOBALS['ip_app'].'modcp.php');$branch=substr($source,strpos($source,"case 'ip':"));
	$code=ip_fragment($branch,'$sql = "SELECT poster_ip','$ip_this_post = decode_ip');eval($code);return $post_row;
}
function ipn_denied($callback,$expected=null){$before=ipn_snapshot();$denied=false;try{call_user_func($callback);}catch(IpLookupDenied$e){$denied=$expected===null||$e->getMessage()===$expected;}ip_check($denied&&ipn_snapshot()===$before,'Current identity rejection leaves fixture unchanged');$GLOBALS['ip_cases']++;}
function ipn_close(){global $db,$ipn_control,$ipn_schema,$ipn_created;if($db){$db->sql_close();}if($ipn_created){ip_check(preg_match('/^codex_ip_lookup_[a-f0-9]{16}$/D',$ipn_schema)&&$ipn_control->sql_query('DROP DATABASE '.$ipn_schema),'Only exact owned schema removed');}if($ipn_control){$ipn_control->sql_close();}}
